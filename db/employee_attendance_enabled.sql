-- Administrative attendance reports are independent from the tablet/online grid.
-- Existing workers remain available to HR after the migration.
ALTER TABLE employees
  ADD COLUMN attendance_enabled TINYINT(1) NOT NULL DEFAULT 1
  AFTER grid,
  ADD INDEX idx_employees_attendance_reports (attendance_enabled, active);
