-- Transfer D-base. Repeat-safe; also creates the transfer table if update 012 was not imported.
-- Graphics transferred from Orders into a separate Product Builder work queue.
-- Additive and repeat-safe. Existing listings and IMG statuses are unchanged.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS product_builder_transfers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 order_item_id BIGINT UNSIGNED NOT NULL,
 order_id BIGINT UNSIGNED NOT NULL,
 order_number VARCHAR(150) NOT NULL,
 title VARCHAR(255) NOT NULL,
 source_sku VARCHAR(255) NOT NULL DEFAULT '',
 snapshot_json MEDIUMTEXT NOT NULL,
 status ENUM('pending','completed','dismissed') NOT NULL DEFAULT 'pending',
 purpose ENUM('new_listing','update_images') NOT NULL DEFAULT 'new_listing',
 product_id BIGINT UNSIGNED NULL,
 folder_path VARCHAR(2048) NOT NULL DEFAULT '',
 work_note TEXT NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_by INT NULL,
 updated_by INT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 UNIQUE KEY uq_pb_transfer_item (order_item_id),
 KEY idx_pb_transfer_queue (status,created_at,id),
 KEY idx_pb_transfer_product (product_id),
 KEY idx_pb_transfer_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transfer D-base image preparation status; NULL means not decided yet.
SET @pb_transfer_img_exists=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_transfers' AND column_name='img_status');
SET @pb_transfer_img_sql=IF(@pb_transfer_img_exists=0,
 "ALTER TABLE product_builder_transfers ADD COLUMN img_status ENUM('skip','gen','ready') NULL DEFAULT NULL AFTER status", 'DO 0');
PREPARE pb_transfer_img_migration FROM @pb_transfer_img_sql;
EXECUTE pb_transfer_img_migration;
DEALLOCATE PREPARE pb_transfer_img_migration;
