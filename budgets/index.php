<?php
$page_title = 'Budgets';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

$errors = [];
$editing = null;
$categoryRows = categories();
$allowedScopes = ['Overall', 'Category'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $budgetId = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        if (ownership('budgets', $budgetId)) {
            db()->prepare('DELETE FROM budgets WHERE id=? AND user_id=?')->execute([$budgetId, user_id()]);
            flash('Budget deleted successfully.');
        } else {
            flash('Budget not found.', 'danger');
        }
        redirect('budgets/index.php?month=' . urlencode($_POST['view_month'] ?? date('Y-m')));
    }
    if (!in_array($action, ['create', 'update'], true)) {
        flash('Invalid budget action.', 'danger');
        redirect('budgets/index.php');
    }
    if ($action === 'update' && !ownership('budgets', $budgetId)) {
        flash('Budget not found.', 'danger');
        redirect('budgets/index.php');
    }

    $scope = $_POST['budget_scope'] ?? '';
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $budgetAmount = amount($_POST['budget_amount'] ?? '');
    $budgetMonth = $_POST['budget_month'] ?? '';

    if (!in_array($scope, $allowedScopes, true)) {
        $errors[] = 'Select overall or category budget.';
    }
    if ($scope === 'Category' && (!$categoryId || !ownership('categories', $categoryId))) {
        $errors[] = 'Select one of your categories.';
    }
    if ($scope === 'Overall') $categoryId = 0;
    if ($budgetAmount === null || $budgetAmount > 9999999999.99) {
        $errors[] = 'Budget amount must be greater than RM 0.00.';
    }
    if (!is_string($budgetMonth) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $budgetMonth) || !valid_date($budgetMonth . '-01')) {
        $errors[] = 'Select a valid budget month.';
    }

    if (!$errors) {
        if ($categoryId) {
            $statement = db()->prepare('SELECT id FROM budgets WHERE user_id=? AND category_id=? AND budget_month=? AND id<>?');
            $statement->execute([user_id(), $categoryId, $budgetMonth, $budgetId]);
        } else {
            $statement = db()->prepare('SELECT id FROM budgets WHERE user_id=? AND category_id IS NULL AND budget_month=? AND id<>?');
            $statement->execute([user_id(), $budgetMonth, $budgetId]);
        }

        if ($statement->fetch()) {
            $errors[] = $categoryId ? 'A budget already exists for this category and month.' : 'An overall budget already exists for this month.';
        } elseif ($action === 'update') {
            db()->prepare('UPDATE budgets SET category_id=?, budget_amount=?, budget_month=? WHERE id=? AND user_id=?')
                ->execute([$categoryId ?: null, $budgetAmount, $budgetMonth, $budgetId, user_id()]);
            notification_check_budget_month($budgetMonth);
            flash('Budget updated successfully.');
            redirect('budgets/index.php?month=' . urlencode($budgetMonth));
        } else {
            db()->prepare('INSERT INTO budgets (user_id, category_id, budget_amount, budget_month) VALUES (?, ?, ?, ?)')
                ->execute([user_id(), $categoryId ?: null, $budgetAmount, $budgetMonth]);
            notification_check_budget_month($budgetMonth);
            flash('Budget created successfully.');
            redirect('budgets/index.php?month=' . urlencode($budgetMonth));
        }
    }

    $editing = [
        'id' => $budgetId,
        'category_id' => $categoryId ?: null,
        'budget_amount' => $_POST['budget_amount'] ?? '',
        'budget_month' => $budgetMonth,
        'budget_scope' => $scope,
    ];
}

if (isset($_GET['edit'])) {
    $editing = ownership('budgets', (int)$_GET['edit']);
    if (!$editing) {
        flash('Budget not found.', 'warning');
        redirect('budgets/index.php');
    }
    $editing['budget_scope'] = $editing['category_id'] === null ? 'Overall' : 'Category';
}

