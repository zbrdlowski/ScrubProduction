SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM order_activity;
DELETE FROM order_assignments;
DELETE FROM order_item_categories;
DELETE FROM order_categories;
DELETE FROM order_addresses;
DELETE FROM order_items;
DELETE FROM shipments;
DELETE FROM invoices;
DELETE FROM orders;
DELETE FROM customers;

ALTER TABLE order_activity AUTO_INCREMENT = 1;
ALTER TABLE order_assignments AUTO_INCREMENT = 1;
ALTER TABLE order_addresses AUTO_INCREMENT = 1;
ALTER TABLE order_items AUTO_INCREMENT = 1;
ALTER TABLE shipments AUTO_INCREMENT = 1;
ALTER TABLE invoices AUTO_INCREMENT = 1;
ALTER TABLE orders AUTO_INCREMENT = 1;
ALTER TABLE customers AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;