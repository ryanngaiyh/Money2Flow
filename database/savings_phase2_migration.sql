-- New features Phase 2. Additive, one-time migration for the existing student_finance database.
ALTER TABLE savings_goals
    ADD COLUMN status ENUM('Active','Completed','Archived') NOT NULL DEFAULT 'Active',
    ADD COLUMN completed_at DATETIME NULL,
    ADD COLUMN archived_at DATE NULL,
    ADD COLUMN cycle_month CHAR(7) NULL,
    ADD COLUMN monthly_cycle_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ADD INDEX ix_goals_status_archive (status,completed_at);

CREATE TABLE savings_goal_contributions (
    contribution_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    goal_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    entry_type ENUM('Opening','Deposit','Adjustment') NOT NULL,
    contribution_at DATETIME NOT NULL,
    INDEX ix_contributions_goal_date (goal_id,contribution_at),
    INDEX ix_contributions_user (user_id),
    CONSTRAINT fk_contributions_goal FOREIGN KEY (goal_id) REFERENCES savings_goals(id) ON DELETE CASCADE,
    CONSTRAINT fk_contributions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE savings_goal_monthly_history (
    history_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    goal_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    cycle_month CHAR(7) NOT NULL,
    goal_name VARCHAR(150) NOT NULL,
    target_amount DECIMAL(12,2) NOT NULL,
    achieved_amount DECIMAL(12,2) NOT NULL,
    result ENUM('Achieved','Missed') NOT NULL,
    completed_at DATETIME NULL,
    closed_at DATETIME NULL,
    UNIQUE KEY uq_monthly_goal_cycle (goal_id,cycle_month),
    INDEX ix_monthly_history_user_month (user_id,cycle_month),
    CONSTRAINT fk_monthly_history_goal FOREIGN KEY (goal_id) REFERENCES savings_goals(id) ON DELETE CASCADE,
    CONSTRAINT fk_monthly_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Original contribution dates are unknown. Preserve existing balances as opening entries.
INSERT INTO savings_goal_contributions (goal_id,user_id,amount,entry_type,contribution_at)
SELECT id,user_id,current_amount,'Opening',NOW() FROM savings_goals WHERE current_amount>0;
UPDATE savings_goals SET cycle_month=DATE_FORMAT(NOW(),'%Y-%m'),monthly_cycle_amount=current_amount WHERE goal_type='Monthly';
-- Existing reached targets are first recognized on migration day; no historical completion date is invented.
UPDATE savings_goals SET status='Completed',completed_at=NOW() WHERE goal_type='Target' AND current_amount>=target_amount;
