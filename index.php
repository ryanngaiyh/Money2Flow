<?php require_once __DIR__ . '/includes/auth.php'; header('Location: ' . app_url(empty($_SESSION['user_id']) ? 'auth/login.php' : 'dashboard/index.php')); exit;
