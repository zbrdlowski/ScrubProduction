CREATE TABLE `order_invoices` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_oinv_order` (`order_id`),
  KEY `ix_oinv_number` (`invoice_number`),
  KEY `ix_oinv_created_by` (`created_by`),
  KEY `ix_oinv_deleted` (`deleted_at`),
  CONSTRAINT `fk_oinv_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_oinv_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `order_tracking_numbers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) NOT NULL,
  `tracking_number` varchar(120) NOT NULL,
  `carrier` varchar(80) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_otn_order` (`order_id`),
  KEY `ix_otn_tracking` (`tracking_number`),
  KEY `ix_otn_created_by` (`created_by`),
  KEY `ix_otn_deleted` (`deleted_at`),
  CONSTRAINT `fk_otn_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_otn_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `order_activity`
  ADD COLUMN `entity_type` varchar(64) DEFAULT NULL AFTER `action`,
  ADD COLUMN `entity_id` bigint(20) DEFAULT NULL AFTER `entity_type`,
  ADD COLUMN `note` varchar(255) DEFAULT NULL AFTER `payload`,
  ADD KEY `ix_act_entity` (`entity_type`, `entity_id`);