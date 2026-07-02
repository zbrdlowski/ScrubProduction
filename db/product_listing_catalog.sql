CREATE TABLE IF NOT EXISTS `scrub_catalog_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_type` enum('design','seatcover') NOT NULL,
  `product_code` varchar(64) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_scrub_catalog_products_code` (`product_code`),
  KEY `ix_scrub_catalog_products_type` (`product_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scrub_catalog_product_listings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `model_code` varchar(32) NOT NULL,
  `marketplace` enum('shoptet','ebay') NOT NULL,
  `external_code` varchar(64) DEFAULT NULL,
  `external_url` varchar(1000) DEFAULT NULL,
  `listing_title` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_scrub_catalog_listing_unique` (`product_id`,`model_code`,`marketplace`),
  KEY `ix_scrub_catalog_product_listings_model_code` (`model_code`),
  KEY `ix_scrub_catalog_product_listings_marketplace` (`marketplace`),
  CONSTRAINT `fk_scrub_catalog_product_listings_product`
    FOREIGN KEY (`product_id`) REFERENCES `scrub_catalog_products` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
