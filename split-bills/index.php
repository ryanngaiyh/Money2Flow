<?php
$page_title = 'Split Bills';
// Handle all POST actions before rendering the shared header. Redirects send
// HTTP headers, which PHP cannot do after the sidebar/topbar has produced HTML.
require_once __DIR__ . '/../includes/auth.php';
require_login();

$errors = [];
$shareErrors = [];
$allowedSplitTypes = ['Equal', 'Custom'];

/** Parse a positive decimal amount exactly into integer sen. */
function split_amount_cents($value): ?int
{
    if (!is_string($value)) return null;
    $value = trim($value);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) return null;
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $whole = ltrim($whole, '0');
    if (strlen($whole) > 10) return null;
    $cents = (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
    return $cents >= 1 && $cents <= 999999999999 ? $cents : null;
}

function split_decimal(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function owned_split_bill(int $billId): ?array
{
    $statement = db()->prepare('SELECT * FROM split_bills WHERE split_bill_id=? AND user_id=?');
    $statement->execute([$billId, user_id()]);
    return $statement->fetch() ?: null;
}

$form = [
    'description' => '',
    'total_amount' => '',
    'bill_date' => date('Y-m-d'),
    'split_type' => 'Equal',
    'payer_index' => 0,
    'participant_names' => ['You', ''],
    'participant_shares' => ['', ''],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $billId = (int)($_POST['split_bill_id'] ?? 0);

    if ($action === 'delete') {
        if (owned_split_bill($billId)) {
            db()->prepare('DELETE FROM split_bills WHERE split_bill_id=? AND user_id=?')->execute([$billId, user_id()]);
            flash('Split bill deleted successfully.');
        } else {
            flash('Split bill not found.', 'danger');
        }
        redirect('split-bills/index.php');
    }

    if ($action === 'settle' || $action === 'reopen') {
        $participantId = (int)($_POST['participant_id'] ?? 0);
        $statement = db()->prepare('SELECT p.* FROM split_bill_participants p JOIN split_bills b ON b.split_bill_id=p.split_bill_id WHERE p.participant_id=? AND b.user_id=?');
        $statement->execute([$participantId, user_id()]);
        $participant = $statement->fetch();
        if (!$participant) {
            flash('Participant not found.', 'danger');
        } elseif ((int)$participant['is_payer'] === 1) {
            flash('The payer is already recorded as paid.', 'warning');
        } elseif ($action === 'settle') {
            db()->prepare("UPDATE split_bill_participants SET status='Paid', paid_at=NOW() WHERE participant_id=?")->execute([$participantId]);
            flash($participant['name'] . ' marked as settled.');
        } else {
            db()->prepare("UPDATE split_bill_participants SET status='Pending', paid_at=NULL WHERE participant_id=?")->execute([$participantId]);
            flash($participant['name'] . ' marked as pending.');
        }
        redirect('split-bills/index.php');
    }

    $form = [
        'description' => trim($_POST['description'] ?? ''),
        'total_amount' => trim((string)($_POST['total_amount'] ?? '')),
        'bill_date' => trim($_POST['bill_date'] ?? ''),
        'split_type' => $_POST['split_type'] ?? '',
        'payer_index' => (int)($_POST['payer_index'] ?? -1),
        'participant_names' => is_array($_POST['participant_names'] ?? null) ? array_values($_POST['participant_names']) : [],
        'participant_shares' => is_array($_POST['participant_shares'] ?? null) ? array_values($_POST['participant_shares']) : [],
    ];

    $totalCents = split_amount_cents($form['total_amount']);
    if ($form['description'] === '' || mb_strlen($form['description']) > 180) $errors[] = 'Description is required and must be 180 characters or fewer.';
    if ($totalCents === null) $errors[] = 'Total amount must be between RM 0.01 and RM 9,999,999,999.99, with at most two decimal places.';
    if (!valid_date($form['bill_date'])) $errors[] = 'Select a valid bill date.';
    if (!in_array($form['split_type'], $allowedSplitTypes, true)) $errors[] = 'Select an equal or custom split.';

    $participantNames = [];
    foreach ($form['participant_names'] as $index => $name) {
        $cleanName = $index === 0 ? 'You' : trim((string)$name);
        if ($cleanName === '' || mb_strlen($cleanName) > 80) $errors[] = 'Every participant needs a name of 80 characters or fewer.';
        $participantNames[] = $cleanName;
    }
    $participantCount = count($participantNames);
    if ($participantCount < 2 || $participantCount > 20) $errors[] = 'Add between 2 and 20 participants, including you.';
    $uniqueNames = array_unique(array_map(fn($name) => mb_strtolower($name), $participantNames));
    if (count($uniqueNames) !== $participantCount) $errors[] = 'Participant names must be different.';
    if ($form['payer_index'] < 0 || $form['payer_index'] >= $participantCount) $errors[] = 'Select who paid the bill.';

    $sharesInCents = [];
    if ($totalCents !== null && $participantCount >= 2) {
        if ($form['split_type'] === 'Equal') {
            $baseShare = intdiv($totalCents, $participantCount);
            $extraCents = $totalCents % $participantCount;
            for ($index = 0; $index < $participantCount; $index++) $sharesInCents[] = $baseShare + ($index < $extraCents ? 1 : 0);
        } elseif ($form['split_type'] === 'Custom') {
            if (count($form['participant_shares']) !== $participantCount) {
                $errors[] = 'Enter a custom share for every participant.';
            } else {
                foreach ($form['participant_shares'] as $index => $share) {
                    $shareCents = split_amount_cents($share);
                    if ($shareCents === null) {
                        $shareErrors[$index] = 'Enter an amount greater than RM 0.00 with at most two decimal places.';
                    } else {
                        $sharesInCents[] = $shareCents;
                    }
                }
                if ($shareErrors) $errors[] = 'Check the highlighted participant shares.';
                elseif (array_sum($sharesInCents) !== $totalCents) $errors[] = 'Custom shares must add up exactly to RM ' . number_format($totalCents / 100, 2) . '.';
            }
        }
    }

    if (!$errors) {
        try {
            db()->beginTransaction();
            $statement = db()->prepare('INSERT INTO split_bills (user_id,total_amount,description,bill_date,split_type) VALUES (?,?,?,?,?)');
            $statement->execute([user_id(), split_decimal($totalCents), $form['description'], $form['bill_date'], $form['split_type']]);
            $newBillId = (int)db()->lastInsertId();
            $participantStatement = db()->prepare('INSERT INTO split_bill_participants (split_bill_id,name,share_amount,status,paid_at,is_owner,is_payer) VALUES (?,?,?,?,?,?,?)');
            foreach ($participantNames as $index => $name) {
                $isPayer = $index === $form['payer_index'];
                $participantStatement->execute([$newBillId, $name, split_decimal($sharesInCents[$index]), $isPayer ? 'Paid' : 'Pending', $isPayer ? date('Y-m-d H:i:s') : null, $index === 0 ? 1 : 0, $isPayer ? 1 : 0]);
            }
            db()->commit();
            flash('Split bill created successfully.');
            redirect('split-bills/index.php');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'Unable to save the split bill. Please try again.';
        }
    }
}

$summaryStatement = db()->prepare("SELECT
    COALESCE(SUM(CASE WHEN payer.is_owner=1 AND participant.is_payer=0 AND participant.status='Pending' THEN participant.share_amount ELSE 0 END),0) you_are_owed,
    COALESCE(SUM(CASE WHEN participant.is_owner=1 AND participant.is_payer=0 AND participant.status='Pending' THEN participant.share_amount ELSE 0 END),0) you_owe
    FROM split_bills bill
    JOIN split_bill_participants payer ON payer.split_bill_id=bill.split_bill_id AND payer.is_payer=1
    JOIN split_bill_participants participant ON participant.split_bill_id=bill.split_bill_id
    WHERE bill.user_id=?");
$summaryStatement->execute([user_id()]);
$balanceSummary = $summaryStatement->fetch();

$statement = db()->prepare("SELECT bill.*,
    SUM(participant.status='Pending' AND participant.is_payer=0) pending_count,
    COUNT(participant.participant_id) participant_count
    FROM split_bills bill
    JOIN split_bill_participants participant ON participant.split_bill_id=bill.split_bill_id
    WHERE bill.user_id=? GROUP BY bill.split_bill_id ORDER BY bill.bill_date DESC,bill.split_bill_id DESC");
$statement->execute([user_id()]);
$bills = $statement->fetchAll();

$participantsByBill = [];
if ($bills) {
    $billIds = array_column($bills, 'split_bill_id');
    $placeholders = implode(',', array_fill(0, count($billIds), '?'));
    $statement = db()->prepare("SELECT * FROM split_bill_participants WHERE split_bill_id IN ($placeholders) ORDER BY split_bill_id,participant_id");
    $statement->execute($billIds);
    foreach ($statement->fetchAll() as $participant) $participantsByBill[$participant['split_bill_id']][] = $participant;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="page-title mb-1">Split Bills</h1><p class="text-secondary mb-0">Share student expenses and keep track of who has settled.</p></div>
    <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#splitBillForm">+ Split a Bill</button>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6"><div class="card p-3 h-100"><div class="metric-label">You are owed</div><div class="metric text-success"><?= currency($balanceSummary['you_are_owed']) ?></div><small class="text-secondary">Pending shares when you paid</small></div></div>
    <div class="col-md-6"><div class="card p-3 h-100"><div class="metric-label">You owe</div><div class="metric text-danger"><?= currency($balanceSummary['you_owe']) ?></div><small class="text-secondary">Your pending shares when someone else paid</small></div></div>
</div>

<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>

<div class="collapse <?= $errors ? 'show' : '' ?> mb-4" id="splitBillForm">
    <div class="card p-4">
        <h5 class="mb-1">Create split bill</h5><p class="text-secondary small">Add everyone included in the bill, choose the payer, then split equally or enter custom shares.</p>
        <form method="post" id="splitForm" class="row g-3" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="create">
            <div class="col-md-5"><label class="form-label" for="description">Description</label><input class="form-control" id="description" name="description" maxlength="180" required placeholder="e.g. Dinner at Mamak" value="<?= e($form['description']) ?>"></div>
            <div class="col-md-3"><label class="form-label" for="total_amount">Total amount (RM)</label><input class="form-control" id="total_amount" name="total_amount" type="number" min="0.01" max="9999999999.99" step="0.01" required value="<?= e($form['total_amount']) ?>"></div>
            <div class="col-md-2"><label class="form-label" for="bill_date">Date</label><input class="form-control" id="bill_date" name="bill_date" type="date" required value="<?= e($form['bill_date']) ?>"></div>
            <div class="col-md-2"><label class="form-label" for="split_type">Split method</label><select class="form-select" id="split_type" name="split_type"><option value="Equal" <?= $form['split_type']==='Equal'?'selected':'' ?>>Equal split</option><option value="Custom" <?= $form['split_type']==='Custom'?'selected':'' ?>>Custom split</option></select></div>

            <div class="col-12"><div class="split-participants"><div class="d-flex justify-content-between align-items-center mb-3"><div><strong>Participants</strong><div class="small text-secondary">Select the person who paid the full bill.</div></div><button class="btn btn-sm btn-outline-primary" id="addParticipant" type="button">+ Add person</button></div>
                <div class="split-participant-head"><span>Participant</span><span class="custom-share-heading">Share (RM)</span><span>Who paid?</span><span></span></div>
                <div id="participantRows">
                <?php foreach ($form['participant_names'] as $index => $participantName): ?>
                    <div class="split-participant-row" data-participant-row>
                        <input class="form-control participant-name" name="participant_names[]" maxlength="80" required <?= $index===0?'readonly':'' ?> value="<?= e($index===0?'You':$participantName) ?>">
                        <div><input class="form-control participant-share<?= isset($shareErrors[$index]) ? ' is-invalid' : '' ?>" name="participant_shares[]" type="text" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" maxlength="32" placeholder="0.00" aria-label="Share for <?= e($participantName ?: 'participant ' . ($index + 1)) ?>" value="<?= e(is_string($form['participant_shares'][$index] ?? null) ? $form['participant_shares'][$index] : '') ?>"><div class="invalid-feedback<?= isset($shareErrors[$index]) ? ' d-block' : '' ?>" role="alert"><?= e($shareErrors[$index] ?? '') ?></div></div>
                        <label class="payer-choice"><input class="form-check-input payer-radio" type="radio" name="payer_index" value="<?= $index ?>" <?= (int)$form['payer_index']===$index?'checked':'' ?>><span><?= $index===0?'You':e($participantName ?: 'Person '.($index+1)) ?></span></label>
                        <button class="btn btn-sm btn-outline-danger remove-participant" type="button" <?= $index===0?'disabled':'' ?> aria-label="Remove participant">×</button>
                    </div>
                <?php endforeach; ?>
                </div>
                <div class="split-total-check mt-3"><span id="splitHint">Enter a total to preview the equal shares.</span><strong id="customTotal"></strong></div>
            </div></div>
            <div class="col-12"><button class="btn btn-primary" type="submit">Create Split Bill</button></div>
        </form>
    </div>
</div>

<div class="row g-3">
<?php foreach ($bills as $bill):
    $participants = $participantsByBill[$bill['split_bill_id']] ?? [];
    $payer = null;
    foreach ($participants as $participant) if ((int)$participant['is_payer'] === 1) $payer = $participant;
    $settled = (int)$bill['pending_count'] === 0;
?>
    <div class="col-xl-6"><article class="card split-bill-card h-100">
        <div class="p-4 border-bottom"><div class="d-flex justify-content-between align-items-start gap-3"><div><span class="badge text-bg-light border mb-2"><?= e($bill['split_type']) ?> split</span><h5 class="mb-1"><?= e($bill['description']) ?></h5><div class="small text-secondary"><?= e(date('d M Y', strtotime($bill['bill_date']))) ?> · Paid by <?= e($payer['name'] ?? 'Unknown') ?></div></div><div class="text-end"><div class="metric"><?= currency($bill['total_amount']) ?></div><span class="badge text-bg-<?= $settled?'success':'warning' ?>"><?= $settled?'Fully settled':(int)$bill['pending_count'].' pending' ?></span></div></div></div>
        <div class="split-person-list">
        <?php foreach ($participants as $participant): ?>
            <div class="split-person"><div><strong><?= e($participant['name']) ?></strong><?= (int)$participant['is_owner']===1?' <span class="badge text-bg-primary">You</span>':'' ?><?= (int)$participant['is_payer']===1?' <span class="badge text-bg-light border">Payer</span>':'' ?><div class="small text-secondary"><?= currency($participant['share_amount']) ?> share<?= $participant['paid_at']?' · Settled '.e(date('d M Y',strtotime($participant['paid_at']))):'' ?></div></div><div class="d-flex align-items-center gap-2"><span class="badge text-bg-<?= $participant['status']==='Paid'?'success':'warning' ?>"><?= e($participant['status']) ?></span><?php if ((int)$participant['is_payer']!==1): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="<?= $participant['status']==='Paid'?'reopen':'settle' ?>"><input type="hidden" name="participant_id" value="<?= (int)$participant['participant_id'] ?>"><button class="btn btn-sm <?= $participant['status']==='Paid'?'btn-outline-secondary':'btn-outline-success' ?>"><?= $participant['status']==='Paid'?'Mark pending':'Mark settled' ?></button></form><?php endif; ?></div></div>
        <?php endforeach; ?>
        </div>
        <div class="p-3 text-end border-top"><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="split_bill_id" value="<?= (int)$bill['split_bill_id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete this split bill and all participant records?">Delete bill</button></form></div>
    </article></div>
<?php endforeach; ?>
<?php if (!$bills): ?><div class="col-12"><div class="card empty-state"><strong>No split bills yet</strong><span>Split a meal, trip, or shared student expense to get started.</span></div></div><?php endif; ?>
</div>

<template id="participantTemplate"><div class="split-participant-row" data-participant-row><input class="form-control participant-name" name="participant_names[]" maxlength="80" required placeholder="Participant name"><div><input class="form-control participant-share" name="participant_shares[]" type="text" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" maxlength="32" placeholder="0.00"><div class="invalid-feedback" role="alert"></div></div><label class="payer-choice"><input class="form-check-input payer-radio" type="radio" name="payer_index"><span>New person</span></label><button class="btn btn-sm btn-outline-danger remove-participant" type="button" aria-label="Remove participant">×</button></div></template>
<script>
const rows=document.getElementById('participantRows'),template=document.getElementById('participantTemplate'),splitType=document.getElementById('split_type'),totalInput=document.getElementById('total_amount'),hint=document.getElementById('splitHint'),customTotal=document.getElementById('customTotal');
function participantRows(){return [...rows.querySelectorAll('[data-participant-row]')];}
function parseSplitCents(raw){const value=raw.trim();if(!/^\d+(?:\.\d{1,2})?$/.test(value))return null;const parts=value.split('.'),whole=parts[0].replace(/^0+/,'');if(whole.length>10)return null;const cents=Number(whole||'0')*100+Number((parts[1]||'').padEnd(2,'0'));return cents>=1&&cents<=999999999999?cents:null;}
function formatSplitCents(cents){return `${Math.floor(cents/100)}.${String(cents%100).padStart(2,'0')}`;}
function showShareError(row,message){const share=row.querySelector('.participant-share'),feedback=row.querySelector('.invalid-feedback');share.classList.toggle('is-invalid',!!message);share.setAttribute('aria-invalid',message?'true':'false');feedback.textContent=message;feedback.classList.toggle('d-block',!!message);}
function refreshParticipants(){const list=participantRows();list.forEach((row,index)=>{const name=row.querySelector('.participant-name'),radio=row.querySelector('.payer-radio'),label=row.querySelector('.payer-choice span'),share=row.querySelector('.participant-share'),feedback=row.querySelector('.invalid-feedback');radio.value=index;label.textContent=index===0?'You':(name.value.trim()||`Person ${index+1}`);feedback.id=`share-error-${index}`;share.setAttribute('aria-describedby',feedback.id);share.setAttribute('aria-label',`Share for ${label.textContent}`);row.querySelector('.remove-participant').disabled=index===0||list.length<=2;});refreshSplit();}
function refreshSplit(){const custom=splitType.value==='Custom',list=participantRows(),total=parseSplitCents(totalInput.value);document.querySelectorAll('.custom-share-heading').forEach(el=>el.style.visibility=custom?'visible':'hidden');list.forEach((row,index)=>{const share=row.querySelector('.participant-share');share.disabled=!custom;share.required=custom;if(!custom){showShareError(row,'');if(total!==null){const base=Math.floor(total/list.length),extra=total%list.length;share.value=formatSplitCents(base+(index<extra?1:0));}}});if(custom){const values=list.map(row=>parseSplitCents(row.querySelector('.participant-share').value));const valid=values.every(value=>value!==null);const sum=valid?values.reduce((a,b)=>a+b,0):null;hint.textContent='Custom shares must match the bill total.';customTotal.textContent=valid&&total!==null?`Entered: RM ${formatSplitCents(sum)} / RM ${formatSplitCents(total)}`:'Enter valid shares with up to two decimal places.';customTotal.className=valid&&sum===total?'text-success':'text-danger';}else{hint.textContent=total!==null?`The total is divided across ${list.length} people. Extra cents are assigned from the top.`:'Enter a total to preview the equal shares.';customTotal.textContent='';}}
document.getElementById('addParticipant').addEventListener('click',()=>{if(participantRows().length>=20)return;rows.appendChild(template.content.cloneNode(true));refreshParticipants();rows.lastElementChild.querySelector('.participant-name').focus();});
rows.addEventListener('click',event=>{if(event.target.classList.contains('remove-participant')){const row=event.target.closest('[data-participant-row]');if(participantRows().length>2)row.remove();refreshParticipants();}});rows.addEventListener('input',event=>{const row=event.target.closest('[data-participant-row]');if(row&&event.target.classList.contains('participant-share')&&event.target.classList.contains('is-invalid'))showShareError(row,parseSplitCents(event.target.value)===null?'Enter an amount greater than RM 0.00 with at most two decimal places.':'');refreshParticipants();});splitType.addEventListener('change',refreshSplit);totalInput.addEventListener('input',refreshSplit);document.getElementById('splitForm').addEventListener('submit',event=>{if(splitType.value!=='Custom')return;let firstInvalid=null;participantRows().forEach(row=>{const share=row.querySelector('.participant-share'),invalid=parseSplitCents(share.value)===null;showShareError(row,invalid?'Enter an amount greater than RM 0.00 with at most two decimal places.':'');if(invalid&&!firstInvalid)firstInvalid=share;});if(firstInvalid){event.preventDefault();firstInvalid.focus();}});refreshParticipants();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
