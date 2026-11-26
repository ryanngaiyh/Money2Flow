<?php
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard/index.php');
}

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($name === '' || mb_strlen($name) > 120) {
        $errors[] = 'Please enter your full name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Password confirmation does not match.';
    }

    if (!$errors) {
        $statement = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $statement->execute([$email]);

        if ($statement->fetch()) {
            $errors[] = 'This email is already registered.';
        } else {
            try {
                db()->beginTransaction();
                $statement = db()->prepare('INSERT INTO users (full_name, email, password_hash) VALUES (?, ?, ?)');
                $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $newUserId = (int)db()->lastInsertId();

                // Preserve compatibility with the existing later-phase schema without
                // making categories a requirement for a Phase 1-only installation.
                $categoriesTable = db()->query("SHOW TABLES LIKE 'categories'")->fetchColumn();
                if ($categoriesTable) {
                    $classificationColumn = db()->query("SHOW COLUMNS FROM categories LIKE 'need_want'")->fetchColumn();
                    $defaultCategories = [
                        ['Food & Drinks', 'Need'],
                        ['Transportation', 'Need'],
                        ['Education', 'Need'],
                        ['Entertainment', 'Want'],
                        ['Shopping', 'Want'],
                        ['Bills', 'Need'],
                        ['Health', 'Need'],
                        ['Personal', 'Need'],
                        ['Others', null],
                    ];

                    if ($classificationColumn) {
                        $categoryStatement = db()->prepare('INSERT INTO categories (user_id, name, need_want) VALUES (?, ?, ?)');
                        foreach ($defaultCategories as [$categoryName, $classification]) {
                            $categoryStatement->execute([$newUserId, $categoryName, $classification]);
                        }
                    } else {
                        $categoryStatement = db()->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)');
                        foreach ($defaultCategories as [$categoryName]) {
                            $categoryStatement->execute([$newUserId, $categoryName]);
                        }
                    }
                }

                $rulesTable = db()->query("SHOW TABLES LIKE 'category_rules'")->fetchColumn();
                if ($rulesTable) {
                    $ruleStatement = db()->prepare("INSERT INTO category_rules (user_id,merchant_keyword,category_id)
                        SELECT c.user_id,m.keyword,c.id
                        FROM categories c
                        JOIN (
                            SELECT 'McDonald''s' keyword,'Food & Drinks' category_name UNION ALL
                            SELECT 'Starbucks','Food & Drinks' UNION ALL
                            SELECT 'GrabFood','Food & Drinks' UNION ALL
                            SELECT 'Grab','Transportation' UNION ALL
                            SELECT 'Shell','Transportation' UNION ALL
                            SELECT 'Petron','Transportation' UNION ALL
                            SELECT 'Popular Bookstore','Education' UNION ALL
                            SELECT 'Netflix','Entertainment' UNION ALL
                            SELECT 'Steam','Entertainment' UNION ALL
                            SELECT 'Shopee','Shopping'
                        ) m ON m.category_name=c.name
                        WHERE c.user_id=?");
                    $ruleStatement->execute([$newUserId]);
                }

                db()->commit();
                flash('Registration successful. Please log in.');
                redirect('auth/login.php');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                $errors[] = 'Unable to create your account. Please try again.';
            }
        }
    }
}

$auth_title = 'Register';
require __DIR__ . '/../includes/auth_header.php';
?>
<div class="mb-4">
    <span class="auth-mobile-brand d-lg-none"><?= e(APP_NAME) ?></span>
    <h2 class="fw-bold mb-1">Create your account</h2>
    <p class="text-secondary mb-0">Start building healthier money habits.</p>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <div class="mb-3">
        <label class="form-label" for="name">Full name</label>
        <input class="form-control" id="name" name="name" autocomplete="name" maxlength="120" required value="<?= e($name) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input class="form-control" id="email" name="email" type="email" autocomplete="email" maxlength="190" required value="<?= e($email) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
        <div class="form-text">Use at least 8 characters.</div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="confirm_password">Confirm password</label>
        <input class="form-control" id="confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
    </div>
    <button class="btn btn-primary w-100 py-2" type="submit">Create account</button>
</form>

<p class="text-center mt-4 mb-0">Already registered? <a href="<?= e(app_url('auth/login.php')) ?>">Log in</a></p>
<?php require __DIR__ . '/../includes/auth_footer.php'; ?>
