<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['match' => false, 'message' => 'Authentication required.']);
    exit;
}

$merchant = trim($_GET['merchant'] ?? '');
if ($merchant === '' || mb_strlen($merchant) > 120) {
    echo json_encode(['match' => false]);
    exit;
}

$suggestion = suggested_category($merchant);
echo json_encode($suggestion ? [
    'match' => true,
    'category_id' => (int)$suggestion['category_id'],
    'category_name' => $suggestion['category_name'],
    'merchant_keyword' => $suggestion['merchant_keyword'],
] : ['match' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
