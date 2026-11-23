-- Run in the existing DarkScrub database. Additive and safe to re-import.
-- Multiple model-specific compatibilities per product. Modelcodes may be shared by different models.
CREATE TABLE IF NOT EXISTS product_builder_compatibility (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 product_id BIGINT UNSIGNED NOT NULL,
 brand VARCHAR(100) NOT NULL, model VARCHAR(150) NOT NULL,
 year_range VARCHAR(50) NOT NULL DEFAULT '', modelcode VARCHAR(7) NOT NULL,
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 UNIQUE KEY uq_pb_compatibility (product_id,brand,model,year_range,modelcode),
 KEY idx_pb_compatibility_modelcode (modelcode,product_id),
 CONSTRAINT fk_pb_compatibility_product FOREIGN KEY (product_id) REFERENCES product_builder_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve the original specific category. Re-import never replaces a saved selection.
INSERT INTO product_builder_compatibility (product_id,brand,model,year_range,modelcode,sort_order)
SELECT p.id,p.brand,p.model,p.year_range,p.modelcode,0
FROM product_builder_products p
WHERE p.category_kind='specific' AND p.modelcode<>''
AND NOT EXISTS (SELECT 1 FROM product_builder_compatibility c WHERE c.product_id=p.id);
