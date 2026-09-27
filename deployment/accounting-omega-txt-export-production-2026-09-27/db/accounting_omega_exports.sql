CREATE TABLE IF NOT EXISTS accounting_omega_export_settings (
  id TINYINT NOT NULL,
  current_customer_number INT NOT NULL DEFAULT 2602995,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO accounting_omega_export_settings (id, current_customer_number)
VALUES (1, 2602995);

CREATE TABLE IF NOT EXISTS accounting_omega_export_batches (
  id BIGINT NOT NULL AUTO_INCREMENT,
  processing_date DATE NOT NULL,
  import_from DATE NOT NULL,
  import_to DATE NOT NULL,
  custom_workday DATE NOT NULL,
  order_count INT NOT NULL DEFAULT 0,
  partner_count INT NOT NULL DEFAULT 0,
  waiting_payout_count INT NOT NULL DEFAULT 0,
  blocked_count INT NOT NULL DEFAULT 0,
  partners_downloaded_at DATETIME DEFAULT NULL,
  invoices_downloaded_at DATETIME DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_accounting_omega_export_batches_created (created_at),
  KEY ix_accounting_omega_export_batches_processing (processing_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounting_omega_export_items (
  id BIGINT NOT NULL AUTO_INCREMENT,
  batch_id BIGINT NOT NULL,
  order_id BIGINT NOT NULL,
  source_code VARCHAR(32) NOT NULL,
  readiness_basis VARCHAR(32) NOT NULL,
  partner_code VARCHAR(20) NOT NULL,
  payout_transaction_id BIGINT DEFAULT NULL,
  exchange_rate DECIMAL(16,8) DEFAULT NULL,
  total_eur DECIMAL(14,2) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounting_omega_export_order (order_id),
  UNIQUE KEY uq_accounting_omega_export_partner_code (partner_code),
  KEY ix_accounting_omega_export_items_batch (batch_id),
  CONSTRAINT fk_accounting_omega_export_items_batch FOREIGN KEY (batch_id)
    REFERENCES accounting_omega_export_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_accounting_omega_export_items_order FOREIGN KEY (order_id)
    REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
