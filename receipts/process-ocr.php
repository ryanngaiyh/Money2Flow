<?php
require_once __DIR__ . '/../includes/receipt.php';

header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$receipt = owned_receipt((int)($_POST['receipt_id'] ?? 0));
if (!$receipt || $receipt['transaction_id']) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Receipt draft not found.']);
    exit;
}

$text = trim(str_replace("\0", '', (string)($_POST['ocr_text'] ?? '')));
$confidence = is_numeric($_POST['confidence'] ?? null) ? max(0, min(100, round((float)$_POST['confidence'], 2))) : null;
if ($text === '' || strlen($text) > 100000) {
    db()->prepare("UPDATE receipt_uploads SET ocr_status='Failed',ocr_engine='Tesseract.js',ocr_confidence=? WHERE receipt_id=? AND user_id=?")
        ->execute([$confidence, $receipt['receipt_id'], user_id()]);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No readable text was detected. Enter the receipt details manually.']);
    exit;
}

$parsed = parse_receipt_text($text);
$statement = db()->prepare("UPDATE receipt_uploads SET ocr_text=?,ocr_status='Completed',ocr_engine='Tesseract.js',ocr_confidence=?,detected_merchant=?,detected_amount=?,detected_date=? WHERE receipt_id=? AND user_id=?");
$statement->execute([$text, $confidence, $parsed['merchant'], $parsed['amount'], $parsed['date'], $receipt['receipt_id'], user_id()]);

echo json_encode([
    'success' => true,
    'confidence' => $confidence,
    'merchant' => $parsed['merchant'],
    'amount' => $parsed['amount'],
    'date' => $parsed['date'],
    'category_id' => $parsed['category_id'],
    'category_name' => $parsed['category_name'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
