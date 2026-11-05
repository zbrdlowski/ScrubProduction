-- Product builder update 003: store the per-component fields used by Custom Orders.
-- Import into the existing application database. Existing products, SKUs, colors and notes are preserved.
-- Safe to import repeatedly. Requires the base product builder installation (including update 002).
SET @pb_has_options = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_components' AND column_name='options_json');
SET @pb_options_sql = IF(@pb_has_options=0,
 'ALTER TABLE product_builder_components ADD COLUMN options_json LONGTEXT NULL AFTER notes',
 'DO 0');
PREPARE pb_options_migration FROM @pb_options_sql;
EXECUTE pb_options_migration;
DEALLOCATE PREPARE pb_options_migration;
