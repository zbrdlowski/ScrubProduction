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


