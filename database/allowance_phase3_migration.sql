-- Phase 3 only. Run once against an existing student_finance database.
CREATE TABLE IF NOT EXISTS allowance_settings (
    user_id INT UNSIGNED PRIMARY KEY,
    mode ENUM('Source','Manual') NOT NULL,
    income_source_id INT UNSIGNED NULL,
    manual_amount DECIMAL(12,2) NULL,
    frequency ENUM('Weekly','Monthly','Yearly','Irregular') NOT NULL DEFAULT 'Irregular',
    next_allowance_date DATE NOT NULL,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    reserved_savings DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_allowance_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_allowance_settings_income FOREIGN KEY (income_source_id) REFERENCES incomes(id) ON DELETE SET NULL,
    CONSTRAINT chk_allowance_reserved CHECK (reserved_savings >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS allowance_commitments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    due_date DATE NOT NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX ix_allowance_commitment_period (user_id, paid_at, due_date),
    CONSTRAINT fk_allowance_commitment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_allowance_commitment_amount CHECK (amount > 0)
) ENGINE=InnoDB;
