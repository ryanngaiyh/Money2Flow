<?php
$page_title = 'Income Sources';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/recurring_income.php';
require_once __DIR__ . '/../includes/allowance.php';

$errors = [];
$editing = null;
$formAction = 'create';
$allowedTypes = ['Recurring', 'One-time'];
$allowedFrequencies = ['Weekly', 'Monthly', 'Yearly', 'N/A'];
$allowedStatuses = ['Active', 'Inactive'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $incomeId = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $income = ownership('incomes', $incomeId);
        if ($income) {
            db()->prepare('DELETE FROM incomes WHERE id = ? AND user_id = ?')->execute([$incomeId, user_id()]);
            flash('Income source deleted successfully.');
        } else {
            flash('Income source not found.', 'danger');
        }
        redirect('income/index.php');
    }
    if (!in_array($action, ['create', 'update'], true)) {
        flash('Invalid income action.', 'danger');
        redirect('income/index.php');
    }
    if ($action === 'update' && !ownership('incomes', $incomeId)) {
        flash('Income source not found.', 'danger');
        redirect('income/index.php');
    }
    $formAction = $action;

    $source = trim($_POST['source'] ?? '');
    $incomeAmount = amount($_POST['amount'] ?? '');
    $incomeType = $_POST['income_type'] ?? '';
    $frequency = $_POST['frequency'] ?? 'N/A';
    $startDate = $_POST['start_date'] ?? '';
    $status = $_POST['status'] ?? '';
    $description = trim($_POST['description'] ?? '');

    if ($source === '' || mb_strlen($source) > 120) {
        $errors[] = 'Enter an income name of 120 characters or fewer.';
    }
    if ($incomeAmount === null) {
        $errors[] = 'Amount must be greater than RM 0.00.';
    }
    if (!in_array($incomeType, $allowedTypes, true)) {
        $errors[] = 'Select a valid income type.';
    }
    if ($incomeType === 'Recurring' && !in_array($frequency, ['Weekly', 'Monthly', 'Yearly'], true)) {
        $errors[] = 'Select a frequency for recurring income.';
    }
    if ($incomeType === 'One-time') {
        $frequency = 'N/A';
    }
    if (!in_array($frequency, $allowedFrequencies, true)) {
        $errors[] = 'Select a valid frequency.';
    }
    if (!valid_date($startDate)) {
        $errors[] = 'Select a valid start date.';
    }
    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Select a valid status.';
    }
    if (mb_strlen($description) > 500) {
        $errors[] = 'Description must be 500 characters or fewer.';
    }

    if (!$errors) {
        if ($action === 'update') {
            // Finish due work under the old terms before changing the schedule.
            try { recurring_process_source(db(), $incomeId, user_id()); }
            catch (Throwable $e) {
                error_log('Money2Flow recurring income before edit: ' . $e->getMessage());
                flash('The income source was not changed because recurring income could not be checked.', 'danger');
                redirect('income/index.php');
            }
            db()->beginTransaction();
            $lock = db()->prepare('SELECT * FROM incomes WHERE id=? AND user_id=? FOR UPDATE');
            $lock->execute([$incomeId,user_id()]);
            $previous = $lock->fetch();
            if (!$previous) { db()->rollBack(); flash('Income source not found.', 'danger'); redirect('income/index.php'); }
            $statement = db()->prepare('UPDATE incomes SET source=?, amount=?, income_type=?, frequency=?, start_date=?, status=?, income_date=?, description=? WHERE id=? AND user_id=?');
            $statement->execute([$source, $incomeAmount, $incomeType, $frequency, $startDate, $status, $startDate, $description, $incomeId, user_id()]);
            if ($previous['source'] !== $source || (float)$previous['amount'] !== (float)$incomeAmount || $previous['income_type'] !== $incomeType || $previous['frequency'] !== $frequency || $previous['start_date'] !== $startDate || $previous['status'] !== $status || $previous['description'] !== $description) {
                $state = db()->prepare('INSERT INTO recurring_income_state (income_id,user_id,effective_from) VALUES (?,?,?) ON DUPLICATE KEY UPDATE effective_from=VALUES(effective_from)');
                $state->execute([$incomeId,user_id(),date('Y-m-d')]);
            }
            // A source-based planner uses the recurring source's start date as its
            // schedule anchor. Keep that anchor aligned when the selected source is edited.
            $plannerStatement = db()->prepare("UPDATE allowance_settings SET next_allowance_date=?, updated_at=? WHERE user_id=? AND mode='Source' AND income_source_id=?");
            $plannerStatement->execute([$startDate, date('Y-m-d H:i:s'), user_id(), $incomeId]);
            db()->commit();
            flash($plannerStatement->rowCount() > 0
                ? 'Income source and next allowance date updated successfully.'
                : 'Income source updated successfully.');
        } else {
            $statement = db()->prepare('INSERT INTO incomes (user_id, source, amount, income_type, frequency, start_date, status, income_date, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([user_id(), $source, $incomeAmount, $incomeType, $frequency, $startDate, $status, $startDate, $description]);
            flash('Income source added successfully.');
        }
        redirect('income/index.php');
    }

    $editing = [
        'id' => $incomeId,
        'source' => $source,
        'amount' => $_POST['amount'] ?? '',
        'income_type' => $incomeType,
        'frequency' => $frequency,
        'start_date' => $startDate,
        'status' => $status,
        'description' => $description,
    ];
}

