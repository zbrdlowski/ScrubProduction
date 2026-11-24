-- Product builder update 015: recoverable product deletion.
-- Import into the same database after update 014. Existing products remain active.
-- No products, components, SKU values, platform listings or transfers are removed.
SET NAMES utf8mb4;
SET @pb_delete_exists = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='deleted_at');
SET @pb_delete_sql = IF(@pb_delete_exists=0, 'ALTER TABLE product_builder_products ADD COLUMN deleted_at DATETIME NULL', 'DO 0');
PREPARE pb_delete_migration FROM @pb_delete_sql;
EXECUTE pb_delete_migration;
DEALLOCATE PREPARE pb_delete_migration;
SET @pb_delete_exists = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='deleted_by');
SET @pb_delete_sql = IF(@pb_delete_exists=0, 'ALTER TABLE product_builder_products ADD COLUMN deleted_by INT NULL', 'DO 0');
PREPARE pb_delete_migration FROM @pb_delete_sql;
EXECUTE pb_delete_migration;
DEALLOCATE PREPARE pb_delete_migration;
SET @pb_delete_exists = (SELECT COUNT(*) FROM information_schema.statistics
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND index_name='idx_pb_deleted_created');
SET @pb_delete_sql = IF(@pb_delete_exists=0, 'ALTER TABLE product_builder_products ADD INDEX idx_pb_deleted_created (deleted_at,created_at,id)', 'DO 0');
PREPARE pb_delete_migration FROM @pb_delete_sql;
EXECUTE pb_delete_migration;
DEALLOCATE PREPARE pb_delete_migration;
