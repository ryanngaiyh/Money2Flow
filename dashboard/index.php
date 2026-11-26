<?php
$page_title = 'Dashboard';
require_once __DIR__ . '/../includes/recurring_income.php';
require_login();
recurring_process_user(user_id());
require_once __DIR__ . '/../includes/header.php';

$requiredTables = ['categories', 'transactions', 'budgets', 'savings_goals'];
$availableTables = db()->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")
    ->fetchAll(PDO::FETCH_COLUMN);
$fullDashboardAvailable = count(array_diff($requiredTables, $availableTables)) === 0;

if (!$fullDashboardAvailable):
?>
<div class="finance-banner mb-4">
    <span class="pill">PHASE 1 READY</span>
    <h1 class="mt-2 mb-1 fw-bold">Welcome, <?= e($_SESSION['user_name'] ?? 'Student') ?>!</h1>
    <p class="mb-0 text-white-50">Your secure Money2Flow account and dashboard foundation are working.</p>
</div>
<div class="card p-4"><h4 class="fw-bold">Dashboard modules are not installed yet</h4><p class="text-secondary mb-0">Complete the transaction, budget, and goal phases to activate financial calculations.</p></div>
<?php require_once __DIR__ . '/../includes/footer.php'; return; endif;

$allowedPeriods = ['week', 'month', '3months'];
$period = $_GET['period'] ?? 'month';
if (!in_array($period, $allowedPeriods, true)) $period = 'month';

$selectedMonth = $_GET['month'] ?? date('Y-m');
if (!is_string($selectedMonth) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth) || !valid_date($selectedMonth . '-01')) $selectedMonth = date('Y-m');
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));

if ($period === 'week') {
    $rangeEnd = date('Y-m-d', strtotime(date('Y-m-d') . ' +1 day'));
    $rangeStart = date('Y-m-d', strtotime($rangeEnd . ' -7 days'));
    $rangeLabel = date('j M', strtotime($rangeStart)) . ' – ' . date('j M Y', strtotime($rangeEnd . ' -1 day'));
    $bucketFormat = '%Y-%m-%d';
    $bucketStep = '+1 day';
} elseif ($period === '3months') {
    $rangeStart = date('Y-m-d', strtotime($monthStart . ' -2 months'));
    $rangeEnd = $monthEnd;
    $rangeLabel = date('M Y', strtotime($rangeStart)) . ' – ' . date('M Y', strtotime($rangeEnd . ' -1 day'));
    $bucketFormat = '%Y-%m';
    $bucketStep = '+1 month';
} else {
    $rangeStart = $monthStart;
    $rangeEnd = $monthEnd;
    $rangeLabel = date('F Y', strtotime($monthStart));
    $bucketFormat = '%Y-%m-%d';
    $bucketStep = '+1 day';
}

$total = function (string $sql, array $arguments): float {
    $statement = db()->prepare($sql);
    $statement->execute($arguments);
    return (float)$statement->fetchColumn();
};

$income = $total("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND type='Income' AND transaction_date>=? AND transaction_date<?", [user_id(), $rangeStart, $rangeEnd]);
$expenses = $total("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND type='Expense' AND transaction_date>=? AND transaction_date<?", [user_id(), $rangeStart, $rangeEnd]);
$totalSavings = $total('SELECT COALESCE(SUM(current_amount),0) FROM savings_goals WHERE user_id=?', [user_id()]);
$youAreOwed = 0;
$youOwe = 0;
$activeSplitBills = 0;
$splitBillsAvailable = in_array('split_bills', $availableTables, true) && in_array('split_bill_participants', $availableTables, true);
if ($splitBillsAvailable) {
    $statement = db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN payer.is_owner=1 AND participant.is_payer=0 AND participant.status='Pending' THEN participant.share_amount ELSE 0 END),0) you_are_owed,
        COALESCE(SUM(CASE WHEN participant.is_owner=1 AND participant.is_payer=0 AND participant.status='Pending' THEN participant.share_amount ELSE 0 END),0) you_owe
        FROM split_bills bill
        JOIN split_bill_participants payer ON payer.split_bill_id=bill.split_bill_id AND payer.is_payer=1
        JOIN split_bill_participants participant ON participant.split_bill_id=bill.split_bill_id
        WHERE bill.user_id=?");
    $statement->execute([user_id()]);
    $splitSummary = $statement->fetch();
    $youAreOwed = (float)$splitSummary['you_are_owed'];
    $youOwe = (float)$splitSummary['you_owe'];
    $activeSplitBills = (int)$total("SELECT COUNT(DISTINCT bill.split_bill_id) FROM split_bills bill JOIN split_bill_participants participant ON participant.split_bill_id=bill.split_bill_id AND participant.is_payer=0 AND participant.status='Pending' WHERE bill.user_id=?", [user_id()]);
}

