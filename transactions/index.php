<?php
$page_title = 'Transactions';
require_once __DIR__ . '/../includes/notifications.php';
require_login();
require_once __DIR__ . '/../includes/recurring_income.php';
recurring_process_user(user_id());
if (empty($_SESSION['transaction_create_token'])) {
    $_SESSION['transaction_create_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$editing = null;
$allowedTypes = ['Income', 'Expense'];
$categoryRows = categories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $transactionId = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $transaction = ownership('transactions', $transactionId);
        if ($transaction) {
            db()->prepare('DELETE FROM transactions WHERE id = ? AND user_id = ?')->execute([$transactionId, user_id()]);
            if ($transaction['type'] === 'Expense') notification_check_budget_month(substr($transaction['transaction_date'], 0, 7));
            flash('Transaction deleted successfully.');
        } else {
            flash('Transaction not found.', 'danger');
        }
        redirect('transactions/index.php');
    }
    if (!in_array($action, ['create', 'update'], true)) {
        flash('Invalid transaction action.', 'danger');
        redirect('transactions/index.php');
    }
    $submittedToken = $_POST['submission_token'] ?? '';
    if ($action === 'create' && (!is_string($submittedToken) || !hash_equals($_SESSION['transaction_create_token'], $submittedToken))) {
        flash('This transaction form has already been submitted. Open Add Transaction again to make a new entry.', 'warning');
        redirect('transactions/index.php');
    }
    $previousTransaction = $action === 'update' ? ownership('transactions', $transactionId) : null;
    if ($action === 'update' && !$previousTransaction) {
        flash('Transaction not found.', 'danger');
        redirect('transactions/index.php');
    }

    $type = $_POST['type'] ?? '';
    $transactionAmount = amount($_POST['amount'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $merchant = trim($_POST['merchant'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $transactionDate = $_POST['transaction_date'] ?? '';
    $rememberCategory = !empty($_POST['remember_category']);

    if ($type === 'Expense' && !$categoryId && $merchant !== '') {
        $suggestion = suggested_category($merchant);
        if ($suggestion) $categoryId = (int)$suggestion['category_id'];
    }

    if (!in_array($type, $allowedTypes, true)) {
        $errors[] = 'Select Income or Expense.';
    }
    if ($transactionAmount === null || $transactionAmount > 9999999999.99) {
        $errors[] = 'Enter a valid amount greater than RM 0.00.';
    }
    if ($type === 'Expense' && !$categoryId) {
        $errors[] = 'Select a category for this expense.';
    }
    if ($type === 'Expense' && $categoryId && !ownership('categories', $categoryId)) {
        $errors[] = 'Select one of your own categories.';
    }
    if ($type === 'Income') {
        $categoryId = 0;
    }
    if (!valid_date($transactionDate)) {
        $errors[] = 'Select a valid transaction date.';
    }
    if (mb_strlen($merchant) > 120) {
        $errors[] = 'Merchant or payer must be 120 characters or fewer.';
    }
    if (mb_strlen($description) > 500) {
        $errors[] = 'Description must be 500 characters or fewer.';
    }
    if ($type === 'Expense' && $rememberCategory && $merchant === '') {
        $errors[] = 'Enter a merchant name before remembering a category rule.';
    }

    if (!$errors) {
        $categoryValue = $categoryId ?: null;
        if ($action === 'update') {
            $statement = db()->prepare('UPDATE transactions SET category_id=?, type=?, amount=?, description=?, merchant=?, transaction_date=? WHERE id=? AND user_id=?');
            $statement->execute([$categoryValue, $type, $transactionAmount, $description, $merchant, $transactionDate, $transactionId, user_id()]);
            $successMessage = 'Transaction updated successfully.';
        } else {
            $statement = db()->prepare('INSERT INTO transactions (user_id, category_id, type, amount, description, merchant, transaction_date, source) VALUES (?, ?, ?, ?, ?, ?, ?, \'Manual\')');
            $statement->execute([user_id(), $categoryValue, $type, $transactionAmount, $description, $merchant, $transactionDate]);
            unset($_SESSION['transaction_create_token']);
            $successMessage = 'Transaction added successfully.';
        }
        if ($type === 'Expense' && $rememberCategory && $merchant !== '') {
            $merchantKeyword = preg_replace('/\s+/u', ' ', trim($merchant));
            $statement = db()->prepare('INSERT INTO category_rules (user_id,merchant_keyword,category_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id)');
            $statement->execute([user_id(), $merchantKeyword, $categoryId]);
            $successMessage .= ' Category rule remembered for ' . $merchantKeyword . '.';
        }
        if ($previousTransaction && $previousTransaction['type'] === 'Expense') notification_check_budget_month(substr($previousTransaction['transaction_date'], 0, 7));
        if ($type === 'Expense') notification_check_budget_month(substr($transactionDate, 0, 7));
        flash($successMessage);
        redirect('transactions/index.php');
    }

    $editing = [
        'id' => $transactionId,
        'type' => $type,
        'amount' => $_POST['amount'] ?? '',
        'category_id' => $categoryId,
        'merchant' => $merchant,
        'description' => $description,
        'transaction_date' => $transactionDate,
        'remember_category' => $rememberCategory,
    ];
}

if (isset($_GET['edit'])) {
    $editing = ownership('transactions', (int)$_GET['edit']);
    if (!$editing) {
        flash('Transaction not found.', 'warning');
        redirect('transactions/index.php');
    }
}

$search = trim($_GET['search'] ?? '');
$typeFilter = $_GET['type'] ?? '';
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';
$where = ['t.user_id = ?'];
$arguments = [user_id()];

if ($search !== '') {
    $where[] = '(t.merchant LIKE ? OR t.description LIKE ?)';
    $arguments[] = "%{$search}%";
    $arguments[] = "%{$search}%";
}
if (in_array($typeFilter, $allowedTypes, true)) {
    $where[] = 't.type = ?';
    $arguments[] = $typeFilter;
}
if ($categoryFilter && ownership('categories', $categoryFilter)) {
    $where[] = 't.category_id = ?';
    $arguments[] = $categoryFilter;
}
if ($dateFrom !== '' && valid_date($dateFrom)) {
    $where[] = 't.transaction_date >= ?';
    $arguments[] = $dateFrom;
}
if ($dateTo !== '' && valid_date($dateTo)) {
    $where[] = 't.transaction_date <= ?';
    $arguments[] = $dateTo;
}

$statement = db()->prepare('SELECT t.*, c.name category_name FROM transactions t LEFT JOIN categories c ON c.id=t.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.transaction_date DESC, t.id DESC');
$statement->execute($arguments);
$transactions = $statement->fetchAll();

$summaryStart = date('Y-m-01');
$summaryEnd = date('Y-m-d', strtotime($summaryStart . ' +1 month'));
$statement = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='Income' THEN amount ELSE 0 END),0) total_income, COALESCE(SUM(CASE WHEN type='Expense' THEN amount ELSE 0 END),0) total_expenses FROM transactions WHERE user_id=? AND transaction_date>=? AND transaction_date<?");
$statement->execute([user_id(), $summaryStart, $summaryEnd]);
$totals = $statement->fetch();
$totalIncome = (float)$totals['total_income'];
$totalExpenses = (float)$totals['total_expenses'];
$formIsEdit = !empty($editing['id']);
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="page-title mb-1">Transactions</h1><p class="text-secondary mb-0">Record money received and money spent.</p></div>
    <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="<?= e(app_url('receipts/index.php')) ?>">▣ Scan Receipt</a><a class="btn btn-outline-secondary" href="<?= e(app_url('income/index.php')) ?>">Manage Income Sources</a><button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#transactionForm">+ Add Transaction</button></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card p-3"><div class="metric-label">This month income</div><div class="metric text-success"><?= currency($totalIncome) ?></div></div></div>
    <div class="col-md-4"><div class="card p-3"><div class="metric-label">This month expenses</div><div class="metric text-danger"><?= currency($totalExpenses) ?></div></div></div>
    <div class="col-md-4"><div class="card p-3"><div class="metric-label">This month balance</div><div class="metric <?= $totalIncome-$totalExpenses < 0 ? 'text-danger' : 'accent' ?>"><?= currency($totalIncome-$totalExpenses) ?></div></div></div>
</div>

<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>

<div class="collapse <?= $editing ? 'show' : '' ?> mb-4" id="transactionForm">
    <div class="card p-4">
        <h5 class="mb-3"><?= $formIsEdit ? 'Edit transaction' : 'Add transaction' ?></h5>
        <form method="post" class="row g-3" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="action" value="<?= $formIsEdit ? 'update' : 'create' ?>">
            <?php if (!$formIsEdit): ?><input type="hidden" name="submission_token" value="<?= e($_SESSION['transaction_create_token']) ?>"><?php endif; ?>
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <div class="col-md-3"><label class="form-label" for="type">Type</label><select class="form-select" id="type" name="type" required><option value="Expense" <?= ($editing['type'] ?? 'Expense') === 'Expense' ? 'selected' : '' ?>>Expense</option><option value="Income" <?= ($editing['type'] ?? '') === 'Income' ? 'selected' : '' ?>>Income</option></select></div>
            <div class="col-md-3"><label class="form-label" for="amount">Amount (RM)</label><input class="form-control" id="amount" name="amount" type="number" min="0.01" max="9999999999.99" step="0.01" required value="<?= e($editing['amount'] ?? '') ?>"></div>
            <div class="col-md-3" id="categoryField"><label class="form-label" for="category_id">Category</label><select class="form-select" id="category_id" name="category_id"><option value="">Select category</option><?php foreach ($categoryRows as $category): ?><option value="<?= (int)$category['id'] ?>" <?= (int)($editing['category_id'] ?? 0)===(int)$category['id']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="transaction_date">Date</label><input class="form-control" id="transaction_date" name="transaction_date" type="date" required value="<?= e($editing['transaction_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-4"><label class="form-label" for="merchant">Merchant / payer</label><input class="form-control" id="merchant" name="merchant" maxlength="120" autocomplete="organization" placeholder="e.g. Starbucks" value="<?= e($editing['merchant'] ?? '') ?>"><div class="category-suggestion mt-2" id="categorySuggestion" hidden></div></div>
            <div class="col-md-8"><label class="form-label" for="description">Description</label><input class="form-control" id="description" name="description" maxlength="500" placeholder="What was this transaction for?" value="<?= e($editing['description'] ?? '') ?>"></div>
            <div class="col-12" id="rememberRuleField"><div class="form-check"><input class="form-check-input" id="remember_category" name="remember_category" type="checkbox" value="1" <?= !empty($editing['remember_category'])?'checked':'' ?>><label class="form-check-label" for="remember_category">Remember this merchant and category for future expenses</label></div></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $formIsEdit ? 'Update Transaction' : 'Save Transaction' ?></button><?php if ($formIsEdit): ?><a class="btn btn-light" href="<?= e(app_url('transactions/index.php')) ?>">Cancel</a><?php endif; ?><span class="align-self-center small text-secondary">Source: Manual</span></div>
        </form>
    </div>
</div>

<div class="card p-3 mb-4">
    <form class="row g-2">
        <div class="col-lg-3"><input class="form-control" name="search" placeholder="Search merchant or description" value="<?= e($search) ?>"></div>
        <div class="col-lg-2"><select class="form-select" name="type"><option value="">All types</option><?php foreach ($allowedTypes as $filterType): ?><option value="<?= e($filterType) ?>" <?= $typeFilter===$filterType?'selected':'' ?>><?= e($filterType) ?></option><?php endforeach; ?></select></div>
        <div class="col-lg-2"><select class="form-select" name="category_id"><option value="">All categories</option><?php foreach ($categoryRows as $category): ?><option value="<?= (int)$category['id'] ?>" <?= $categoryFilter===(int)$category['id']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-lg-2"><input class="form-control" name="from" type="date" aria-label="From date" value="<?= e($dateFrom) ?>"></div>
        <div class="col-lg-2"><input class="form-control" name="to" type="date" aria-label="To date" value="<?= e($dateTo) ?>"></div>
        <div class="col-lg-1"><button class="btn btn-outline-secondary w-100">Filter</button></div>
    </form>
</div>

<div class="card p-3 table-responsive">
    <table class="table align-middle mb-0"><thead><tr><th>Date</th><th>Merchant / description</th><th>Category</th><th>Type</th><th>Amount</th><th></th></tr></thead><tbody>
    <?php foreach ($transactions as $transaction): ?><tr><td><?= e($transaction['transaction_date']) ?></td><td><strong><?= e($transaction['merchant'] ?: 'No merchant') ?></strong><br><small class="text-secondary"><?= e($transaction['description']) ?></small></td><td><?= $transaction['category_name'] ? '<span class="badge text-bg-light">'.e($transaction['category_name']).'</span>' : '—' ?></td><td><span class="badge <?= $transaction['type']==='Income'?'text-bg-success':'text-bg-danger' ?>"><?= e($transaction['type']) ?></span><?php if ($transaction['source']==='Receipt'): ?><br><small class="text-primary">Receipt OCR</small><?php endif; ?></td><td class="fw-bold <?= $transaction['type']==='Income'?'text-success':'text-danger' ?>"><?= $transaction['type']==='Income'?'+':'-' ?><?= currency($transaction['amount']) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$transaction['id'] ?>#transactionForm">Edit</a> <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$transaction['id'] ?>"><button data-confirm="Delete this transaction?" class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr><?php endforeach; ?>
    <?php if (!$transactions): ?><tr><td colspan="6" class="text-center text-secondary py-5"><strong>No transactions found.</strong><br>Add a transaction or clear the current filters.</td></tr><?php endif; ?>
    </tbody></table>
</div>

<script>
const transactionType=document.getElementById('type');const categoryField=document.getElementById('categoryField');const categorySelect=document.getElementById('category_id');const merchantInput=document.getElementById('merchant');const suggestionBox=document.getElementById('categorySuggestion');const rememberRuleField=document.getElementById('rememberRuleField');const suggestionUrl=<?= json_encode(app_url('transactions/suggest-category.php')) ?>;let suggestionTimer=0,requestNumber=0;
function syncCategory(suggest=false){const expense=transactionType?.value==='Expense';categoryField.style.display=expense?'':'none';rememberRuleField.style.display=expense?'':'none';categorySelect.required=expense;if(!expense){categorySelect.value='';suggestionBox.hidden=true;}else if(suggest){requestSuggestion();}}
async function requestSuggestion(){clearTimeout(suggestionTimer);const merchant=merchantInput.value.trim(),currentRequest=++requestNumber;if(transactionType.value!=='Expense'||merchant.length<2){suggestionBox.hidden=true;return;}suggestionBox.hidden=false;suggestionBox.className='category-suggestion mt-2 loading';suggestionBox.textContent='Checking saved merchant rules…';suggestionTimer=setTimeout(async()=>{try{const response=await fetch(suggestionUrl+'?merchant='+encodeURIComponent(merchant),{headers:{Accept:'application/json'}});const data=await response.json();if(currentRequest!==requestNumber)return;if(data.match){categorySelect.value=String(data.category_id);suggestionBox.className='category-suggestion mt-2 matched';suggestionBox.textContent=`Suggested: ${data.category_name} · You can still change it.`;}else{suggestionBox.className='category-suggestion mt-2';suggestionBox.textContent='No saved rule found. Choose a category manually.';}}catch(error){if(currentRequest===requestNumber)suggestionBox.hidden=true;}},250);}
merchantInput?.addEventListener('input',requestSuggestion);categorySelect?.addEventListener('change',()=>{if(!suggestionBox.hidden){suggestionBox.className='category-suggestion mt-2 manual';suggestionBox.textContent='Manual category selected. This choice will be used when you save.';}});transactionType?.addEventListener('change',()=>syncCategory(true));syncCategory();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