$viewMonth = $_GET['month'] ?? $editing['budget_month'] ?? date('Y-m');
if (!is_string($viewMonth) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $viewMonth) || !valid_date($viewMonth . '-01')) $viewMonth = date('Y-m');
$monthStart = $viewMonth . '-01';
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));

$statement = db()->prepare("SELECT b.*, COALESCE(c.name,'Overall monthly budget') name, COALESCE(SUM(t.amount),0) spent FROM budgets b LEFT JOIN categories c ON c.id=b.category_id LEFT JOIN transactions t ON t.user_id=b.user_id AND t.type='Expense' AND t.transaction_date>=? AND t.transaction_date<? AND (b.category_id IS NULL OR t.category_id=b.category_id) WHERE b.user_id=? AND b.budget_month=? GROUP BY b.id ORDER BY b.category_id IS NOT NULL, c.name");
$statement->execute([$monthStart, $monthEnd, user_id(), $viewMonth]);
$budgets = $statement->fetchAll();

$statement = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND type='Expense' AND transaction_date>=? AND transaction_date<?");
$statement->execute([user_id(), $monthStart, $monthEnd]);
$monthlySpending = (float)$statement->fetchColumn();

$overallBudget = null;
foreach ($budgets as $budget) if ($budget['category_id'] === null) $overallBudget = $budget;
$overallRemaining = $overallBudget ? (float)$overallBudget['budget_amount'] - $monthlySpending : null;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="page-title mb-1">Budget Management</h1><p class="text-secondary mb-0">Plan an overall monthly limit and focused category limits.</p></div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(app_url('categories/index.php')) ?>">Manage Categories</a>
        <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#budgetForm">+ Create Budget</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card p-3 h-100"><div class="metric-label">Overall budget · <?= e(date('M Y', strtotime($monthStart))) ?></div><div class="metric text-primary"><?= $overallBudget ? currency($overallBudget['budget_amount']) : '—' ?></div><small class="text-secondary"><?= $overallBudget ? 'Monthly spending limit' : 'No overall budget set' ?></small></div></div>
    <div class="col-md-4"><div class="card p-3 h-100"><div class="metric-label">Actual monthly spending</div><div class="metric text-danger"><?= currency($monthlySpending) ?></div><small class="text-secondary">From unified expense transactions</small></div></div>
    <div class="col-md-4"><div class="card p-3 h-100"><div class="metric-label">Overall remaining</div><div class="metric <?= $overallRemaining !== null && $overallRemaining < 0 ? 'text-danger' : 'text-success' ?>"><?= $overallRemaining !== null ? currency($overallRemaining) : '—' ?></div><small class="text-secondary"><?= $overallRemaining === null ? 'Create an overall budget to calculate this' : ($overallRemaining < 0 ? 'Overall budget exceeded' : 'Available for the rest of the month') ?></small></div></div>
</div>

<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>

<div class="collapse <?= $editing ? 'show' : '' ?> mb-4" id="budgetForm">
    <div class="card p-4"><h5 class="mb-3"><?= $editing ? 'Edit budget' : 'Create budget' ?></h5>
        <form method="post" class="row g-3" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <div class="col-md-3"><label class="form-label" for="budget_scope">Budget type</label><select class="form-select" id="budget_scope" name="budget_scope"><option value="Overall" <?= ($editing['budget_scope'] ?? 'Overall')==='Overall'?'selected':'' ?>>Overall monthly budget</option><option value="Category" <?= ($editing['budget_scope'] ?? '')==='Category'?'selected':'' ?>>Category budget</option></select></div>
            <div class="col-md-3" id="budgetCategoryField"><label class="form-label" for="category_id">Category</label><select class="form-select" id="category_id" name="category_id"><option value="">Select category</option><?php foreach ($categoryRows as $category): ?><option value="<?= (int)$category['id'] ?>" <?= (int)($editing['category_id'] ?? 0)===(int)$category['id']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="budget_amount">Budget amount (RM)</label><input class="form-control" id="budget_amount" name="budget_amount" type="number" min="0.01" max="9999999999.99" step="0.01" required value="<?= e($editing['budget_amount'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label" for="budget_month">Month</label><input class="form-control" id="budget_month" name="budget_month" type="month" required value="<?= e($editing['budget_month'] ?? $viewMonth) ?>"></div>
            <div class="col-12"><button class="btn btn-primary" type="submit"><?= $editing ? 'Update Budget' : 'Save Budget' ?></button><?php if ($editing): ?> <a class="btn btn-light" href="<?= e(app_url('budgets/index.php?month='.$viewMonth)) ?>">Cancel</a><?php endif; ?></div>
        </form>
    </div>