$monthlyExpenses = $total("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND type='Expense' AND transaction_date>=? AND transaction_date<?", [user_id(), $monthStart, $monthEnd]);
$overallBudgetStatement = db()->prepare('SELECT budget_amount FROM budgets WHERE user_id=? AND budget_month=? AND category_id IS NULL LIMIT 1');
$overallBudgetStatement->execute([user_id(), $selectedMonth]);
$overallBudgetValue = $overallBudgetStatement->fetchColumn();
if ($overallBudgetValue !== false) {
    $monthlyBudget = (float)$overallBudgetValue;
    $budgetSpending = $monthlyExpenses;
} else {
    $monthlyBudget = $total('SELECT COALESCE(SUM(budget_amount),0) FROM budgets WHERE user_id=? AND budget_month=? AND category_id IS NOT NULL', [user_id(), $selectedMonth]);
    $budgetSpending = $total("SELECT COALESCE(SUM(t.amount),0) FROM transactions t JOIN budgets b ON b.user_id=t.user_id AND b.category_id=t.category_id AND b.budget_month=? WHERE t.user_id=? AND t.type='Expense' AND t.transaction_date>=? AND t.transaction_date<?", [$selectedMonth, user_id(), $monthStart, $monthEnd]);
}
$remainingBudget = $monthlyBudget - $budgetSpending;

$statement = db()->prepare("SELECT DATE_FORMAT(transaction_date, ?) bucket, SUM(CASE WHEN type='Income' THEN amount ELSE 0 END) income, SUM(CASE WHEN type='Expense' THEN amount ELSE 0 END) expenses FROM transactions WHERE user_id=? AND transaction_date>=? AND transaction_date<? GROUP BY bucket ORDER BY bucket");
$statement->execute([$bucketFormat, user_id(), $rangeStart, $rangeEnd]);
$trendRows = [];
foreach ($statement->fetchAll() as $row) $trendRows[$row['bucket']] = $row;

$trendLabels = [];
$trendIncome = [];
$trendExpenses = [];
for ($cursor = $rangeStart; $cursor < $rangeEnd; $cursor = date('Y-m-d', strtotime($cursor . ' ' . $bucketStep))) {
    $key = $period === '3months' ? date('Y-m', strtotime($cursor)) : $cursor;
    $trendLabels[] = $period === '3months' ? date('M Y', strtotime($cursor)) : date('j M', strtotime($cursor));
    $trendIncome[] = isset($trendRows[$key]) ? (float)$trendRows[$key]['income'] : 0;
    $trendExpenses[] = isset($trendRows[$key]) ? (float)$trendRows[$key]['expenses'] : 0;
}

$statement = db()->prepare("SELECT c.name, SUM(t.amount) value FROM transactions t JOIN categories c ON c.id=t.category_id AND c.user_id=t.user_id WHERE t.user_id=? AND t.type='Expense' AND t.transaction_date>=? AND t.transaction_date<? GROUP BY c.id, c.name ORDER BY value DESC");
$statement->execute([user_id(), $rangeStart, $rangeEnd]);
$categorySpending = $statement->fetchAll();

$statement = db()->prepare("SELECT transaction_date, COALESCE(NULLIF(merchant,''), NULLIF(description,''), type) label, description, amount, type FROM transactions WHERE user_id=? AND transaction_date>=? AND transaction_date<? ORDER BY transaction_date DESC, id DESC LIMIT 5");
$statement->execute([user_id(), $rangeStart, $rangeEnd]);
$recentTransactions = $statement->fetchAll();

