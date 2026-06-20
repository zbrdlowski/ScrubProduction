CREATE TABLE IF NOT EXISTS `custom_order_number_sequences` (
  `prefix_code` varchar(8) NOT NULL,
  `current_value` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`prefix_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `custom_order_number_sequences` (`prefix_code`, `current_value`) VALUES
  ('SO', 0),
  ('GO', 0),
  ('SC', 0);

CREATE TABLE IF NOT EXISTS `custom_order_contacts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `display_name` varchar(255) DEFAULT NULL,
  `social_platform` varchar(64) DEFAULT NULL,
  `social_handle` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `country` varchar(2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_custom_order_contacts_lookup` (`display_name`(120), `social_handle`(120), `email`(120), `phone`(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `custom_orders` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `internal_code` varchar(32) NOT NULL,
  `official_order_number` varchar(32) DEFAULT NULL,
  `official_prefix` varchar(8) DEFAULT NULL,
  `owner_employee_id` int(11) DEFAULT NULL,
  `owner_assigned_by` int(11) DEFAULT NULL,
  `owner_assigned_at` datetime DEFAULT NULL,
  `contact_directory_id` bigint(20) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'LEAD',
  `complexity_level` tinyint(4) NOT NULL DEFAULT 1,
  `source_channel` varchar(64) DEFAULT NULL,
  `social_platform` varchar(64) DEFAULT NULL,
  `social_handle` varchar(255) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(64) DEFAULT NULL,
  `customer_country` varchar(2) DEFAULT NULL,
  `bike_brand` varchar(128) DEFAULT NULL,
  `bike_model` varchar(128) DEFAULT NULL,
  `bike_year` varchar(32) DEFAULT NULL,
  `bike_details` varchar(255) DEFAULT NULL,
  `rider_name` varchar(255) DEFAULT NULL,
  `rider_number` varchar(64) DEFAULT NULL,
  `shipping_name` varchar(255) DEFAULT NULL,
  `shipping_company` varchar(255) DEFAULT NULL,
  `shipping_street` varchar(255) DEFAULT NULL,
  `shipping_city` varchar(128) DEFAULT NULL,
  `shipping_zip` varchar(32) DEFAULT NULL,
  `shipping_country` varchar(2) DEFAULT NULL,
  `shipping_email` varchar(255) DEFAULT NULL,
  `shipping_phone` varchar(64) DEFAULT NULL,
  `shipping_method` varchar(128) DEFAULT NULL,
  `shipping_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(8) NOT NULL DEFAULT 'EUR',
  `deposit_revision_limit` tinyint(4) NOT NULL DEFAULT 3,
  `deposit_revision_used` tinyint(4) NOT NULL DEFAULT 0,
  `graphics_brief` text DEFAULT NULL,
  `customer_notes` text DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `bike_photo_urls` text DEFAULT NULL,
  `reference_urls` text DEFAULT NULL,
  `last_contact_at` datetime DEFAULT NULL,
  `next_followup_at` datetime DEFAULT NULL,
  `dead_order_flag` tinyint(1) NOT NULL DEFAULT 0,
  `production_order_id` bigint(20) DEFAULT NULL,
  `exported_at` datetime DEFAULT NULL,
  `exported_by` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_custom_orders_internal_code` (`internal_code`),
  UNIQUE KEY `ux_custom_orders_official_order_number` (`official_order_number`),
  KEY `ix_custom_orders_status` (`status`),
  KEY `ix_custom_orders_owner_employee_id` (`owner_employee_id`),
  KEY `ix_custom_orders_contact_directory_id` (`contact_directory_id`),
  KEY `ix_custom_orders_production_order_id` (`production_order_id`),
  CONSTRAINT `fk_custom_orders_contact_directory` FOREIGN KEY (`contact_directory_id`) REFERENCES `custom_order_contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `custom_orders`
  ADD COLUMN IF NOT EXISTS `owner_employee_id` int(11) DEFAULT NULL AFTER `official_prefix`,
  ADD COLUMN IF NOT EXISTS `owner_assigned_by` int(11) DEFAULT NULL AFTER `owner_employee_id`,
  ADD COLUMN IF NOT EXISTS `owner_assigned_at` datetime DEFAULT NULL AFTER `owner_assigned_by`;

ALTER TABLE `custom_orders`
  ADD KEY IF NOT EXISTS `ix_custom_orders_owner_employee_id` (`owner_employee_id`);

UPDATE `custom_orders`
SET `owner_employee_id` = `created_by`,
    `owner_assigned_by` = COALESCE(`owner_assigned_by`, `created_by`),
    `owner_assigned_at` = COALESCE(`owner_assigned_at`, `created_at`)
WHERE `owner_employee_id` IS NULL
  AND `created_by` IS NOT NULL;

CREATE TABLE IF NOT EXISTS `custom_order_items` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `custom_order_id` bigint(20) NOT NULL,
  `line_no` int(11) NOT NULL DEFAULT 1,
  `item_type_code` varchar(10) NOT NULL DEFAULT 'G',
  `sku` varchar(128) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `custom_label` varchar(255) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_upsell` tinyint(1) NOT NULL DEFAULT 0,
  `upsell_source` varchar(128) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'DRAFT',
  `options_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options_json`)),
  `internal_options_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`internal_options_json`)),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_custom_order_items_order` (`custom_order_id`),
  CONSTRAINT `fk_custom_order_items_order` FOREIGN KEY (`custom_order_id`) REFERENCES `custom_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `custom_order_payments` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `custom_order_id` bigint(20) NOT NULL,
  `payment_kind` varchar(32) NOT NULL DEFAULT 'DEPOSIT',
  `paypal_transaction_id` varchar(128) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(8) NOT NULL DEFAULT 'EUR',
  `received_at` datetime DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_custom_order_payments_order` (`custom_order_id`),
  KEY `ix_custom_order_payments_paypal` (`paypal_transaction_id`),
  CONSTRAINT `fk_custom_order_payments_order` FOREIGN KEY (`custom_order_id`) REFERENCES `custom_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `custom_order_followups` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `custom_order_id` bigint(20) NOT NULL,
  `contacted_at` datetime NOT NULL,
  `channel` varchar(64) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_custom_order_followups_order` (`custom_order_id`),
  CONSTRAINT `fk_custom_order_followups_order` FOREIGN KEY (`custom_order_id`) REFERENCES `custom_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `custom_order_activity` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `custom_order_id` bigint(20) NOT NULL,
  `actor_employee_id` int(11) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_custom_order_activity_order` (`custom_order_id`),
  CONSTRAINT `fk_custom_order_activity_order` FOREIGN KEY (`custom_order_id`) REFERENCES `custom_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
