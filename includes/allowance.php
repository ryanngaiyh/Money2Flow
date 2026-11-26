<?php
require_once __DIR__ . '/auth.php';

function allowance_today(): DateTimeImmutable
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))->setTime(0, 0);
}

function allowance_cents($value): int
{
    return (int)round((float)$value * 100);
}

function allowance_recorded_funds_cents(int $ownerId, DateTimeImmutable $today, int $openingCents = 0): int
{
    $ledger = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='Income' THEN amount ELSE -amount END),0) FROM transactions WHERE user_id=? AND transaction_date<=?");
    $ledger->execute([$ownerId, $today->format('Y-m-d')]);
    return $openingCents + allowance_cents($ledger->fetchColumn());
}

function allowance_next_date(string $anchor, string $frequency, DateTimeImmutable $today): ?DateTimeImmutable
{
    if (!valid_date($anchor)) return null;
    $start = (new DateTimeImmutable($anchor, $today->getTimezone()))->setTime(0, 0);
    if ($start >= $today) return $start;
    if ($frequency === 'Irregular') return null;
    if ($frequency === 'Weekly') {
        $days = (int)$start->diff($today)->format('%a');
        return $start->modify('+' . ((int)ceil($days / 7) * 7) . ' days');
    }
    if ($frequency === 'Monthly') {
        $months = ((int)$today->format('Y') - (int)$start->format('Y')) * 12 + (int)$today->format('n') - (int)$start->format('n');
        foreach ([$months, $months + 1] as $offset) {
            $first = $start->modify('first day of this month')->modify('+' . $offset . ' months');
            $day = min((int)$start->format('j'), (int)$first->format('t'));
            $candidate = $first->setDate((int)$first->format('Y'), (int)$first->format('n'), $day);
            if ($candidate >= $today) return $candidate;
        }
    }
    if ($frequency === 'Yearly') {
        foreach ([(int)$today->format('Y'), (int)$today->format('Y') + 1] as $year) {
            $first = new DateTimeImmutable($year . '-' . $start->format('m') . '-01', $today->getTimezone());
            $day = min((int)$start->format('j'), (int)$first->format('t'));
            $candidate = $first->setDate($year, (int)$start->format('n'), $day);
            if ($candidate >= $today) return $candidate;
        }
    }
    return null;
}

/** Pure arithmetic for controlled-date tests; all inputs and outputs are integer sen. */
function allowance_calculate(int $balance, int $commitments, int $reserved, ?int $days): array
{
    $available = $balance - $commitments - $reserved;
    return [
        'balance_cents' => $balance,
        'commitments_cents' => $commitments,
        'reserved_cents' => $reserved,
        'available_cents' => $available,
        'days' => $days,
        'daily_cents' => $days !== null && $days > 0 ? max(0, intdiv($available, $days)) : null,
    ];
}

function allowance_plan(int $ownerId, ?DateTimeImmutable $clock = null): array
{
    $today = ($clock ?? allowance_today())->setTime(0, 0);
    $statement = db()->prepare('SELECT s.*,i.source income_name,i.amount income_amount,i.frequency income_frequency,i.status income_status,i.income_type FROM allowance_settings s LEFT JOIN incomes i ON i.id=s.income_source_id AND i.user_id=s.user_id WHERE s.user_id=?');
    $statement->execute([$ownerId]);
    $settings = $statement->fetch() ?: null;
    if (!$settings) return ['state' => 'unconfigured', 'today' => $today];

    $sourceMode = $settings['mode'] === 'Source';
    if ($sourceMode && (!$settings['income_source_id'] || $settings['income_status'] !== 'Active' || $settings['income_type'] !== 'Recurring')) {
        return ['state' => 'source_missing', 'today' => $today, 'settings' => $settings];
    }
    $frequency = $sourceMode ? $settings['income_frequency'] : $settings['frequency'];
    $name = $sourceMode ? $settings['income_name'] : 'Manual allowance';
    $amount = $sourceMode ? $settings['income_amount'] : $settings['manual_amount'];
    $next = allowance_next_date($settings['next_allowance_date'], $frequency, $today);
    $state = $next ? 'ready' : 'date_missing';
    $days = $next ? (int)$today->diff($next)->format('%a') : null;

    $balance = allowance_recorded_funds_cents($ownerId, $today, allowance_cents($settings['opening_balance']));
    $commitments = 0;
    if ($next) {
        $pending = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM allowance_commitments WHERE user_id=? AND paid_at IS NULL AND due_date<?');
        $pending->execute([$ownerId, $next->format('Y-m-d')]);
        $commitments = allowance_cents($pending->fetchColumn());
    }
    $result = allowance_calculate($balance, $commitments, allowance_cents($settings['reserved_savings']), $days);
    return array_merge($result, [
        'state' => $state,
        'today' => $today,
        'next_date' => $next,
        'settings' => $settings,
        'source_name' => $name,
        'allowance_amount_cents' => allowance_cents($amount),
        'frequency' => $frequency,
    ]);
}

function allowance_guidance(array $plan): string
{
    if ($plan['state'] === 'unconfigured') return 'Set up your allowance to see a daily estimate.';
    if ($plan['state'] === 'source_missing') return 'Your selected income source is inactive or missing. Choose another source.';
    if ($plan['state'] === 'date_missing') return 'Set a future date for your irregular allowance to see an estimate.';
    if ($plan['days'] === 0) return 'Your allowance is due today. Record it as income when it actually arrives.';
    if ($plan['available_cents'] < 0) return 'Your recorded money does not cover your pending commitments and savings reserve.';
    if ($plan['available_cents'] === 0) return 'No discretionary money remains before your next allowance.';
    if ($plan['daily_cents'] < 1000) return 'Your available spending money is running low.';
    return 'Your suggested spending limit is ' . currency($plan['daily_cents'] / 100) . ' per day for ' . $plan['days'] . ' day' . ($plan['days'] === 1 ? '' : 's') . '.';
}
