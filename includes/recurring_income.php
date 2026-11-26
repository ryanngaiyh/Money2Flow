<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

/** Calculate each occurrence from the original anchor, avoiding month-end drift. */
function recurring_date(string $anchor, string $frequency, int $index): DateTimeImmutable {
    $zone = new DateTimeZone('Asia/Kuala_Lumpur');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $anchor, $zone);
    if (!$date || $date->format('Y-m-d') !== $anchor || $index < 0) throw new InvalidArgumentException('Invalid recurring schedule');
    if ($frequency === 'Weekly') return $date->modify('+' . (7 * $index) . ' days');
    if ($frequency === 'Monthly') {
        $first = $date->modify('first day of this month')->modify('+' . $index . ' months');
        return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min((int)$date->format('j'), (int)$first->format('t')));
    }
    if ($frequency === 'Yearly') {
        $first = $date->setDate((int)$date->format('Y') + $index, (int)$date->format('n'), 1);
        return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min((int)$date->format('j'), (int)$first->format('t')));
    }
    throw new InvalidArgumentException('Unsupported recurring frequency');
}

function recurring_process_source(PDO $pdo, int $sourceId, ?int $ownerId = null, ?DateTimeImmutable $clock = null): array {
    $today = ($clock ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'))->setTime(0,0);
    $result = ['generated'=>0,'matched'=>0,'review'=>0];
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT * FROM incomes WHERE id=?'.($ownerId === null ? '' : ' AND user_id=?').' FOR UPDATE');
        $stmt->execute($ownerId === null ? [$sourceId] : [$sourceId,$ownerId]);
        $source=$stmt->fetch();
        if (!$source || $source['status'] !== 'Active' || $source['income_type'] !== 'Recurring') { $pdo->commit(); return $result; }
        $stmt=$pdo->prepare('INSERT IGNORE INTO recurring_income_state (income_id,user_id,effective_from) VALUES (?,?,?)');
        $stmt->execute([$sourceId,$source['user_id'],$source['start_date']]);
        $stmt=$pdo->prepare('SELECT effective_from FROM recurring_income_state WHERE income_id=? AND user_id=?');
        $stmt->execute([$sourceId,$source['user_id']]);
        $effective=$stmt->fetchColumn();
        if (!$effective) throw new RuntimeException('Recurring income state ownership mismatch');
        for ($i=0; $i<10000; $i++) {
            $date=recurring_date($source['start_date'],$source['frequency'],$i);
            if ($date > $today) break;
            $day=$date->format('Y-m-d');
            if ($day < $effective) continue;
            $stmt=$pdo->prepare('SELECT id FROM recurring_income_occurrences WHERE income_id=? AND occurrence_date=?');
            $stmt->execute([$sourceId,$day]);
            if ($stmt->fetchColumn()) continue;
            // An existing unlinked income on this date may be a manual payment.
            // Do not create a duplicate where the historical identity is uncertain.
            $stmt=$pdo->prepare("SELECT t.* FROM transactions t LEFT JOIN recurring_income_occurrences o ON o.transaction_id=t.id WHERE t.user_id=? AND t.type='Income' AND t.transaction_date=? AND o.id IS NULL FOR UPDATE");
            $stmt->execute([$source['user_id'],$day]);
            $candidates=$stmt->fetchAll(); $matches=[];
            foreach ($candidates as $candidate) {
                $legacy=$candidate['import_reference'] === 'legacy-income:' . $sourceId || $candidate['import_reference'] === 'legacy-income-' . $sourceId;
                $name=mb_strtolower(trim($source['source']));
                $named=mb_strtolower(trim((string)$candidate['merchant'])) === $name || mb_strtolower(trim((string)$candidate['description'])) === $name;
                if ((string)$candidate['amount'] === (string)$source['amount'] && ($legacy || $named)) $matches[]=$candidate;
            }
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM incomes WHERE user_id=? AND id<>? AND status='Active' AND income_type='Recurring' AND amount=?");
            $stmt->execute([$source['user_id'],$sourceId,$source['amount']]);
            $otherSameAmount=(int)$stmt->fetchColumn()>0;
            if (count($matches)===1 && !$otherSameAmount) {
                $transactionId=(int)$matches[0]['id']; $status='matched';
            } elseif ($candidates) {
                $transactionId=null; $status='review';
                error_log("Money2Flow recurring income: review required for user {$source['user_id']}, source $sourceId, date $day");
            } else {
                $stmt=$pdo->prepare("INSERT INTO transactions (user_id,category_id,type,amount,description,merchant,transaction_date,source,import_reference) VALUES (?,NULL,'Income',?,?,?,?,'Import',?)");
                $stmt->execute([$source['user_id'],$source['amount'],$source['description'],$source['source'],$day,"recurring:$sourceId:$day"]);
                $transactionId=(int)$pdo->lastInsertId(); $status='generated';
            }
            $stmt=$pdo->prepare('INSERT INTO recurring_income_occurrences (income_id,user_id,occurrence_date,transaction_id,status,created_at) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$sourceId,$source['user_id'],$day,$transactionId,$status,$today->format('Y-m-d H:i:s')]);
            $result[$status]++;
        }
        if ($i===10000) throw new RuntimeException('Recurring schedule exceeds safety limit');
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function recurring_process_user(int $ownerId): void {
    try {
        $stmt=db()->prepare("SELECT id FROM incomes WHERE user_id=? AND income_type='Recurring' AND status='Active' ORDER BY id");
        $stmt->execute([$ownerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) recurring_process_source(db(),(int)$id,$ownerId);
        $stmt=db()->prepare("SELECT i.source,o.occurrence_date FROM recurring_income_occurrences o LEFT JOIN incomes i ON i.id=o.income_id AND i.user_id=o.user_id WHERE o.user_id=? AND o.status='review' ORDER BY o.occurrence_date LIMIT 5");
        $stmt->execute([$ownerId]);
        $pending=$stmt->fetchAll();
        if ($pending) {
            $items=array_map(fn($r)=>($r['source'] ?: 'Deleted source').' ('.$r['occurrence_date'].')',$pending);
            flash('Recurring income needs manual reconciliation: '.implode(', ', $items).'. No duplicate was created.', 'warning');
        }
    } catch (Throwable $e) {
        error_log('Money2Flow recurring income processing failed for user '.$ownerId.': '.$e->getMessage());
        flash('Recurring income could not be checked. Please contact your administrator.', 'warning');
    }
}
