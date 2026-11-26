<?php
require_once __DIR__ . '/notifications.php';

function savings_clock(?DateTimeImmutable $clock = null): DateTimeImmutable
{
    return $clock ?? notification_now();
}

function savings_cents($value): int
{
    return (int)round((float)$value * 100);
}

function savings_record_contribution(int $ownerId, int $goalId, int $cents, string $kind, DateTimeImmutable $now): void
{
    if ($cents === 0) return;
    db()->prepare('INSERT INTO savings_goal_contributions (goal_id,user_id,amount,entry_type,contribution_at) VALUES (?,?,?,?,?)')
        ->execute([$goalId, $ownerId, $cents / 100, $kind, $now->format('Y-m-d H:i:s')]);
}

function savings_complete_target(array $goal, DateTimeImmutable $now): bool
{
    if ($goal['goal_type'] !== 'Target' || $goal['status'] !== 'Active' || savings_cents($goal['current_amount']) < savings_cents($goal['target_amount'])) return false;
    db()->prepare("UPDATE savings_goals SET status='Completed',completed_at=? WHERE id=? AND user_id=? AND status='Active'")
        ->execute([$now->format('Y-m-d H:i:s'), $goal['id'], $goal['user_id']]);
    notification_send_for_user((int)$goal['user_id'], 'goal_achievement',
        'goal:' . $goal['id'] . ':' . savings_cents($goal['target_amount']),
        $goal['goal_name'] . ' Goal Achieved',
        'Congratulations! You saved ' . currency($goal['current_amount']) . ' toward your ' . currency($goal['target_amount']) . ' ' . $goal['goal_name'] . ' goal.',
        'goal', (int)$goal['id']);
    return true;
}

function savings_update_monthly_result(array $goal, DateTimeImmutable $now, ?string $closedAt = null): bool
{
    $achieved = savings_cents($goal['monthly_cycle_amount']) >= savings_cents($goal['target_amount']);
    $firstAchievement = $achieved && empty($goal['completed_at']);
    $completedAt = $achieved ? ($goal['completed_at'] ?: $now->format('Y-m-d H:i:s')) : null;
    if ($firstAchievement) {
        db()->prepare('UPDATE savings_goals SET completed_at=? WHERE id=? AND user_id=?')->execute([$completedAt, $goal['id'], $goal['user_id']]);
    }
    if ($achieved || $closedAt !== null) {
        db()->prepare('INSERT INTO savings_goal_monthly_history (goal_id,user_id,cycle_month,goal_name,target_amount,achieved_amount,result,completed_at,closed_at) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE goal_name=VALUES(goal_name),target_amount=VALUES(target_amount),achieved_amount=VALUES(achieved_amount),result=VALUES(result),completed_at=VALUES(completed_at),closed_at=COALESCE(VALUES(closed_at),closed_at)')
            ->execute([$goal['id'], $goal['user_id'], $goal['cycle_month'], $goal['goal_name'], $goal['target_amount'], $goal['monthly_cycle_amount'], $achieved ? 'Achieved' : 'Missed', $completedAt, $closedAt]);
    }
    if ($firstAchievement) {
        notification_send_for_user((int)$goal['user_id'], 'goal_achievement', 'goal-monthly:' . $goal['id'] . ':' . $goal['cycle_month'],
            $goal['goal_name'] . ' Monthly Target Reached',
            'Congratulations! You saved ' . currency($goal['monthly_cycle_amount']) . ' toward your ' . currency($goal['target_amount']) . ' target for ' . $goal['cycle_month'] . '.',
            'goal', (int)$goal['id']);
    }
    return $firstAchievement;
}

