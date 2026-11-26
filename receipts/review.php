<?php
require_once __DIR__ . '/../includes/receipt.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

$receiptId = (int)($_GET['id'] ?? $_POST['receipt_id'] ?? 0);
$receipt = owned_receipt($receiptId);
if (!$receipt) {
    flash('Receipt not found.', 'warning');
    redirect('receipts/index.php');
}

$categoryRows = categories();
$errors = [];
$transaction = null;
if ($receipt['transaction_id']) {
    $statement = db()->prepare('SELECT t.*,c.name category_name FROM transactions t LEFT JOIN categories c ON c.id=t.category_id WHERE t.id=? AND t.user_id=?');
    $statement->execute([$receipt['transaction_id'], user_id()]);
    $transaction = $statement->fetch() ?: null;
}

$suggestion = $receipt['detected_merchant'] ? suggested_category($receipt['detected_merchant']) : null;
$form = [
    'merchant' => $transaction['merchant'] ?? $receipt['detected_merchant'] ?? '',
    'amount' => $transaction['amount'] ?? $receipt['detected_amount'] ?? '',
    'transaction_date' => $transaction['transaction_date'] ?? $receipt['detected_date'] ?? date('Y-m-d'),
    'category_id' => $transaction['category_id'] ?? $suggestion['category_id'] ?? '',
    'description' => $transaction['description'] ?? 'Expense from uploaded receipt',
    'remember_category' => false,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    verify_csrf();
    if ($transaction) {
        flash('This receipt has already been saved.', 'warning');
        redirect('receipts/review.php?id=' . $receiptId);
    }

    $form = [
        'merchant' => trim($_POST['merchant'] ?? ''),
        'amount' => trim((string)($_POST['amount'] ?? '')),
        'transaction_date' => trim($_POST['transaction_date'] ?? ''),
        'category_id' => (int)($_POST['category_id'] ?? 0),
        'description' => trim($_POST['description'] ?? ''),
        'remember_category' => !empty($_POST['remember_category']),
    ];
    $transactionAmount = amount($form['amount']);
    if ($form['merchant'] === '' || mb_strlen($form['merchant']) > 120) $errors[] = 'Merchant is required and must be 120 characters or fewer.';
    if ($transactionAmount === null || $transactionAmount > 9999999999.99) $errors[] = 'Enter a valid receipt total greater than RM 0.00.';
    if (!valid_date($form['transaction_date'])) $errors[] = 'Select a valid transaction date.';
    if (!$form['category_id'] || !ownership('categories', $form['category_id'])) $errors[] = 'Select one of your categories.';
    if (mb_strlen($form['description']) > 500) $errors[] = 'Description must be 500 characters or fewer.';

    if (!$errors) {
        try {
            db()->beginTransaction();
            $importReference = 'receipt:' . $receiptId;
            $statement = db()->prepare("INSERT INTO transactions (user_id,category_id,type,amount,description,merchant,transaction_date,source,import_reference) VALUES (?,?, 'Expense',?,?,?,?, 'Receipt',?)");
            $statement->execute([user_id(), $form['category_id'], $transactionAmount, $form['description'], $form['merchant'], $form['transaction_date'], $importReference]);
            $transactionId = (int)db()->lastInsertId();
            db()->prepare('UPDATE receipt_uploads SET detected_merchant=?,detected_amount=?,detected_date=?,transaction_id=?,confirmed_at=NOW() WHERE receipt_id=? AND user_id=? AND transaction_id IS NULL')
                ->execute([$form['merchant'], $transactionAmount, $form['transaction_date'], $transactionId, $receiptId, user_id()]);
            if ($form['remember_category']) {
                $keyword = preg_replace('/\s+/u', ' ', $form['merchant']);
                db()->prepare('INSERT INTO category_rules (user_id,merchant_keyword,category_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id)')
                    ->execute([user_id(), $keyword, $form['category_id']]);
            }
            db()->commit();
            notification_check_budget_month(substr($form['transaction_date'], 0, 7));
            flash('Receipt confirmed and saved as an expense transaction.');
            redirect('receipts/index.php');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'The receipt transaction could not be saved. Please try again.';
        }
    }
}

