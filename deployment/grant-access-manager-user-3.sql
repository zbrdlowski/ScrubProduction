-- DarkScrub: povolenie správy používateľských oprávnení pre employee ID 3.
-- Predpoklad: centrálna autorizácia z db/authorization.sql je už nainštalovaná.
-- Skript je bezpečné spustiť opakovane.

START TRANSACTION;

INSERT INTO auth_roles
  (role_key, label, description, is_system, is_active, sort_order)
VALUES
  ('access_manager', 'Správca oprávnení',
   'Môže prideľovať roly a individuálne oprávnenia bez ostatných administrátorských práv.',
   1, 1, 20)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  description = VALUES(description),
  is_system = 1,
  is_active = 1,
  sort_order = VALUES(sort_order);

INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM auth_roles r
JOIN auth_permissions p ON p.permission_key = 'access.manage'
WHERE r.role_key = 'access_manager';

INSERT IGNORE INTO auth_employee_roles (employee_id, role_id, assigned_by)
SELECT e.id, r.id, NULL
FROM employees e
JOIN auth_roles r ON r.role_key = 'access_manager'
WHERE e.id = 3;

COMMIT;

-- Kontrola: výsledkom má byť práve jeden riadok s employee_id = 3.
SELECT
  er.employee_id,
  r.role_key,
  r.label,
  p.permission_key
FROM auth_employee_roles er
JOIN auth_roles r ON r.id = er.role_id
JOIN auth_role_permissions rp ON rp.role_id = r.id
JOIN auth_permissions p ON p.id = rp.permission_id
WHERE er.employee_id = 3
  AND r.role_key = 'access_manager'
  AND p.permission_key = 'access.manage';
