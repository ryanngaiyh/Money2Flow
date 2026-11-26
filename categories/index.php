<?php
$page_title = 'Categories';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$errors = [];
$editing = null;
$allowedClassifications = ['', 'Need', 'Want'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $categoryId = (int)($_POST['id'] ?? 0);

    if ($action === 'delete_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $statement = db()->prepare('DELETE FROM category_rules WHERE rule_id=? AND user_id=?');
        $statement->execute([$ruleId, user_id()]);
        flash($statement->rowCount() ? 'Merchant rule deleted successfully.' : 'Merchant rule not found.', $statement->rowCount() ? 'success' : 'warning');
        redirect('categories/index.php#merchantRules');
    }

    if ($action === 'delete') {
        $category = ownership('categories', $categoryId);
        if (!$category) {
            flash('Category not found.', 'danger');
            redirect('categories/index.php');
        }

        $used = 0;
        $existingTables = db()->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")
            ->fetchAll(PDO::FETCH_COLUMN);

        if (in_array('expenses', $existingTables, true)) {
            $statement = db()->prepare('SELECT COUNT(*) FROM expenses WHERE category_id = ? AND user_id = ?');
            $statement->execute([$categoryId, user_id()]);
            $used += (int)$statement->fetchColumn();
        }
        if (in_array('budgets', $existingTables, true)) {
            $statement = db()->prepare('SELECT COUNT(*) FROM budgets WHERE category_id = ? AND user_id = ?');
            $statement->execute([$categoryId, user_id()]);
            $used += (int)$statement->fetchColumn();
        }
        if (in_array('transactions', $existingTables, true)) {
            $statement = db()->prepare('SELECT COUNT(*) FROM transactions WHERE category_id = ? AND user_id = ?');
            $statement->execute([$categoryId, user_id()]);
            $used += (int)$statement->fetchColumn();
        }

        if ($used > 0) {
            flash('This category is used by an existing transaction or budget. Reassign those records before deleting it.', 'warning');
        } else {
            db()->prepare('DELETE FROM categories WHERE id = ? AND user_id = ?')->execute([$categoryId, user_id()]);
            flash('Category deleted successfully.');
        }
        redirect('categories/index.php');
    }
    if (!in_array($action, ['create', 'update'], true)) {
        flash('Invalid category action.', 'danger');
        redirect('categories/index.php');
    }
    if ($action === 'update' && !ownership('categories', $categoryId)) {
        flash('Category not found.', 'danger');
        redirect('categories/index.php');
    }

    $categoryName = trim($_POST['name'] ?? '');
    $classification = $_POST['need_want'] ?? '';

    if ($categoryName === '' || mb_strlen($categoryName) > 80) {
        $errors[] = 'Enter a category name of 80 characters or fewer.';
    }
    if (!in_array($classification, $allowedClassifications, true)) {
        $errors[] = 'Select Need, Want, or leave the classification unassigned.';
    }
    $classification = $classification === '' ? null : $classification;

    if (!$errors) {
        $statement = db()->prepare('SELECT id FROM categories WHERE user_id = ? AND LOWER(name) = LOWER(?) AND id <> ?');
        $statement->execute([user_id(), $categoryName, $categoryId]);

        if ($statement->fetch()) {
            $errors[] = 'You already have a category with this name.';
        } elseif ($action === 'update') {
            db()->prepare('UPDATE categories SET name = ?, need_want = ? WHERE id = ? AND user_id = ?')
                ->execute([$categoryName, $classification, $categoryId, user_id()]);
            flash('Category updated successfully.');
            redirect('categories/index.php');
        } else {
            db()->prepare('INSERT INTO categories (user_id, name, need_want) VALUES (?, ?, ?)')
                ->execute([user_id(), $categoryName, $classification]);
            flash('Category created successfully.');
            redirect('categories/index.php');
        }
    }

    $editing = ['id' => $categoryId, 'name' => $categoryName, 'need_want' => $classification];
}

if (isset($_GET['edit'])) {
    $editing = ownership('categories', (int)$_GET['edit']);
    if (!$editing) {
        flash('Category not found.', 'warning');
        redirect('categories/index.php');
    }
}

$classificationFilter = $_GET['classification'] ?? '';
$where = ['user_id = ?'];
$arguments = [user_id()];
if (in_array($classificationFilter, ['Need', 'Want'], true)) {
    $where[] = 'need_want = ?';
    $arguments[] = $classificationFilter;
}

