<?php
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/savings_lifecycle.php';
if (empty($readOnlyPage)) {
    savings_maintain_user(user_id());
    notification_refresh_reminders();
}
$showDashboardNotifications = $page_title === 'Dashboard';
if ($showDashboardNotifications) {
    $unreadStatement = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
    $unreadStatement->execute([user_id()]);
    $unreadCount = (int)$unreadStatement->fetchColumn();
    $recentStatement = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC,notification_id DESC LIMIT 5');
    $recentStatement->execute([user_id()]);
    $recentNotifications = $recentStatement->fetchAll();
}
?>
<header class="topbar">
    <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="true">☰</button>
    <div class="topbar-title">
        <span class="text-secondary small">Student finance workspace</span>
        <strong><?= e($page_title) ?></strong>
    </div>
    <div class="topbar-actions">
        <?php if ($showDashboardNotifications): ?><div class="dropdown">
            <button class="notification-bell" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications<?= $unreadCount ? ', ' . $unreadCount . ' unread' : '' ?>" title="Notifications">🔔<?php if ($unreadCount): ?><span class="notification-count"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span><?php endif; ?></button>
            <div class="dropdown-menu dropdown-menu-end notification-menu p-0 shadow">
                <div class="d-flex align-items-center justify-content-between gap-2 p-3 border-bottom"><strong>Notifications</strong><?php if ($unreadCount): ?><form method="post" action="<?= e(app_url('notifications/index.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="mark_all_read"><input type="hidden" name="return_to" value="dashboard"><button class="btn btn-sm btn-link text-decoration-none">Mark All as Read</button></form><?php endif; ?></div>
                <?php if (!$recentNotifications): ?><div class="text-secondary small text-center py-4">No notifications yet.</div><?php endif; ?>
                <?php foreach ($recentNotifications as $notification): ?><div class="notification-menu-item <?= (int)$notification['is_read'] ? '' : 'notification-unread' ?>"><span class="notification-icon" aria-hidden="true"><?= e(notification_icon($notification['notification_type'])) ?></span><div class="min-width-0 notification-body"><a class="fw-semibold text-decoration-none text-dark" href="<?= e(notification_link($notification)) ?>"><?= e($notification['title']) ?></a><div class="small text-secondary notification-preview"><?= e($notification['message']) ?></div><small class="text-secondary"><?= e(date('j M, g:i A', strtotime($notification['created_at']))) ?> · <?= (int)$notification['is_read'] ? 'Read' : 'Unread' ?></small><?php if (!(int)$notification['is_read']): ?><form method="post" action="<?= e(app_url('notifications/index.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notification_id" value="<?= (int)$notification['notification_id'] ?>"><input type="hidden" name="return_to" value="dashboard"><button class="btn btn-sm btn-link text-decoration-none p-0">Mark as Read</button></form><?php endif; ?></div></div><?php endforeach; ?>
                <a class="d-block text-center fw-semibold text-decoration-none p-3 border-top" href="<?= e(app_url('dashboard/index.php?notifications=all#notificationHistory')) ?>">View All Notifications</a>
            </div>
        </div><?php endif; ?>
        <a class="btn btn-primary add-transaction-btn" href="<?= e(app_url('transactions/index.php#transactionForm')) ?>">+ Add Transaction</a>
        <a class="user-chip" href="<?= e(app_url('auth/profile.php')) ?>" aria-label="Open profile">
            <span class="user-avatar"><?= e(strtoupper(substr($_SESSION['user_name'] ?? 'S', 0, 1))) ?></span>
            <span class="d-none d-sm-inline"><?= e($_SESSION['user_name'] ?? 'Student') ?></span>
        </a>
    </div>
</header>
