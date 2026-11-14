-- Personal boards. employee_id refers to employees.id, not the employee code.
CREATE TABLE IF NOT EXISTS kanban_boards (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  done_limit SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kanban_employee (employee_id),
  CONSTRAINT fk_kanban_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kanban_columns (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  board_id INT UNSIGNED NOT NULL,
  title VARCHAR(80) NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#60a5fa',
  position INT NOT NULL DEFAULT 0,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_kanban_columns (board_id, position, id),
  CONSTRAINT fk_kanban_column_board FOREIGN KEY (board_id) REFERENCES kanban_boards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kanban_cards (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  column_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  priority VARCHAR(10) NOT NULL DEFAULT 'normal',
  due_date DATE NULL,
  label VARCHAR(60) NOT NULL DEFAULT '',
  reference VARCHAR(160) NOT NULL DEFAULT '',
  reference_url VARCHAR(1000) NOT NULL DEFAULT '',
  position INT NOT NULL DEFAULT 0,
  completed_at DATETIME(6) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_kanban_card_order (column_id, position, id),
  KEY idx_kanban_card_done (column_id, completed_at, id),
  CONSTRAINT fk_kanban_card_column FOREIGN KEY (column_id) REFERENCES kanban_columns(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
