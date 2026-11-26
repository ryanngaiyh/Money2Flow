USE student_finance;

ALTER TABLE savings_goals
    ADD COLUMN IF NOT EXISTS goal_type ENUM('Monthly','Target') NOT NULL DEFAULT 'Target' AFTER goal_name;

ALTER TABLE savings_goals
    ADD INDEX IF NOT EXISTS ix_goals_user_type (user_id, goal_type);
