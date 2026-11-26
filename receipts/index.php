<?php
require_once __DIR__ . '/../includes/receipt.php';
require_login();

$errors = [];
$maximumFileSize = 5 * 1024 * 1024;
$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $receipt = owned_receipt((int)($_POST['receipt_id'] ?? 0));
        if (!$receipt) {
            flash('Receipt not found.', 'danger');
        } elseif ($receipt['transaction_id']) {
            flash('A confirmed receipt is retained with its transaction.', 'warning');
        } else {
            db()->prepare('DELETE FROM receipt_uploads WHERE receipt_id=? AND user_id=?')->execute([$receipt['receipt_id'], user_id()]);
            $filePath = receipt_file_path($receipt['stored_name']);
            if ($filePath) @unlink($filePath);
            flash('Receipt draft deleted successfully.');
        }
        redirect('receipts/index.php');
    }

    $upload = $_FILES['receipt_image'] ?? null;
    if (!$upload || !isset($upload['error']) || is_array($upload['error'])) {
        $errors[] = 'Choose a receipt image to upload.';
    } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
        $errors[] = $upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE ? 'The receipt image is too large.' : 'The receipt upload failed. Please try again.';
    } elseif ((int)$upload['size'] <= 0 || (int)$upload['size'] > $maximumFileSize) {
        $errors[] = 'Receipt images must be 5 MB or smaller.';
    }

    $mimeType = null;
    $extension = null;
    if (!$errors) {
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($upload['tmp_name']);
        $extension = $allowedMimeTypes[$mimeType] ?? null;
        if (!$extension || @getimagesize($upload['tmp_name']) === false) $errors[] = 'Upload a valid JPG, PNG, or WebP receipt image.';
    }

    if (!$errors) {
        $storageDirectory = receipt_storage_directory();
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0775, true)) {
            $errors[] = 'The receipt storage folder could not be prepared.';
        } else {
            $storedName = bin2hex(random_bytes(20)) . '.' . $extension;
            $storedPath = $storageDirectory . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($upload['tmp_name'], $storedPath)) {
                $errors[] = 'The receipt image could not be stored.';
            } else {
                try {
                    $ocr = run_local_receipt_ocr($storedPath);
                    $parsed = $ocr['status'] === 'Completed' ? parse_receipt_text($ocr['text']) : ['merchant' => null, 'amount' => null, 'date' => null];
                    $statement = db()->prepare('INSERT INTO receipt_uploads (user_id,stored_name,original_name,mime_type,file_size,ocr_text,ocr_status,ocr_engine,detected_merchant,detected_amount,detected_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                    $statement->execute([user_id(), $storedName, mb_substr(basename($upload['name']), 0, 255), $mimeType, (int)$upload['size'], $ocr['text'] ?: null, $ocr['status'], $ocr['engine'], $parsed['merchant'], $parsed['amount'], $parsed['date']]);
                    $receiptId = (int)db()->lastInsertId();
                    redirect('receipts/review.php?id=' . $receiptId);
                } catch (Throwable $exception) {
                    @unlink($storedPath);
                    $errors[] = 'The receipt record could not be created.';
                }
            }
        }
    }
}

$statement = db()->prepare('SELECT r.*,t.amount transaction_amount,t.merchant transaction_merchant FROM receipt_uploads r LEFT JOIN transactions t ON t.id=r.transaction_id AND t.user_id=r.user_id WHERE r.user_id=? ORDER BY r.created_at DESC,r.receipt_id DESC');
$statement->execute([user_id()]);
$receipts = $statement->fetchAll();

$page_title = 'Receipt Scanner';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="page-title mb-1">Receipt Scanner</h1><p class="text-secondary mb-0">Upload a receipt, review the detected details, then choose whether to save.</p></div>
    <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#receiptUpload">+ Scan Receipt</button>
</div>

<div class="alert alert-info border-0 receipt-notice"><strong>Review required:</strong> OCR results are never saved as a transaction automatically. You can correct every field before confirming.</div>
<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>

<div class="collapse <?= $errors ? 'show' : '' ?> mb-4" id="receiptUpload">
    <div class="card p-4"><h5 class="mb-1">Upload receipt image</h5><p class="text-secondary small">JPG, PNG, or WebP · Maximum 5 MB · Clear, straight photos give better results.</p>
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="MAX_FILE_SIZE" value="<?= $maximumFileSize ?>">
            <div class="col-md-8"><label class="form-label" for="receipt_image">Receipt photo</label><input class="form-control" id="receipt_image" name="receipt_image" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" required></div>
            <div class="col-md-4 align-self-end"><button class="btn btn-primary w-100" type="submit">Upload & Detect Details</button></div>
        </form>
    </div>
</div>

<div class="row g-3">
<?php foreach ($receipts as $receipt):
    $confirmed = !empty($receipt['transaction_id']);
    $statusClass = $confirmed ? 'success' : ($receipt['ocr_status']==='Completed' ? 'primary' : ($receipt['ocr_status']==='Failed' ? 'danger' : 'warning'));
    $statusLabel = $confirmed ? 'Saved to transactions' : ($receipt['ocr_status']==='Completed' ? 'Ready to review' : ($receipt['ocr_status']==='Failed' ? 'OCR needs retry' : 'Browser OCR available'));
?>
    <div class="col-md-6 col-xl-4"><article class="card receipt-card h-100"><img src="<?= e(app_url('receipts/image.php?id='.(int)$receipt['receipt_id'])) ?>" alt="Uploaded receipt <?= e($receipt['original_name']) ?>"><div class="p-3"><div class="d-flex justify-content-between align-items-start gap-2"><div><span class="badge text-bg-<?= e($statusClass) ?> mb-2"><?= e($statusLabel) ?></span><h6 class="mb-1"><?= e($confirmed ? ($receipt['transaction_merchant'] ?: 'Receipt transaction') : ($receipt['detected_merchant'] ?: $receipt['original_name'])) ?></h6><small class="text-secondary"><?= e(date('d M Y, g:i A', strtotime($receipt['created_at']))) ?></small></div><?php if ($confirmed): ?><strong><?= currency($receipt['transaction_amount']) ?></strong><?php endif; ?></div><div class="mt-3 d-flex justify-content-between align-items-center"><a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('receipts/review.php?id='.(int)$receipt['receipt_id'])) ?>"><?= $confirmed?'View receipt':'Review receipt' ?></a><?php if (!$confirmed): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="receipt_id" value="<?= (int)$receipt['receipt_id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete this receipt draft?">Delete</button></form><?php endif; ?></div></div></article></div>
<?php endforeach; ?>
<?php if (!$receipts): ?><div class="col-12"><div class="card empty-state"><strong>No receipts uploaded yet</strong><span>Upload a clear receipt photo to begin the review workflow.</span></div></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