$page_title = 'Review Receipt';
require_once __DIR__ . '/../includes/header.php';
$needsBrowserOcr = !$transaction && $receipt['ocr_status'] !== 'Completed';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><span class="dashboard-eyebrow">RECEIPT DETECTED</span><h1 class="page-title mb-1">Review receipt</h1><p class="text-secondary mb-0">Correct anything uncertain before saving this expense.</p></div><a class="btn btn-light" href="<?= e(app_url('receipts/index.php')) ?>">← Receipt history</a></div>

<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
<?php if ($transaction): ?><div class="alert alert-success"><strong>Confirmed:</strong> This receipt is linked to transaction #<?= (int)$transaction['id'] ?>. No duplicate transaction will be created.</div><?php elseif ($receipt['ocr_status'] === 'Completed'): ?><div class="alert alert-info"><strong>OCR complete.</strong> <?= $receipt['ocr_confidence'] !== null ? 'Text confidence: '.number_format((float)$receipt['ocr_confidence'],1).'%. ' : '' ?>Please verify all detected fields.</div><?php else: ?><div class="alert alert-warning" id="ocrAvailability"><strong>Local OCR is unavailable.</strong> Free browser OCR will start below. If it cannot read the image, enter the fields manually.</div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5"><div class="card receipt-preview"><img id="receiptImage" src="<?= e(app_url('receipts/image.php?id='.$receiptId)) ?>" alt="Receipt preview"><div class="p-3"><div class="d-flex justify-content-between small text-secondary"><span><?= e($receipt['original_name']) ?></span><span><?= number_format((int)$receipt['file_size']/1024,0) ?> KB</span></div><?php if (!$transaction): ?><button class="btn btn-sm btn-outline-primary w-100 mt-3" type="button" id="runOcr">Run browser OCR again</button><div class="progress mt-3 receipt-ocr-progress" id="ocrProgress" hidden><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:5%"></div></div><div class="small text-secondary mt-2" id="ocrMessage"></div><?php endif; ?></div></div></div>
    <div class="col-lg-7"><div class="card p-4"><div class="d-flex justify-content-between align-items-start gap-3 mb-3"><div><h5 class="mb-1"><?= $transaction?'Saved receipt details':'Detected details' ?></h5><p class="text-secondary small mb-0"><?= $transaction?'These values were confirmed and saved.':'Every field below is editable.' ?></p></div><?php if (!$transaction): ?><button class="btn btn-sm btn-outline-secondary" id="editDetected" type="button">Edit</button><?php endif; ?></div>
        <form method="post" id="receiptReviewForm" class="row g-3" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="confirm"><input type="hidden" name="receipt_id" value="<?= $receiptId ?>">
            <div class="col-md-7"><label class="form-label" for="merchant">Merchant</label><input class="form-control review-field" id="merchant" name="merchant" maxlength="120" required value="<?= e($form['merchant']) ?>" <?= $transaction?'readonly':'' ?>><div class="category-suggestion mt-2" id="receiptCategorySuggestion" hidden></div></div>
            <div class="col-md-5"><label class="form-label" for="amount">Amount (RM)</label><input class="form-control review-field" id="amount" name="amount" type="number" min="0.01" max="9999999999.99" step="0.01" required value="<?= e($form['amount']) ?>" <?= $transaction?'readonly':'' ?>></div>
            <div class="col-md-6"><label class="form-label" for="transaction_date">Date</label><input class="form-control review-field" id="transaction_date" name="transaction_date" type="date" required value="<?= e($form['transaction_date']) ?>" <?= $transaction?'readonly':'' ?>></div>
            <div class="col-md-6"><label class="form-label" for="category_id">Category</label><select class="form-select review-field" id="category_id" name="category_id" required <?= $transaction?'disabled':'' ?>><option value="">Select category</option><?php foreach ($categoryRows as $category): ?><option value="<?= (int)$category['id'] ?>" <?= (int)$form['category_id']===(int)$category['id']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select><?php if ($transaction): ?><input type="hidden" name="category_id" value="<?= (int)$form['category_id'] ?>"><?php endif; ?></div>
            <div class="col-12"><label class="form-label" for="description">Description</label><input class="form-control review-field" id="description" name="description" maxlength="500" value="<?= e($form['description']) ?>" <?= $transaction?'readonly':'' ?>></div>
            <?php if (!$transaction): ?><div class="col-12"><div class="form-check"><input class="form-check-input" id="remember_category" name="remember_category" type="checkbox" value="1" <?= $form['remember_category']?'checked':'' ?>><label class="form-check-label" for="remember_category">Remember this merchant and category for future expenses</label></div></div><div class="col-12"><button class="btn btn-primary" type="submit">Confirm & Save Transaction</button> <span class="small text-secondary ms-2">Nothing is saved until you confirm.</span></div><?php endif; ?>
        </form>
        <?php if ($receipt['ocr_text']): ?><details class="mt-4"><summary class="small text-secondary">View extracted OCR text</summary><pre class="receipt-ocr-text mt-2"><?= e($receipt['ocr_text']) ?></pre></details><?php endif; ?>
    </div></div>