if (isset($_GET['edit'])) {
    $editing = ownership('incomes', (int)$_GET['edit']);
    if (!$editing) {
        flash('Income source not found.', 'warning');
        redirect('income/index.php');
    }
    $formAction = 'update';
}

$search = trim($_GET['search'] ?? '');
$typeFilter = $_GET['income_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$where = ['user_id = ?'];
$arguments = [user_id()];

if ($search !== '') {
    $where[] = '(source LIKE ? OR description LIKE ?)';
    $arguments[] = "%{$search}%";
    $arguments[] = "%{$search}%";
}
if (in_array($typeFilter, $allowedTypes, true)) {
    $where[] = 'income_type = ?';
    $arguments[] = $typeFilter;
}
if (in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = 'status = ?';
    $arguments[] = $statusFilter;
}

$statement = db()->prepare('SELECT * FROM incomes WHERE ' . implode(' AND ', $where) . ' ORDER BY status ASC, start_date DESC, id DESC');
$statement->execute($arguments);
$incomes = $statement->fetchAll();

$activeMonthlyIncome = 0.0;
foreach ($incomes as $income) {
    if ($income['status'] !== 'Active') continue;
    if ($income['income_type'] === 'One-time') continue;
    $multiplier = match ($income['frequency']) {
        'Weekly' => 52 / 12,
        'Yearly' => 1 / 12,
        default => 1,
    };
    $activeMonthlyIncome += (float)$income['amount'] * $multiplier;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="page-title mb-1">Income Sources</h1><p class="text-secondary mb-0">Manage recurring allowance and one-time income.</p></div>
    <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#incomeForm">+ Add Income</button>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6"><div class="card p-3"><div class="metric-label">Estimated recurring income / month</div><div class="metric text-success"><?= currency($activeMonthlyIncome) ?></div><small class="text-secondary">Weekly and yearly sources are converted to a monthly estimate.</small></div></div>
    <div class="col-md-6"><div class="card p-3"><div class="metric-label">Income sources shown</div><div class="metric"><?= count($incomes) ?></div><small class="text-secondary">Based on the filters below.</small></div></div>
</div>

<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>

<div class="collapse <?= $editing ? 'show' : '' ?> mb-4" id="incomeForm">
    <div class="card p-4">
        <h5 class="mb-3"><?= $formAction === 'update' ? 'Edit income source' : 'Add income source' ?></h5>
        <form method="post" class="row g-3" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="action" value="<?= e($formAction) ?>">
            <?php if ($formAction === 'update'): ?><input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>"><?php endif; ?>
            <div class="col-md-6"><label class="form-label" for="source">Income name</label><input class="form-control" id="source" name="source" maxlength="120" placeholder="e.g. Monthly Allowance" required value="<?= e($editing['source'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label" for="amount">Amount (RM)</label><input class="form-control" id="amount" name="amount" type="number" min="0.01" step="0.01" required value="<?= e($editing['amount'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label" for="income_type">Income type</label><select class="form-select" id="income_type" name="income_type" required><option value="Recurring" <?= ($editing['income_type'] ?? '') === 'Recurring' ? 'selected' : '' ?>>Recurring</option><option value="One-time" <?= ($editing['income_type'] ?? 'One-time') === 'One-time' ? 'selected' : '' ?>>One-time</option></select></div>
            <div class="col-md-3"><label class="form-label" for="frequency">Frequency</label><select class="form-select" id="frequency" name="frequency"><option value="N/A">Not applicable</option><?php foreach (['Weekly','Monthly','Yearly'] as $frequency): ?><option value="<?= e($frequency) ?>" <?= ($editing['frequency'] ?? '') === $frequency ? 'selected' : '' ?>><?= e($frequency) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="start_date">Start / received date</label><input class="form-control" id="start_date" name="start_date" type="date" required value="<?= e($editing['start_date'] ?? $editing['income_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-3"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="Active" <?= ($editing['status'] ?? 'Active') === 'Active' ? 'selected' : '' ?>>Active</option><option value="Inactive" <?= ($editing['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
            <div class="col-md-3"><label class="form-label" for="description">Description</label><input class="form-control" id="description" name="description" maxlength="500" value="<?= e($editing['description'] ?? '') ?>"></div>
            <div><button class="btn btn-primary" type="submit"><?= $formAction === 'update' ? 'Update Income' : 'Save Income' ?></button><?php if ($formAction === 'update'): ?><a class="btn btn-light" href="<?= e(app_url('income/index.php')) ?>">Cancel</a><?php endif; ?></div>
        </form>
    </div>
</div>

<div class="card p-3 mb-4">
    <form class="row g-2">
        <div class="col-md-5"><input class="form-control" name="search" placeholder="Search name or description" value="<?= e($search) ?>"></div>
        <div class="col-md-3"><select class="form-select" name="income_type"><option value="">All types</option><?php foreach ($allowedTypes as $type): ?><option value="<?= e($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><select class="form-select" name="status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $incomeStatus): ?><option value="<?= e($incomeStatus) ?>" <?= $statusFilter === $incomeStatus ? 'selected' : '' ?>><?= e($incomeStatus) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filter</button></div>
    </form>
</div>

<div class="card p-3 table-responsive">
    <table class="table align-middle mb-0"><thead><tr><th>Income</th><th>Type</th><th>Amount</th><th>Start date</th><th>Next scheduled</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($incomes as $income): ?>
    <?php
        $next = null;
        if ($income['status'] === 'Active' && $income['income_type'] === 'Recurring') {
            $today = allowance_today();
            $next = allowance_next_date($income['start_date'], $income['frequency'], $today);
            if ($next && $next->format('Y-m-d') === $today->format('Y-m-d')) {
                $check = db()->prepare("SELECT status FROM recurring_income_occurrences WHERE income_id=? AND user_id=? AND occurrence_date=?");
                $check->execute([$income['id'], user_id(), $next->format('Y-m-d')]);
                if (in_array($check->fetchColumn(), ['generated','matched'], true)) $next = allowance_next_date($income['start_date'], $income['frequency'], $today->modify('+1 day'));
            }
        }
    ?>
    <tr><td><strong><?= e($income['source']) ?></strong><br><small class="text-secondary"><?= e($income['description']) ?></small></td><td><span class="badge text-bg-light"><?= e($income['income_type']) ?></span><?php if ($income['income_type'] === 'Recurring'): ?><br><small class="text-secondary"><?= e($income['frequency']) ?></small><?php endif; ?></td><td class="text-success fw-bold"><?= currency($income['amount']) ?></td><td><?= e($income['start_date']) ?></td><td><?= $next ? e($next->format('Y-m-d')) : '—' ?></td><td><span class="badge <?= $income['status']==='Active'?'text-bg-success':'text-bg-secondary' ?>"><?= e($income['status']) ?></span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$income['id'] ?>#incomeForm">Edit</a> <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$income['id'] ?>"><button data-confirm="Delete this income source?" class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr><?php endforeach; ?>
    <?php if (!$incomes): ?><tr><td colspan="7" class="text-center text-secondary py-5"><strong>No income sources found.</strong><br>Add allowance, part-time work, scholarship, or another source.</td></tr><?php endif; ?>
    </tbody></table>
</div>

<script>
const typeField = document.getElementById('income_type');
const frequencyField = document.getElementById('frequency');
function syncFrequency(){const recurring=typeField?.value==='Recurring';frequencyField.disabled=!recurring;if(!recurring)frequencyField.value='N/A';}
typeField?.addEventListener('change',syncFrequency);syncFrequency();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
