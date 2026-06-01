-- Holiday / time off planner module.
-- Run once before opening index.php?page=holidays in production.

ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `holiday_planner_enabled` tinyint(1) NOT NULL DEFAULT 0
  COMMENT 'Visible in holiday planner' AFTER `active`;

UPDATE `employees`
SET `holiday_planner_enabled` = 1
WHERE `active` = 'Active';

CREATE TABLE IF NOT EXISTS `holiday_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `request_type` enum('holiday','toil','doctor','sick','other') NOT NULL DEFAULT 'holiday',
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `note` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `employee_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_holiday_employee` (`employee_id`),
  KEY `idx_holiday_dates` (`start_date`, `end_date`),
  KEY `idx_holiday_status` (`status`),
  CONSTRAINT `fk_holiday_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `fk_holiday_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `employees` (`id`),
  CONSTRAINT `fk_holiday_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
