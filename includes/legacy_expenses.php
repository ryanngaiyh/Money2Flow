<?php

/**
 * Copies a user's pre-unified-ledger expenses into transactions.
 *
 * Older versions of Money2Flow saved expenses in a separate `expenses` table,
 * which the dashboard and reports do not read.  Keep that table as a historical
 * backup, but mirror each row once into the unified transactions ledger.  The
 * source row ID is stored as an import reference, so this can safely run again
 * on every signed-in visit without creating duplicates.
 */
function migrate_legacy_expenses_for_user(int $ownerId): int
{
    static $checkedUsers = [];

    if (isset($checkedUsers[$ownerId])) {
        return 0;
    }
    $checkedUsers[$ownerId] = true;

    // Newer installations may not have the retired table at all.
    $legacyTableExists = db()->query("SHOW TABLES LIKE 'expenses'")->fetchColumn();
    if (!$legacyTableExists) {
        return 0;
    }

    $statement = db()->prepare("INSERT INTO transactions
        (user_id, category_id, type, amount, description, merchant, transaction_date, source, import_reference)
        SELECT e.user_id, e.category_id, 'Expense', e.amount, e.description, NULL, e.expense_date, 'Import',
               CONCAT('legacy-expense:', e.id)
        FROM expenses e
        LEFT JOIN transactions t
          ON t.user_id = e.user_id
         AND t.import_reference = CONCAT('legacy-expense:', e.id)
        WHERE e.user_id = ? AND t.id IS NULL");
    $statement->execute([$ownerId]);

    return $statement->rowCount();
}