</div>

<div class="card p-3 mb-4"><form class="row g-2 align-items-center"><div class="col-md-3"><label class="visually-hidden" for="month">View month</label><input class="form-control" id="month" type="month" name="month" value="<?= e($viewMonth) ?>"></div><div class="col-md-2"><button class="btn btn-outline-secondary w-100">View month</button></div><div class="col-md-7 text-md-end text-secondary small">Warning levels: 80% approaching · 100% exceeded</div></form></div>

<div class="row g-3">
<?php foreach ($budgets as $budget):
    $spent = (float)$budget['spent'];
    $budgetValue = (float)$budget['budget_amount'];
    $usage = $budgetValue > 0 ? $spent / $budgetValue * 100 : 0;
    $remaining = $budgetValue - $spent;
    $statusClass = $usage >= 100 ? 'danger' : ($usage >= 80 ? 'warning' : 'success');
    $statusText = $usage >= 100 ? 'Budget exceeded' : ($usage >= 80 ? 'Approaching limit' : 'On track');
?>
    <div class="col-lg-6"><div class="card p-4 h-100 budget-card budget-<?= e($statusClass) ?>">
        <div class="d-flex justify-content-between align-items-start gap-3"><div><span class="badge text-bg-<?= e($statusClass) ?> mb-2"><?= e($statusText) ?></span><h5 class="mb-1"><?= e($budget['name']) ?></h5><small class="text-secondary"><?= $budget['category_id'] === null ? 'All monthly expenses' : 'Category spending only' ?></small></div><div class="text-end"><strong><?= currency($spent) ?></strong><div class="small text-secondary">of <?= currency($budgetValue) ?></div></div></div>
        <div class="progress my-3"><div class="progress-bar bg-<?= e($statusClass) ?>" style="width:<?= min(100,$usage) ?>%"></div></div>
        <div class="d-flex justify-content-between small"><span><?= number_format($usage,1) ?>% used</span><strong class="<?= $remaining<0?'text-danger':'' ?>"><?= $remaining<0?'Exceeded by '.currency(abs($remaining)):currency($remaining).' remaining' ?></strong></div>
        <div class="mt-3 text-end"><a class="btn btn-sm btn-outline-primary" href="?month=<?= e($viewMonth) ?>&edit=<?= (int)$budget['id'] ?>#budgetForm">Edit</a> <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$budget['id'] ?>"><input type="hidden" name="view_month" value="<?= e($viewMonth) ?>"><button data-confirm="Delete this budget?" class="btn btn-sm btn-outline-danger">Delete</button></form></div>
    </div></div>
<?php endforeach; ?>
<?php if (!$budgets): ?><div class="col-12"><div class="card empty-state"><strong>No budgets for <?= e(date('F Y', strtotime($monthStart))) ?></strong><span>Create an overall or category budget to begin.</span></div></div><?php endif; ?>
</div>

<script>
const budgetScope=document.getElementById('budget_scope');const budgetCategoryField=document.getElementById('budgetCategoryField');const budgetCategory=document.getElementById('category_id');function syncBudgetScope(){const category=budgetScope?.value==='Category';budgetCategoryField.style.display=category?'':'none';budgetCategory.required=category;if(!category)budgetCategory.value='';}budgetScope?.addEventListener('change',syncBudgetScope);syncBudgetScope();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
