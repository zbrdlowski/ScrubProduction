-- Read-only verification of the same six tables and fourteen columns checked by the application.
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
SELECT 'product_builder_products.weight_kg', IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name='weight_kg'),'OK','MISSING');
