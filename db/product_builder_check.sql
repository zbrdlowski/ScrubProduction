-- Read-only verification of the same seven tables and twenty-five columns checked by the application.
-- Select the DarkScrub application database in phpMyAdmin before importing.
SELECT DATABASE() AS checked_database;
SELECT 'product_builder_products' AS required_object, IF(EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='product_builder_products'),'OK','MISSING') AS status
UNION ALL
SELECT 'product_builder_components' AS required_object, IF(EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='product_builder_components'),'OK','MISSING') AS status
UNION ALL
SELECT 'product_builder_uni_categories' AS required_object, IF(EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='product_builder_uni_categories'),'OK','MISSING') AS status
UNION ALL
SELECT 'product_builder_designs' AS required_object, IF(EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='product_builder_designs'),'OK','MISSING') AS status
UNION ALL
SELECT 'product_builder_graphics_versions' AS required_object, IF(EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='product_builder_graphics_versions'),'OK','MISSING') AS status
UNION ALL
SELECT 'product_builder_boxes' AS required_object, IF(EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='product_builder_boxes'),'OK','MISSING') AS status
UNION ALL
SELECT 'product_builder_components.color', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_components' AND column_name='color'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_components.options_json', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_components' AND column_name='options_json'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_components.design_id', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_components' AND column_name='design_id'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_components.graphics_version_id', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_components' AND column_name='graphics_version_id'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.recommended_price', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='recommended_price'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.recommended_currency', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='recommended_currency'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.web_price', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='web_price'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.web_currency', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='web_currency'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.ebay_price', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='ebay_price'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.ebay_currency', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='ebay_currency'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.amazon_price', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='amazon_price'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.amazon_currency', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='amazon_currency'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.box_id', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='box_id'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.weight_kg', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='weight_kg'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.img_status', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='img_status'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.img_path', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='img_path'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_products.img_version', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='img_version'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_channel_listings', IF(EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='product_builder_channel_listings'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_channel_listings.product_id', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_channel_listings' AND column_name='product_id'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_channel_listings.channel', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_channel_listings' AND column_name='channel'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_channel_listings.listing_status', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_channel_listings' AND column_name='listing_status'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_channel_listings.listing_url', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_channel_listings' AND column_name='listing_url'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_channel_listings.listed_price', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_channel_listings' AND column_name='listed_price'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_channel_listings.listed_currency', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_channel_listings' AND column_name='listed_currency'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_channel_listings.version', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_channel_listings' AND column_name='version'),'OK','MISSING')
UNION ALL
SELECT 'product_builder_channel_listings.listed_date', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_channel_listings' AND column_name='listed_date'),'OK','MISSING');
