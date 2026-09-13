-- Run once on every database before deploying the payment-release UI.
-- Safe to run repeatedly on MariaDB 10.x.
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `production_started_at` datetime DEFAULT NULL
  COMMENT 'Time the order entered the production queue; original order_date remains unchanged'
  AFTER `order_date`,
  ADD COLUMN IF NOT EXISTS `payment_received_amount` decimal(12,2) DEFAULT NULL
  COMMENT 'Actual amount entered when payment was confirmed; orders.total remains unchanged'
  AFTER `total`;