$statement = db()->prepare("SELECT b.*, COALESCE(c.name,'Overall monthly budget') name, COALESCE(SUM(t.amount),0) spent FROM budgets b LEFT JOIN categories c ON c.id=b.category_id LEFT JOIN transactions t ON t.user_id=b.user_id AND t.type='Expense' AND t.transaction_date>=? AND t.transaction_date<? AND (b.category_id IS NULL OR t.category_id=b.category_id) WHERE b.user_id=? AND b.budget_month=? GROUP BY b.id ORDER BY b.category_id IS NOT NULL, c.name");
$statement->execute([$monthStart, $monthEnd, user_id(), $selectedMonth]);
$budgets = $statement->fetchAll();

$statement = db()->prepare("SELECT * FROM savings_goals WHERE user_id=? AND status='Active' ORDER BY target_date IS NULL, target_date LIMIT 3");
$statement->execute([user_id()]);
$goals = $statement->fetchAll();
require_once __DIR__ . '/../includes/allowance.php';
$allowancePlan = allowance_plan(user_id());
$openingCents = allowance_cents($allowancePlan['settings']['opening_balance'] ?? 0);
$recordedBalanceCents = $allowancePlan['balance_cents'] ?? allowance_recorded_funds_cents(user_id(), allowance_today(), $openingCents);
$dashboardAllowanceGuidance = allowance_guidance($allowancePlan);
if ($allowancePlan['state'] === 'ready' && $allowancePlan['days'] > 0 && $allowancePlan['available_cents'] > 0 && $allowancePlan['daily_cents'] >= 1000) {
    $dashboardAllowanceGuidance = 'Use the daily limit below to plan until your next allowance.';
}

$statement = db()->prepare("SELECT goal_name,current_amount,completed_at,status FROM savings_goals WHERE user_id=? AND goal_type='Target' AND status IN ('Completed','Archived') AND completed_at IS NOT NULL ORDER BY completed_at DESC,id DESC LIMIT 3");
$statement->execute([user_id()]);
$achievements = [];
foreach ($statement->fetchAll() as $goal) $achievements[] = ['name' => $goal['goal_name'], 'amount' => $goal['current_amount'], 'completed_at' => $goal['completed_at'], 'status' => $goal['status']];
$statement = db()->prepare("SELECT goal_name,achieved_amount,completed_at,cycle_month FROM savings_goal_monthly_history WHERE user_id=? AND result='Achieved' AND completed_at IS NOT NULL ORDER BY completed_at DESC,history_id DESC LIMIT 3");
$statement->execute([user_id()]);
foreach ($statement->fetchAll() as $cycle) $achievements[] = ['name' => $cycle['goal_name'] . ' · ' . $cycle['cycle_month'], 'amount' => $cycle['achieved_amount'], 'completed_at' => $cycle['completed_at'], 'status' => 'Monthly achieved'];
usort($achievements, static fn($a, $b) => strcmp($b['completed_at'], $a['completed_at']));
$achievements = array_slice($achievements, 0, 3);
$dashboardAlerts = array_slice($recentNotifications, 0, 3);
$showNotificationHistory = ($_GET['notifications'] ?? '') === 'all';
$notificationHistory = [];
$notificationTotal = 0;
$notificationPage = 1;
$notificationPageCount = 1;
if ($showNotificationHistory) {
    $countStatement = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=?');
    $countStatement->execute([user_id()]);
    $notificationTotal = (int)$countStatement->fetchColumn();
    $notificationPageCount = max(1, (int)ceil($notificationTotal / 20));
    $notificationPage = min($notificationPageCount, max(1, (int)($_GET['notification_page'] ?? 1)));
    $historyStatement = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC,notification_id DESC LIMIT 20 OFFSET ?');
    $historyStatement->bindValue(1, user_id(), PDO::PARAM_INT);
    $historyStatement->bindValue(2, ($notificationPage - 1) * 20, PDO::PARAM_INT);
    $historyStatement->execute();
    $notificationHistory = $historyStatement->fetchAll();
}
?>
<div class="dashboard-hero mb-4">
    <div>
        <span class="dashboard-eyebrow">YOUR DASHBOARD</span>
        <h1 class="page-title mb-1">Hello, <?= e(explode(' ', trim($_SESSION['user_name'] ?? 'Student'))[0]) ?></h1>
        <p class="text-secondary mb-0">Your finances for <?= e($rangeLabel) ?>, calculated from real transactions.</p>
    </div>
    <form class="dashboard-controls" method="get">
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <label class="visually-hidden" for="month">Dashboard month</label>
        <input class="form-control" id="month" type="month" name="month" value="<?= e($selectedMonth) ?>" onchange="this.form.submit()">
    </form>
