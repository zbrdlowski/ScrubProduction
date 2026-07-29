-- Projects use order_activity for audit rows that are not tied to an order.
-- Keep the existing FK for real order activity, but allow project rows to store NULL.

ALTER TABLE `order_activity`
  DROP FOREIGN KEY `fk_act_order`;

ALTER TABLE `order_activity`
  MODIFY `order_id` bigint(20) DEFAULT NULL;

UPDATE `order_activity`
SET `order_id` = NULL
WHERE `order_id` = 0;

ALTER TABLE `order_activity`
  ADD CONSTRAINT `fk_act_order`
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);
