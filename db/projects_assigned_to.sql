-- Optional project ownership / responsible employee.
-- Run after the base projects tables exist.

ALTER TABLE `projects`
  ADD COLUMN `assigned_to` int(11) DEFAULT NULL COMMENT 'employee_id responsible for project' AFTER `created_by`,
  ADD KEY `idx_projects_assigned_to` (`assigned_to`);

ALTER TABLE `projects`
  ADD CONSTRAINT `fk_projects_assigned_to`
  FOREIGN KEY (`assigned_to`) REFERENCES `employees` (`id`);
