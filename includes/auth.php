<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function require_login(): void { if (empty($_SESSION['user_id'])) { flash('Please log in to continue.', 'warning'); header('Location: ' . app_url('auth/login.php')); exit; } }
function user_id(): int { return (int)$_SESSION['user_id']; }
function is_logged_in(): bool { return !empty($_SESSION['user_id']); }
function redirect(string $path): never { header('Location: ' . app_url($path)); exit; }
function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('Invalid request token. Please go back and try again.'); } }
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'][] = [$message, $type]; }
function consume_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function valid_date(string $date): bool { $d = DateTime::createFromFormat('Y-m-d', $date); return $d && $d->format('Y-m-d') === $date; }
function amount($value): ?float {
    $input = trim((string)$value);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $input)) return null;
    $parsed = (float)$input;
    return $parsed >= 0.01 && $parsed <= 9999999999.99 ? $parsed : null;
}
function ownership(string $table, int $id): ?array { $s = db()->prepare("SELECT * FROM `$table` WHERE id = ? AND user_id = ?"); $s->execute([$id, user_id()]); return $s->fetch() ?: null; }
function categories(): array { $s = db()->prepare('SELECT id, name FROM categories WHERE user_id = ? ORDER BY name'); $s->execute([user_id()]); return $s->fetchAll(); }
function currency($number): string { return 'RM ' . number_format((float)$number, 2); }
function suggested_category(string $merchant): ?array {
    $merchant = trim($merchant);
    if ($merchant === '') return null;
    $statement = db()->prepare("SELECT c.id category_id,c.name category_name,r.merchant_keyword FROM category_rules r JOIN categories c ON c.id=r.category_id AND c.user_id=r.user_id WHERE r.user_id=? AND LOWER(?) LIKE CONCAT('%',LOWER(r.merchant_keyword),'%') ORDER BY CHAR_LENGTH(r.merchant_keyword) DESC,r.rule_id DESC LIMIT 1");
    $statement->execute([user_id(), $merchant]);
    return $statement->fetch() ?: null;
}
function app_url(string $path = ''): string {
    static $base = null;
    if ($base === null) {
        $root = realpath(__DIR__ . '/..');
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $base = ($root && $documentRoot && str_starts_with(strtolower($root), strtolower($documentRoot))) ? str_replace('\\', '/', substr($root, strlen($documentRoot))) : '';
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function asset_url(string $path): string {
    $relativePath = ltrim($path, '/');
    $filePath = realpath(__DIR__ . '/../' . $relativePath);
    $version = $filePath && is_file($filePath) ? (string)filemtime($filePath) : '1';
    return app_url($relativePath) . '?v=' . rawurlencode($version);
}
