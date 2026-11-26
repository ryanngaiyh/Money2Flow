<?php
// Run daily using Windows Task Scheduler. Safe to retry after downtime.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/notifications.php';

$owners = db()->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$clock = notification_now();
$failed = 0;
foreach ($owners as $ownerId) {
    $_SESSION['user_id'] = (int)$ownerId;
    try {
        notification_refresh_reminders($clock);
    } catch (Throwable $error) {
        $failed++;
        error_log('Reminder processing failed for account ' . (int)$ownerId . ': ' . $error->getMessage());
    }
}
echo 'Reminder processing complete: ' . (count($owners) - $failed) . ' accounts processed, ' . $failed . ' failed.' . PHP_EOL;
exit($failed ? 1 : 0);
