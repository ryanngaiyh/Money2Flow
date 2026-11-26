<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/recurring_income.php';
$failed=0; $totals=['generated'=>0,'matched'=>0,'review'=>0];
try {
    if (count($argv)>2 || (isset($argv[1]) && !preg_match('/^--user=([1-9][0-9]*)$/D',$argv[1],$match))) throw new InvalidArgumentException('Usage: php process_recurring_income.php [--user=ID]');
    if (isset($match[1])) {
        $q=db()->prepare("SELECT id FROM incomes WHERE status='Active' AND income_type='Recurring' AND user_id=? ORDER BY id");
        $q->execute([(int)$match[1]]); $ids=$q->fetchAll(PDO::FETCH_COLUMN);
    } else $ids=db()->query("SELECT id FROM incomes WHERE status='Active' AND income_type='Recurring' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        try { $result=recurring_process_source(db(),(int)$id); foreach ($totals as $key=>$_) $totals[$key]+=$result[$key]; }
        catch (Throwable $e) { $failed++; error_log('Money2Flow recurring source '.(int)$id.': '.$e->getMessage()); }
    }
    echo json_encode(['sources'=>count($ids),'result'=>$totals,'failed'=>$failed],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) { error_log('Money2Flow recurring job failed: '.$e->getMessage()); exit(1); }
exit($failed ? 1 : 0);
