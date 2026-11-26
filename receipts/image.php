<?php
require_once __DIR__ . '/../includes/receipt.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Authentication required.');
}

$receipt = owned_receipt((int)($_GET['id'] ?? 0));
$filePath = $receipt ? receipt_file_path($receipt['stored_name']) : null;
if (!$receipt || !$filePath) {
    http_response_code(404);
    exit('Receipt image not found.');
}

header('Content-Type: ' . $receipt['mime_type']);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="receipt-' . (int)$receipt['receipt_id'] . '.' . pathinfo($receipt['stored_name'], PATHINFO_EXTENSION) . '"');
readfile($filePath);
