<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__.'/../includes/recurring_income.php';
if (count($argv)!==3 || !ctype_digit($argv[1]) || !ctype_digit($argv[2])) {
    fwrite(STDERR,"Usage: php reconcile_recurring_income.php OCCURRENCE_ID TRANSACTION_ID\n"); exit(2);
}
$pdo=db();
try {
    $pdo->beginTransaction();
    $q=$pdo->prepare("SELECT o.*,i.amount FROM recurring_income_occurrences o JOIN incomes i ON i.id=o.income_id AND i.user_id=o.user_id WHERE o.id=? AND o.status='review' FOR UPDATE");
    $q->execute([(int)$argv[1]]); $occurrence=$q->fetch();
    if (!$occurrence) throw new RuntimeException('Pending review not found or source was deleted');
    $q=$pdo->prepare("SELECT * FROM transactions WHERE id=? AND user_id=? AND type='Income' AND transaction_date=? FOR UPDATE");
    $q->execute([(int)$argv[2],$occurrence['user_id'],$occurrence['occurrence_date']]); $transaction=$q->fetch();
    if (!$transaction || (string)$transaction['amount'] !== (string)$occurrence['amount']) throw new RuntimeException('Transaction owner, type, date, or amount differs');
    $q=$pdo->prepare("UPDATE recurring_income_occurrences SET transaction_id=?,status='matched' WHERE id=? AND status='review'");
    $q->execute([(int)$argv[2],(int)$argv[1]]);
    $pdo->commit(); echo "Reconciliation saved; the transaction was not modified.\n";
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
