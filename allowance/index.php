<?php
$page_title = 'Until Next Allowance';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/allowance.php';

$errors = [];
$editing = null;
$now = allowance_today();
$sourceStatement = db()->prepare("SELECT id,source,amount,frequency,start_date FROM incomes WHERE user_id=? AND income_type='Recurring' AND status='Active' ORDER BY source,id");
$sourceStatement->execute([user_id()]);
$sources = $sourceStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $commitmentId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    if ($action === 'save_settings') {
        $mode = $_POST['mode'] ?? '';
        $sourceId = filter_var($_POST['income_source_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $manualAmount = amount($_POST['manual_amount'] ?? '');
        $frequency = $_POST['frequency'] ?? '';
        $nextDate = $_POST['next_allowance_date'] ?? '';
        $openingInput = trim((string)($_POST['opening_balance'] ?? ''));
        $reserveInput = trim((string)($_POST['reserved_savings'] ?? ''));
        $opening = filter_var($openingInput, FILTER_VALIDATE_FLOAT);
        $reserve = filter_var($reserveInput, FILTER_VALIDATE_FLOAT);
        if (!in_array($mode, ['Source', 'Manual'], true)) $errors[] = 'Choose an allowance mode.';
        if ($mode === 'Source' && !array_filter($sources, static fn($row) => (int)$row['id'] === $sourceId)) $errors[] = 'Choose one of your active recurring income sources.';
        if ($mode === 'Manual' && ($manualAmount === null || $manualAmount < 0.01 || $manualAmount > 9999999999.99)) $errors[] = 'Enter a valid allowance amount.';
        if ($mode === 'Manual' && !in_array($frequency, ['Weekly', 'Monthly', 'Yearly', 'Irregular'], true)) $errors[] = 'Choose a valid frequency.';
        if (!valid_date($nextDate)) $errors[] = 'Enter a valid next allowance date.';
        if ($opening === false || !is_finite($opening) || abs($opening) > 9999999999.99) $errors[] = 'Enter a valid starting cash adjustment.';
        if ($reserve === false || !is_finite($reserve) || $reserve < 0 || $reserve > 9999999999.99) $errors[] = 'Enter a valid, non-negative savings reserve.';
        if (!$errors) {
            $statement = db()->prepare('INSERT INTO allowance_settings (user_id,mode,income_source_id,manual_amount,frequency,next_allowance_date,opening_balance,reserved_savings,updated_at) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode),income_source_id=VALUES(income_source_id),manual_amount=VALUES(manual_amount),frequency=VALUES(frequency),next_allowance_date=VALUES(next_allowance_date),opening_balance=VALUES(opening_balance),reserved_savings=VALUES(reserved_savings),updated_at=VALUES(updated_at)');
            $statement->execute([user_id(), $mode, $mode === 'Source' ? $sourceId : null, $mode === 'Manual' ? $manualAmount : null, $mode === 'Manual' ? $frequency : 'Irregular', $nextDate, round($opening, 2), round($reserve, 2), $now->format('Y-m-d H:i:s')]);
            flash('Allowance planner updated. Expected income is not added to your balance until you record it as a transaction.');
            redirect('allowance/index.php');
        }
    } elseif (in_array($action, ['add_commitment', 'update_commitment'], true)) {
        $title = trim((string)($_POST['title'] ?? ''));
        $value = amount($_POST['amount'] ?? '');
        $due = $_POST['due_date'] ?? '';
        if ($title === '' || mb_strlen($title) > 120) $errors[] = 'Enter a commitment name of 120 characters or fewer.';
        if ($value === null || $value < 0.01 || $value > 9999999999.99) $errors[] = 'Enter a valid amount above RM 0.00.';
        if (!valid_date($due)) $errors[] = 'Enter a valid due date.';
        if ($action === 'update_commitment') {
            $ownedCommitment = ownership('allowance_commitments', $commitmentId);
            if (!$ownedCommitment || $ownedCommitment['paid_at'] !== null) $errors[] = 'Pending commitment not found.';
        }
        if (!$errors) {
            if ($action === 'add_commitment') {
                db()->prepare('INSERT INTO allowance_commitments (user_id,title,amount,due_date,created_at) VALUES (?,?,?,?,?)')->execute([user_id(), $title, $value, $due, $now->format('Y-m-d H:i:s')]);
                flash('Commitment added.');
            } else {
                db()->prepare('UPDATE allowance_commitments SET title=?,amount=?,due_date=? WHERE id=? AND user_id=? AND paid_at IS NULL')->execute([$title, $value, $due, $commitmentId, user_id()]);
                flash('Commitment updated.');
            }
            redirect('allowance/index.php#commitments');
        }
        $editing = ['id' => $commitmentId, 'title' => $title, 'amount' => $_POST['amount'] ?? '', 'due_date' => $due];
    } elseif (in_array($action, ['settle_commitment', 'reopen_commitment', 'delete_commitment'], true)) {
        $owned = ownership('allowance_commitments', $commitmentId);
        if (!$owned) flash('Commitment not found.', 'danger');
        elseif ($action === 'delete_commitment') {
            db()->prepare('DELETE FROM allowance_commitments WHERE id=? AND user_id=?')->execute([$commitmentId, user_id()]);
            flash('Commitment deleted.');
        } elseif ($action === 'settle_commitment') {
            db()->prepare('UPDATE allowance_commitments SET paid_at=? WHERE id=? AND user_id=? AND paid_at IS NULL')->execute([$now->format('Y-m-d H:i:s'), $commitmentId, user_id()]);
            flash('Commitment marked settled. If you paid it, record the expense separately so your balance remains accurate.');
        } else {
            db()->prepare('UPDATE allowance_commitments SET paid_at=NULL WHERE id=? AND user_id=?')->execute([$commitmentId, user_id()]);
            flash('Commitment reopened.');
        }
        redirect('allowance/index.php#commitments');
    } else {
        flash('Invalid planner action.', 'danger');
        redirect('allowance/index.php');
    }
}

if (isset($_GET['edit']) && $editing === null) {
    $editing = ownership('allowance_commitments', (int)$_GET['edit']);
    if (!$editing || $editing['paid_at'] !== null) {
        flash('Pending commitment not found.', 'warning');
        redirect('allowance/index.php#commitments');
    }
}
$plan = allowance_plan(user_id());
$settings = $plan['settings'] ?? [];
$commitmentStatement = db()->prepare('SELECT * FROM allowance_commitments WHERE user_id=? ORDER BY paid_at IS NOT NULL,due_date,id');
$commitmentStatement->execute([user_id()]);
$commitments = $commitmentStatement->fetchAll();
$modeValue = $_POST['mode'] ?? ($settings['mode'] ?? 'Source');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4"><div><h1 class="page-title mb-1">Until Next Allowance</h1><p class="text-secondary mb-0">A daily estimate based on recorded money, upcoming commitments and money you plan to reserve.</p></div><div class="d-flex flex-wrap gap-2"><a class="btn btn-primary" href="<?= e(app_url('simulator/index.php')) ?>">Can I Afford It?</a><a class="btn btn-outline-primary" href="<?= e(app_url('income/index.php')) ?>">Manage Income Sources</a></div></div>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-7"><div class="card allowance-result p-4 h-100">
        <div class="d-flex justify-content-between align-items-start gap-2"><div><span class="dashboard-eyebrow">UNTIL NEXT ALLOWANCE</span><h2 class="h4 mb-1"><?= $plan['state']==='ready' ? e($plan['next_date']->format('j M Y')) : 'Set your next date' ?></h2><p class="text-secondary mb-3"><?= e(allowance_guidance($plan)) ?></p></div><span class="badge text-bg-light"><?= $plan['state']==='ready' ? ($plan['days']===0 ? 'Due today' : $plan['days'].' days remaining') : 'Setup needed' ?></span></div>
        <?php if (in_array($plan['state'], ['ready','date_missing'], true)): ?>
        <div class="allowance-figure"><?= $plan['daily_cents'] === null ? '—' : currency($plan['daily_cents']/100) ?><span> / day estimated</span></div>
        <div class="row g-2 mt-2 allowance-breakdown"><div class="col-6 col-md-3"><small>Recorded funds</small><strong><?= currency($plan['balance_cents']/100) ?></strong></div><div class="col-6 col-md-3"><small>Unpaid commitments</small><strong><?= currency($plan['commitments_cents']/100) ?></strong></div><div class="col-6 col-md-3"><small>Reserved savings</small><strong><?= currency($plan['reserved_cents']/100) ?></strong></div><div class="col-6 col-md-3"><small>Available to spend</small><strong class="<?= $plan['available_cents']<0?'text-danger':'' ?>"><?= currency($plan['available_cents']/100) ?></strong></div></div>
        <?php if ($plan['state']==='ready'): $progress = $plan['balance_cents'] > 0 ? max(0,min(100,(int)round($plan['available_cents']/$plan['balance_cents']*100))) : 0; ?><div class="d-flex justify-content-between small text-secondary mt-4"><span>Discretionary share of recorded funds</span><span><?= $progress ?>%</span></div><div class="progress mt-2" role="progressbar" aria-label="Discretionary share of recorded funds" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width:<?= $progress ?>%"></div></div><?php endif; ?>
        <p class="small text-secondary mt-3 mb-0">Estimate only. Expected allowance is not cash until you record its arrival. Paid commitments and savings already reflected in your recorded balance should not be reserved again.</p>
        <?php endif; ?>
    </div></div>
    <div class="col-lg-5"><div class="card p-4 h-100"><h2 class="h5 mb-3">Allowance settings</h2>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="save_settings">
            <label class="form-label" for="allowanceMode">Allowance type</label><select class="form-select mb-3" id="allowanceMode" name="mode"><option value="Source" <?= $modeValue==='Source'?'selected':'' ?>>Use an existing recurring Income Source</option><option value="Manual" <?= $modeValue==='Manual'?'selected':'' ?>>Enter allowance details here</option></select>
            <div id="sourceFields"><label class="form-label" for="incomeSource">Income Source</label><select class="form-select mb-3" id="incomeSource" name="income_source_id"><option value="">Select source</option><?php foreach ($sources as $source): ?><option value="<?= (int)$source['id'] ?>" <?= (int)($_POST['income_source_id']??($settings['income_source_id']??0))===(int)$source['id']?'selected':'' ?>><?= e($source['source']) ?> · <?= currency($source['amount']) ?> / <?= e(strtolower($source['frequency'])) ?></option><?php endforeach; ?></select><?php if (!$sources): ?><p class="small text-secondary">No active recurring source yet. Add one in Income Sources or choose manual.</p><?php endif; ?></div>
            <div id="manualFields"><div class="row g-2 mb-3"><div class="col-6"><label class="form-label" for="manualAmount">Allowance amount (RM)</label><input class="form-control" id="manualAmount" name="manual_amount" type="number" min="0.01" max="9999999999.99" step="0.01" value="<?= e((string)($_POST['manual_amount']??($settings['manual_amount']??''))) ?>"></div><div class="col-6"><label class="form-label" for="frequency">Frequency</label><select class="form-select" id="frequency" name="frequency"><?php foreach (['Weekly','Monthly','Yearly','Irregular'] as $item): ?><option value="<?= $item ?>" <?= ($_POST['frequency']??($settings['frequency']??'Irregular'))===$item?'selected':'' ?>><?= $item ?></option><?php endforeach; ?></select></div></div></div>
            <label class="form-label" for="nextDate">Next allowance date</label><input class="form-control mb-1" id="nextDate" name="next_allowance_date" type="date" required value="<?= e((string)($_POST['next_allowance_date']??($settings['next_allowance_date']??''))) ?>"><p class="small text-secondary">Recurring schedules roll forward automatically. For irregular income, update this date yourself.</p>
            <div class="row g-2"><div class="col-6"><label class="form-label" for="openingBalance">Starting cash adjustment (RM)</label><input class="form-control" id="openingBalance" name="opening_balance" type="number" step="0.01" min="-9999999999.99" max="9999999999.99" value="<?= e((string)($_POST['opening_balance']??($settings['opening_balance']??'0'))) ?>"></div><div class="col-6"><label class="form-label" for="reservedSavings">Savings to set aside (RM)</label><input class="form-control" id="reservedSavings" name="reserved_savings" type="number" step="0.01" min="0" max="9999999999.99" value="<?= e((string)($_POST['reserved_savings']??($settings['reserved_savings']??'0'))) ?>"></div></div>
            <p class="small text-secondary mt-2">Starting cash adjusts incomplete transaction history. Savings reserve is only money you still intend to set aside; do not include transfers already recorded in your balance.</p><button class="btn btn-primary">Save settings</button>
        </form>
    </div></div>
</div>

<div class="card p-4" id="commitments"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">Upcoming commitments</h2><p class="small text-secondary mb-0">Unpaid items due before your next allowance reduce the estimate. Past-due unpaid items remain included.</p></div></div>
    <form method="post" class="row g-2 align-items-end mb-4"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="<?= $editing?'update_commitment':'add_commitment' ?>"><?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?><div class="col-md-5"><label class="form-label" for="commitmentTitle">Commitment</label><input class="form-control" id="commitmentTitle" name="title" maxlength="120" required placeholder="Rent, bill or fee" value="<?= e((string)($editing['title']??'')) ?>"></div><div class="col-md-2"><label class="form-label" for="commitmentAmount">Amount (RM)</label><input class="form-control" id="commitmentAmount" name="amount" type="number" step="0.01" min="0.01" required value="<?= e((string)($editing['amount']??'')) ?>"></div><div class="col-md-3"><label class="form-label" for="commitmentDue">Due date</label><input class="form-control" id="commitmentDue" name="due_date" type="date" required value="<?= e((string)($editing['due_date']??'')) ?>"></div><div class="col-md-2 d-grid"><button class="btn btn-primary"><?= $editing?'Save changes':'Add commitment' ?></button></div><?php if ($editing): ?><div class="col-12"><a href="<?= e(app_url('allowance/index.php#commitments')) ?>">Cancel edit</a></div><?php endif; ?></form>
    <?php if (!$commitments): ?><div class="empty-state"><strong>No commitments yet</strong><span>Add payments you expect before the next allowance.</span></div><?php else: ?><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Commitment</th><th>Due</th><th>Amount</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody><?php foreach ($commitments as $item): ?><tr><td><?= e($item['title']) ?></td><td><?= e($item['due_date']) ?></td><td><?= currency($item['amount']) ?></td><td><span class="badge <?= $item['paid_at']===null?'text-bg-warning':'text-bg-success' ?>"><?= $item['paid_at']===null?'Unpaid':'Settled' ?></span></td><td><div class="d-flex justify-content-end gap-1 flex-wrap"><?php if ($item['paid_at']===null): ?><a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$item['id'] ?>#commitments">Edit</a><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="action" value="<?= $item['paid_at']===null?'settle_commitment':'reopen_commitment' ?>"><button class="btn btn-sm btn-outline-secondary" <?= $item['paid_at']===null?'title="Record any paid expense as a transaction too"':'' ?>><?= $item['paid_at']===null?'Mark settled':'Reopen' ?></button></form><form method="post" onsubmit="return confirm('Delete this commitment?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="action" value="delete_commitment"><button class="btn btn-sm btn-outline-danger">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <p class="small text-secondary mb-0">Mark an item settled after recording its expense, or if you no longer owe it. The planner never creates a transaction automatically.</p>
</div>
<script>const allowanceMode=document.getElementById('allowanceMode');function toggleAllowanceMode(){const source=allowanceMode.value==='Source';document.getElementById('sourceFields').hidden=!source;document.getElementById('manualFields').hidden=source}allowanceMode.addEventListener('change',toggleAllowanceMode);toggleAllowanceMode();</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
