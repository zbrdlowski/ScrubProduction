CREATE TABLE IF NOT EXISTS accounting_omega_imports (
  id BIGINT NOT NULL AUTO_INCREMENT,
  original_filename VARCHAR(255) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  detected_encoding VARCHAR(32) DEFAULT NULL,
  source_row_count INT NOT NULL DEFAULT 0,
  invoice_row_count INT NOT NULL DEFAULT 0,
  item_row_count INT NOT NULL DEFAULT 0,
  created_invoice_count INT NOT NULL DEFAULT 0,
  updated_invoice_count INT NOT NULL DEFAULT 0,
  unchanged_invoice_count INT NOT NULL DEFAULT 0,
  matched_order_count INT NOT NULL DEFAULT 0,
  matched_custom_order_count INT NOT NULL DEFAULT 0,
  imported_by INT DEFAULT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounting_omega_import_hash (file_hash),
  KEY ix_accounting_omega_imported_at (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounting_omega_invoices (
  id BIGINT NOT NULL AUTO_INCREMENT,
  invoice_number VARCHAR(100) NOT NULL,
  issue_date DATE NOT NULL,
  order_number VARCHAR(128) DEFAULT NULL,
  payment_type VARCHAR(128) DEFAULT NULL,
  total_amount DECIMAL(14,2) NOT NULL,
  currency VARCHAR(8) NOT NULL DEFAULT 'EUR',
  document_type VARCHAR(32) DEFAULT NULL,
  customer_name VARCHAR(255) DEFAULT NULL,
  item_count INT NOT NULL DEFAULT 0,
  source_hash CHAR(64) NOT NULL,
  raw_json LONGTEXT DEFAULT NULL,
  matched_order_id BIGINT DEFAULT NULL,
  matched_custom_order_id BIGINT DEFAULT NULL,
  first_import_id BIGINT NOT NULL,
  last_import_id BIGINT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounting_omega_invoice_number (invoice_number),
  KEY ix_accounting_omega_issue_date (issue_date),
  KEY ix_accounting_omega_order_number (order_number),
  KEY ix_accounting_omega_payment_type (payment_type),
  KEY ix_accounting_omega_matched_order (matched_order_id),
  KEY ix_accounting_omega_matched_custom (matched_custom_order_id),
  KEY ix_accounting_omega_last_import (last_import_id),
  CONSTRAINT fk_accounting_omega_first_import FOREIGN KEY (first_import_id)
    REFERENCES accounting_omega_imports(id),
  CONSTRAINT fk_accounting_omega_last_import FOREIGN KEY (last_import_id)
    REFERENCES accounting_omega_imports(id),
  CONSTRAINT fk_accounting_omega_order FOREIGN KEY (matched_order_id)
    REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_accounting_omega_custom_order FOREIGN KEY (matched_custom_order_id)
    REFERENCES custom_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounting_omega_invoice_items (
  id BIGINT NOT NULL AUTO_INCREMENT,
  invoice_id BIGINT NOT NULL,
  line_number INT NOT NULL,
  description TEXT DEFAULT NULL,
  quantity DECIMAL(14,4) DEFAULT NULL,
  unit VARCHAR(32) DEFAULT NULL,
  unit_price_without_vat DECIMAL(14,4) DEFAULT NULL,
  raw_json LONGTEXT DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounting_omega_invoice_line (invoice_id, line_number),
  KEY ix_accounting_omega_item_invoice (invoice_id),
  CONSTRAINT fk_accounting_omega_item_invoice FOREIGN KEY (invoice_id)
    REFERENCES accounting_omega_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
