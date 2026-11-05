-- Product builder update 007: editable box library.
-- Import into the existing DarkScrub database after update 006.
-- Creates an empty library; existing products, prices, components and other libraries are preserved.
-- Safe to import repeatedly. Dimensions are in cm, empty-box weight is in kg and may be zero.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS product_builder_boxes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 length_cm DECIMAL(7,2) NOT NULL, width_cm DECIMAL(7,2) NOT NULL, height_cm DECIMAL(7,2) NOT NULL,
 weight_kg DECIMAL(9,3) NOT NULL DEFAULT 0.000,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pb_box_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
