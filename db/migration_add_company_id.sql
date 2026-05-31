-- Migration: Add company_id field to order_addresses table
-- Allows tracking company ID for billing and shipping addresses separately

ALTER TABLE `order_addresses` 
ADD COLUMN `company_id` VARCHAR(255) DEFAULT NULL AFTER `company`;

-- Create index for searching by company ID
CREATE INDEX idx_order_addresses_company_id ON `order_addresses` (`company_id`);