</div>

<div class="period-switch mb-4" role="group" aria-label="Spending period">
    <a class="<?= $period==='week'?'active':'' ?>" href="?period=week&month=<?= e($selectedMonth) ?>">This Week</a>
    <a class="<?= $period==='month'?'active':'' ?>" href="?period=month&month=<?= e($selectedMonth) ?>">This Month</a>
    <a class="<?= $period==='3months'?'active':'' ?>" href="?period=3months&month=<?= e($selectedMonth) ?>">3 Months</a>
</div>

<section class="dashboard-section mb-4" aria-labelledby="snapshotTitle">
    <h2 class="dashboard-section-title" id="snapshotTitle">At a glance</h2>
    <div class="row g-3">
        <div class="col-sm-6 col-xl-3"><div class="card p-3 h-100"><div class="metric-label">Current balance · through today</div><div class="metric <?= $recordedBalanceCents<0?'text-danger':'accent' ?>"><?= currency($recordedBalanceCents/100) ?></div><small class="text-secondary">Selected period net: <?= currency($income-$expenses) ?></small></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card p-3 h-100"><div class="metric-label">Total income · <?= e($rangeLabel) ?></div><div class="metric text-success"><?= currency($income) ?></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card p-3 h-100"><div class="metric-label">Total expenses · <?= e($rangeLabel) ?></div><div class="metric text-danger"><?= currency($expenses) ?></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card p-3 h-100"><div class="metric-label">Remaining budget · <?= e(date('M Y', strtotime($monthStart))) ?></div><div class="metric <?= $remainingBudget<0?'text-danger':'text-primary' ?>"><?= currency($remainingBudget) ?></div><small class="text-secondary"><?= $monthlyBudget>0 ? currency($budgetSpending).' spent of '.currency($monthlyBudget) : 'No category budgets set' ?></small></div></div>
    </div>
</section>

<section class="dashboard-section mb-4" aria-labelledby="planningTitle">
    <h2 class="dashboard-section-title" id="planningTitle">Plan your spending</h2>
    <div class="card allowance-dashboard-card p-4"><div class="d-flex flex-wrap justify-content-between align-items-start gap-3"><div><span class="dashboard-eyebrow">UNTIL NEXT ALLOWANCE</span><h3 class="h5 mb-1"><?= $allowancePlan['state']==='ready' ? e($allowancePlan['next_date']->format('j M Y')) : 'Plan your next allowance' ?></h3><p class="text-secondary mb-0"><?= e($dashboardAllowanceGuidance) ?></p></div><div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary btn-sm" href="<?= e(app_url('allowance/index.php')) ?>"><?= $allowancePlan['state']==='unconfigured'?'Set up planner':'Manage planner' ?></a><a class="btn btn-primary btn-sm" href="<?= e(app_url('simulator/index.php')) ?>">Can I Afford It?</a></div></div>
    <?php if ($allowancePlan['state']==='ready'): $allowanceProgress=$allowancePlan['balance_cents']>0?max(0,min(100,(int)round($allowancePlan['available_cents']/$allowancePlan['balance_cents']*100))):0; ?><div class="allowance-dashboard-grid mt-3"><div><small>Days remaining</small><strong><?= (int)$allowancePlan['days'] ?></strong></div><div><small>Available to spend</small><strong class="<?= $allowancePlan['available_cents']<0?'text-danger':'' ?>"><?= currency($allowancePlan['available_cents']/100) ?></strong></div><div><small>Suggested daily spending limit</small><strong><?= $allowancePlan['daily_cents']===null?'—':currency($allowancePlan['daily_cents']/100) ?></strong></div></div><div class="progress mt-3" role="progressbar" aria-label="Discretionary share of recorded funds" aria-valuenow="<?= $allowanceProgress ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width:<?= $allowanceProgress ?>%"></div></div><small class="text-secondary d-block mt-2">Estimate from all recorded transactions through today, not the dashboard month filter.</small><?php endif; ?></div>
</section>

<section class="dashboard-section mb-4" aria-labelledby="overviewTitle">
    <h2 class="dashboard-section-title" id="overviewTitle">Financial overview</h2>
