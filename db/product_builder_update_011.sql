-- DARKSCRUB: sales channel listing date (update 011).
-- Select the application database in phpMyAdmin; run after update 010.
-- Existing listing dates remain unknown (NULL), never guessed from edit timestamps.
SET NAMES utf8mb4;
SET @pb_listing_date_exists = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='product_builder_channel_listings' AND column_name='listed_date');
SET @pb_listing_date_sql = IF(@pb_listing_date_exists=0,
 'ALTER TABLE product_builder_channel_listings ADD COLUMN listed_date DATE NULL AFTER listed_currency', 'DO 0');
PREPARE pb_listing_date_migration FROM @pb_listing_date_sql;
EXECUTE pb_listing_date_migration;
DEALLOCATE PREPARE pb_listing_date_migration;