function savings_maintain_goal(int $goalId, int $ownerId, ?DateTimeImmutable $clock = null): void
{
    $now = savings_clock($clock);
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT * FROM savings_goals WHERE id=? AND user_id=? FOR UPDATE');
        $statement->execute([$goalId, $ownerId]);
        $goal = $statement->fetch();
        if (!$goal) { if ($ownTransaction) $pdo->commit(); return; }

        if ($goal['goal_type'] === 'Target') {
            if (savings_complete_target($goal, $now)) {
                $goal['status'] = 'Completed';
                $goal['completed_at'] = $now->format('Y-m-d H:i:s');
            }
            if ($goal['status'] === 'Completed' && $goal['completed_at']) {
                $due = (new DateTimeImmutable(substr($goal['completed_at'], 0, 10), $now->getTimezone()))->modify('+30 days');
                if ($now->format('Y-m-d') >= $due->format('Y-m-d')) {
                    $pdo->prepare("UPDATE savings_goals SET status='Archived',archived_at=? WHERE id=? AND user_id=? AND status='Completed'")
                        ->execute([$due->format('Y-m-d'), $goalId, $ownerId]);
                }
            }
        } elseif ($goal['goal_type'] === 'Monthly' && $goal['status'] === 'Active') {
            $currentMonth = $now->format('Y-m');
            $needsWrite = false;
            if (!$goal['cycle_month']) {
                $goal['cycle_month'] = $currentMonth;
                $goal['monthly_cycle_amount'] = $goal['current_amount'];
                $pdo->prepare('UPDATE savings_goals SET cycle_month=?,monthly_cycle_amount=? WHERE id=? AND user_id=?')
                    ->execute([$currentMonth, $goal['current_amount'], $goalId, $ownerId]);
                $needsWrite = true;
            }
            $cursor = new DateTimeImmutable($goal['cycle_month'] . '-01', $now->getTimezone());
            $limit = new DateTimeImmutable($currentMonth . '-01', $now->getTimezone());
            while ($cursor < $limit) {
                $next = $cursor->modify('+1 month');
                savings_update_monthly_result($goal, $now, $next->format('Y-m-d H:i:s'));
                $cursor = $next;
                $goal['cycle_month'] = $cursor->format('Y-m');
                $goal['monthly_cycle_amount'] = '0.00';
                $goal['completed_at'] = null;
                $needsWrite = true;
            }
            if ($goal['cycle_month'] !== $currentMonth) throw new RuntimeException('Monthly goal cycle could not advance.');
            if ($needsWrite) $pdo->prepare('UPDATE savings_goals SET cycle_month=?,monthly_cycle_amount=?,completed_at=? WHERE id=? AND user_id=?')
                ->execute([$goal['cycle_month'], $goal['monthly_cycle_amount'], $goal['completed_at'], $goalId, $ownerId]);
            if (empty($goal['completed_at']) && savings_cents($goal['monthly_cycle_amount']) >= savings_cents($goal['target_amount'])) savings_update_monthly_result($goal, $now);
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $exception) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function savings_maintain_user(int $ownerId, ?DateTimeImmutable $clock = null): void
{
    $statement = db()->prepare("SELECT id FROM savings_goals WHERE user_id=? AND (status='Completed' OR (status='Active' AND (goal_type='Monthly' OR current_amount>=target_amount)))");
    $statement->execute([$ownerId]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $goalId) savings_maintain_goal((int)$goalId, $ownerId, $clock);
}

function savings_deposit(int $ownerId, int $goalId, int $cents, ?DateTimeImmutable $clock = null): bool
{
    $now = savings_clock($clock);
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT * FROM savings_goals WHERE id=? AND user_id=? FOR UPDATE');
        $statement->execute([$goalId, $ownerId]);
        $goal = $statement->fetch();
        if (!$goal || $goal['status'] !== 'Active') throw new DomainException('Only active goals can receive contributions.');
        if ($cents <= 0 || savings_cents($goal['current_amount']) + $cents > 999999999999) throw new DomainException('The savings amount is invalid or too large.');
        $cycle = $goal['goal_type'] === 'Monthly' ? savings_cents($goal['monthly_cycle_amount']) + $cents : 0;
        $total = savings_cents($goal['current_amount']) + $cents;
        $pdo->prepare('UPDATE savings_goals SET current_amount=?,monthly_cycle_amount=? WHERE id=? AND user_id=?')
            ->execute([$total / 100, $cycle / 100, $goalId, $ownerId]);
        savings_record_contribution($ownerId, $goalId, $cents, 'Deposit', $now);
        $goal['current_amount'] = $total / 100;
        $goal['monthly_cycle_amount'] = $cycle / 100;
        $completed = $goal['goal_type'] === 'Target' ? savings_complete_target($goal, $now) : savings_update_monthly_result($goal, $now);
        if ($ownTransaction) $pdo->commit();
        return $completed;
    } catch (Throwable $exception) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
