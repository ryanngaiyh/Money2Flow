<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/allowance.php';

function notification_types(): array
{
    return [
        'budget_warning' => 'Budget alerts',
        'allowance_reminder' => 'Allowance reminders',
        'goal_achievement' => 'Savings goal updates',
        'goal_deadline' => 'Goal deadline reminders',
        'split_bill_reminder' => 'Split bill reminders',
    ];
}

function notification_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
}

function notification_preferences(): array
{
    $statement = db()->prepare('SELECT * FROM notification_preferences WHERE user_id=?');
    $statement->execute([user_id()]);
    $saved = $statement->fetch() ?: [];
    $preferences = [];
    foreach (notification_types() as $type => $label) {
        $preferences[$type] = !array_key_exists($type, $saved) || (int)$saved[$type] === 1;
    }
    return $preferences;
}

function notification_send(string $type, string $eventKey, string $title, string $message, ?string $recordType = null, ?int $recordId = null): void
{
    notification_send_for_user(user_id(), $type, $eventKey, $title, $message, $recordType, $recordId);
}

function notification_send_for_user(int $ownerId, string $type, string $eventKey, string $title, string $message, ?string $recordType = null, ?int $recordId = null): void
{
    if (!array_key_exists($type, notification_types())) return;
    $preference = db()->prepare('SELECT `' . $type . '` FROM notification_preferences WHERE user_id=?');
    $preference->execute([$ownerId]);
    $enabled = $preference->fetchColumn();
    if ($enabled !== false && (int)$enabled === 0) return;
    $statement = db()->prepare('INSERT IGNORE INTO notifications (user_id,notification_type,title,message,related_record_type,related_record_id,event_key,created_at) VALUES (?,?,?,?,?,?,?,?)');
    $statement->execute([$ownerId, $type, mb_substr($title, 0, 180), mb_substr($message, 0, 500), $recordType, $recordId, $eventKey, notification_now()->format('Y-m-d H:i:s')]);
}

function notification_budget_alert_state(int $budgetId, int $budgetCents, int $threshold): array
{
    $prefix = 'budget:' . $budgetId . ':' . $budgetCents . ':';
    $statement = db()->prepare('SELECT event_key,message FROM notifications WHERE user_id=? AND event_key LIKE ?');
    $statement->execute([user_id(), $prefix . '%']);
    $hasInitialAlert = false;
    $highestAlertedCents = $threshold === 100 ? $budgetCents : (int)ceil($budgetCents * 0.8);
    $followupPrefix = $prefix . ($threshold === 100 ? 'over:' : 'near:');
    foreach ($statement->fetchAll() as $alert) {
        if ($alert['event_key'] === $prefix . $threshold) {
            $hasInitialAlert = true;
            // Older threshold alerts stored the spend in their message, not their event key.
            if (preg_match('/^You have used RM ([0-9,]+\.[0-9]{2}) out of /', $alert['message'], $matches)) {
                $highestAlertedCents = max($highestAlertedCents, (int)round((float)str_replace(',', '', $matches[1]) * 100));
            }
        } elseif (str_starts_with($alert['event_key'], $followupPrefix)) {
            $highestAlertedCents = max($highestAlertedCents, (int)substr($alert['event_key'], strlen($followupPrefix)));
        }
    }
    return [$hasInitialAlert, $highestAlertedCents];
}

