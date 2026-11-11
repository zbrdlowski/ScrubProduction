-- DARKSCRUB: Web/eBay/Amazon listing workflow (update 010).
-- Select the application database in phpMyAdmin. Safe to import repeatedly.
-- Missing channel records mean Do not list. Existing suggested prices and products are preserved.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS product_builder_channel_listings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 product_id BIGINT UNSIGNED NOT NULL, channel VARCHAR(16) NOT NULL,
 listing_status VARCHAR(16) NOT NULL DEFAULT 'do_not_list',
 listing_url VARCHAR(2048) NULL, listed_price DECIMAL(10,2) NULL,
 listed_currency CHAR(3) NOT NULL DEFAULT 'EUR', version INT UNSIGNED NOT NULL DEFAULT 1,
 updated_by INT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pb_channel_product (product_id,channel),
 KEY idx_pb_channel_status (channel,listing_status),
 CONSTRAINT fk_pb_channel_product FOREIGN KEY (product_id) REFERENCES product_builder_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
