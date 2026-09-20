CREATE TABLE IF NOT EXISTS order_multishipping_groups (
  id BIGINT NOT NULL AUTO_INCREMENT,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  tracking_number VARCHAR(120) DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  exported_by INT DEFAULT NULL,
  exported_at DATETIME DEFAULT NULL,
  shipped_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_multishipping_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_multishipping_orders (
  group_id BIGINT NOT NULL,
  order_id BIGINT NOT NULL,
  position INT NOT NULL DEFAULT 0,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, order_id),
  UNIQUE KEY uq_multishipping_order (order_id),
  UNIQUE KEY uq_multishipping_position (group_id, position),
  KEY ix_multishipping_group (group_id),
  CONSTRAINT fk_multishipping_group FOREIGN KEY (group_id)
    REFERENCES order_multishipping_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_multishipping_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_fedex_export_locks (
  order_id BIGINT NOT NULL,
  group_id BIGINT DEFAULT NULL,
  export_token VARCHAR(64) NOT NULL,
  exported_by INT DEFAULT NULL,
  exported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  active TINYINT(1) NOT NULL DEFAULT 1,
  released_by INT DEFAULT NULL,
  released_at DATETIME DEFAULT NULL,
  PRIMARY KEY (order_id),
  KEY ix_fedex_export_active (active, exported_at),
  KEY ix_fedex_export_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