$statement = db()->prepare('SELECT * FROM categories WHERE ' . implode(' AND ', $where) . ' ORDER BY need_want IS NULL, need_want, name');
$statement->execute($arguments);
$categoryRows = $statement->fetchAll();

$statement = db()->prepare('SELECT r.rule_id,r.merchant_keyword,c.name category_name FROM category_rules r JOIN categories c ON c.id=r.category_id AND c.user_id=r.user_id WHERE r.user_id=? ORDER BY r.merchant_keyword');
$statement->execute([user_id()]);
$categoryRules = $statement->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="page-title mb-1">Categories</h1><p class="text-secondary mb-0">Organise spending and optionally classify it as a need or want.</p></div>
    <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#categoryForm">+ Add Category</button>
</div>

<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>

<div class="collapse <?= $editing ? 'show' : '' ?> mb-4" id="categoryForm">
    <div class="card p-4">
        <h5 class="mb-3"><?= $editing ? 'Edit category' : 'Add custom category' ?></h5>
        <form method="post" class="row g-3" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <div class="col-md-6"><label class="form-label" for="name">Category name</label><input class="form-control" id="name" name="name" maxlength="80" required placeholder="e.g. Sports" value="<?= e($editing['name'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label" for="need_want">Classification</label><select class="form-select" id="need_want" name="need_want"><option value="">Unassigned</option><option value="Need" <?= ($editing['need_want'] ?? '') === 'Need' ? 'selected' : '' ?>>Need</option><option value="Want" <?= ($editing['need_want'] ?? '') === 'Want' ? 'selected' : '' ?>>Want</option></select></div>
            <div class="col-md-3 align-self-end"><button class="btn btn-primary" type="submit"><?= $editing ? 'Update Category' : 'Add Category' ?></button><?php if ($editing): ?> <a class="btn btn-light" href="<?= e(app_url('categories/index.php')) ?>">Cancel</a><?php endif; ?></div>
        </form>
    </div>
</div>

<div class="card p-3 mb-4">
    <form class="row g-2 align-items-center">
        <div class="col-md-4"><select class="form-select" name="classification"><option value="">All classifications</option><option value="Need" <?= $classificationFilter === 'Need' ? 'selected' : '' ?>>Needs</option><option value="Want" <?= $classificationFilter === 'Want' ? 'selected' : '' ?>>Wants</option></select></div>
        <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filter</button></div>
        <div class="col-md-6 text-md-end text-secondary small">Need = essential spending · Want = optional spending</div>
    </form>
</div>

<div class="row g-3">
<?php foreach ($categoryRows as $category): ?>
    <div class="col-sm-6 col-xl-4"><div class="card p-3 h-100"><div class="d-flex align-items-start justify-content-between gap-3"><div><div class="fw-bold mb-1"><?= e($category['name']) ?></div><?php if ($category['need_want']): ?><span class="badge <?= $category['need_want']==='Need'?'text-bg-primary':'text-bg-warning' ?>"><?= e($category['need_want']) ?></span><?php else: ?><span class="badge text-bg-light">Unassigned</span><?php endif; ?></div><div class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$category['id'] ?>#categoryForm">Edit</a> <form class="d-inline" method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$category['id'] ?>"><button data-confirm="Delete this category?" class="btn btn-sm btn-outline-danger">Delete</button></form></div></div></div></div>
<?php endforeach; ?>
<?php if (!$categoryRows): ?><div class="col-12"><div class="card p-5 text-center text-secondary"><strong>No categories found.</strong><br>Clear the filter or add a custom category.</div></div><?php endif; ?>
</div>

<div class="card p-4 mt-4" id="merchantRules">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h5 class="mb-1">Saved merchant rules</h5><p class="text-secondary small mb-0">These keywords suggest a category while you enter an expense.</p></div><span class="badge text-bg-light border"><?= count($categoryRules) ?> rule<?= count($categoryRules)===1?'':'s' ?></span></div>
    <?php if ($categoryRules): ?><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Merchant keyword</th><th>Suggested category</th><th></th></tr></thead><tbody><?php foreach ($categoryRules as $rule): ?><tr><td><strong><?= e($rule['merchant_keyword']) ?></strong></td><td><span class="badge text-bg-light border"><?= e($rule['category_name']) ?></span></td><td class="text-end"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="rule_id" value="<?= (int)$rule['rule_id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete this merchant rule?">Delete rule</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><strong>No merchant rules yet</strong><span>Save a transaction with “Remember this merchant” to create one.</span></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
