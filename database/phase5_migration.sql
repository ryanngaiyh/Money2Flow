USE student_finance;

ALTER TABLE budgets
    MODIFY category_id INT UNSIGNED NULL;

ALTER TABLE budgets
    ADD COLUMN IF NOT EXISTS category_key INT UNSIGNED
        AS (IFNULL(category_id, 0)) PERSISTENT AFTER category_id;

ALTER TABLE budgets
    ADD UNIQUE INDEX IF NOT EXISTS uq_budget_scope (user_id, budget_month, category_key);

ALTER TABLE budgets
    DROP INDEX IF EXISTS uq_budget;
