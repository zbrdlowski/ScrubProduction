-- Additive migration for subcategory-aware item statuses.
-- Existing rows remain available to their whole department.

CREATE TABLE IF NOT EXISTS status_definition_targets (
  status_definition_id INT(11) NOT NULL,
  target_type VARCHAR(20) NOT NULL,
  subcategory_code VARCHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY (status_definition_id, target_type, subcategory_code),
  KEY idx_status_definition_targets_lookup (target_type, subcategory_code),
  CONSTRAINT fk_status_definition_targets_definition
    FOREIGN KEY (status_definition_id) REFERENCES status_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO status_definition_targets (status_definition_id, target_type, subcategory_code)
SELECT sd.id, 'ALL', ''
FROM status_definitions sd
LEFT JOIN status_definition_targets sdt ON sdt.status_definition_id = sd.id
WHERE sd.scope = 'item' AND sdt.status_definition_id IS NULL;