<div class="row g-3">
    <div class="col-xl-7"><div class="card p-4 h-100"><div class="d-flex justify-content-between align-items-center mb-2"><h3 class="h5 mb-0">Spending overview</h3><small class="text-secondary"><?= e($rangeLabel) ?></small></div><div class="chart-box"><canvas id="trend"></canvas></div></div></div>
    <div class="col-xl-5"><div class="card p-4 h-100"><h3 class="h5">Spending by category</h3><div class="chart-box"><canvas id="pie"></canvas></div></div></div>

    <div class="col-12"><div class="card p-4 h-100"><div class="d-flex justify-content-between align-items-center mb-2"><h3 class="h5 mb-0">Recent transactions</h3><a href="<?= e(app_url('transactions/index.php')) ?>">View all transactions</a></div>
        <?php if ($recentTransactions): ?><div class="list-group list-group-flush"><?php foreach ($recentTransactions as $transaction): ?><div class="list-group-item px-0 d-flex justify-content-between align-items-center"><div><strong><?= e($transaction['label']) ?></strong><div class="small text-secondary"><?= e(date('j M Y', strtotime($transaction['transaction_date']))) ?><?= $transaction['description'] && $transaction['description'] !== $transaction['label'] ? ' · '.e($transaction['description']) : '' ?></div></div><strong class="<?= $transaction['type']==='Income'?'text-success':'text-danger' ?>"><?= $transaction['type']==='Income'?'+':'-' ?><?= currency($transaction['amount']) ?></strong></div><?php endforeach; ?></div>
        <?php else: ?><div class="empty-state"><strong>No transactions in this period</strong><span>Add a transaction or choose a wider date range.</span><a class="btn btn-sm btn-primary mt-2" href="<?= e(app_url('transactions/index.php#transactionForm')) ?>">Add transaction</a></div><?php endif; ?>
    </div></div>
</div>
</section>

<section class="dashboard-section mb-4" aria-labelledby="progressTitle">
    <h2 class="dashboard-section-title" id="progressTitle">Your progress</h2>
<div class="row g-3">
    <div class="col-xl-5"><div class="card p-4 h-100"><div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0">Budget progress</h3><a href="<?= e(app_url('budgets/index.php?month='.$selectedMonth)) ?>">Manage</a></div>
        <?php foreach ($budgets as $budget): $rawPercent=(float)$budget['budget_amount']>0?(float)$budget['spent']/(float)$budget['budget_amount']*100:0; $barPercent=min(100,$rawPercent); $remaining=(float)$budget['budget_amount']-(float)$budget['spent']; ?><div class="mb-3"><div class="d-flex justify-content-between small"><strong><?= e($budget['name']) ?></strong><span><?= number_format($rawPercent,0) ?>%</span></div><div class="progress my-2"><div class="progress-bar <?= $rawPercent>=100?'bg-danger':($rawPercent>=80?'bg-warning':'bg-success') ?>" style="width:<?= $barPercent ?>%"></div></div><div class="d-flex justify-content-between small text-secondary"><span><?= currency($budget['spent']) ?> / <?= currency($budget['budget_amount']) ?></span><span class="<?= $remaining<0?'text-danger':'' ?>"><?= $remaining<0?'Exceeded by '.currency(abs($remaining)):currency($remaining).' left' ?></span></div></div><?php endforeach; ?>
        <?php if (!$budgets): ?><div class="empty-state"><strong>No budgets for <?= e(date('F Y', strtotime($monthStart))) ?></strong><span>Create category budgets to monitor spending.</span><a class="btn btn-sm btn-outline-primary mt-2" href="<?= e(app_url('budgets/index.php')) ?>">Create budget</a></div><?php endif; ?>
    </div></div>

    <div class="col-xl-7"><div class="card p-4 h-100"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h3 class="h5 mb-0">Active savings goals</h3><small class="text-secondary">Saved across all goals: <?= currency($totalSavings) ?></small></div><div class="d-flex gap-3"><a href="<?= e(app_url('savings/index.php')) ?>">Manage goals</a><a href="<?= e(app_url('savings/index.php#goalHistory')) ?>">Goal History</a></div></div><div class="row g-3">
        <?php foreach ($goals as $goal): $goalProgressAmount=(float)($goal['goal_type']==='Monthly'?$goal['monthly_cycle_amount']:$goal['current_amount']);$goalPercent=(float)$goal['target_amount']>0?$goalProgressAmount/(float)$goal['target_amount']*100:0;$goalBarPercent=min(100,max(0,$goalPercent)); ?><div class="col-md-6 col-xxl-4"><div class="goal-summary h-100"><div class="d-flex justify-content-between gap-2"><strong><?= e($goal['goal_name']) ?></strong><span><?= number_format($goalPercent,0) ?>%</span></div><div class="progress my-2"><div class="progress-bar bg-success" style="width:<?= $goalBarPercent ?>%"></div></div><small class="text-secondary"><?= currency($goalProgressAmount) ?> of <?= currency($goal['target_amount']) ?><?= $goal['goal_type']==='Monthly'?' this month':'' ?></small></div></div><?php endforeach; ?>
        <?php if (!$goals): ?><div class="col-12"><div class="empty-state"><strong>No active savings goals yet</strong><span>Create a goal to track your progress.</span></div></div><?php endif; ?>
    </div></div></div>
