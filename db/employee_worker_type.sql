-- Distinguishes the contractual relationship from account/activity switches.
-- Existing records are employees by default; contractors can remain Active
-- while staying out of attendance by setting grid = 0.
ALTER TABLE employees
  ADD COLUMN worker_type ENUM('employee', 'contractor') NOT NULL DEFAULT 'employee'
  AFTER active,
  ADD INDEX idx_employees_worker_status (worker_type, active),
  ADD INDEX idx_employees_attendance (grid, active);
