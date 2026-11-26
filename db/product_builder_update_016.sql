-- Product builder update 016: manual transfers, independent of Orders.
-- Repeat-safe. Existing order transfers, statuses and product links are preserved.
SET NAMES utf8mb4;
SET @pb_manual_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_transfers' AND column_name='order_item_id' AND is_nullable='YES');
SET @pb_manual_sql = IF(@pb_manual_exists=0, 'ALTER TABLE product_builder_transfers MODIFY COLUMN order_item_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE pb_manual_migration FROM @pb_manual_sql;
EXECUTE pb_manual_migration;
DEALLOCATE PREPARE pb_manual_migration;
SET @pb_manual_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_transfers' AND column_name='order_id' AND is_nullable='YES');
SET @pb_manual_sql = IF(@pb_manual_exists=0, 'ALTER TABLE product_builder_transfers MODIFY COLUMN order_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE pb_manual_migration FROM @pb_manual_sql;
EXECUTE pb_manual_migration;
DEALLOCATE PREPARE pb_manual_migration;
SET @pb_manual_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_transfers' AND column_name='source_kind');
SET @pb_manual_sql = IF(@pb_manual_exists=0, 'ALTER TABLE product_builder_transfers ADD COLUMN source_kind ENUM(''order'',''manual'') NOT NULL DEFAULT ''order''', 'DO 0');
PREPARE pb_manual_migration FROM @pb_manual_sql;
EXECUTE pb_manual_migration;
DEALLOCATE PREPARE pb_manual_migration;
SET @pb_manual_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_transfers' AND column_name='request_key');
SET @pb_manual_sql = IF(@pb_manual_exists=0, 'ALTER TABLE product_builder_transfers ADD COLUMN request_key CHAR(32) NULL', 'DO 0');
PREPARE pb_manual_migration FROM @pb_manual_sql;
EXECUTE pb_manual_migration;
DEALLOCATE PREPARE pb_manual_migration;
SET @pb_manual_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='product_builder_transfers' AND index_name='uq_pb_transfer_request');
SET @pb_manual_sql = IF(@pb_manual_exists=0, 'ALTER TABLE product_builder_transfers ADD UNIQUE KEY uq_pb_transfer_request (request_key)', 'DO 0');
PREPARE pb_manual_migration FROM @pb_manual_sql;
EXECUTE pb_manual_migration;
DEALLOCATE PREPARE pb_manual_migration;
