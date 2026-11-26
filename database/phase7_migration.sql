USE student_finance;

CREATE TABLE IF NOT EXISTS split_bills (
    split_bill_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(180) NOT NULL,
    bill_date DATE NOT NULL,
    split_type ENUM('Equal','Custom') NOT NULL DEFAULT 'Equal',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_split_bills_user_date (user_id,bill_date),
    CONSTRAINT fk_split_bills_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_split_bill_total_positive CHECK (total_amount > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS split_bill_participants (
    participant_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    split_bill_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    share_amount DECIMAL(12,2) NOT NULL,
    status ENUM('Paid','Pending') NOT NULL DEFAULT 'Pending',
    paid_at DATETIME NULL,
    is_owner TINYINT(1) NOT NULL DEFAULT 0,
    is_payer TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_split_participants_bill_status (split_bill_id,status),
    CONSTRAINT fk_split_participants_bill FOREIGN KEY (split_bill_id) REFERENCES split_bills(split_bill_id) ON DELETE CASCADE,
    CONSTRAINT chk_split_share_nonnegative CHECK (share_amount >= 0)
) ENGINE=InnoDB;
