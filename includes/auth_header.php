<?php
require_once __DIR__ . '/auth.php';
$auth_title = $auth_title ?? APP_NAME;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($auth_title) ?> | <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(asset_url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="auth-page">
<main class="auth-shell">
    <section class="auth-intro d-none d-lg-flex">
        <div>
            <a class="auth-brand" href="<?= e(app_url()) ?>"><?= e(APP_NAME) ?></a>
            <span class="auth-kicker">Built for student life</span>
            <h1>Know where your money goes.</h1>
            <p>Record faster, understand your spending, and build better financial habits without complicated accounting.</p>
            <div class="auth-points">
                <span>✓ Simple RM tracking</span>
                <span>✓ Private student account</span>
                <span>✓ Clear budgets and goals</span>
            </div>
        </div>
    </section>
    <section class="auth-form-panel">
        <div class="auth-card">