</div>

<?php if ($splitBillsAvailable): ?><div class="card p-3 mt-3"><div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2"><h3 class="h6 mb-0">Shared expenses</h3><a href="<?= e(app_url('split-bills/index.php')) ?>">Manage split bills</a></div><div class="shared-expense-summary"><div><small>You are owed</small><strong class="text-success"><?= currency($youAreOwed) ?></strong></div><div><small>You owe</small><strong class="text-danger"><?= currency($youOwe) ?></strong></div><div><small>Active split bills</small><strong><?= $activeSplitBills ?></strong></div></div></div><?php endif; ?>
</section>

<section class="dashboard-section" aria-labelledby="updatesTitle">
    <h2 class="dashboard-section-title" id="updatesTitle">Latest updates</h2>
<div class="row g-3">
    <div class="col-lg-6"><div class="card p-4 h-100 dashboard-update-card"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><h3 class="h5 mb-0">Recent achievements</h3><a href="<?= e(app_url('savings/index.php#goalHistory')) ?>">Goal History</a></div><?php if ($achievements): ?><div class="list-group list-group-flush"><?php foreach ($achievements as $achievement): ?><div class="list-group-item px-0 d-flex justify-content-between align-items-start gap-3"><div><strong><?= e($achievement['name']) ?></strong><div class="small text-secondary"><?= e(date('j M Y', strtotime($achievement['completed_at']))) ?> · <?= currency($achievement['amount']) ?> saved</div></div><span class="badge dashboard-achievement-badge <?= $achievement['status']==='Archived'?'text-bg-secondary':'text-bg-success' ?>"><?= e($achievement['status']) ?></span></div><?php endforeach; ?></div><?php else: ?><div class="empty-state"><strong>No achievements yet</strong><span>Completed targets and monthly milestones will appear here.</span></div><?php endif; ?></div></div>
    <div class="col-lg-6"><div class="card p-4 h-100 dashboard-update-card"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h3 class="h5 mb-0">Recent alerts</h3><small class="text-secondary"><?= $unreadCount ?> unread</small></div><a href="<?= e(app_url('dashboard/index.php?notifications=all#notificationHistory')) ?>">View all</a></div><?php if ($dashboardAlerts): ?><div class="list-group list-group-flush"><?php foreach ($dashboardAlerts as $alert): ?><a class="list-group-item list-group-item-action px-0 d-flex align-items-start gap-3 text-decoration-none" href="<?= e(notification_link($alert)) ?>"><span class="notification-icon" aria-hidden="true"><?= e(notification_icon($alert['notification_type'])) ?></span><span class="min-width-0"><strong class="d-block text-dark"><?= e($alert['title']) ?><?= (int)$alert['is_read']===0?' · New':'' ?></strong><small class="d-block text-secondary"><?= e(date('j M Y, g:i A', strtotime($alert['created_at']))) ?></small><span class="small text-secondary notification-preview"><?= e($alert['message']) ?></span></span></a><?php endforeach; ?></div><small class="text-secondary d-block mt-2">Alerts record past events. Budget progress above shows current spending.</small><?php else: ?><div class="empty-state"><strong>No alerts yet</strong><span>Budget, allowance, and goal updates will appear here.</span></div><?php endif; ?></div></div>
</div>
</section>

