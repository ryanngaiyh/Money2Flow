<?php
require_once __DIR__ . '/../includes/notifications.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        $statement = db()->prepare('UPDATE notifications SET is_read=1,read_at=COALESCE(read_at,?) WHERE notification_id=? AND user_id=?');
        $statement->execute([notification_now()->format('Y-m-d H:i:s'), $notificationId, user_id()]);
        flash($statement->rowCount() ? 'Notification marked as read.' : 'Notification not found.', $statement->rowCount() ? 'success' : 'warning');
    } elseif ($action === 'mark_all_read') {
        db()->prepare('UPDATE notifications SET is_read=1,read_at=? WHERE user_id=? AND is_read=0')->execute([notification_now()->format('Y-m-d H:i:s'), user_id()]);
        flash('All notifications marked as read.');
    } elseif ($action === 'save_preferences') {
        $values = [];
        foreach (notification_types() as $type => $label) $values[] = !empty($_POST[$type]) ? 1 : 0;
        db()->prepare('INSERT INTO notification_preferences (user_id,budget_warning,allowance_reminder,goal_achievement,goal_deadline,split_bill_reminder,updated_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE budget_warning=VALUES(budget_warning),allowance_reminder=VALUES(allowance_reminder),goal_achievement=VALUES(goal_achievement),goal_deadline=VALUES(goal_deadline),split_bill_reminder=VALUES(split_bill_reminder),updated_at=VALUES(updated_at)')
            ->execute([user_id(), ...$values, notification_now()->format('Y-m-d H:i:s')]);
        flash('Notification preferences saved.');
    } else {
        flash('Invalid notification action.', 'danger');
    }
    redirect(($_POST['return_to'] ?? '') === 'dashboard' ? 'dashboard/index.php?notifications=all#notificationHistory' : 'notifications/index.php');
}

notification_refresh_reminders();
$preferences = notification_preferences();
$filter = ($_GET['filter'] ?? '') === 'unread' ? 'unread' : 'all';
$page = max(1, min(10000, (int)($_GET['page'] ?? 1)));
$perPage = 20;
$where = 'user_id=?' . ($filter === 'unread' ? ' AND is_read=0' : '');
$count = db()->prepare('SELECT COUNT(*) FROM notifications WHERE ' . $where);
$count->execute([user_id()]);
$total = (int)$count->fetchColumn();
$pageCount = max(1, (int)ceil($total / $perPage));
$page = min($page, $pageCount);
$statement = db()->prepare('SELECT * FROM notifications WHERE ' . $where . ' ORDER BY created_at DESC,notification_id DESC LIMIT ? OFFSET ?');
$statement->bindValue(1, user_id(), PDO::PARAM_INT);
$statement->bindValue(2, $perPage, PDO::PARAM_INT);
$statement->bindValue(3, ($page - 1) * $perPage, PDO::PARAM_INT);
$statement->execute();
$notifications = $statement->fetchAll();
$page_title = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><span class="dashboard-eyebrow">YOUR FINANCIAL UPDATES</span><h1 class="page-title mb-1">Notifications</h1><p class="text-secondary mb-0">Important updates from your budgets, allowance, savings goals, and split bills.</p></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="mark_all_read"><button class="btn btn-outline-primary">Mark All as Read</button></form>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card overflow-hidden">
            <div class="d-flex gap-2 p-3 border-bottom"><a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?filter=all">All</a><a class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?filter=unread">Unread</a><span class="ms-auto small text-secondary align-self-center"><?= $total ?> notification<?= $total === 1 ? '' : 's' ?></span></div>
            <?php if (!$notifications): ?><div class="empty-state"><strong><?= $filter === 'unread' ? 'You are all caught up.' : 'No notifications yet.' ?></strong><span>Updates will appear when your financial events occur.</span></div><?php endif; ?>
            <?php foreach ($notifications as $notification): ?>
                <div class="notification-row <?= (int)$notification['is_read'] ? '' : 'notification-unread' ?>">
                    <span class="notification-icon" aria-hidden="true"><?= e(notification_icon($notification['notification_type'])) ?></span>
                    <div class="notification-body"><div class="d-flex flex-wrap align-items-center gap-2"><strong><?= e($notification['title']) ?></strong><?php if (!(int)$notification['is_read']): ?><span class="badge text-bg-success">New</span><?php endif; ?></div><p class="mb-1 text-secondary small"><?= e($notification['message']) ?></p><small class="text-secondary"><?= e(date('j M Y, g:i A', strtotime($notification['created_at']))) ?></small><div class="d-flex flex-wrap gap-2 mt-2"><a class="btn btn-sm btn-outline-primary" href="<?= e(notification_link($notification)) ?>">View related feature</a><?php if (!(int)$notification['is_read']): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notification_id" value="<?= (int)$notification['notification_id'] ?>"><button class="btn btn-sm btn-link text-decoration-none">Mark as Read</button></form><?php endif; ?></div></div>
                </div>
            <?php endforeach; ?>
            <?php if ($pageCount > 1): ?><div class="d-flex justify-content-between p-3 border-top"><a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="?filter=<?= e($filter) ?>&page=<?= $page - 1 ?>">Previous</a><span class="small text-secondary align-self-center">Page <?= $page ?> of <?= $pageCount ?></span><a class="btn btn-sm btn-outline-secondary <?= $page >= $pageCount ? 'disabled' : '' ?>" href="?filter=<?= e($filter) ?>&page=<?= $page + 1 ?>">Next</a></div><?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4"><div class="card p-4"><h2 class="h5 mb-1">Notification settings</h2><p class="text-secondary small">Choose which updates you want to see in Money2Flow.</p><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="save_preferences"><?php foreach (notification_types() as $type => $label): ?><div class="form-check form-switch py-2 border-bottom"><input class="form-check-input" type="checkbox" role="switch" id="pref_<?= e($type) ?>" name="<?= e($type) ?>" value="1" <?= $preferences[$type] ? 'checked' : '' ?>><label class="form-check-label" for="pref_<?= e($type) ?>"><?= e($label) ?></label></div><?php endforeach; ?><button class="btn btn-primary mt-3 w-100">Save settings</button></form><p class="small text-secondary mt-3 mb-0">Reminders are checked when you use the app. They do not require email or push notifications.</p></div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
