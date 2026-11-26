-- New features, Phase 1: additive notification storage. Run once on student_finance.
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    notification_type VARCHAR(40) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message VARCHAR(500) NOT NULL,
    related_record_id INT UNSIGNED NULL,
    related_record_type VARCHAR(40) NULL,
    event_key VARCHAR(190) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    read_at DATETIME NULL,
    UNIQUE KEY uq_notification_event (user_id, event_key),
    INDEX ix_notification_inbox (user_id, is_read, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id INT UNSIGNED PRIMARY KEY,
    budget_warning TINYINT(1) NOT NULL DEFAULT 1,
    allowance_reminder TINYINT(1) NOT NULL DEFAULT 1,
    goal_achievement TINYINT(1) NOT NULL DEFAULT 1,
    goal_deadline TINYINT(1) NOT NULL DEFAULT 1,
    split_bill_reminder TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
