<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/legacy_expenses.php';
require_login();

$migratedLegacyExpenseCount = empty($readOnlyPage) ? migrate_legacy_expenses_for_user(user_id()) : 0;
if ($migratedLegacyExpenseCount > 0) {
    flash($migratedLegacyExpenseCount . ' previous expense' . ($migratedLegacyExpenseCount === 1 ? ' was' : 's were') . ' added to Transactions.');
}

$page_title = $page_title ?? APP_NAME;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($page_title) ?> | <?= e(APP_NAME) ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="<?= e(asset_url('assets/css/style.css')) ?>" rel="stylesheet"></head><body>
<?php require __DIR__ . '/sidebar.php'; ?><div class="app-main"><?php require __DIR__ . '/topbar.php'; ?><main class="content"><div class="container-fluid py-4">
<?php foreach (consume_flashes() as [$message, $type]): ?><div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert"><?= e($message) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?>
