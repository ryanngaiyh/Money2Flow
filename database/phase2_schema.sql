CREATE DATABASE IF NOT EXISTS student_finance
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

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
    need_want ENUM('Need', 'Want') NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_category_user_name (user_id, name),
    CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS incomes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    source VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    income_type ENUM('Recurring', 'One-time') NOT NULL DEFAULT 'One-time',
    frequency ENUM('Weekly', 'Monthly', 'Yearly', 'N/A') NOT NULL DEFAULT 'N/A',
    start_date DATE NOT NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    income_date DATE NOT NULL,
    description VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_income_user_date (user_id, income_date),
    INDEX ix_income_user_status (user_id, status),
    CONSTRAINT fk_incomes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