function notification_check_budget_month(string $month): void
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) return;
    $statement = db()->prepare('SELECT b.id,b.category_id,b.budget_amount,c.name FROM budgets b LEFT JOIN categories c ON c.id=b.category_id AND c.user_id=b.user_id WHERE b.user_id=? AND b.budget_month=?');
    $statement->execute([user_id(), $month]);
    $budgets = $statement->fetchAll();
    if (!$budgets) return;
    $start = $month . '-01';
    $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
    $spending = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND type='Expense' AND transaction_date>=? AND transaction_date<? AND (? IS NULL OR category_id=?)");
    foreach ($budgets as $budget) {
        $spending->execute([user_id(), $start, $end, $budget['category_id'], $budget['category_id']]);
        $spentCents = (int)round((float)$spending->fetchColumn() * 100);
        $budgetCents = (int)round((float)$budget['budget_amount'] * 100);
        if ($budgetCents < 1 || $spentCents * 100 < $budgetCents * 80) continue;
        $threshold = $spentCents >= $budgetCents ? 100 : 80;
        if ($threshold === 80) {
            $alreadyExceeded = db()->prepare('SELECT 1 FROM notifications WHERE user_id=? AND event_key=? LIMIT 1');
            $alreadyExceeded->execute([user_id(), 'budget:' . $budget['id'] . ':' . $budgetCents . ':100']);
            if ($alreadyExceeded->fetchColumn()) continue;
            [$hasInitialAlert, $highestAlertedCents] = notification_budget_alert_state((int)$budget['id'], $budgetCents, 80);
            if ($hasInitialAlert) {
                if ($spentCents <= $highestAlertedCents) continue;
                $name = $budget['name'] ?: 'Overall';
                notification_send('budget_warning', 'budget:' . $budget['id'] . ':' . $budgetCents . ':near:' . $spentCents,
                    $name . ' Budget Approaching Limit',
                    'Spending has increased to ' . currency($spentCents / 100) . ' for your ' . $name . ' budget in ' . $month . '. Only ' . currency(($budgetCents - $spentCents) / 100) . ' remains of ' . currency($budgetCents / 100) . '.',
                    'budget', (int)$budget['id']);
                continue;
            }
        } else {
            [$hasInitialAlert, $highestAlertedCents] = notification_budget_alert_state((int)$budget['id'], $budgetCents, 100);
            if ($hasInitialAlert) {
                if ($spentCents <= $highestAlertedCents) continue;
                $name = $budget['name'] ?: 'Overall';
                notification_send('budget_warning', 'budget:' . $budget['id'] . ':' . $budgetCents . ':over:' . $spentCents,
                    $name . ' Budget Still Exceeded',
                    'Spending has increased to ' . currency($spentCents / 100) . ' for your ' . $name . ' budget in ' . $month . '. You are ' . currency(($spentCents - $budgetCents) / 100) . ' over the ' . currency($budgetCents / 100) . ' limit.',
                    'budget', (int)$budget['id']);
                continue;
            }
        }
        $name = $budget['name'] ?: 'Overall';
        $remaining = max(0, ($budgetCents - $spentCents) / 100);
        $title = $name . ' Budget ' . ($threshold === 100 ? 'Exceeded' : 'Warning');
        $message = 'You have used ' . currency($spentCents / 100) . ' out of your ' . currency($budgetCents / 100) . ' ' . $name . ' budget for ' . $month . '. ' . ($threshold === 100 ? 'The limit has been reached or exceeded.' : 'Only ' . currency($remaining) . ' remains.');
        notification_send('budget_warning', 'budget:' . $budget['id'] . ':' . $budgetCents . ':' . $threshold, $title, $message, 'budget', (int)$budget['id']);
    }
}

function notification_check_goal_achievement(int $goalId, ?float $previousAmount = null, ?float $previousTarget = null): void
{
    $goal = ownership('savings_goals', $goalId);
    if (!$goal || (int)round((float)$goal['current_amount'] * 100) < (int)round((float)$goal['target_amount'] * 100)) return;
    if ($previousAmount !== null && $previousTarget !== null && $previousAmount >= $previousTarget) return;
    notification_send('goal_achievement', 'goal:' . $goalId . ':' . (int)round((float)$goal['target_amount'] * 100),
        $goal['goal_name'] . ' Goal Achieved',
        'Congratulations! You saved ' . currency($goal['current_amount']) . ' toward your ' . currency($goal['target_amount']) . ' ' . $goal['goal_name'] . ' goal.',
        'goal', $goalId);
}

function notification_next_allowance(array $source, DateTimeImmutable $today): ?DateTimeImmutable
{
    $start = new DateTimeImmutable($source['start_date'], $today->getTimezone());
    if ($start >= $today) return $start;
    if ($source['frequency'] === 'Weekly') {
        $days = (int)$start->diff($today)->format('%a');
        return $start->modify('+' . ((int)ceil($days / 7) * 7) . ' days');
    }
    if ($source['frequency'] === 'Monthly') {
        $offset = ((int)$today->format('Y') - (int)$start->format('Y')) * 12 + (int)$today->format('n') - (int)$start->format('n');
        foreach ([$offset, $offset + 1] as $months) {
            $first = $start->modify('first day of this month')->modify('+' . $months . ' months');
            $day = min((int)$start->format('j'), (int)$first->format('t'));
            $candidate = $first->setDate((int)$first->format('Y'), (int)$first->format('n'), $day);
            if ($candidate >= $today) return $candidate;
        }
    }
    if ($source['frequency'] === 'Yearly') {
        foreach ([(int)$today->format('Y'), (int)$today->format('Y') + 1] as $year) {
            $first = new DateTimeImmutable($year . '-' . $start->format('m') . '-01', $today->getTimezone());
            $day = min((int)$start->format('j'), (int)$first->format('t'));
            $candidate = $first->setDate($year, (int)$start->format('n'), $day);
            if ($candidate >= $today) return $candidate;
        }
    }
    return null;
}

