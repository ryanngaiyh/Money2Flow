CREATE TABLE IF NOT EXISTS recurring_income_state (
  income_id INT UNSIGNED NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  effective_from DATE NOT NULL,
  CONSTRAINT fk_ri_state_source FOREIGN KEY (income_id) REFERENCES incomes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS recurring_income_occurrences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  income_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  occurrence_date DATE NOT NULL,
  transaction_id INT UNSIGNED NULL,
  status ENUM('generated','matched','review') NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_recurring_occurrence (income_id,occurrence_date),
  UNIQUE KEY uq_recurring_transaction (transaction_id),
  KEY ix_recurring_owner (user_id,occurrence_date),
  CONSTRAINT fk_ri_occ_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_occ_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB;
