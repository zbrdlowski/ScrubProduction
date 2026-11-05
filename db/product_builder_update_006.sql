-- Product builder update 006: recommended price and independent Web/eBay/Amazon prices and currencies.
-- Import into the existing DarkScrub database after update 005 and before uploading the PHP files.
-- Existing products and SKUs are preserved. Old prices remain unset (NULL); currencies default to EUR.
-- Reimport preserves all entered prices and currencies. No exchange-rate conversion is performed.
SET NAMES utf8mb4;

SET @pb_has_price_column = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='recommended_price');
SET @pb_price_sql = IF(@pb_has_price_column=0,
 'ALTER TABLE product_builder_products ADD COLUMN recommended_price DECIMAL(10,2) NULL', 'DO 0');
PREPARE pb_price_migration FROM @pb_price_sql;
EXECUTE pb_price_migration;
DEALLOCATE PREPARE pb_price_migration;

SET @pb_has_price_column = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='recommended_currency');
SET @pb_price_sql = IF(@pb_has_price_column=0,
 'ALTER TABLE product_builder_products ADD COLUMN recommended_currency CHAR(3) NOT NULL DEFAULT ''EUR''', 'DO 0');
PREPARE pb_price_migration FROM @pb_price_sql;
EXECUTE pb_price_migration;
DEALLOCATE PREPARE pb_price_migration;

SET @pb_has_price_column = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='web_price');
SET @pb_price_sql = IF(@pb_has_price_column=0,
 'ALTER TABLE product_builder_products ADD COLUMN web_price DECIMAL(10,2) NULL', 'DO 0');
PREPARE pb_price_migration FROM @pb_price_sql;
EXECUTE pb_price_migration;
DEALLOCATE PREPARE pb_price_migration;

SET @pb_has_price_column = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='web_currency');
SET @pb_price_sql = IF(@pb_has_price_column=0,
 'ALTER TABLE product_builder_products ADD COLUMN web_currency CHAR(3) NOT NULL DEFAULT ''EUR''', 'DO 0');
PREPARE pb_price_migration FROM @pb_price_sql;
EXECUTE pb_price_migration;
DEALLOCATE PREPARE pb_price_migration;

SET @pb_has_price_column = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='ebay_price');
SET @pb_price_sql = IF(@pb_has_price_column=0,
 'ALTER TABLE product_builder_products ADD COLUMN ebay_price DECIMAL(10,2) NULL', 'DO 0');
PREPARE pb_price_migration FROM @pb_price_sql;
EXECUTE pb_price_migration;
DEALLOCATE PREPARE pb_price_migration;

SET @pb_has_price_column = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='ebay_currency');
SET @pb_price_sql = IF(@pb_has_price_column=0,
 'ALTER TABLE product_builder_products ADD COLUMN ebay_currency CHAR(3) NOT NULL DEFAULT ''EUR''', 'DO 0');
PREPARE pb_price_migration FROM @pb_price_sql;
EXECUTE pb_price_migration;
DEALLOCATE PREPARE pb_price_migration;

SET @pb_has_price_column = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='amazon_price');
SET @pb_price_sql = IF(@pb_has_price_column=0,
 'ALTER TABLE product_builder_products ADD COLUMN amazon_price DECIMAL(10,2) NULL', 'DO 0');
PREPARE pb_price_migration FROM @pb_price_sql;
EXECUTE pb_price_migration;
DEALLOCATE PREPARE pb_price_migration;

SET @pb_has_price_column = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='amazon_currency');
SET @pb_price_sql = IF(@pb_has_price_column=0,
 'ALTER TABLE product_builder_products ADD COLUMN amazon_currency CHAR(3) NOT NULL DEFAULT ''EUR''', 'DO 0');
PREPARE pb_price_migration FROM @pb_price_sql;
EXECUTE pb_price_migration;
DEALLOCATE PREPARE pb_price_migration;
