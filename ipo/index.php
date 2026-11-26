<?php
$page_title = 'How Money2Flow Works';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="finance-banner mb-4"><span class="pill">DIPLOMA FYP SYSTEM MODEL</span><h1 class="mt-2 mb-1 fw-bold">Input → Process → Output</h1><p class="mb-0 text-white-50">A clear explanation of how Money2Flow turns student finance records into useful results.</p></div>

<div class="row g-3 mb-4">
    <div class="col-lg-4"><section class="ipo-step ipo-input"><div class="fw-bold text-primary mb-2">1 · INPUT</div><p class="mb-2">The student provides finance information.</p><ul class="small mb-0"><li>Registration and login details</li><li>Income and expense transactions</li><li>Categories, budgets, and savings goals</li><li>Split bills and participant shares</li><li>Merchant rules and receipt images</li></ul></section></div>
    <div class="col-lg-4"><section class="ipo-step ipo-process"><div class="fw-bold text-warning-emphasis mb-2">2 · PROCESS</div><p class="mb-2">The PHP application validates and calculates using MySQL records.</p><ul class="small mb-0"><li>Authenticate users and enforce record ownership</li><li>Calculate income, expenses, balance, and trends</li><li>Compare spending with budgets</li><li>Calculate savings and shared-expense progress</li><li>Match merchant rules and extract receipt text</li></ul></section></div>
    <div class="col-lg-4"><section class="ipo-step ipo-output"><div class="fw-bold text-success mb-2">3 · OUTPUT</div><p class="mb-2">The student receives understandable financial information.</p><ul class="small mb-0"><li>Dashboard totals and recent activity</li><li>Budget warnings and savings requirements</li><li>Category, needs-versus-wants, and trend charts</li><li>Split-bill balances and settlement states</li><li>Reports and rule-based financial insights</li></ul></section></div>
</div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card p-4 h-100"><h4 class="fw-bold">Security and privacy</h4><p class="text-secondary mb-0">Every financial record belongs to one authenticated user. Forms use CSRF protection, passwords are hashed, database work uses prepared statements, and ownership checks prevent one account from viewing or changing another account’s records.</p></div></div>
    <div class="col-lg-6"><div class="card p-4 h-100"><h4 class="fw-bold">System boundaries</h4><p class="text-secondary mb-2">Money2Flow is a student finance tracker, not a banking platform.</p><ul class="small text-secondary mb-0"><li>No real payment transfers</li><li>No paid OCR or external banking connection</li><li>No AI-generated financial advice</li><li>No blockchain, social network, or unnecessary admin panel</li></ul></div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
