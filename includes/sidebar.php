<?php
$currentScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$mainNavigation = [
    ['dashboard/index.php', 'Dashboard', 'dashboard'],
    ['transactions/index.php', 'Transactions', 'transactions'],
    ['categories/index.php', 'Categories', 'categories'],
    ['budgets/index.php', 'Budget', 'budget'],
    ['savings/index.php', 'Savings Goals', 'savings'],
    ['split-bills/index.php', 'Split Bills', 'split'],
    ['reports/index.php', 'Analytics', 'analytics'],
];
?>
<nav class="sidebar" id="appSidebar" aria-label="Main navigation">
    <svg class="sidebar-symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
        <symbol id="nav-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
        <symbol id="nav-transactions" viewBox="0 0 24 24"><path d="M4 7h15m-4-4 4 4-4 4M20 17H5m4-4-4 4 4 4"/></symbol>
        <symbol id="nav-categories" viewBox="0 0 24 24"><path d="M3 4h8l10 10-7 7L3 10V4Z"/><circle cx="7.5" cy="8" r="1.2"/></symbol>
        <symbol id="nav-budget" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 3v9h9M7.5 16.5l3-3"/></symbol>
        <symbol id="nav-savings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></symbol>
        <symbol id="nav-split" viewBox="0 0 24 24"><circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2.5 20v-2a5.5 5.5 0 0 1 11 0v2H2.5Zm11.5-6a4.5 4.5 0 0 1 7.5 3.4V20H16"/></symbol>
        <symbol id="nav-analytics" viewBox="0 0 24 24"><path d="M4 20V4M4 20h17M8 16v-4m5 4V8m5 8V5"/></symbol>
        <symbol id="nav-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M10 2h4l.6 2.5 2 .8 2.2-1.3 2.8 2.8-1.3 2.2.8 2L23 12l-1.9 1 .8 2 1.3 2.2-2.8 2.8-2.2-1.3-2 .8L14 22h-4l-.6-2.5-2-.8-2.2 1.3-2.8-2.8 1.3-2.2-.8-2L1 12l1.9-1-.8-2L.8 6.8 3.6 4l2.2 1.3 2-.8L10 2Z"/></symbol>
        <symbol id="nav-logout" viewBox="0 0 24 24"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5m4-4 4-4-4-4m4 4H9"/></symbol>
    </svg>
    <div class="sidebar-heading">
        <a class="brand" href="<?= e(app_url('dashboard/index.php')) ?>">Money<span>2Flow</span></a>
        <button class="sidebar-close" id="sidebarClose" type="button" aria-label="Close navigation">×</button>
    </div>
    <div class="sidebar-greeting">Hi, <?= e($_SESSION['user_name'] ?? '') ?></div>

    <div class="sidebar-main">
        <?php foreach ($mainNavigation as [$path, $label, $icon]): ?>
            <?php $active = str_ends_with($currentScript, '/' . $path); ?>
            <a class="sidebar-link<?= $active ? ' is-active' : '' ?>" href="<?= e(app_url($path)) ?>"<?= $active ? ' aria-current="page"' : '' ?>><svg class="sidebar-icon" aria-hidden="true"><use href="#nav-<?= e($icon) ?>"></use></svg><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-bottom">
        <?php $settingsActive = str_ends_with($currentScript, '/auth/profile.php'); ?>
        <a class="sidebar-link<?= $settingsActive ? ' is-active' : '' ?>" href="<?= e(app_url('auth/profile.php')) ?>"<?= $settingsActive ? ' aria-current="page"' : '' ?>><svg class="sidebar-icon" aria-hidden="true"><use href="#nav-settings"></use></svg><span>Settings</span></a>
        <a class="sidebar-link logout" href="<?= e(app_url('auth/logout.php')) ?>"><svg class="sidebar-icon" aria-hidden="true"><use href="#nav-logout"></use></svg><span>Logout</span></a>
    </div>
</nav>
