-- Product builder update 008: a selected box and manual total packed weight per product.
-- Import after update 007 into the existing DarkScrub application database.
-- Old products remain without a box or weight. Existing data is preserved on repeat import.
-- Total weight is entered manually in kg, including packaging; it is not calculated from box weight.
SET NAMES utf8mb4;
SET @pb_has_box_link = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='box_id');
SET @pb_box_sql = IF(@pb_has_box_link=0,
 'ALTER TABLE product_builder_products ADD COLUMN box_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE pb_box_migration FROM @pb_box_sql;
EXECUTE pb_box_migration;
DEALLOCATE PREPARE pb_box_migration;
SET @pb_has_product_weight = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='weight_kg');
SET @pb_box_sql = IF(@pb_has_product_weight=0,
 'ALTER TABLE product_builder_products ADD COLUMN weight_kg DECIMAL(9,3) NULL', 'DO 0');
PREPARE pb_box_migration FROM @pb_box_sql;
EXECUTE pb_box_migration;
DEALLOCATE PREPARE pb_box_migration;
SET @pb_has_box_fk = (SELECT COUNT(*) FROM information_schema.table_constraints
 WHERE constraint_schema=DATABASE() AND table_name='product_builder_products' AND constraint_name='fk_pb_product_box');
SET @pb_box_sql = IF(@pb_has_box_fk=0,
 'ALTER TABLE product_builder_products ADD CONSTRAINT fk_pb_product_box FOREIGN KEY (box_id) REFERENCES product_builder_boxes(id) ON DELETE RESTRICT', 'DO 0');
PREPARE pb_box_migration FROM @pb_box_sql;
EXECUTE pb_box_migration;
DEALLOCATE PREPARE pb_box_migration;
