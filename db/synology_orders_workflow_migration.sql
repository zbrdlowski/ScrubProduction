-- ScrubProduction Orders Workflow migration
-- Source: diff between synology_scrub_orders_workflow.sql and home_scrub_orders_workflow.sql
-- Target: MariaDB 10.x
-- Safe intent: add missing workflow/order columns, tables, indexes and foreign keys without deleting data.
--
-- IMPORTANT:
-- 1) Make a DB backup before running.
-- 2) Run on Synology/MariaDB as a user with ALTER/CREATE permissions.
-- 3) This script uses INFORMATION_SCHEMA guards so it can be re-run safely in MariaDB.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS=0;

-- ---------------------------------------------------------------------
-- Helper procedures
-- ---------------------------------------------------------------------
DELIMITER //

DROP PROCEDURE IF EXISTS add_col_if_missing//
CREATE PROCEDURE add_col_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DROP PROCEDURE IF EXISTS add_idx_if_missing//
CREATE PROCEDURE add_idx_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DROP PROCEDURE IF EXISTS add_fk_if_missing//
CREATE PROCEDURE add_fk_if_missing(
    IN p_table VARCHAR(64),
    IN p_fk VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND CONSTRAINT_NAME = p_fk
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_fk, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DELIMITER ;

-- ---------------------------------------------------------------------
-- Missing columns: orders
-- ---------------------------------------------------------------------
CALL add_col_if_missing('orders', 'manual_types_override', '`manual_types_override` varchar(20) DEFAULT NULL');
CALL add_col_if_missing('orders', 'manual_types_updated_by', '`manual_types_updated_by` int(11) DEFAULT NULL');
CALL add_col_if_missing('orders', 'manual_types_updated_at', '`manual_types_updated_at` datetime DEFAULT NULL');
CALL add_col_if_missing('orders', 'production_note', '`production_note` text DEFAULT NULL');
CALL add_col_if_missing('orders', 'production_note_updated_by', '`production_note_updated_by` int(11) DEFAULT NULL');
CALL add_col_if_missing('orders', 'production_note_updated_at', '`production_note_updated_at` datetime DEFAULT NULL');
CALL add_col_if_missing('orders', 'traffic_light', '`traffic_light` varchar(20) DEFAULT ''RED''');
CALL add_col_if_missing('orders', 'traffic_blocker', '`traffic_blocker` varchar(5) DEFAULT NULL');
CALL add_col_if_missing('orders', 'traffic_summary_json', '`traffic_summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`traffic_summary_json`))');

-- ---------------------------------------------------------------------
-- Missing columns: order_items
-- ---------------------------------------------------------------------
CALL add_col_if_missing('order_items', 'deleted_at', '`deleted_at` datetime DEFAULT NULL');
CALL add_col_if_missing('order_items', 'created_by', '`created_by` int(11) DEFAULT NULL');
CALL add_col_if_missing('order_items', 'updated_by', '`updated_by` int(11) DEFAULT NULL');
CALL add_col_if_missing('order_items', 'updated_at', '`updated_at` datetime DEFAULT NULL');
CALL add_col_if_missing('order_items', 'status', '`status` varchar(50) DEFAULT ''NEW''');
CALL add_col_if_missing('order_items', 'waiting_note', '`waiting_note` text DEFAULT NULL');
CALL add_col_if_missing('order_items', 'expected_date', '`expected_date` date DEFAULT NULL');
CALL add_col_if_missing('order_items', 'completed_by', '`completed_by` int(11) DEFAULT NULL');
CALL add_col_if_missing('order_items', 'completed_at', '`completed_at` datetime DEFAULT NULL');

-- ---------------------------------------------------------------------
-- Missing columns: order_activity
-- ---------------------------------------------------------------------
CALL add_col_if_missing('order_activity', 'entity_type', '`entity_type` varchar(64) DEFAULT NULL');
CALL add_col_if_missing('order_activity', 'entity_id', '`entity_id` bigint(20) DEFAULT NULL');
CALL add_col_if_missing('order_activity', 'note', '`note` varchar(255) DEFAULT NULL');

-- ---------------------------------------------------------------------
-- New tables
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_item_statuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_item_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `note` text DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_oist_item` (`order_item_id`),
  KEY `ix_oist_changed_by` (`changed_by`),
  KEY `ix_oist_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `order_invoices` (
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
  KEY `ix_oinv_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `order_tracking_numbers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) NOT NULL,
  `tracking_number` varchar(120) NOT NULL,
  `carrier` varchar(80) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_tracking_order_number_active` (`order_id`,`tracking_number`,`deleted_at`),
  KEY `ix_otn_order` (`order_id`),
  KEY `ix_otn_tracking` (`tracking_number`),
  KEY `ix_otn_created_by` (`created_by`),
  KEY `ix_otn_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Missing indexes on existing tables
-- ---------------------------------------------------------------------
CALL add_idx_if_missing('orders', 'ix_orders_order_number', 'KEY `ix_orders_order_number` (`order_number`)');
CALL add_idx_if_missing('orders', 'ix_orders_external_order_id', 'KEY `ix_orders_external_order_id` (`external_order_id`)');
CALL add_idx_if_missing('orders', 'ix_orders_production_note_updated_by', 'KEY `ix_orders_production_note_updated_by` (`production_note_updated_by`)');
CALL add_idx_if_missing('orders', 'ix_orders_manual_types_updated_by', 'KEY `ix_orders_manual_types_updated_by` (`manual_types_updated_by`)');

CALL add_idx_if_missing('order_activity', 'ix_act_entity', 'KEY `ix_act_entity` (`entity_type`,`entity_id`)');

CALL add_idx_if_missing('order_items', 'ix_order_items_deleted_at', 'KEY `ix_order_items_deleted_at` (`deleted_at`)');
CALL add_idx_if_missing('order_items', 'ix_order_items_created_by', 'KEY `ix_order_items_created_by` (`created_by`)');
CALL add_idx_if_missing('order_items', 'ix_order_items_updated_by', 'KEY `ix_order_items_updated_by` (`updated_by`)');

-- ---------------------------------------------------------------------
-- Foreign keys added in home/dev schema.
-- These are guarded. They require the employees table to exist, which it should in production.
-- ---------------------------------------------------------------------
CALL add_fk_if_missing('orders', 'fk_orders_manual_types_updated_by', 'FOREIGN KEY (`manual_types_updated_by`) REFERENCES `employees` (`id`)');
CALL add_fk_if_missing('orders', 'fk_orders_production_note_updated_by', 'FOREIGN KEY (`production_note_updated_by`) REFERENCES `employees` (`id`)');

CALL add_fk_if_missing('order_items', 'fk_order_items_created_by', 'FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`)');
CALL add_fk_if_missing('order_items', 'fk_order_items_updated_by', 'FOREIGN KEY (`updated_by`) REFERENCES `employees` (`id`)');

CALL add_fk_if_missing('order_invoices', 'fk_oinv_order', 'FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)');
CALL add_fk_if_missing('order_invoices', 'fk_oinv_created_by', 'FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`)');

CALL add_fk_if_missing('order_tracking_numbers', 'fk_otn_order', 'FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)');
CALL add_fk_if_missing('order_tracking_numbers', 'fk_otn_created_by', 'FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`)');

-- Optional FK for status history. Home export did not have it, but it is useful and guarded.
-- If this causes any issue on your Synology, comment out the next line and re-run.
CALL add_fk_if_missing('order_item_statuses', 'fk_oist_item', 'FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`)');

-- ---------------------------------------------------------------------
-- Backfill safe defaults for existing production data
-- ---------------------------------------------------------------------
UPDATE `orders`
SET `traffic_light` = 'RED'
WHERE `traffic_light` IS NULL OR `traffic_light` = '';

UPDATE `order_items`
SET `status` = 'NEW'
WHERE `status` IS NULL OR `status` = '';

-- ---------------------------------------------------------------------
-- Clean up helper procedures
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS add_col_if_missing;
DROP PROCEDURE IF EXISTS add_idx_if_missing;
DROP PROCEDURE IF EXISTS add_fk_if_missing;

SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
