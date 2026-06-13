CREATE TABLE IF NOT EXISTS `status_workflow_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `result_order_status_code` varchar(32) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `stop_on_match` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status_workflow_rules_priority` (`active`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `status_workflow_rule_conditions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_id` int(11) NOT NULL,
  `department` varchar(2) NOT NULL,
  `condition_type` varchar(20) NOT NULL DEFAULT 'status',
  `operator` varchar(20) NOT NULL,
  `status_code` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status_workflow_rule_conditions_rule` (`rule_id`, `sort_order`),
  CONSTRAINT `fk_status_workflow_rule_conditions_rule`
    FOREIGN KEY (`rule_id`) REFERENCES `status_workflow_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
