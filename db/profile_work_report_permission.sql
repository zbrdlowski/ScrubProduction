INSERT INTO auth_permissions
  (permission_key, module_key, label, description, risk_level, sort_order, is_active, created_at, updated_at)
VALUES
  ('profile.work_report', 'Profile', 'Zobraziť vlastný výkaz práce', 'Zobrazí záložku Výkaz práce v profile a povolí používateľovi filtrovať iba vlastné položky.', 'normal', 520, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  module_key = VALUES(module_key),
  label = VALUES(label),
  description = VALUES(description),
  risk_level = VALUES(risk_level),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active),
  updated_at = NOW();