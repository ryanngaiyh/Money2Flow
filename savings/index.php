<?php
require_once __DIR__ . '/../includes/savings_lifecycle.php';
require_login();
savings_maintain_user(user_id());
$page_title = 'Savings Goals';
$errors = [];
$editing = null;
$form = null;
$maximumCents = 999999999999;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $goalId = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $goal = ownership('savings_goals', $goalId);
        $history = db()->prepare('SELECT COUNT(*) FROM savings_goal_monthly_history WHERE goal_id=? AND user_id=?');
        $history->execute([$goalId, user_id()]);
        $contributions = db()->prepare('SELECT COUNT(*) FROM savings_goal_contributions WHERE goal_id=? AND user_id=?');
        $contributions->execute([$goalId, user_id()]);
        if (!$goal) flash('Savings goal not found.', 'danger');
        elseif ($goal['status'] !== 'Active' || savings_cents($goal['current_amount']) > 0 || (int)$history->fetchColumn() > 0 || (int)$contributions->fetchColumn() > 0) flash('Goals with savings or achievements are kept to preserve your history.', 'warning');
        else {
            db()->prepare('DELETE FROM savings_goals WHERE id=? AND user_id=?')->execute([$goalId, user_id()]);
            flash('Empty savings goal deleted.');
        }
        redirect('savings/index.php');
    }
    if ($action === 'deposit') {
        $contribution = amount($_POST['contribution'] ?? '');
        if ($contribution === null || savings_cents($contribution) <= 0) flash('Enter a savings amount greater than RM 0.00.', 'danger');
        else {
            try {
                $completed = savings_deposit(user_id(), $goalId, savings_cents($contribution));
                flash($completed ? 'Congratulations! You achieved your savings target.' : currency($contribution) . ' added to your savings goal.');
            } catch (DomainException $exception) { flash($exception->getMessage(), 'danger'); }
        }
        redirect('savings/index.php');
    }
    if (!in_array($action, ['create', 'update'], true)) {
        flash('Invalid savings goal action.', 'danger');
        redirect('savings/index.php');
    }

    $previous = $action === 'update' ? ownership('savings_goals', $goalId) : null;
    if ($action === 'update' && (!$previous || $previous['status'] !== 'Active')) {
        flash('Only active goals can be edited.', 'danger');
        redirect('savings/index.php');
    }
    $form = [
        'goal_name' => trim((string)($_POST['goal_name'] ?? '')),
        'goal_type' => (string)($_POST['goal_type'] ?? ''),
        'target_amount' => trim((string)($_POST['target_amount'] ?? '')),
        'current_amount' => trim((string)($_POST['current_amount'] ?? '0')),
        'target_date' => trim((string)($_POST['target_date'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
    ];
    $target = amount($form['target_amount']);
    $currentValid = is_numeric($form['current_amount']) && (float)$form['current_amount'] >= 0 && savings_cents($form['current_amount']) <= $maximumCents;
    $currentCents = $currentValid ? savings_cents($form['current_amount']) : 0;
    if ($form['goal_name'] === '' || mb_strlen($form['goal_name']) > 150) $errors[] = 'Goal name must be 1 to 150 characters.';
    if (!in_array($form['goal_type'], ['Monthly', 'Target'], true)) $errors[] = 'Select a valid goal type.';
    if ($target === null || savings_cents($target) <= 0 || savings_cents($target) > $maximumCents) $errors[] = 'Enter a valid target amount.';
    if (!$currentValid) $errors[] = 'Enter a valid non-negative saved amount.';
    if ($form['goal_type'] === 'Target' && !valid_date($form['target_date'])) $errors[] = 'Select a valid target deadline.';
    if ($form['goal_type'] === 'Monthly') $form['target_date'] = '';
    if (mb_strlen($form['description']) > 500) $errors[] = 'Description must be 500 characters or fewer.';
    if ($previous && $form['goal_type'] !== $previous['goal_type']) $errors[] = 'Goal type cannot change after creation.';

    $cycleCents = $form['goal_type'] === 'Monthly' ? $currentCents : 0;
    if ($previous && $form['goal_type'] === 'Monthly') {
        $priorCycle = savings_cents($previous['monthly_cycle_amount']);
        $priorTotal = savings_cents($previous['current_amount']);
        $cycleCents = $priorCycle + $currentCents - $priorTotal;
        if ($cycleCents < 0) $errors[] = 'Saved amount cannot be lower than savings recorded in earlier monthly cycles.';
        if ($previous['completed_at'] && ($cycleCents < savings_cents($target) || savings_cents($target) !== savings_cents($previous['target_amount']))) $errors[] = 'An achieved monthly target cannot be changed during its completed cycle.';
    }

    if (!$errors) {
        $now = savings_clock();
        try {
            db()->beginTransaction();
            if ($previous) {
                $lockedStatement = db()->prepare('SELECT status,current_amount,monthly_cycle_amount,target_amount FROM savings_goals WHERE id=? AND user_id=? FOR UPDATE');
                $lockedStatement->execute([$goalId, user_id()]);
                $lockedGoal = $lockedStatement->fetch();
                if (!$lockedGoal || $lockedGoal['status'] !== 'Active' || savings_cents($lockedGoal['current_amount']) !== savings_cents($previous['current_amount']) || savings_cents($lockedGoal['monthly_cycle_amount']) !== savings_cents($previous['monthly_cycle_amount']) || savings_cents($lockedGoal['target_amount']) !== savings_cents($previous['target_amount'])) throw new RuntimeException('Goal changed while editing.');
                $delta = $currentCents - savings_cents($previous['current_amount']);
                db()->prepare('UPDATE savings_goals SET goal_name=?,target_amount=?,current_amount=?,monthly_cycle_amount=?,target_date=?,description=? WHERE id=? AND user_id=? AND status=\'Active\'')
                    ->execute([$form['goal_name'], $target, $currentCents / 100, $cycleCents / 100, $form['target_date'] ?: null, $form['description'] ?: null, $goalId, user_id()]);
                savings_record_contribution(user_id(), $goalId, $delta, 'Adjustment', $now);
                $goal = array_merge($previous, ['goal_name' => $form['goal_name'], 'target_amount' => $target, 'current_amount' => $currentCents / 100, 'monthly_cycle_amount' => $cycleCents / 100]);
            } else {
                db()->prepare('INSERT INTO savings_goals (user_id,goal_name,goal_type,target_amount,current_amount,target_date,description,status,cycle_month,monthly_cycle_amount) VALUES (?,?,?,?,?,?,?,\'Active\',?,?)')
                    ->execute([user_id(), $form['goal_name'], $form['goal_type'], $target, $currentCents / 100, $form['target_date'] ?: null, $form['description'] ?: null, $form['goal_type'] === 'Monthly' ? $now->format('Y-m') : null, $cycleCents / 100]);
                $goalId = (int)db()->lastInsertId();
                savings_record_contribution(user_id(), $goalId, $currentCents, 'Opening', $now);
                $goal = ['id' => $goalId, 'user_id' => user_id(), 'goal_name' => $form['goal_name'], 'goal_type' => $form['goal_type'], 'target_amount' => $target, 'current_amount' => $currentCents / 100, 'monthly_cycle_amount' => $cycleCents / 100, 'cycle_month' => $now->format('Y-m'), 'completed_at' => null, 'status' => 'Active'];
            }
            $completed = $goal['goal_type'] === 'Target' ? savings_complete_target($goal, $now) : savings_update_monthly_result($goal, $now);
            db()->commit();
            flash($completed ? 'Congratulations! You achieved your savings target.' : ($previous ? 'Savings goal updated.' : 'Savings goal created.'));
            redirect('savings/index.php');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'Unable to save the goal. Please try again.';
        }
    }
    $editing = $previous ?: null;
}

if (isset($_GET['edit']) && !$form) {
    $editing = ownership('savings_goals', (int)$_GET['edit']);
    if (!$editing || $editing['status'] !== 'Active') {
        flash('Only active goals can be edited.', 'warning');
        redirect('savings/index.php');
    }
    $form = $editing;
}

$typeFilter = in_array($_GET['type'] ?? 'All', ['All', 'Monthly', 'Target'], true) ? ($_GET['type'] ?? 'All') : 'All';
$statement = db()->prepare('SELECT * FROM savings_goals WHERE user_id=?' . ($typeFilter === 'All' ? '' : ' AND goal_type=?') . ' ORDER BY status,goal_type,target_date IS NULL,target_date,id DESC');
$statement->execute($typeFilter === 'All' ? [user_id()] : [user_id(), $typeFilter]);
$allGoals = $statement->fetchAll();
$activeGoals = array_values(array_filter($allGoals, fn($goal) => $goal['status'] === 'Active'));
$completedGoals = array_values(array_filter($allGoals, fn($goal) => $goal['status'] === 'Completed'));
$archivedGoals = array_values(array_filter($allGoals, fn($goal) => $goal['status'] === 'Archived'));
$savedStatement = db()->prepare('SELECT COALESCE(SUM(current_amount),0) FROM savings_goals WHERE user_id=?');
$savedStatement->execute([user_id()]);
$savedTotal = (float)$savedStatement->fetchColumn();

$currentMonth = savings_clock()->format('Y-m');
$statement = db()->prepare("SELECT * FROM savings_goal_monthly_history WHERE user_id=? AND cycle_month=? AND result='Achieved' AND closed_at IS NULL ORDER BY completed_at DESC");
$statement->execute([user_id(), $currentMonth]);
$monthlyAchievements = $typeFilter === 'Target' ? [] : $statement->fetchAll();
$statement = db()->prepare('SELECT * FROM savings_goal_monthly_history WHERE user_id=? AND closed_at IS NOT NULL ORDER BY cycle_month DESC,history_id DESC');
$statement->execute([user_id()]);
$monthlyHistory = $typeFilter === 'Target' ? [] : $statement->fetchAll();
$historyRows = [];
foreach ($archivedGoals as $goal) $historyRows[] = ['kind' => 'Target', 'name' => $goal['goal_name'], 'target' => $goal['target_amount'], 'saved' => $goal['current_amount'], 'completed' => $goal['completed_at'], 'deadline' => $goal['target_date'], 'on_time' => $goal['target_date'] && substr($goal['completed_at'], 0, 10) <= $goal['target_date'], 'archived' => $goal['archived_at'], 'result' => 'Achieved'];
foreach ($monthlyHistory as $row) $historyRows[] = ['kind' => 'Monthly', 'name' => $row['goal_name'], 'target' => $row['target_amount'], 'saved' => $row['achieved_amount'], 'completed' => $row['completed_at'], 'deadline' => $row['cycle_month'] . ' (monthly)', 'on_time' => $row['result'] === 'Achieved', 'archived' => substr($row['closed_at'], 0, 10), 'result' => $row['result']];
$sort = ($_GET['sort'] ?? '') === 'oldest' ? 'oldest' : 'recent';
usort($historyRows, fn($a, $b) => $sort === 'oldest' ? strcmp($a['archived'], $b['archived']) : strcmp($b['archived'], $a['archived']));

$statement = db()->prepare('SELECT c.*,g.goal_name FROM savings_goal_contributions c JOIN savings_goals g ON g.id=c.goal_id AND g.user_id=c.user_id WHERE c.user_id=? ORDER BY c.contribution_at DESC,c.contribution_id DESC LIMIT 10');
$statement->execute([user_id()]);
$recentContributions = $statement->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="page-title mb-1">Savings Goals</h1><p class="text-secondary mb-0">Track active goals, celebrate achievements, and keep your history.</p></div><button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#savingsForm">+ Add Savings Goal</button></div>
<div class="row g-3 mb-4"><div class="col-md-4"><div class="card p-3 h-100"><div class="metric-label">Lifetime saved in goals</div><div class="metric text-primary"><?= currency($savedTotal) ?></div></div></div><div class="col-md-4"><div class="card p-3 h-100"><div class="metric-label">Active goals</div><div class="metric"><?= count($activeGoals) ?></div></div></div><div class="col-md-4"><div class="card p-3 h-100"><div class="metric-label">Completed targets</div><div class="metric text-success"><?= count($completedGoals) + count($archivedGoals) ?></div></div></div></div>
<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
<div class="collapse <?= $form ? 'show' : '' ?> mb-4" id="savingsForm"><div class="card p-4"><h2 class="h5 mb-3"><?= $editing ? 'Edit active goal' : 'Create savings goal' ?></h2><form method="post" class="row g-3" novalidate><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>"><div class="col-md-4"><label class="form-label" for="goal_name">Goal name</label><input class="form-control" id="goal_name" name="goal_name" maxlength="150" required value="<?= e($form['goal_name'] ?? '') ?>"></div><div class="col-md-2"><label class="form-label" for="goal_type">Goal type</label><select class="form-select" id="goal_type" name="goal_type" <?= $editing ? 'disabled' : '' ?>><option value="Target" <?= ($form['goal_type'] ?? 'Target') === 'Target' ? 'selected' : '' ?>>Target</option><option value="Monthly" <?= ($form['goal_type'] ?? '') === 'Monthly' ? 'selected' : '' ?>>Monthly</option></select><?php if ($editing): ?><input type="hidden" name="goal_type" value="<?= e($editing['goal_type']) ?>"><?php endif; ?></div><div class="col-md-2"><label class="form-label" for="target_amount">Target (RM)</label><input class="form-control" id="target_amount" name="target_amount" type="number" min="0.01" max="9999999999.99" step="0.01" required value="<?= e($form['target_amount'] ?? '') ?>"></div><div class="col-md-2"><label class="form-label" for="current_amount">Lifetime saved (RM)</label><input class="form-control" id="current_amount" name="current_amount" type="number" min="0" max="9999999999.99" step="0.01" required value="<?= e($form['current_amount'] ?? '0.00') ?>"></div><div class="col-md-2" id="targetDateField"><label class="form-label" for="target_date">Deadline</label><input class="form-control" id="target_date" name="target_date" type="date" value="<?= e($form['target_date'] ?? '') ?>"></div><div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control" id="description" name="description" maxlength="500" rows="2"><?= e($form['description'] ?? '') ?></textarea></div><div class="col-12"><button class="btn btn-primary">Save Goal</button><?php if ($editing): ?> <a class="btn btn-light" href="<?= e(app_url('savings/index.php')) ?>">Cancel</a><?php endif; ?></div></form></div></div>
<div class="period-switch mb-4" aria-label="Savings goal filter"><?php foreach (['All','Monthly','Target'] as $filter): ?><a class="<?= $typeFilter === $filter ? 'active' : '' ?>" href="?type=<?= e($filter) ?>"><?= e($filter === 'All' ? 'All goals' : $filter) ?></a><?php endforeach; ?></div>
<h2 class="h4 mb-3">Active Goals</h2><div class="row g-3 mb-5">
<?php foreach ($activeGoals as $goal): $current = (float)($goal['goal_type'] === 'Monthly' ? $goal['monthly_cycle_amount'] : $goal['current_amount']); $targetValue = (float)$goal['target_amount']; $percent = min(100, $targetValue > 0 ? $current / $targetValue * 100 : 0); $reached = $goal['goal_type'] === 'Monthly' && $current >= $targetValue; ?>
<div class="col-xl-6"><article class="card p-4 h-100 savings-card savings-<?= $reached ? 'success' : 'primary' ?>"><div class="d-flex justify-content-between gap-2"><div><span class="badge text-bg-light border mb-2"><?= e($goal['goal_type']) ?> Goal</span><h3 class="h5 mb-1"><?= e($goal['goal_name']) ?></h3><p class="small text-secondary mb-0"><?= e($goal['description'] ?: 'No description added.') ?></p></div><span class="badge text-bg-<?= $reached ? 'success' : 'primary' ?> align-self-start"><?= $reached ? 'This month achieved' : 'Active' ?></span></div><div class="savings-figures my-4"><div><span><?= $goal['goal_type'] === 'Monthly' ? 'Saved this month' : 'Saved' ?></span><strong><?= currency($current) ?></strong></div><div><span>Remaining</span><strong><?= currency(max(0, $targetValue - $current)) ?></strong></div><div><span>Lifetime saved</span><strong><?= currency($goal['current_amount']) ?></strong></div></div><div class="d-flex justify-content-between small mb-2"><span><?= number_format($targetValue > 0 ? $current / $targetValue * 100 : 0, 1) ?>% complete</span><strong>Target <?= currency($targetValue) ?></strong></div><div class="progress"><div class="progress-bar bg-success" style="width:<?= $percent ?>%"></div></div><div class="small text-secondary mt-2"><?= $goal['goal_type'] === 'Target' ? 'Deadline: ' . e($goal['target_date'] ?: 'none') : 'Progress resets each month; lifetime savings stay recorded.' ?></div><div class="savings-actions mt-4 pt-3 border-top"><form method="post" class="d-flex gap-2 flex-grow-1"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="deposit"><input type="hidden" name="id" value="<?= (int)$goal['id'] ?>"><div class="input-group input-group-sm"><span class="input-group-text">RM</span><input class="form-control" name="contribution" type="number" min="0.01" max="9999999999.99" step="0.01" required aria-label="Amount to add" placeholder="Amount"><button class="btn btn-primary">Add Savings</button></div></form><div class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$goal['id'] ?>#savingsForm">Edit</a><?php if (savings_cents($goal['current_amount']) === 0): ?> <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$goal['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete this empty goal?">Delete</button></form><?php endif; ?></div></div></article></div>
<?php endforeach; ?><?php if (!$activeGoals): ?><div class="col-12"><div class="card empty-state"><strong>No active goals</strong><span>Add a new goal to start saving.</span></div></div><?php endif; ?></div>
<h2 class="h4 mb-3">Completed Goals</h2><div class="row g-3 mb-5">
<?php foreach ($completedGoals as $goal): $due = (new DateTimeImmutable(substr($goal['completed_at'],0,10), savings_clock()->getTimezone()))->modify('+30 days'); $days = max(0, (int)savings_clock()->setTime(0,0)->diff($due)->format('%r%a')); ?><div class="col-lg-6"><div class="card p-4 h-100 savings-card savings-success"><span class="badge text-bg-success align-self-start mb-2">Completed</span><h3 class="h5"><?= e($goal['goal_name']) ?></h3><p class="mb-1">Congratulations! You achieved your <?= e($goal['goal_name']) ?> savings goal.</p><div class="small text-secondary">Saved <?= currency($goal['current_amount']) ?> of <?= currency($goal['target_amount']) ?> · 100% progress<br>Completed <?= e(substr($goal['completed_at'],0,10)) ?> · <?= $days ?> day<?= $days === 1 ? '' : 's' ?> until Goal History (<?= e($due->format('Y-m-d')) ?>)</div></div></div><?php endforeach; ?>
<?php foreach ($monthlyAchievements as $row): ?><div class="col-lg-6"><div class="card p-4 h-100 savings-card savings-success"><span class="badge text-bg-success align-self-start mb-2">Monthly target achieved</span><h3 class="h5"><?= e($row['goal_name']) ?> · <?= e($row['cycle_month']) ?></h3><p class="mb-1">Congratulations! You reached this month's goal.</p><div class="small text-secondary"><?= currency($row['achieved_amount']) ?> of <?= currency($row['target_amount']) ?> · achieved <?= e(substr($row['completed_at'],0,10)) ?>. The recurring goal stays active.</div></div></div><?php endforeach; ?>
<?php if (!$completedGoals && !$monthlyAchievements): ?><div class="col-12"><div class="card empty-state"><strong>No recent completions</strong><span>Completed target goals stay here for 30 days.</span></div></div><?php endif; ?></div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3" id="goalHistory"><h2 class="h4 mb-0">Goal History</h2><form class="d-flex gap-2"><input type="hidden" name="type" value="<?= e($typeFilter) ?>"><label class="visually-hidden" for="history_sort">Sort history</label><select id="history_sort" class="form-select form-select-sm" name="sort"><option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Newest first</option><option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option></select><button class="btn btn-sm btn-outline-secondary">Sort</button></form></div>
<div class="card p-3 mb-5 table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Goal</th><th>Type / result</th><th>Target</th><th>Saved</th><th>Completed</th><th>Original deadline</th><th>On time</th><th>Archived</th></tr></thead><tbody><?php foreach ($historyRows as $row): ?><tr><td><strong><?= e($row['name']) ?></strong></td><td><?= e($row['kind']) ?> · <?= e($row['result']) ?></td><td><?= currency($row['target']) ?></td><td><?= currency($row['saved']) ?></td><td><?= e($row['completed'] ? substr($row['completed'],0,10) : '—') ?></td><td><?= e($row['deadline'] ?: '—') ?></td><td><?= $row['on_time'] ? 'Yes' : 'No' ?></td><td><?= e($row['archived']) ?></td></tr><?php endforeach; ?><?php if (!$historyRows): ?><tr><td colspan="8" class="text-center text-secondary py-4">No archived goals or finished monthly cycles yet.</td></tr><?php endif; ?></tbody></table></div>
<h2 class="h4 mb-3">Recent Savings Entries</h2><div class="card p-3 table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Date</th><th>Goal</th><th>Entry</th><th>Amount</th></tr></thead><tbody><?php foreach ($recentContributions as $entry): ?><tr><td><?= e($entry['contribution_at']) ?></td><td><?= e($entry['goal_name']) ?></td><td><?= e($entry['entry_type']) ?></td><td><?= currency($entry['amount']) ?></td></tr><?php endforeach; ?><?php if (!$recentContributions): ?><tr><td colspan="4" class="text-center text-secondary py-4">No savings entries yet.</td></tr><?php endif; ?></tbody></table></div>
<script>const goalType=document.getElementById('goal_type'),targetDateField=document.getElementById('targetDateField'),targetDate=document.getElementById('target_date');function syncGoalType(){const target=goalType?.value==='Target';targetDateField.style.display=target?'':'none';targetDate.required=target;if(!target)targetDate.value='';}goalType?.addEventListener('change',syncGoalType);syncGoalType();</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
