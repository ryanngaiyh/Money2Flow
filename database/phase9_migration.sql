USE student_finance;

CREATE TABLE IF NOT EXISTS receipt_uploads (
    receipt_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    stored_name VARCHAR(100) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(50) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    ocr_text MEDIUMTEXT NULL,
    ocr_status ENUM('Pending','Completed','Unavailable','Failed') NOT NULL DEFAULT 'Pending',
    ocr_engine VARCHAR(50) NULL,
    ocr_confidence DECIMAL(5,2) NULL,
    detected_merchant VARCHAR(120) NULL,
    detected_amount DECIMAL(12,2) NULL,
    detected_date DATE NULL,
    transaction_id INT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_receipt_stored_name (stored_name),
    UNIQUE KEY uq_receipt_transaction (transaction_id),
    INDEX ix_receipts_user_created (user_id,created_at),
    CONSTRAINT fk_receipts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_receipts_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB;
