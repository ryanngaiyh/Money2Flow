USE student_finance;

ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS need_want ENUM('Need', 'Want') NULL AFTER name;

ALTER TABLE incomes
    ADD COLUMN IF NOT EXISTS income_type ENUM('Recurring', 'One-time') NOT NULL DEFAULT 'One-time' AFTER amount,
    ADD COLUMN IF NOT EXISTS frequency ENUM('Weekly', 'Monthly', 'Yearly', 'N/A') NOT NULL DEFAULT 'N/A' AFTER income_type,
    ADD COLUMN IF NOT EXISTS start_date DATE NULL AFTER frequency,
    ADD COLUMN IF NOT EXISTS status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active' AFTER start_date;

UPDATE incomes
SET start_date = income_date
WHERE start_date IS NULL;

ALTER TABLE incomes
    MODIFY start_date DATE NOT NULL;

UPDATE categories current_category
LEFT JOIN categories preferred_category
    ON preferred_category.user_id = current_category.user_id
    AND preferred_category.name = 'Food & Drinks'
SET current_category.name = 'Food & Drinks'
WHERE current_category.name = 'Food'
  AND preferred_category.id IS NULL;

INSERT INTO categories (user_id, name, need_want)
SELECT users.id, defaults.category_name, defaults.classification
FROM users
CROSS JOIN (
    SELECT 'Food & Drinks' category_name, 'Need' classification
    UNION ALL SELECT 'Transportation', 'Need'
    UNION ALL SELECT 'Education', 'Need'
    UNION ALL SELECT 'Entertainment', 'Want'
    UNION ALL SELECT 'Shopping', 'Want'
    UNION ALL SELECT 'Bills', 'Need'
    UNION ALL SELECT 'Health', 'Need'
    UNION ALL SELECT 'Personal', 'Need'
    UNION ALL SELECT 'Others', NULL
) defaults
LEFT JOIN categories existing
    ON existing.user_id = users.id
    AND existing.name = defaults.category_name
WHERE existing.id IS NULL;

UPDATE categories
SET need_want = CASE
    WHEN name IN ('Food & Drinks', 'Food', 'Transportation', 'Education', 'Bills', 'Health', 'Personal') THEN 'Need'
    WHEN name IN ('Entertainment', 'Shopping') THEN 'Want'
    ELSE need_want
END
WHERE need_want IS NULL;
