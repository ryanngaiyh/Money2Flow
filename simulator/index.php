<?php
$page_title = 'Can I Afford It?';
// Loading or submitting this page must not run shared background maintenance.
$readOnlyPage = true;
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/purchase_simulator.php';

$plan = allowance_plan(user_id());
$categoryStatement = db()->prepare('SELECT id,name FROM categories WHERE user_id=? ORDER BY name,id');
$categoryStatement->execute([user_id()]);
$categories = $categoryStatement->fetchAll();
$errors = [];
$result = null;
$purchaseName = '';
$purchaseAmount = '';
$categoryId = 0;
$categoryName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $rawName = $_POST['purchase_name'] ?? '';
    $rawAmount = $_POST['purchase_amount'] ?? '';
    $purchaseName = is_string($rawName) ? trim($rawName) : '';
    $purchaseAmount = is_string($rawAmount) ? trim($rawAmount) : '';
    $categoryId = filter_var($_POST['category_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    $priceCents = purchase_amount_cents($purchaseAmount);
    if (!is_string($rawName) || mb_strlen($purchaseName) > 120) $errors[] = 'Use a purchase name of 120 characters or fewer.';
    if ($priceCents === null) $errors[] = 'Enter a purchase amount above RM 0.00 with no more than two decimal places.';
    if ($categoryId) {
        foreach ($categories as $category) {
            if ((int)$category['id'] === $categoryId) { $categoryName = $category['name']; break; }
        }
        if ($categoryName === '') $errors[] = 'Choose one of your own categories.';
    } elseif (($_POST['category_id'] ?? '') !== '' && ($_POST['category_id'] ?? '') !== '0') {
        $errors[] = 'Choose a valid category.';
    }
    if ($plan['state'] !== 'ready') $errors[] = 'Set up a current allowance date before using the simulator.';
    if (!$errors) $result = purchase_simulate($plan, $priceCents);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4"><div><span class="dashboard-eyebrow">PURCHASE SIMULATOR</span><h1 class="page-title mb-1">Can I Afford It?</h1><p class="text-secondary mb-0">See how a possible purchase would change your allowance plan before you spend.</p></div><a class="btn btn-outline-primary" href="<?= e(app_url('allowance/index.php')) ?>">View allowance planner</a></div>

<?php if ($plan['state'] !== 'ready'): ?><div class="alert alert-warning" role="status"><?= e(allowance_guidance($plan)) ?> <a href="<?= e(app_url('allowance/index.php')) ?>">Set up or update your planner</a> to simulate a purchase.</div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>

<div class="row g-3">
    <div class="col-lg-5"><div class="card p-4 h-100"><h2 class="h5 mb-3">Try a purchase</h2><form method="post" novalidate><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><div class="mb-3"><label class="form-label" for="purchaseName">Purchase name <span class="text-secondary">(optional)</span></label><input class="form-control" id="purchaseName" name="purchase_name" maxlength="120" placeholder="e.g. Shoes" value="<?= e($purchaseName) ?>"></div><div class="mb-3"><label class="form-label" for="purchaseAmount">Purchase amount (RM)</label><input class="form-control" id="purchaseAmount" name="purchase_amount" type="number" min="0.01" max="9999999999.99" step="0.01" inputmode="decimal" required value="<?= e($purchaseAmount) ?>"></div><div class="mb-3"><label class="form-label" for="purchaseCategory">Category <span class="text-secondary">(optional)</span></label><select class="form-select" id="purchaseCategory" name="category_id"><option value="">No category</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>" <?= $categoryId===(int)$category['id']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select><div class="form-text">Category provides context; it does not change the calculation.</div></div><button class="btn btn-primary" type="submit" <?= $plan['state']!=='ready'?'disabled':'' ?>>Simulate purchase</button></form><p class="small text-secondary mt-4 mb-0">This is a simulation only. Submitting the form does not save an expense, move money, or change your allowance settings.</p></div></div>
    <div class="col-lg-7"><div class="card p-4 h-100" aria-live="polite"><h2 class="h5 mb-2">Financial impact</h2>
    <?php if ($result): ?><p class="text-secondary mb-3"><?= $purchaseName!==''?e($purchaseName):'This purchase' ?><?= $categoryName!==''?' · '.e($categoryName):'' ?> · <?= currency($result['price_cents']/100) ?></p><div class="simulator-comparison"><div><span class="simulator-state">Before purchase</span><small>Available to spend</small><strong><?= currency($result['available_before_cents']/100) ?></strong><small>Suggested daily limit</small><strong><?= $result['daily_before_cents']===null?'—':currency($result['daily_before_cents']/100) ?></strong></div><div class="simulator-arrow" aria-hidden="true">→</div><div><span class="simulator-state">After purchase</span><small>Available to spend</small><strong class="<?= $result['available_after_cents']<0?'text-danger':'' ?>"><?= currency($result['available_after_cents']/100) ?></strong><small>Suggested daily limit</small><strong><?= $result['daily_after_cents']===null?'—':currency($result['daily_after_cents']/100) ?></strong></div></div><div class="simulator-guidance mt-3 <?= $result['available_after_cents']<0?'simulator-warning':'' ?>"><strong>What changes</strong><p class="mb-0 mt-1"><?= e(purchase_guidance($result)) ?></p></div><p class="small text-secondary mt-3 mb-0">Based on the latest recorded transactions, unpaid commitments, and savings reserve. <?= (int)$result['days'] ?> day<?= $result['days']===1?'':'s' ?> until the next allowance. This is guidance, not a guaranteed affordability decision.</p>
    <?php elseif ($plan['state']==='ready'): ?><div class="empty-state"><strong>No purchase simulated yet</strong><span>Enter an amount to compare your available money and daily limit before and after.</span></div><div class="simulator-current"><small>Currently available to spend</small><strong><?= currency($plan['available_cents']/100) ?></strong></div>
    <?php else: ?><div class="empty-state"><strong>Allowance setup needed</strong><span>Add a current allowance date to see a meaningful comparison.</span></div><?php endif; ?>
    </div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
