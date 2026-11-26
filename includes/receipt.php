<?php
require_once __DIR__ . '/auth.php';

function receipt_storage_directory(): string
{
    return __DIR__ . '/../storage/receipts';
}

function receipt_file_path(string $storedName): ?string
{
    if ($storedName === '' || basename($storedName) !== $storedName) return null;
    $path = receipt_storage_directory() . DIRECTORY_SEPARATOR . $storedName;
    return is_file($path) ? $path : null;
}

function owned_receipt(int $receiptId): ?array
{
    $statement = db()->prepare('SELECT * FROM receipt_uploads WHERE receipt_id=? AND user_id=?');
    $statement->execute([$receiptId, user_id()]);
    return $statement->fetch() ?: null;
}

function local_tesseract_path(): ?string
{
    $configuredPath = trim((string)getenv('TESSERACT_PATH'));
    $candidates = array_filter([
        $configuredPath,
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
        'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
    ]);
    foreach ($candidates as $candidate) if (is_file($candidate)) return $candidate;
    return null;
}

function run_local_receipt_ocr(string $imagePath): array
{
    $executable = local_tesseract_path();
    if (!$executable || !function_exists('proc_open')) return ['status' => 'Unavailable', 'text' => '', 'engine' => null];

    $pipes = [];
    $process = @proc_open([$executable, $imagePath, 'stdout', '-l', 'eng', '--psm', '6'], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) return ['status' => 'Failed', 'text' => '', 'engine' => 'Local Tesseract'];

    fclose($pipes[0]);
    $text = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || trim((string)$text) === '') return ['status' => 'Failed', 'text' => '', 'engine' => 'Local Tesseract', 'error' => trim((string)$error)];
    return ['status' => 'Completed', 'text' => trim($text), 'engine' => 'Local Tesseract'];
}

function receipt_date_from_text(string $text): ?string
{
    $patterns = [
        '/\b(20\d{2})[-\/.](0?[1-9]|1[0-2])[-\/.](0?[1-9]|[12]\d|3[01])\b/' => 'Y-m-d',
        '/\b(0?[1-9]|[12]\d|3[01])[-\/.](0?[1-9]|1[0-2])[-\/.](20\d{2}|\d{2})\b/' => 'd-m-Y',
    ];
    foreach ($patterns as $pattern => $format) {
        if (!preg_match($pattern, $text, $match)) continue;
        if ($format === 'Y-m-d') {
            $date = sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3]);
        } else {
            $year = (int)$match[3];
            if ($year < 100) $year += $year >= 70 ? 1900 : 2000;
            $date = sprintf('%04d-%02d-%02d', $year, $match[2], $match[1]);
        }
        if (valid_date($date)) return $date;
    }
    return null;
}

function receipt_amount_from_text(string $text): ?float
{
    $lines = preg_split('/\R/u', $text) ?: [];
    $amountPattern = '/(?:RM\s*)?((?:\d{1,3}(?:,\d{3})*|\d+)\.\d{2})\b/i';
    foreach ($lines as $line) {
        if (!preg_match('/\b(grand\s*total|total\s*amount|amount\s*due|net\s*total|total)\b/i', $line)) continue;
        if (preg_match_all($amountPattern, $line, $matches) && $matches[1]) {
            $value = (float)str_replace(',', '', end($matches[1]));
            if ($value > 0) return round($value, 2);
        }
    }
    preg_match_all($amountPattern, $text, $matches);
    $values = array_filter(array_map(fn($value) => (float)str_replace(',', '', $value), $matches[1] ?? []), fn($value) => $value > 0);
    return $values ? round(max($values), 2) : null;
}

function receipt_merchant_from_text(string $text): ?string
{
    $knownRule = suggested_category($text);
    if ($knownRule) return $knownRule['merchant_keyword'];

    $lines = preg_split('/\R/u', $text) ?: [];
    foreach ($lines as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line));
        if ($line === '' || mb_strlen($line) > 120 || !preg_match('/[A-Za-z]{2}/', $line)) continue;
        if (preg_match('/\b(receipt|tax invoice|official|thank you|welcome|date|time|cashier|subtotal|total|change|cash|visa|mastercard|sst|gst|tel|phone)\b/i', $line)) continue;
        if (preg_match('/^\W*\d[\d\W]*$/', $line)) continue;
        return $line;
    }
    return null;
}

function parse_receipt_text(string $text): array
{
    $text = trim(str_replace("\0", '', $text));
    $merchant = receipt_merchant_from_text($text);
    $suggestion = $merchant ? suggested_category($merchant) : null;
    return [
        'merchant' => $merchant,
        'amount' => receipt_amount_from_text($text),
        'date' => receipt_date_from_text($text),
        'category_id' => $suggestion ? (int)$suggestion['category_id'] : null,
        'category_name' => $suggestion['category_name'] ?? null,
    ];
}
