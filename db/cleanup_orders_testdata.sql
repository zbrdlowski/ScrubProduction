-- ============================================================
--  SCRUBPRODUCTION — vymazanie všetkých dát z orders modulu
--  Použitie: lokálne testovanie po importe
--  !! NESPÚŠŤAJ NA PRODUKCII !!
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- leaf tabuľky (žiadne child FK)
DELETE FROM `order_item_categories`;
DELETE FROM `order_item_statuses`;
DELETE FROM `order_item_assignments`;

-- order_items (parent pre vyššie)
DELETE FROM `order_items`;

-- ostatné child tabuľky orders
DELETE FROM `order_activity`;
DELETE FROM `order_addresses`;
DELETE FROM `order_assignments`;
DELETE FROM `order_categories`;
DELETE FROM `order_invoices`;
DELETE FROM `order_tracking_numbers`;
DELETE FROM `shipments`;

-- hlavná tabuľka
DELETE FROM `orders`;

-- sources nechávame — sú to lookup hodnoty (SHOPTET, EBAY, MXLOCKER)
-- ak chceš vymazať aj ich, odkomentuj:
-- DELETE FROM `order_sources`;

SET FOREIGN_KEY_CHECKS = 1;
