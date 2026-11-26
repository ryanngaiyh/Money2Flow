<?php
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard/index.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Please enter a valid email and password.';
    } else {
        $statement = db()->prepare('SELECT id, full_name, password_hash FROM users WHERE email = ? LIMIT 1');
        $statement->execute([$email]);
        $user = $statement->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            redirect('dashboard/index.php');
        }

        $error = 'Invalid email or password.';
    }
}

$auth_title = 'Login';
require __DIR__ . '/../includes/auth_header.php';
?>
<div class="mb-4">
    <span class="auth-mobile-brand d-lg-none"><?= e(APP_NAME) ?></span>
    <h2 class="fw-bold mb-1">Welcome back</h2>
    <p class="text-secondary mb-0">Log in to view your student finance dashboard.</p>
</div>

<?php foreach (consume_flashes() as [$message, $type]): ?>
    <div class="alert alert-<?= e($type) ?>"><?= e($message) ?></div>
<?php endforeach; ?>

<?php if ($error): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input class="form-control" id="email" name="email" type="email" autocomplete="email" required value="<?= e($email) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn-primary w-100 py-2" type="submit">Log in</button>
</form>

<p class="text-center mt-4 mb-0">New to <?= e(APP_NAME) ?>? <a href="<?= e(app_url('auth/register.php')) ?>">Create an account</a></p>
<?php require __DIR__ . '/../includes/auth_footer.php'; ?>
