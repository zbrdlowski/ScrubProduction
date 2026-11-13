<?php
declare(strict_types=1);
/** Imported sales only: one purchased bundle, not each generated production component. */
function pb_listing_sales(PDO $pdo, array $productIds): array
{
    if (!$productIds) return [];
    $sourceChannels = ['SHOPTET'=>'web','EBAY'=>'ebay','AMAZON'=>'amazon'];
    $sources = $pdo->query("SELECT code FROM order_sources WHERE code IN ('SHOPTET','EBAY','AMAZON')")->fetchAll(PDO::FETCH_COLUMN);
    $defaults = ['web'=>null,'ebay'=>null,'amazon'=>null];
    foreach ($sources as $source) if (isset($sourceChannels[$source])) $defaults[$sourceChannels[$source]] = 0;
    $find = $pdo->prepare('SELECT id,sku FROM product_builder_products WHERE id IN (' . implode(',',array_fill(0,count($productIds),'?')) . ')');
    $find->execute($productIds);
    $idsBySku = []; $result = array_fill_keys($productIds,$defaults);
    foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $row) $idsBySku[$row['sku']] = (int)$row['id'];
    if (!$idsBySku) return $result;
    $skus = array_keys($idsBySku); $ph = implode(',',array_fill(0,count($skus),'?'));
    // eBay's seller SKU lives in custom_label; sku can hold the numeric eBay item ID.
    $identifier = "CASE WHEN s.code='EBAY' THEN COALESCE(NULLIF(TRIM(i.custom_label),''),i.sku) ELSE i.sku END";
    $auto = "JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(i.options_json),i.options_json,'{}'),'$._auto_generated'))";
    $followup = "JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(o.source_meta),o.source_meta,'{}'),'$._followup.type'))";
    $stmt = $pdo->prepare("SELECT BINARY ($identifier) AS sku,s.code,SUM(GREATEST(i.qty,0)) AS sold_quantity
        FROM order_items i JOIN orders o ON o.id=i.order_id JOIN order_sources s ON s.id=o.source_id
        WHERE ((s.code IN ('SHOPTET','AMAZON') AND i.sku IN ($ph))
          OR (s.code='EBAY' AND (i.custom_label IN ($ph) OR ((i.custom_label IS NULL OR TRIM(i.custom_label)='') AND i.sku IN ($ph)))))
          AND i.deleted_at IS NULL
          AND UPPER(TRIM(COALESCE(o.status,''))) NOT IN ('CANCELLED','CANCELED')
          AND UPPER(TRIM(COALESCE(i.status,''))) NOT IN ('CANCELLED','CANCELED')
          AND COALESCE($auto,'') IN ('','null')
          AND COALESCE(UPPER($followup),'') IN ('','NULL','SPLIT')
        GROUP BY BINARY ($identifier),s.code");
    $stmt->execute(array_merge($skus,$skus,$skus));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Exact SKU identity: never match a prefix, wildcard, different case or another variation.
        if (isset($idsBySku[$row['sku']])) $result[$idsBySku[$row['sku']]][$sourceChannels[$row['code']]] = (int)$row['sold_quantity'];
    }
    return $result;
}
