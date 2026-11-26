<?php
// Run daily with Windows Task Scheduler. Also safe to run manually or repeatedly.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/savings_lifecycle.php';

$statement = db()->query('SELECT id FROM users ORDER BY id');
$processed = 0;
foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $ownerId) {
    savings_maintain_user((int)$ownerId);
    $processed++;
}
echo 'Savings maintenance complete for ' . $processed . ' accounts at ' . savings_clock()->format('Y-m-d H:i:s') . " Asia/Kuala_Lumpur\n";
