CREATE DATABASE IF NOT EXISTS student_finance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE student_finance;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    need_want ENUM('Need','Want') NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_category_user_name (user_id,name),
    CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS incomes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    source VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    income_type ENUM('Recurring','One-time') NOT NULL DEFAULT 'One-time',
    frequency ENUM('Weekly','Monthly','Yearly','N/A') NOT NULL DEFAULT 'N/A',
    start_date DATE NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    income_date DATE NOT NULL,
    description VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_income_user_date (user_id,income_date),
    CONSTRAINT fk_incomes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    type ENUM('Income','Expense') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(500) NULL,
    merchant VARCHAR(120) NULL,
    transaction_date DATE NOT NULL,
    source ENUM('Manual','Receipt','Import') NOT NULL DEFAULT 'Manual',
    import_reference VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_transactions_user_date (user_id,transaction_date),
    INDEX ix_transactions_user_type (user_id,type),
    INDEX ix_transactions_category (category_id),
    UNIQUE KEY uq_transactions_import_reference (user_id,import_reference),
    CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_transactions_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS budgets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    category_key INT UNSIGNED AS (IFNULL(category_id,0)) PERSISTENT,
    budget_amount DECIMAL(12,2) NOT NULL,
    budget_month CHAR(7) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_budget_scope (user_id,budget_month,category_key),
    CONSTRAINT fk_budgets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_budgets_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    CONSTRAINT chk_budget_positive CHECK (budget_amount > 0)
) ENGINE=InnoDB;
