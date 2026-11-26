<?php
$page_title = 'Reports & Analytics';
require_once __DIR__ . '/../includes/header.php';

$selectedMonth = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) $selectedMonth = date('Y-m');
$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $selectedMonth);
$monthStart = $monthDate->format('Y-m-d');
$monthEnd = $monthDate->modify('+1 month')->format('Y-m-d');
$previousStart = $monthDate->modify('-1 month')->format('Y-m-d');
$sixMonthStart = $monthDate->modify('-5 months')->format('Y-m-d');
$monthLabel = $monthDate->format('F Y');

$statement = db()->prepare("SELECT
    COALESCE(SUM(CASE WHEN transaction_date>=? AND transaction_date<? AND type='Income' THEN amount ELSE 0 END),0) income,
    COALESCE(SUM(CASE WHEN transaction_date>=? AND transaction_date<? AND type='Expense' THEN amount ELSE 0 END),0) expenses,
    COALESCE(SUM(CASE WHEN transaction_date>=? AND transaction_date<? AND type='Income' THEN amount ELSE 0 END),0) previous_income,
    COALESCE(SUM(CASE WHEN transaction_date>=? AND transaction_date<? AND type='Expense' THEN amount ELSE 0 END),0) previous_expenses
    FROM transactions WHERE user_id=?");
$statement->execute([$monthStart, $monthEnd, $monthStart, $monthEnd, $previousStart, $monthStart, $previousStart, $monthStart, user_id()]);
$totals = $statement->fetch();
$income = (float)$totals['income'];
$expenses = (float)$totals['expenses'];
$balance = $income - $expenses;
$previousIncome = (float)$totals['previous_income'];
$previousExpenses = (float)$totals['previous_expenses'];
$previousBalance = $previousIncome - $previousExpenses;

$statement = db()->prepare("SELECT DATE_FORMAT(transaction_date,'%Y-%m') month_key,
    SUM(CASE WHEN type='Income' THEN amount ELSE 0 END) income,
    SUM(CASE WHEN type='Expense' THEN amount ELSE 0 END) expenses
    FROM transactions WHERE user_id=? AND transaction_date>=? AND transaction_date<? GROUP BY month_key ORDER BY month_key");
$statement->execute([user_id(), $sixMonthStart, $monthEnd]);
$monthlyRows = [];
foreach ($statement->fetchAll() as $row) $monthlyRows[$row['month_key']] = $row;
$monthlyLabels = $monthlyIncome = $monthlyExpenses = [];
for ($cursor = $monthDate->modify('-5 months'); $cursor <= $monthDate; $cursor = $cursor->modify('+1 month')) {
    $key = $cursor->format('Y-m');
    $monthlyLabels[] = $cursor->format('M Y');
    $monthlyIncome[] = isset($monthlyRows[$key]) ? (float)$monthlyRows[$key]['income'] : 0;
    $monthlyExpenses[] = isset($monthlyRows[$key]) ? (float)$monthlyRows[$key]['expenses'] : 0;
}

$statement = db()->prepare("SELECT transaction_date,SUM(amount) amount FROM transactions WHERE user_id=? AND type='Expense' AND transaction_date>=? AND transaction_date<? GROUP BY transaction_date ORDER BY transaction_date");
$statement->execute([user_id(), $monthStart, $monthEnd]);
$dailyRows = [];
foreach ($statement->fetchAll() as $row) $dailyRows[$row['transaction_date']] = (float)$row['amount'];
$dailyLabels = $dailyValues = [];
for ($cursor = $monthDate; $cursor < $monthDate->modify('+1 month'); $cursor = $cursor->modify('+1 day')) {
    $key = $cursor->format('Y-m-d');
    $dailyLabels[] = $cursor->format('j M');
    $dailyValues[] = $dailyRows[$key] ?? 0;
}

$statement = db()->prepare("SELECT COALESCE(c.id,0) category_id,COALESCE(c.name,'Uncategorized') name,c.need_want,
    SUM(CASE WHEN t.transaction_date>=? AND t.transaction_date<? THEN t.amount ELSE 0 END) current_amount,
    SUM(CASE WHEN t.transaction_date>=? AND t.transaction_date<? THEN t.amount ELSE 0 END) previous_amount
    FROM transactions t LEFT JOIN categories c ON c.id=t.category_id AND c.user_id=t.user_id
    WHERE t.user_id=? AND t.type='Expense' AND t.transaction_date>=? AND t.transaction_date<?
    GROUP BY COALESCE(c.id,0),COALESCE(c.name,'Uncategorized'),c.need_want ORDER BY current_amount DESC,name");
$statement->execute([$monthStart, $monthEnd, $previousStart, $monthStart, user_id(), $previousStart, $monthEnd]);
$categoryRows = $statement->fetchAll();
$currentCategories = array_values(array_filter($categoryRows, fn($row) => (float)$row['current_amount'] > 0));
$needsWants = ['Need' => 0.0, 'Want' => 0.0, 'Unassigned' => 0.0];
foreach ($currentCategories as $row) $needsWants[$row['need_want'] ?: 'Unassigned'] += (float)$row['current_amount'];

$statement = db()->prepare("SELECT b.*,COALESCE(c.name,'Overall monthly budget') name,COALESCE(SUM(t.amount),0) spent
    FROM budgets b LEFT JOIN categories c ON c.id=b.category_id
    LEFT JOIN transactions t ON t.user_id=b.user_id AND t.type='Expense' AND t.transaction_date>=? AND t.transaction_date<? AND (b.category_id IS NULL OR t.category_id=b.category_id)
    WHERE b.user_id=? AND b.budget_month=? GROUP BY b.id ORDER BY b.category_id IS NOT NULL,c.name");
$statement->execute([$monthStart, $monthEnd, user_id(), $selectedMonth]);
$budgets = $statement->fetchAll();

$statement = db()->prepare("SELECT * FROM savings_goals WHERE user_id=? AND status<>'Archived' ORDER BY status='Completed',target_date IS NULL,target_date,created_at");
$statement->execute([user_id()]);
$goals = $statement->fetchAll();

function analytics_goal_plan(array $goal): array
{
    $target = (float)$goal['target_amount'];
    $current = (float)($goal['goal_type'] === 'Monthly' ? $goal['monthly_cycle_amount'] : $goal['current_amount']);
    $remaining = max(0, $target - $current);
    $progress = $target > 0 ? $current / $target * 100 : 0;
    $required = null;
    $status = $remaining <= 0 ? 'Completed' : 'In progress';
    if ($goal['goal_type'] === 'Monthly') {
        $required = $remaining;
    } elseif ($remaining > 0 && $goal['target_date']) {
        $today = new DateTimeImmutable('today');
        $deadline = new DateTimeImmutable($goal['target_date']);
        $days = (int)$today->diff($deadline)->format('%r%a');
        if ($days < 0) {
            $status = 'Overdue';
        } else {
            $months = max(1, (int)ceil(($days + 1) / 30.4375));
            $required = $remaining / $months;
        }
    }
    return ['remaining' => $remaining, 'progress' => $progress, 'bar' => min(100, max(0, $progress)), 'required' => $required, 'status' => $status];
}

$insights = [];
foreach ($budgets as $budget) {
    $usage = (float)$budget['budget_amount'] > 0 ? (float)$budget['spent'] / (float)$budget['budget_amount'] * 100 : 0;
    if ($usage >= 100) {
        $insights[] = ['tone' => 'danger', 'title' => 'Budget exceeded', 'body' => 'You have used ' . number_format($usage, 0) . '% of your ' . $budget['name'] . '.'];
    } elseif ($usage >= 80) {
        $insights[] = ['tone' => 'warning', 'title' => 'Budget approaching limit', 'body' => 'You have used ' . number_format($usage, 0) . '% of your ' . $budget['name'] . '.'];
    }
}

$categoryChanges = $categoryRows;
usort($categoryChanges, fn($a, $b) => abs((float)$b['current_amount'] - (float)$b['previous_amount']) <=> abs((float)$a['current_amount'] - (float)$a['previous_amount']));
foreach (array_slice($categoryChanges, 0, 2) as $row) {
    $difference = (float)$row['current_amount'] - (float)$row['previous_amount'];
    if (abs($difference) < 0.01) continue;
    $insights[] = ['tone' => $difference > 0 ? 'info' : 'success', 'title' => 'Monthly spending change', 'body' => 'You spent ' . currency(abs($difference)) . ($difference > 0 ? ' more' : ' less') . ' on ' . $row['name'] . ' than last month.'];
}

foreach ($goals as $goal) {
    $plan = analytics_goal_plan($goal);
    if ($plan['status'] === 'Overdue') {
        $insights[] = ['tone' => 'danger', 'title' => 'Savings deadline passed', 'body' => $goal['goal_name'] . ' still needs ' . currency($plan['remaining']) . '. Review its deadline or contribution plan.'];
    } elseif ($plan['required'] !== null && $plan['required'] > 0) {
        $insights[] = ['tone' => 'primary', 'title' => 'Savings plan', 'body' => 'You need approximately ' . currency($plan['required']) . ($goal['goal_type'] === 'Monthly' ? ' more this month for ' : ' per month to reach ') . $goal['goal_name'] . '.'];
    }
    if (count($insights) >= 6) break;
}

if ($currentCategories) {
    $largest = $currentCategories[0];
    $share = $expenses > 0 ? (float)$largest['current_amount'] / $expenses * 100 : 0;
    array_unshift($insights, ['tone' => 'primary', 'title' => 'Largest spending category', 'body' => $largest['name'] . ' is your largest category at ' . currency($largest['current_amount']) . ' (' . number_format($share, 1) . '% of expenses).']);
}
if (!$insights) $insights[] = ['tone' => 'secondary', 'title' => 'Build your report', 'body' => 'Add transactions, budgets, and savings goals to receive useful rule-based insights.'];
$insights = array_slice($insights, 0, 6);

$incomeChange = $previousIncome > 0 ? ($income - $previousIncome) / $previousIncome * 100 : null;
$expenseChange = $previousExpenses > 0 ? ($expenses - $previousExpenses) / $previousExpenses * 100 : null;
$hasMonthlyData = array_sum($monthlyIncome) + array_sum($monthlyExpenses) > 0;
$hasDailyData = array_sum($dailyValues) > 0;
$hasNeedWantData = array_sum($needsWants) > 0;
?>
<div class="analytics-hero mb-4">
    <div><span class="dashboard-eyebrow">REPORTS & ANALYTICS</span><h1 class="page-title mb-1">Financial report</h1><p class="text-secondary mb-0">Your performance for <?= e($monthLabel) ?>, calculated from unified transactions.</p></div>
    <form method="get" class="analytics-month"><label class="visually-hidden" for="month">Report month</label><input class="form-control" id="month" type="month" name="month" value="<?= e($selectedMonth) ?>" onchange="this.form.submit()"></form>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="card p-3 h-100"><div class="metric-label">Income · <?= e($monthLabel) ?></div><div class="metric text-success"><?= currency($income) ?></div><small class="text-secondary"><?= $incomeChange===null?'No previous-month baseline':($incomeChange>=0?'+':'').number_format($incomeChange,1).'% vs last month' ?></small></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card p-3 h-100"><div class="metric-label">Expenses · <?= e($monthLabel) ?></div><div class="metric text-danger"><?= currency($expenses) ?></div><small class="text-secondary"><?= $expenseChange===null?'No previous-month baseline':($expenseChange>=0?'+':'').number_format($expenseChange,1).'% vs last month' ?></small></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card p-3 h-100"><div class="metric-label">Balance · <?= e($monthLabel) ?></div><div class="metric <?= $balance<0?'text-danger':'accent' ?>"><?= currency($balance) ?></div><small class="text-secondary">Income minus expenses</small></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card p-3 h-100"><div class="metric-label">Previous balance</div><div class="metric <?= $previousBalance<0?'text-danger':'text-primary' ?>"><?= currency($previousBalance) ?></div><small class="text-secondary"><?= e($monthDate->modify('-1 month')->format('F Y')) ?></small></div></div>
</div>

<div class="card p-4 mb-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h5 class="mb-1">Rule-based financial insights</h5><p class="text-secondary small mb-0">Generated using your totals, budgets, category changes, and goal deadlines.</p></div><span class="badge text-bg-light border">No AI used</span></div><div class="row g-3"><?php foreach ($insights as $insight): ?><div class="col-md-6 col-xl-4"><div class="insight-card insight-<?= e($insight['tone']) ?>"><strong><?= e($insight['title']) ?></strong><span><?= e($insight['body']) ?></span></div></div><?php endforeach; ?></div></div>

<div class="row g-3 mb-4">
    <div class="col-xl-7"><div class="card p-4 h-100"><div class="d-flex justify-content-between mb-2"><div><h5 class="mb-1">Monthly income vs expenses</h5><small class="text-secondary">Six-month comparison ending <?= e($monthLabel) ?></small></div></div><?php if ($hasMonthlyData): ?><div class="chart-box analytics-chart"><div class="chart-loading">Loading chart…</div><canvas id="monthlyChart"></canvas></div><?php else: ?><div class="empty-state"><strong>No six-month transaction data</strong><span>Add income or expense transactions to build this comparison.</span></div><?php endif; ?></div></div>
    <div class="col-xl-5"><div class="card p-4 h-100"><h5 class="mb-1">Spending by category</h5><small class="text-secondary"><?= e($monthLabel) ?></small><?php if ($currentCategories): ?><div class="chart-box analytics-chart"><div class="chart-loading">Loading chart…</div><canvas id="categoryChart"></canvas></div><?php else: ?><div class="empty-state"><strong>No category spending</strong><span>No expenses were recorded for this month.</span></div><?php endif; ?></div></div>
    <div class="col-xl-7"><div class="card p-4 h-100"><h5 class="mb-1">Daily spending trend</h5><small class="text-secondary">Expense activity throughout <?= e($monthLabel) ?></small><?php if ($hasDailyData): ?><div class="chart-box analytics-chart"><div class="chart-loading">Loading chart…</div><canvas id="dailyChart"></canvas></div><?php else: ?><div class="empty-state"><strong>No daily spending trend</strong><span>Add expenses in this month to see the trend.</span></div><?php endif; ?></div></div>
    <div class="col-xl-5"><div class="card p-4 h-100"><h5 class="mb-1">Needs vs wants</h5><small class="text-secondary">Based on category classifications</small><?php if ($hasNeedWantData): ?><div class="chart-box analytics-chart"><div class="chart-loading">Loading chart…</div><canvas id="needsChart"></canvas></div><?php else: ?><div class="empty-state"><strong>No classified spending</strong><span>Classify categories as Need or Want to compare them.</span></div><?php endif; ?></div></div>
</div>

<div class="card p-4 mb-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h5 class="mb-1">Budget performance</h5><p class="text-secondary small mb-0">Actual expenses compared with <?= e($monthLabel) ?> limits.</p></div><a href="<?= e(app_url('budgets/index.php?month='.$selectedMonth)) ?>">Manage budgets</a></div>
<?php if ($budgets): ?><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Budget</th><th>Limit</th><th>Spent</th><th>Usage</th><th>Remaining</th></tr></thead><tbody><?php foreach ($budgets as $budget): $budgetAmount=(float)$budget['budget_amount'];$spent=(float)$budget['spent'];$usage=$budgetAmount>0?$spent/$budgetAmount*100:0;$remaining=$budgetAmount-$spent;$tone=$usage>=100?'danger':($usage>=80?'warning':'success'); ?><tr><td><strong><?= e($budget['name']) ?></strong><br><small class="text-secondary"><?= $budget['category_id']===null?'All expenses':'Category expenses' ?></small></td><td><?= currency($budgetAmount) ?></td><td><?= currency($spent) ?></td><td style="min-width:180px"><div class="d-flex justify-content-between small"><span><?= number_format($usage,1) ?>%</span><span class="text-<?= e($tone) ?>"><?= $usage>=100?'Exceeded':($usage>=80?'Approaching':'On track') ?></span></div><div class="progress mt-1"><div class="progress-bar bg-<?= e($tone) ?>" style="width:<?= min(100,$usage) ?>%"></div></div></td><td class="<?= $remaining<0?'text-danger':'text-success' ?>"><?= $remaining<0?'-'.currency(abs($remaining)):currency($remaining) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><strong>No budgets for <?= e($monthLabel) ?></strong><span>Create an overall or category budget to measure performance.</span><a class="btn btn-sm btn-outline-primary mt-2" href="<?= e(app_url('budgets/index.php?month='.$selectedMonth)) ?>">Create budget</a></div><?php endif; ?>
</div>

<div class="card p-4 mb-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h5 class="mb-1">Savings progress</h5><p class="text-secondary small mb-0">Progress and estimated monthly contribution requirements.</p></div><a href="<?= e(app_url('savings/index.php')) ?>">Manage goals</a></div><div class="row g-3">
<?php foreach ($goals as $goal): $plan=analytics_goal_plan($goal);$tone=$plan['status']==='Completed'?'success':($plan['status']==='Overdue'?'danger':'primary'); ?><div class="col-md-6 col-xl-4"><div class="goal-summary h-100"><div class="d-flex justify-content-between gap-2"><strong><?= e($goal['goal_name']) ?></strong><span class="badge text-bg-<?= e($tone) ?>"><?= e($plan['status']) ?></span></div><div class="progress my-3"><div class="progress-bar bg-<?= e($tone) ?>" style="width:<?= $plan['bar'] ?>%"></div></div><div class="d-flex justify-content-between small"><span><?= number_format($plan['progress'],1) ?>%</span><span><?= currency($goal['goal_type']==='Monthly'?$goal['monthly_cycle_amount']:$goal['current_amount']) ?> / <?= currency($goal['target_amount']) ?></span></div><div class="mt-2 small text-secondary"><?= $plan['required']!==null?'Required: '.currency($plan['required']).($goal['goal_type']==='Monthly'?' this month':' per month'):($plan['status']==='Completed'?'Target reached':'Update the deadline to calculate') ?></div></div></div><?php endforeach; ?>
<?php if (!$goals): ?><div class="col-12"><div class="empty-state"><strong>No savings goals</strong><span>Create a goal to see savings progress here.</span></div></div><?php endif; ?></div></div>

<div class="card p-4"><h5 class="mb-3">Category comparison</h5><?php if ($categoryRows): ?><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Category</th><th>Classification</th><th><?= e($monthLabel) ?></th><th>Previous month</th><th>Change</th><th>Share</th></tr></thead><tbody><?php foreach ($categoryRows as $row): $change=(float)$row['current_amount']-(float)$row['previous_amount']; ?><tr><td><strong><?= e($row['name']) ?></strong></td><td><?= $row['need_want']?'<span class="badge '.($row['need_want']==='Need'?'text-bg-primary':'text-bg-warning').'">'.e($row['need_want']).'</span>':'<span class="badge text-bg-light">Unassigned</span>' ?></td><td><?= currency($row['current_amount']) ?></td><td><?= currency($row['previous_amount']) ?></td><td class="<?= $change>0?'text-danger':($change<0?'text-success':'text-secondary') ?>"><?= $change>0?'+':'' ?><?= currency($change) ?></td><td><?= $expenses>0?number_format((float)$row['current_amount']/$expenses*100,1):'0.0' ?>%</td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><strong>No category comparison available</strong><span>Add expense transactions in this or the previous month.</span></div><?php endif; ?></div>

<?php if ($hasMonthlyData || $currentCategories || $hasDailyData || $hasNeedWantData): ?><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script>
const money=value=>'RM '+Number(value).toLocaleString('en-MY',{minimumFractionDigits:2,maximumFractionDigits:2});const colors=['#16876b','#e76f51','#f4a261','#457b9d','#8a6bbf','#e9c46a','#06b6d4','#6c757d'];function ready(id,config){const canvas=document.getElementById(id);if(!canvas)return;canvas.parentElement.querySelector('.chart-loading')?.remove();new Chart(canvas,config);}if(typeof Chart==='undefined'){document.querySelectorAll('.chart-loading').forEach(el=>el.textContent='Chart library could not load. The report tables remain available.');}else{
ready('monthlyChart',{type:'bar',data:{labels:<?= json_encode($monthlyLabels) ?>,datasets:[{label:'Income',data:<?= json_encode($monthlyIncome) ?>,backgroundColor:'#16876b',borderRadius:5},{label:'Expenses',data:<?= json_encode($monthlyExpenses) ?>,backgroundColor:'#e76f51',borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{tooltip:{callbacks:{label:c=>c.dataset.label+': '+money(c.parsed.y)}}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,ticks:{callback:v=>'RM '+v}}}}});
ready('categoryChart',{type:'doughnut',data:{labels:<?= json_encode(array_column($currentCategories,'name')) ?>,datasets:[{data:<?= json_encode(array_map('floatval',array_column($currentCategories,'current_amount'))) ?>,backgroundColor:colors,borderColor:'#fff',borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'66%',plugins:{tooltip:{callbacks:{label:c=>c.label+': '+money(c.parsed)}}}}});
ready('dailyChart',{type:'line',data:{labels:<?= json_encode($dailyLabels) ?>,datasets:[{label:'Daily expenses',data:<?= json_encode($dailyValues) ?>,borderColor:'#e76f51',backgroundColor:'#e76f5120',fill:true,tension:.28,pointRadius:2}]},options:{responsive:true,maintainAspectRatio:false,plugins:{tooltip:{callbacks:{label:c=>money(c.parsed.y)}}},scales:{x:{grid:{display:false},ticks:{maxTicksLimit:10}},y:{beginAtZero:true,ticks:{callback:v=>'RM '+v}}}}});
ready('needsChart',{type:'doughnut',data:{labels:<?= json_encode(array_keys(array_filter($needsWants,fn($value)=>$value>0))) ?>,datasets:[{data:<?= json_encode(array_values(array_filter($needsWants,fn($value)=>$value>0))) ?>,backgroundColor:['#457b9d','#f4a261','#9aa7a3'],borderColor:'#fff',borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'66%',plugins:{tooltip:{callbacks:{label:c=>c.label+': '+money(c.parsed)}}}}});}
</script><?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
