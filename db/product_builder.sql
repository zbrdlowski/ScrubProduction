-- Independent product builder; no dependency on the legacy catalogue.
CREATE TABLE IF NOT EXISTS product_builder_uni_categories (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 brand VARCHAR(100) NOT NULL, category_name VARCHAR(150) NOT NULL, modelcode VARCHAR(5) NOT NULL,
 included_models TEXT NOT NULL, price_unlicensed DECIMAL(10,2) NULL, price_licensed DECIMAL(10,2) NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pb_uni_name (brand, category_name), UNIQUE KEY uq_pb_uni_modelcode (modelcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS product_builder_boxes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 length_cm DECIMAL(7,2) NOT NULL, width_cm DECIMAL(7,2) NOT NULL, height_cm DECIMAL(7,2) NOT NULL,
 weight_kg DECIMAL(9,3) NOT NULL DEFAULT 0.000,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pb_box_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS product_builder_products (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(80) NOT NULL, name VARCHAR(255) NOT NULL,
 bundle_type VARCHAR(5) NOT NULL, category_kind ENUM('specific','universal') NOT NULL,
 uni_category_id BIGINT UNSIGNED NULL, brand VARCHAR(100) NOT NULL, model VARCHAR(150) NOT NULL,
 year_range VARCHAR(50) NOT NULL DEFAULT '', modelcode VARCHAR(7) NOT NULL, notes TEXT NOT NULL,
 recommended_price DECIMAL(10,2) NULL,
 recommended_currency CHAR(3) NOT NULL DEFAULT 'EUR',
 web_price DECIMAL(10,2) NULL,
 web_currency CHAR(3) NOT NULL DEFAULT 'EUR',
 ebay_price DECIMAL(10,2) NULL,
 ebay_currency CHAR(3) NOT NULL DEFAULT 'EUR',
 amazon_price DECIMAL(10,2) NULL,
 amazon_currency CHAR(3) NOT NULL DEFAULT 'EUR',
 box_id BIGINT UNSIGNED NULL,
 weight_kg DECIMAL(9,3) NULL,
 img_status VARCHAR(4) NOT NULL DEFAULT 'none',
 img_path TEXT NULL,
 img_version INT UNSIGNED NOT NULL DEFAULT 1,
 request_key CHAR(32) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, created_by INT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pb_sku (sku), UNIQUE KEY uq_pb_request (request_key), KEY idx_pb_modelcode (modelcode),
 CONSTRAINT fk_pb_product_box FOREIGN KEY (box_id) REFERENCES product_builder_boxes(id) ON DELETE RESTRICT,
 CONSTRAINT fk_pb_uni FOREIGN KEY (uni_category_id) REFERENCES product_builder_uni_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS product_builder_designs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 component_type ENUM('G','S') NOT NULL, name VARCHAR(255) NOT NULL,
 seed_key CHAR(64) NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pb_design_name (component_type,name), UNIQUE KEY uq_pb_design_seed (seed_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS product_builder_graphics_versions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(30) NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 seed_key VARCHAR(30) NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pb_graphics_version_name (name), UNIQUE KEY uq_pb_graphics_version_seed (seed_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS product_builder_components (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED NOT NULL,
 component_type CHAR(1) NOT NULL, label VARCHAR(255) NOT NULL, reference VARCHAR(100) NOT NULL DEFAULT '',
 color VARCHAR(100) NOT NULL DEFAULT '', design_id BIGINT UNSIGNED NULL, graphics_version_id BIGINT UNSIGNED NULL,
 quantity INT UNSIGNED NOT NULL DEFAULT 1, notes TEXT NOT NULL, options_json LONGTEXT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 CONSTRAINT fk_pb_component_graphics_version FOREIGN KEY (graphics_version_id) REFERENCES product_builder_graphics_versions(id) ON DELETE RESTRICT,
 CONSTRAINT fk_pb_component_design FOREIGN KEY (design_id) REFERENCES product_builder_designs(id) ON DELETE RESTRICT,
 CONSTRAINT fk_pb_component_product FOREIGN KEY (product_id) REFERENCES product_builder_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_builder_channel_listings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 product_id BIGINT UNSIGNED NOT NULL, channel VARCHAR(16) NOT NULL,
 listing_status VARCHAR(16) NOT NULL DEFAULT 'do_not_list',
 listing_url VARCHAR(2048) NULL, listed_price DECIMAL(10,2) NULL,
 listed_currency CHAR(3) NOT NULL DEFAULT 'EUR', listed_date DATE NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
 updated_by INT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pb_channel_product (product_id,channel),
 KEY idx_pb_channel_status (channel,listing_status),
 CONSTRAINT fk_pb_channel_product FOREIGN KEY (product_id) REFERENCES product_builder_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
 img_status ENUM('skip','gen','ready') NULL DEFAULT NULL,
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

-- Multiple model-specific compatibilities per product. Modelcodes may be shared by different models.
CREATE TABLE IF NOT EXISTS product_builder_compatibility (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 product_id BIGINT UNSIGNED NOT NULL,
 brand VARCHAR(100) NOT NULL, model VARCHAR(150) NOT NULL,
 year_range VARCHAR(50) NOT NULL DEFAULT '', modelcode VARCHAR(7) NOT NULL,
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 UNIQUE KEY uq_pb_compatibility (product_id,brand,model,year_range,modelcode),
 KEY idx_pb_compatibility_modelcode (modelcode,product_id),
 CONSTRAINT fk_pb_compatibility_product FOREIGN KEY (product_id) REFERENCES product_builder_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve the original specific category. Re-import never replaces a saved selection.
INSERT INTO product_builder_compatibility (product_id,brand,model,year_range,modelcode,sort_order)
SELECT p.id,p.brand,p.model,p.year_range,p.modelcode,0
FROM product_builder_products p
WHERE p.category_kind='specific' AND p.modelcode<>''
AND NOT EXISTS (SELECT 1 FROM product_builder_compatibility c WHERE c.product_id=p.id);
