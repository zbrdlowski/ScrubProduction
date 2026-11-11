-- DARKSCRUB: product listing IMG status and local folder path (update 009).
-- Select the application database in phpMyAdmin. Safe to import repeatedly.
-- Existing and new listings start at the display value ✗ (stored as none).
-- No product, SKU, component or existing path is overwritten.
SET NAMES utf8mb4;

SET @pb_img_exists = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='img_status');
SET @pb_img_sql = IF(@pb_img_exists=0,
 'ALTER TABLE product_builder_products ADD COLUMN img_status VARCHAR(4) NOT NULL DEFAULT ''none''', 'DO 0');
PREPARE pb_img_migration FROM @pb_img_sql;
EXECUTE pb_img_migration;
DEALLOCATE PREPARE pb_img_migration;

SET @pb_img_exists = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='img_path');
SET @pb_img_sql = IF(@pb_img_exists=0,
 'ALTER TABLE product_builder_products ADD COLUMN img_path TEXT NULL', 'DO 0');
PREPARE pb_img_migration FROM @pb_img_sql;
EXECUTE pb_img_migration;
DEALLOCATE PREPARE pb_img_migration;

SET @pb_img_exists = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='img_version');
SET @pb_img_sql = IF(@pb_img_exists=0,
 'ALTER TABLE product_builder_products ADD COLUMN img_version INT UNSIGNED NOT NULL DEFAULT 1', 'DO 0');
PREPARE pb_img_migration FROM @pb_img_sql;
EXECUTE pb_img_migration;
DEALLOCATE PREPARE pb_img_migration;