/** Most recent scheduled date before today, including a missed irregular date. */
function notification_missed_allowance_date(string $anchor, string $frequency, DateTimeImmutable $today): ?DateTimeImmutable
{
    if (!valid_date($anchor)) return null;
    $start = (new DateTimeImmutable($anchor, $today->getTimezone()))->setTime(0, 0);
    if ($start >= $today) return null;
    if ($frequency === 'Irregular') return $start;
    $probe = max($start, $today->modify('-367 days'));
    $latest = null;
    while ($probe < $today) {
        $candidate = allowance_next_date($anchor, $frequency, $probe);
        if (!$candidate || $candidate >= $today) break;
        $latest = $candidate;
        $probe = $candidate->modify('+1 day');
    }
    return $latest;
}

function notification_refresh_reminders(?DateTimeImmutable $clock = null): void
{
    static $refreshed = false;
    if ($clock === null && $refreshed) return;
    if ($clock === null) $refreshed = true;
    $today = ($clock ?? notification_now())->setTime(0, 0);
    $todayText = $today->format('Y-m-d');
    $preferences = notification_preferences();

    if ($preferences['allowance_reminder']) {
        $plan = allowance_plan(user_id(), $today);
        // Catch up the latest missed occurrence when the app was not running.
        // The same event key as an on-time reminder makes repeat runs idempotent.
        if (isset($plan['settings']) && $plan['state'] !== 'source_missing') {
            $missed = notification_missed_allowance_date($plan['settings']['next_allowance_date'], $plan['frequency'], $today);
            if ($missed) {
                $sourceId = $plan['settings']['income_source_id'];
                $eventKey = $sourceId ? 'allowance:' . $sourceId . ':' . $missed->format('Y-m-d') : 'allowance-plan:' . $missed->format('Y-m-d');
                notification_send('allowance_reminder', $eventKey, 'Allowance date passed',
                    $plan['source_name'] . ' (' . currency($plan['allowance_amount_cents'] / 100) . ') was expected on ' . $missed->format('j M Y') . '. Record it as income only if it was received.',
                    'allowance', $sourceId ? (int)$sourceId : null);
            }
        }
        if ($plan['state'] === 'ready' && $plan['days'] <= 3) {
            $days = $plan['days'];
            $eventKey = $plan['settings']['income_source_id']
                ? 'allowance:' . $plan['settings']['income_source_id'] . ':' . $plan['next_date']->format('Y-m-d')
                : 'allowance-plan:' . $plan['next_date']->format('Y-m-d');
            notification_send('allowance_reminder', $eventKey,
                'Allowance ' . ($days === 0 ? 'due today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's')),
                $plan['source_name'] . ' (' . currency($plan['allowance_amount_cents'] / 100) . ') is expected on ' . $plan['next_date']->format('j M Y') . '. Estimated available spending is ' . currency($plan['available_cents'] / 100) . ' after unpaid commitments and reserved savings.',
                'allowance', $plan['settings']['income_source_id'] ? (int)$plan['settings']['income_source_id'] : null);
        } elseif ($plan['state'] === 'unconfigured') {
            // Preserve pre-planner reminders for users who have not configured Phase 3.
            $statement = db()->prepare("SELECT id,source,amount,frequency,start_date FROM incomes WHERE user_id=? AND income_type='Recurring' AND status='Active' AND LOWER(source) LIKE '%allowance%'");
            $statement->execute([user_id()]);
            $nextSource = null;
            $nextDate = null;
            foreach ($statement->fetchAll() as $source) {
                $missed = notification_missed_allowance_date($source['start_date'], $source['frequency'], $today);
                if ($missed) {
                    notification_send('allowance_reminder', 'allowance:' . $source['id'] . ':' . $missed->format('Y-m-d'),
                        'Allowance date passed',
                        $source['source'] . ' (' . currency($source['amount']) . ') was scheduled for ' . $missed->format('j M Y') . '. Record it as income only if it was received.',
                        'income', (int)$source['id']);
                }
                $date = notification_next_allowance($source, $today);
                if ($date && ($nextDate === null || $date < $nextDate)) { $nextSource = $source; $nextDate = $date; }
            }
            if ($nextSource && $nextDate) {
                $days = (int)$today->diff($nextDate)->format('%a');
                if ($days <= 3) {
                    $balance = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='Income' THEN amount ELSE -amount END),0) FROM transactions WHERE user_id=? AND transaction_date<=?");
                    $balance->execute([user_id(), $todayText]);
                    notification_send('allowance_reminder', 'allowance:' . $nextSource['id'] . ':' . $nextDate->format('Y-m-d'),
                        'Allowance ' . ($days === 0 ? 'due today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's')),
                        $nextSource['source'] . ' (' . currency($nextSource['amount']) . ') is scheduled for ' . $nextDate->format('j M Y') . '. Your recorded balance is ' . currency($balance->fetchColumn()) . ' before upcoming commitments and savings.',
                        'income', (int)$nextSource['id']);
                }
            }
        }
    }

    if ($preferences['goal_deadline']) {
        $statement = db()->prepare("SELECT id,goal_name,target_amount,current_amount,target_date FROM savings_goals WHERE user_id=? AND goal_type='Target' AND status='Active' AND target_date IS NOT NULL AND current_amount<target_amount AND target_date>=?");
        $statement->execute([user_id(), $todayText]);
        foreach ($statement->fetchAll() as $goal) {
            $deadline = new DateTimeImmutable($goal['target_date'], $today->getTimezone());
            $days = (int)$today->diff($deadline)->format('%a');
            if ($days > 7) continue;
            $remaining = (int)round(((float)$goal['target_amount'] - (float)$goal['current_amount']) * 100) / 100;
            notification_send('goal_deadline', 'goal-deadline:' . $goal['id'] . ':' . $goal['target_date'],
                $goal['goal_name'] . ' deadline approaching',
                'Your target date is ' . $deadline->format('j M Y') . ($days === 0 ? ' (today).' : ' (' . $days . ' days away).') . ' You still need ' . currency($remaining) . '.',
                'goal', (int)$goal['id']);
        }
    }

    if ($preferences['split_bill_reminder']) {
        $statement = db()->prepare("SELECT b.split_bill_id,b.description,b.bill_date,COUNT(p.participant_id) pending_count FROM split_bills b JOIN split_bill_participants p ON p.split_bill_id=b.split_bill_id AND p.status='Pending' AND p.is_payer=0 WHERE b.user_id=? AND b.bill_date<=? GROUP BY b.split_bill_id");
        $statement->execute([user_id(), $todayText]);
        foreach ($statement->fetchAll() as $bill) {
            $billDate = new DateTimeImmutable($bill['bill_date'], $today->getTimezone());
            $days = (int)$billDate->diff($today)->format('%a');
            if ($days < 7) continue;
            $week = intdiv($days, 7);
            notification_send('split_bill_reminder', 'split:' . $bill['split_bill_id'] . ':week:' . $week,
                'Split bill still unsettled',
                $bill['description'] . ' has ' . $bill['pending_count'] . ' unsettled share' . ((int)$bill['pending_count'] === 1 ? '' : 's') . '. Review the bill to update payments.',
                'split_bill', (int)$bill['split_bill_id']);
        }
    }
}

function notification_link(array $notification): string
{
    return match ($notification['related_record_type']) {
        'budget' => app_url('budgets/index.php'),
        'income' => app_url('income/index.php'),
        'allowance' => app_url('allowance/index.php'),
        'goal' => app_url('savings/index.php'),
        'split_bill' => app_url('split-bills/index.php'),
        default => app_url('notifications/index.php'),
    };
}

function notification_icon(string $type): string
{
    return match ($type) {
        'budget_warning' => '◷',
        'allowance_reminder' => '↗',
        'goal_achievement' => '★',
        'goal_deadline' => '◎',
        'split_bill_reminder' => '♧',
        default => '•',
    };
}