<?php if ($showNotificationHistory): ?>
<section class="card overflow-hidden mt-4" id="notificationHistory" aria-labelledby="notificationHistoryTitle">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-4 border-bottom">
        <div><h2 class="h5 mb-1" id="notificationHistoryTitle">Notification history</h2><p class="small text-secondary mb-0"><?= $notificationTotal ?> update<?= $notificationTotal === 1 ? '' : 's' ?> · <?= $unreadCount ?> unread</p></div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('notifications/index.php')) ?>">Notification preferences</a>
            <?php if ($unreadCount): ?><form method="post" action="<?= e(app_url('notifications/index.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="mark_all_read"><input type="hidden" name="return_to" value="dashboard"><button class="btn btn-sm btn-outline-primary">Mark All as Read</button></form><?php endif; ?>
        </div>
    </div>
    <?php if (!$notificationHistory): ?><div class="empty-state"><strong>No notifications yet</strong><span>Budget, allowance, savings-goal, and split-bill updates will appear here.</span></div><?php endif; ?>
    <?php foreach ($notificationHistory as $notification): ?>
        <div class="notification-row <?= (int)$notification['is_read'] ? '' : 'notification-unread' ?>">
            <span class="notification-icon" aria-hidden="true"><?= e(notification_icon($notification['notification_type'])) ?></span>
            <div class="notification-body"><strong><?= e($notification['title']) ?></strong><p class="small text-secondary mb-1"><?= e($notification['message']) ?></p><small class="text-secondary"><?= e(date('j M Y, g:i A', strtotime($notification['created_at']))) ?> · <?= (int)$notification['is_read'] ? 'Read' : 'Unread' ?></small><div class="d-flex flex-wrap gap-2 mt-2"><a class="btn btn-sm btn-outline-primary" href="<?= e(notification_link($notification)) ?>">View related feature</a><?php if (!(int)$notification['is_read']): ?><form method="post" action="<?= e(app_url('notifications/index.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notification_id" value="<?= (int)$notification['notification_id'] ?>"><input type="hidden" name="return_to" value="dashboard"><button class="btn btn-sm btn-link text-decoration-none">Mark as Read</button></form><?php endif; ?></div></div>
        </div>
    <?php endforeach; ?>
    <?php if ($notificationPageCount > 1): ?><div class="d-flex justify-content-between align-items-center p-3 border-top"><a class="btn btn-sm btn-outline-secondary <?= $notificationPage <= 1 ? 'disabled' : '' ?>" href="<?= e(app_url('dashboard/index.php?notifications=all&notification_page=' . ($notificationPage - 1) . '#notificationHistory')) ?>">Previous</a><span class="small text-secondary">Page <?= $notificationPage ?> of <?= $notificationPageCount ?></span><a class="btn btn-sm btn-outline-secondary <?= $notificationPage >= $notificationPageCount ? 'disabled' : '' ?>" href="<?= e(app_url('dashboard/index.php?notifications=all&notification_page=' . ($notificationPage + 1) . '#notificationHistory')) ?>">Next</a></div><?php endif; ?>
</section>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const money=value=>'RM '+Number(value).toLocaleString('en-MY',{minimumFractionDigits:2,maximumFractionDigits:2});
new Chart(document.getElementById('trend'),{type:'bar',data:{labels:<?= json_encode($trendLabels) ?>,datasets:[{label:'Income',data:<?= json_encode($trendIncome) ?>,backgroundColor:'#16876b',borderRadius:5},{label:'Expenses',data:<?= json_encode($trendExpenses) ?>,backgroundColor:'#e76f51',borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{tooltip:{callbacks:{label:context=>context.dataset.label+': '+money(context.parsed.y)}}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,ticks:{callback:value=>'RM '+value}}}}});
const categoryValues=<?= json_encode(array_map('floatval',array_column($categorySpending,'value'))) ?>;
const hasCategorySpending=categoryValues.length>0;
new Chart(document.getElementById('pie'),{type:'doughnut',data:{labels:hasCategorySpending?<?= json_encode(array_column($categorySpending,'name')) ?>:['No expenses'],datasets:[{data:hasCategorySpending?categoryValues:[1],backgroundColor:hasCategorySpending?['#16876b','#e76f51','#f4a261','#457b9d','#8a6bbf','#e9c46a','#6c757d','#06b6d4']:['#dfe9e5'],borderColor:'#fff',borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{tooltip:{callbacks:{label:context=>hasCategorySpending?context.label+': '+money(context.parsed):'No expenses in this period'}}}}});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
