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
