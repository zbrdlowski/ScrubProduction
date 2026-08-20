-- Two explicit workflow positions may be occupied by the same employee.
-- Existing assignment rows remain compatible as WORKER assignments.

ALTER TABLE order_item_assignments
  ADD COLUMN IF NOT EXISTS assignment_role VARCHAR(32) NOT NULL DEFAULT 'WORKER'
  AFTER employee_id;

UPDATE order_item_assignments
SET assignment_role = 'WORKER'
WHERE assignment_role IS NULL OR assignment_role = '';

ALTER TABLE order_item_assignments
  DROP INDEX IF EXISTS uq_oia_item_employee;

ALTER TABLE order_item_assignments
  ADD UNIQUE INDEX IF NOT EXISTS uq_oia_item_employee_role
    (item_id, employee_id, assignment_role),
  ADD INDEX IF NOT EXISTS ix_oia_item_role_active
    (item_id, assignment_role, removed_at);