</div>

<?php if (!$transaction): ?>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
const receiptId=<?= $receiptId ?>,csrfToken=<?= json_encode(csrf()) ?>,processUrl=<?= json_encode(app_url('receipts/process-ocr.php')) ?>,suggestionUrl=<?= json_encode(app_url('transactions/suggest-category.php')) ?>,autoRun=<?= $needsBrowserOcr?'true':'false' ?>;const runButton=document.getElementById('runOcr'),progress=document.getElementById('ocrProgress'),progressBar=progress.querySelector('.progress-bar'),message=document.getElementById('ocrMessage'),merchant=document.getElementById('merchant'),amount=document.getElementById('amount'),receiptDate=document.getElementById('transaction_date'),category=document.getElementById('category_id'),suggestion=document.getElementById('receiptCategorySuggestion');let running=false,suggestTimer=0;document.querySelectorAll('.review-field').forEach(field=>field.addEventListener('input',()=>field.dataset.edited='1'));
async function runOcr(){if(running)return;if(typeof Tesseract==='undefined'){message.textContent='Browser OCR could not load. Enter the details manually.';return;}running=true;runButton.disabled=true;progress.hidden=false;message.textContent='Preparing OCR…';try{const worker=await Tesseract.createWorker('eng',1,{logger:event=>{if(event.progress!==undefined){const percent=Math.max(5,Math.round(event.progress*100));progressBar.style.width=percent+'%';message.textContent=(event.status||'Reading receipt')+' · '+percent+'%';}}});const result=await worker.recognize(document.getElementById('receiptImage').src);await worker.terminate();const body=new URLSearchParams({csrf:csrfToken,receipt_id:String(receiptId),ocr_text:result.data.text||'',confidence:String(result.data.confidence??'')});const response=await fetch(processUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body});const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'OCR could not read this receipt.');if(data.merchant&&!merchant.dataset.edited)merchant.value=data.merchant;if(data.amount&&!amount.dataset.edited)amount.value=Number(data.amount).toFixed(2);if(data.date&&!receiptDate.dataset.edited)receiptDate.value=data.date;if(data.category_id&&!category.dataset.edited)category.value=String(data.category_id);progressBar.style.width='100%';message.textContent=`OCR complete${data.confidence!==null?' · '+Number(data.confidence).toFixed(1)+'% text confidence':''}. Review every field before saving.`;document.getElementById('ocrAvailability')?.remove();}catch(error){message.textContent=(error.message||'OCR failed')+' You can enter all fields manually.';}finally{running=false;runButton.disabled=false;}}
async function suggestCategory(){clearTimeout(suggestTimer);const value=merchant.value.trim();if(value.length<2){suggestion.hidden=true;return;}suggestTimer=setTimeout(async()=>{try{const response=await fetch(suggestionUrl+'?merchant='+encodeURIComponent(value));const data=await response.json();suggestion.hidden=false;if(data.match){if(!category.dataset.edited)category.value=String(data.category_id);suggestion.className='category-suggestion mt-2 matched';suggestion.textContent=`Suggested: ${data.category_name} · You can change it.`;}else{suggestion.className='category-suggestion mt-2';suggestion.textContent='No saved merchant rule found. Choose manually.';}}catch(error){suggestion.hidden=true;}},250);}merchant.addEventListener('input',suggestCategory);category.addEventListener('change',()=>category.dataset.edited='1');runButton.addEventListener('click',runOcr);document.getElementById('editDetected').addEventListener('click',()=>{merchant.focus();merchant.select();});if(autoRun)window.addEventListener('load',runOcr);
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
