USE student_finance;

CREATE TABLE IF NOT EXISTS category_rules (
    rule_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    merchant_keyword VARCHAR(120) NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_category_rule_keyword (user_id,merchant_keyword),
    INDEX ix_category_rules_category (category_id),
    CONSTRAINT fk_category_rules_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_rules_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO category_rules (user_id,merchant_keyword,category_id)
SELECT c.user_id,m.keyword,c.id
FROM categories c
JOIN (
    SELECT 'McDonald''s' keyword,'Food & Drinks' category_name UNION ALL
    SELECT 'Starbucks','Food & Drinks' UNION ALL
    SELECT 'GrabFood','Food & Drinks' UNION ALL
    SELECT 'Grab','Transportation' UNION ALL
    SELECT 'Shell','Transportation' UNION ALL
    SELECT 'Petron','Transportation' UNION ALL
    SELECT 'Popular Bookstore','Education' UNION ALL
    SELECT 'Netflix','Entertainment' UNION ALL
    SELECT 'Steam','Entertainment' UNION ALL
    SELECT 'Shopee','Shopping'
) m ON m.category_name=c.name
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id);
