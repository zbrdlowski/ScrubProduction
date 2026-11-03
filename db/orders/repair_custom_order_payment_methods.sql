-- Hromadna oprava sposobu platby v objednavkach exportovanych z Custom Orders.
-- V phpMyAdmin vyberte databazu aplikacie a spustite cely subor v zalozke SQL
-- alebo cez Import. Ak chcete iba nahlad, spustite samostatne prvy SELECT.
-- Opakovane spustenie uz opravene platby nezmeni.

-- 1. Pocet objednavok na opravu podla ulozeneho sposobu platby.
SELECT
    TRIM(co.payment_method) AS payment_method,
    COUNT(DISTINCT o.id) AS orders_to_update
FROM orders AS o
INNER JOIN custom_orders AS co ON co.production_order_id = o.id
INNER JOIN order_sources AS os ON os.id = o.source_id
WHERE os.code = 'CUSTOM'
  AND UPPER(TRIM(o.payment_method)) = 'CUSTOM'
  AND TRIM(COALESCE(co.payment_method, '')) <> ''
  AND UPPER(TRIM(co.payment_method)) <> 'CUSTOM'
GROUP BY TRIM(co.payment_method)
ORDER BY payment_method;

-- 2. Oprava vsetkych dotknutych exportovanych objednavok bez ohladu na stav.
START TRANSACTION;

UPDATE orders AS o
INNER JOIN custom_orders AS co ON co.production_order_id = o.id
INNER JOIN order_sources AS os ON os.id = o.source_id
SET o.payment_method = TRIM(co.payment_method)
WHERE os.code = 'CUSTOM'
  AND UPPER(TRIM(o.payment_method)) = 'CUSTOM'
  AND TRIM(COALESCE(co.payment_method, '')) <> ''
  AND UPPER(TRIM(co.payment_method)) <> 'CUSTOM';

SELECT ROW_COUNT() AS updated_orders;

COMMIT;

-- 3. Zostavajuce CUSTOM platby na kontrolu (prazdny vysledok = vsetky opravene).
SELECT
    o.id AS production_order_id,
    o.order_number,
    co.id AS custom_order_id,
    o.payment_method AS production_payment,
    co.payment_method AS custom_order_payment,
    CASE
        WHEN co.id IS NULL THEN 'Missing linked Custom Order'
        WHEN TRIM(COALESCE(co.payment_method, '')) = '' THEN 'Missing saved payment method'
        WHEN UPPER(TRIM(co.payment_method)) = 'CUSTOM' THEN 'Saved payment method is also CUSTOM'
        ELSE 'Check manually'
    END AS reason
FROM orders AS o
INNER JOIN order_sources AS os ON os.id = o.source_id
LEFT JOIN custom_orders AS co ON co.production_order_id = o.id
WHERE os.code = 'CUSTOM'
  AND UPPER(TRIM(o.payment_method)) = 'CUSTOM'
ORDER BY o.id;