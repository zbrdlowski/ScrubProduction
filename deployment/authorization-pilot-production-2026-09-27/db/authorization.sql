CREATE TABLE IF NOT EXISTS auth_permissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  permission_key VARCHAR(120) NOT NULL,
  module_key VARCHAR(64) NOT NULL,
  label VARCHAR(160) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  risk_level ENUM('normal','sensitive','critical') NOT NULL DEFAULT 'normal',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_permission_key (permission_key),
  KEY ix_auth_permission_module (module_key, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_key VARCHAR(80) NOT NULL,
  label VARCHAR(120) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_role_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_auth_role_permission_role FOREIGN KEY (role_id) REFERENCES auth_roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_auth_role_permission_permission FOREIGN KEY (permission_id) REFERENCES auth_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_employee_roles (
  employee_id INT NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  assigned_by INT DEFAULT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_id, role_id),
  KEY ix_auth_employee_role_role (role_id),
  CONSTRAINT fk_auth_employee_role_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_auth_employee_role_role FOREIGN KEY (role_id) REFERENCES auth_roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_auth_employee_role_actor FOREIGN KEY (assigned_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_employee_overrides (
  employee_id INT NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  effect ENUM('allow','deny') NOT NULL,
  set_by INT DEFAULT NULL,
  set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_id, permission_id),
  KEY ix_auth_override_permission (permission_id),
  CONSTRAINT fk_auth_override_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_auth_override_permission FOREIGN KEY (permission_id) REFERENCES auth_permissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_auth_override_actor FOREIGN KEY (set_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_permission_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  changed_by INT DEFAULT NULL,
  previous_json LONGTEXT DEFAULT NULL,
  current_json LONGTEXT DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_auth_audit_employee_time (employee_id, created_at),
  CONSTRAINT fk_auth_audit_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_auth_audit_actor FOREIGN KEY (changed_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO auth_permissions
  (permission_key, module_key, label, description, risk_level, sort_order)
VALUES
  ('access.manage', 'Administration', 'Spravovať oprávnenia', 'Pridávať roly a individuálne povolenia alebo zákazy používateľom.', 'critical', 10),
  ('accounting.view', 'Accounting', 'Zobraziť účtovníctvo', 'Zobraziť eBay payouty, OMEGA faktúry a pripravené exporty.', 'sensitive', 100),
  ('accounting.import', 'Accounting', 'Importovať účtovné dáta', 'Importovať payout CSV a OMEGA TXT/TSV súbory.', 'critical', 110),
  ('accounting.export', 'Accounting', 'Vytvárať a sťahovať exporty', 'Exportovať Vycuc a pripravovať alebo sťahovať OMEGA TXT balíky.', 'critical', 120)
ON DUPLICATE KEY UPDATE
  module_key = VALUES(module_key),
  label = VALUES(label),
  description = VALUES(description),
  risk_level = VALUES(risk_level),
  sort_order = VALUES(sort_order),
  is_active = 1;

INSERT INTO auth_roles (role_key, label, description, is_system, sort_order)
VALUES
  ('administrator', 'Administrator', 'Plný prístup ku všetkým centrálnym oprávneniam.', 1, 10),
  ('accounting_viewer', 'Accounting – prezeranie', 'Môže účtovníctvo iba prezerať.', 1, 100),
  ('accounting_operator', 'Accounting – operátor', 'Môže účtovníctvo prezerať, importovať aj exportovať.', 1, 110)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  description = VALUES(description),
  is_system = VALUES(is_system),
  sort_order = VALUES(sort_order),
  is_active = 1;

INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM auth_roles r
JOIN auth_permissions p
WHERE r.role_key = 'administrator';

INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM auth_roles r
JOIN auth_permissions p ON p.permission_key = 'accounting.view'
WHERE r.role_key = 'accounting_viewer';

INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM auth_roles r
JOIN auth_permissions p ON p.permission_key IN ('accounting.view', 'accounting.import', 'accounting.export')
WHERE r.role_key = 'accounting_operator';

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL
FROM employees e
JOIN auth_roles r ON r.role_key = 'administrator'
WHERE e.permission = 900;

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL
FROM employees e
JOIN auth_roles r ON r.role_key = 'accounting_operator'
WHERE e.permission <> 900 AND e.position_id IN (1, 3);
