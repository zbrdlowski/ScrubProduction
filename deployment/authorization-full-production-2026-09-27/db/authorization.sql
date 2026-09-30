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
  ('accounting.export', 'Accounting', 'Vytvárať a sťahovať exporty', 'Exportovať Vycuc a pripravovať alebo sťahovať OMEGA TXT balíky.', 'critical', 120),
  ('orders.view', 'Orders', 'Zobraziť objednávky', 'Zobraziť zoznam a detail production objednávok.', 'normal', 200),
  ('orders.work', 'Orders', 'Pracovať na objednávkach', 'Prevziať objednávku alebo položku, meniť pracovný stav, poznámky a fotografie.', 'normal', 210),
  ('orders.manage', 'Orders', 'Spravovať objednávky', 'Meniť hlavičku, položky, priority, typy a produkčný workflow.', 'sensitive', 220),
  ('orders.financial', 'Orders', 'Spravovať financie objednávok', 'Faktúry, finančné korekcie, sumy a potvrdenie platby.', 'critical', 230),
  ('orders.shipping', 'Orders', 'Spravovať dopravu', 'Tracking, FedEx a multishipping naprieč oboma shipping pracoviskami.', 'sensitive', 240),
  ('orders.admin', 'Orders', 'Administrácia workflow objednávok', 'Inštalácia a zmena systémových pravidiel objednávok.', 'critical', 250),
  ('custom_orders.view', 'Custom Orders', 'Zobraziť custom objednávky', 'Zobraziť zoznam a detail custom objednávok.', 'normal', 300),
  ('custom_orders.work', 'Custom Orders', 'Pracovať na custom objednávkach', 'Poznámky, fotografie, prevzatie a pracovné statusy.', 'normal', 310),
  ('custom_orders.manage', 'Custom Orders', 'Spravovať custom objednávky', 'Vytvárať, upravovať, duplikovať a prideľovať custom objednávky.', 'sensitive', 320),
  ('custom_orders.financial', 'Custom Orders', 'Spravovať platby custom objednávok', 'Pridávať a odstraňovať platby custom objednávok.', 'critical', 330),
  ('custom_orders.export', 'Custom Orders', 'Exportovať custom objednávky', 'Prideliť oficiálne číslo a exportovať objednávku do produkcie.', 'critical', 340),
  ('custom_orders.delete', 'Custom Orders', 'Vymazávať custom objednávky', 'Vymazať objednávku alebo jej evidované dáta.', 'critical', 350),
  ('custom_orders.audit', 'Custom Orders', 'Zobraziť audit poznámok', 'Zobraziť vymazané poznámky a predchádzajúce verzie textu.', 'critical', 360),
  ('plastics.view', 'Plastics Stock', 'Zobraziť sklad plastov', 'Dashboard, zásoby, pohyby, položky, police a skladové objednávky.', 'normal', 400),
  ('plastics.work', 'Plastics Stock', 'Skenovať a presúvať zásoby', 'Skenovať príjem/výdaj, premiestňovať a rozkladať kity.', 'sensitive', 410),
  ('plastics.purchase', 'Plastics Stock', 'Pripravovať nákupné objednávky', 'Pripraviť, upraviť a vytvoriť objednávku dodávateľovi.', 'sensitive', 420),
  ('plastics.receive', 'Plastics Stock', 'Prijímať dodávky', 'Prijať skladovú objednávku a vytvoriť skladové pohyby.', 'sensitive', 430),
  ('plastics.manage', 'Plastics Stock', 'Spravovať skladové dáta', 'Položky, Min/Max, police, dodávatelia a korekcie skladu.', 'critical', 440),
  ('plastics.reports', 'Plastics Stock', 'Zobraziť skladové reporty', 'Interné pohyby, využitie políc a medziročné štatistiky.', 'normal', 450)
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
  ('access_manager', 'Správca oprávnení', 'Môže prideľovať roly a individuálne oprávnenia bez ostatných administrátorských práv.', 1, 20),
  ('accounting_viewer', 'Accounting – prezeranie', 'Môže účtovníctvo iba prezerať.', 1, 100),
  ('accounting_operator', 'Accounting – operátor', 'Môže účtovníctvo prezerať, importovať aj exportovať.', 1, 110),
  ('production_worker', 'Production worker', 'Základná práca na production a custom objednávkach.', 1, 200),
  ('order_manager', 'Orders manager', 'Správa production objednávok a workflow.', 1, 210),
  ('finance_manager', 'Orders finance', 'Financie a doprava production objednávok.', 1, 220),
  ('custom_orders_manager', 'Custom Orders manager', 'Kompletná správa custom objednávok vrátane platieb a exportu.', 1, 300),
  ('warehouse_worker', 'Plastics warehouse worker', 'Skladové operácie, objednávanie a príjem plastov.', 1, 400),
  ('warehouse_manager', 'Plastics warehouse manager', 'Kompletná správa skladu plastov vrátane nastavení a reportov.', 1, 410)
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
JOIN auth_permissions p ON p.permission_key = 'access.manage'
WHERE r.role_key = 'access_manager';

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

INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth_roles r JOIN auth_permissions p ON p.permission_key IN ('orders.view','orders.work','custom_orders.view','custom_orders.work')
WHERE r.role_key = 'production_worker';

INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth_roles r JOIN auth_permissions p ON p.permission_key IN ('orders.view','orders.work','orders.manage')
WHERE r.role_key = 'order_manager';

INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth_roles r JOIN auth_permissions p ON p.permission_key IN ('orders.view','orders.financial','orders.shipping')
WHERE r.role_key = 'finance_manager';

INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth_roles r JOIN auth_permissions p ON p.permission_key IN ('custom_orders.view','custom_orders.work','custom_orders.manage','custom_orders.financial','custom_orders.export','custom_orders.delete')
WHERE r.role_key = 'custom_orders_manager';

INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth_roles r JOIN auth_permissions p ON p.permission_key IN ('plastics.view','plastics.work','plastics.purchase','plastics.receive','plastics.reports')
WHERE r.role_key = 'warehouse_worker';

INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth_roles r JOIN auth_permissions p ON p.permission_key LIKE 'plastics.%'
WHERE r.role_key = 'warehouse_manager';

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL
FROM employees e
JOIN auth_roles r ON r.role_key = 'administrator'
WHERE e.permission = 900;

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL
FROM employees e
JOIN auth_roles r ON r.role_key = 'access_manager'
WHERE e.id = 3;

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL
FROM employees e
JOIN auth_roles r ON r.role_key = 'accounting_operator'
WHERE e.permission <> 900 AND e.position_id IN (1, 3);

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL FROM employees e JOIN auth_roles r ON r.role_key = 'production_worker'
WHERE e.permission >= 1;

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL FROM employees e JOIN auth_roles r ON r.role_key = 'order_manager'
WHERE e.permission >= 300;

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL FROM employees e JOIN auth_roles r ON r.role_key = 'finance_manager'
WHERE e.permission >= 400;

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL FROM employees e JOIN auth_roles r ON r.role_key = 'custom_orders_manager'
WHERE e.permission >= 300;

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL FROM employees e JOIN auth_roles r ON r.role_key = 'warehouse_worker'
WHERE e.position_id = 6;

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL FROM employees e JOIN auth_roles r ON r.role_key = 'warehouse_manager'
WHERE e.permission >= 500;

INSERT INTO auth_employee_overrides (employee_id, permission_id, effect, set_by)
SELECT e.id, p.id, 'allow', NULL
FROM employees e
JOIN auth_permissions p ON p.permission_key = 'custom_orders.audit'
WHERE e.id IN (3, 5)
ON DUPLICATE KEY UPDATE effect = effect;
