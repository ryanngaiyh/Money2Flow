USE student_finance;

CREATE TABLE IF NOT EXISTS transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    type ENUM('Income', 'Expense') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(500) NULL,
    merchant VARCHAR(120) NULL,
    transaction_date DATE NOT NULL,
    source ENUM('Manual', 'Receipt', 'Import') NOT NULL DEFAULT 'Manual',
    import_reference VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_transactions_user_date (user_id, transaction_date),
    INDEX ix_transactions_user_type (user_id, type),
    INDEX ix_transactions_category (category_id),
    UNIQUE KEY uq_transactions_import_reference (user_id, import_reference),
    CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_transactions_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT IGNORE INTO transactions
    (user_id, category_id, type, amount, description, merchant, transaction_date, source, import_reference)
SELECT
    user_id,
    NULL,
    'Income',
    amount,
    description,
    source,
    COALESCE(start_date, income_date),
    'Manual',
    CONCAT('legacy-income-', id)
FROM incomes;

INSERT IGNORE INTO transactions
    (user_id, category_id, type, amount, description, merchant, transaction_date, source, import_reference)
SELECT
    user_id,
    category_id,
    'Expense',
    amount,
    description,
    NULL,
    expense_date,
    'Manual',
    CONCAT('legacy-expense-', id)
FROM expenses;
