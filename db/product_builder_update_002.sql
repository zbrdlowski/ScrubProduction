-- Product builder update 002: editable SKU, plastic item color and expandable components.
-- Select the existing DarkScrub database in phpMyAdmin and import this file before uploading the updated PHP files.
-- Existing products, SKUs and component details are preserved. Safe to import more than once.
SET @pb_has_color = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_components' AND column_name='color');
SET @pb_color_sql = IF(@pb_has_color=0,
 'ALTER TABLE product_builder_components ADD COLUMN color VARCHAR(100) NOT NULL DEFAULT '''' AFTER reference',
 'DO 0');
PREPARE pb_color_migration FROM @pb_color_sql;
EXECUTE pb_color_migration;
DEALLOCATE PREPARE pb_color_migration;
