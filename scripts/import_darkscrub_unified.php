<?php
declare(strict_types=1);

/**
 * Unified DarkScrub CSV importer.
 * Input: DARKSCRUB_IMPORT.csv generated from Google Sheets / Apps Script.
 * One row = one order line. Rows are grouped by source + external_order_id.
 */

function import_darkscrub_unified_csv(mysqli $conn, string $csvPath): array {
  if (!function_exists('oi_csv_read_assoc')) {
    throw new RuntimeException('Missing order import library. Require order_import_lib.php first.');
  }

  $rows = oi_csv_read_assoc($csvPath);
  if (!$rows) {
    return ['orders' => 0, 'items' => 0, 'note' => 'Empty CSV'];
  }

  $catIds = [
    'GRAPHICS'  => oi_db_get_id_by_code($conn, 'categories', 'GRAPHICS'),
    'PLASTICS'  => oi_db_get_id_by_code($conn, 'categories', 'PLASTICS'),
    'SEATCOVER' => oi_db_get_id_by_code($conn, 'categories', 'SEATCOVER'),
    'FITTING'   => oi_db_get_id_by_code($conn, 'categories', 'FITTING'),
  ];

  $byOrder = [];
  foreach ($rows as $r) {
    $source = strtoupper((string)oi_trim($r['source'] ?? null));
    $externalOrderId = (string)oi_trim($r['external_order_id'] ?? null);
    if ($source === '' || $externalOrderId === '') continue;
    $byOrder[$source . '|' . $externalOrderId][] = $r;
  }

  $stats = [
    'orders' => 0,
    'created' => 0,
    'updated' => 0,
    'items' => 0,
    'skipped_shipping_items' => 0,
    'skipped_rows' => count($rows) - array_sum(array_map('count', $byOrder)),
  ];

  foreach ($byOrder as $groupKey => $itemRows) {
    [$sourceCode, $externalOrderId] = explode('|', $groupKey, 2);
    $first = $itemRows[0];

    $sourceId = oi_ensure_order_source($conn, $sourceCode);

    $customerName = oi_first_nonempty(
      $first['delivery_name'] ?? null,
      $first['bill_name'] ?? null
    );

    $customerId = oi_upsert_customer(
      $conn,
      $customerName,
      $first['email'] ?? null,
      $first['phone'] ?? null
    );

    $sourceMeta = [
      'status' => $first['status'] ?? null,
      'exchange_rate' => $first['exchange_rate'] ?? null,
      'customer_ip' => $first['customer_ip'] ?? null,
      'customer_note' => $first['customer_note'] ?? null,
      'internal_note' => $first['internal_note'] ?? null,
      'package_number' => $first['package_number'] ?? null,
      'total_weight' => $first['total_weight'] ?? null,
      'total_price_with_vat' => $first['total_price_with_vat'] ?? null,
      'total_price_without_vat' => $first['total_price_without_vat'] ?? null,
      'total_vat' => $first['total_vat'] ?? null,
      'rounding' => $first['rounding'] ?? null,
      'price_to_pay' => $first['price_to_pay'] ?? null,
      'amount_paid' => $first['amount_paid'] ?? null,
      'paid' => $first['paid'] ?? null,
      'sales_channel' => $first['sales_channel'] ?? null,
      'source_name' => $first['source_name'] ?? null,
    ];

    $beforeExists = oi_find_order_id($conn, $sourceId, $externalOrderId) !== null;

    $orderId = oi_upsert_order_header_mysqli($conn, $sourceId, $externalOrderId, [
      'order_number' => $externalOrderId,
      'order_date' => oi_parse_date_any($first['order_date'] ?? null),
      'currency' => oi_trim($first['currency'] ?? null),
      'total' => oi_parse_money($first['price_to_pay'] ?? null),
      'payment_method' => oi_trim($first['payment_method'] ?? null),
      'shipping_method' => oi_trim($first['shipping_method'] ?? null),
      'note' => oi_first_nonempty($first['customer_note'] ?? null, $first['internal_note'] ?? null),
      'source_meta_json' => oi_json_clean($sourceMeta),
      'customer_id' => $customerId,
    ]);

    if ($beforeExists) $stats['updated']++; else $stats['created']++;

    oi_upsert_address($conn, $orderId, 'BILLING', [
      'name' => $first['bill_name'] ?? null,
      'company' => $first['bill_company'] ?? null,
      'street' => oi_join_street($first['bill_street'] ?? null, $first['bill_house_number'] ?? null),
      'city' => $first['bill_city'] ?? null,
      'zip' => $first['bill_zip'] ?? null,
      'country' => $first['bill_country'] ?? null,
      'email' => $first['email'] ?? null,
      'phone' => $first['phone'] ?? null,
    ]);

    oi_upsert_address($conn, $orderId, 'SHIPPING', [
      'name' => oi_first_nonempty($first['delivery_name'] ?? null, $first['bill_name'] ?? null),
      'company' => oi_first_nonempty($first['delivery_company'] ?? null, $first['bill_company'] ?? null),
      'street' => oi_join_street(
        oi_first_nonempty($first['delivery_street'] ?? null, $first['bill_street'] ?? null),
        oi_first_nonempty($first['delivery_house_number'] ?? null, $first['bill_house_number'] ?? null)
      ),
      'city' => oi_first_nonempty($first['delivery_city'] ?? null, $first['bill_city'] ?? null),
      'zip' => oi_first_nonempty($first['delivery_zip'] ?? null, $first['bill_zip'] ?? null),
      'country' => oi_first_nonempty($first['delivery_country'] ?? null, $first['bill_country'] ?? null),
      'email' => $first['email'] ?? null,
      'phone' => $first['phone'] ?? null,
    ]);

    // Add/update mode: refresh items from latest CSV snapshot.
    oi_delete_items_for_order($conn, $orderId);

    $seenShipping = false;
    foreach ($itemRows as $r) {
      if (oi_is_shipping_or_payment_line($r)) {
        $stats['skipped_shipping_items']++;
        $seenShipping = true;
        continue;
      }

      $lineNo = (int)(oi_trim($r['item_line_no'] ?? null) ?? 0);
      $qty = (int)(oi_trim($r['item_qty'] ?? null) ?? 1);
      if ($qty < 1) $qty = 1;

      $sku = oi_trim($r['item_sku'] ?? null);
      $customLabel = oi_trim($r['custom_label'] ?? null);
      $title = oi_trim($r['item_name'] ?? null);
      $variant = oi_trim($r['item_variant'] ?? null);
      if ($variant) $title = trim((string)$title . ' / ' . $variant);

      $itemType = oi_detect_item_type($sku, $customLabel, $title, $r['item_type_code'] ?? null);
      if ($itemType === null) {
        // Unknown product line. Keep it as an item without category; review later via source_meta/options_json.
        $itemType = null;
      }

      $optionsJson = oi_merge_options_json($r);

      $itemId = oi_insert_item_unified(
        $conn,
        $orderId,
        $lineNo ?: null,
        $sku,
        $title,
        $customLabel,
        $itemType,
        $qty,
        $optionsJson
      );

      $categoryCodes = oi_item_type_to_category_codes($itemType, $sku, $customLabel, $title);
      $categoryIds = [];
      foreach ($categoryCodes as $code) {
        if (isset($catIds[$code])) $categoryIds[] = $catIds[$code];
      }
      if ($categoryIds) oi_add_item_categories($conn, $itemId, array_values(array_unique($categoryIds)));
      $stats['items']++;
    }

    oi_refresh_order_categories($conn, $orderId);

    oi_upsert_shipment_from_unified_row($conn, $orderId, $first, $seenShipping);

    if (function_exists('oi_log_order_activity')) {
      oi_log_order_activity($conn, $orderId, null, $beforeExists ? 'IMPORT_UPDATED' : 'IMPORT_CREATED', [
        'source' => $sourceCode,
        'external_order_id' => $externalOrderId,
        'importer' => 'DARKSCRUB_UNIFIED',
      ]);
    }

    $stats['orders']++;
  }

  $stats['note'] = 'DARKSCRUB unified add/update import completed';
  return $stats;
}

function oi_first_nonempty(...$values): ?string {
  foreach ($values as $v) {
    $v = oi_trim(is_scalar($v) ? (string)$v : null);
    if ($v !== null) return $v;
  }
  return null;
}

function oi_json_clean(array $data): ?string {
  $clean = [];
  foreach ($data as $k => $v) {
    if (is_array($v)) {
      $nested = json_decode((string)oi_json_clean($v), true) ?: [];
      if ($nested) $clean[$k] = $nested;
      continue;
    }
    $v = is_scalar($v) ? trim((string)$v) : null;
    if ($v !== null && $v !== '') $clean[$k] = $v;
  }
  return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}

function oi_join_street(?string $street, ?string $houseNumber): ?string {
  $street = oi_trim($street);
  $houseNumber = oi_trim($houseNumber);
  return oi_trim(trim((string)$street . ' ' . (string)$houseNumber));
}

function oi_find_order_id(mysqli $conn, int $sourceId, string $externalOrderId): ?int {
  $stmt = $conn->prepare('SELECT id FROM orders WHERE source_id=? AND external_order_id=? LIMIT 1');
  $stmt->bind_param('is', $sourceId, $externalOrderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ? (int)$row['id'] : null;
}

function oi_ensure_order_source(mysqli $conn, string $code): int {
  try {
    return oi_db_get_id_by_code($conn, 'order_sources', $code);
  } catch (Throwable $e) {
    $stmt = $conn->prepare('INSERT INTO order_sources (code) VALUES (?)');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
  }
}

function oi_detect_item_type(?string $sku, ?string $customLabel, ?string $title, ?string $provided = null): ?string {
  $provided = strtoupper((string)oi_trim($provided));
  if (in_array($provided, ['G','T','M','P','S','F'], true)) return $provided;

  foreach ([$sku, $customLabel] as $candidate) {
    $candidate = strtoupper((string)oi_trim($candidate));
    if ($candidate === '') continue;

    if (preg_match('/^GFP[_-]/', $candidate)) return 'G';
    if (preg_match('/^G[_-]?RT[_-]/', $candidate)) return 'G';
    if (preg_match('/^(G|T|M|P|S|F)[_-]/', $candidate, $m)) return $m[1];
  }

  $t = mb_strtolower((string)$title);
  if ($t === '') return null;
  if (str_contains($t, 'bike mat')) return 'M';
  if (str_contains($t, 'plastic')) return 'P';
  if (str_contains($t, 'seat cover') || str_contains($t, 'housse de siège')) return 'S';
  if (str_contains($t, 'rim tape') || str_contains($t, 'sticker') || str_contains($t, 'graphic') || str_contains($t, 'decal')) return 'G';
  if (str_contains($t, 'fitting') || str_contains($t, 'install')) return 'F';

  return null;
}

function oi_item_type_to_category_codes(?string $itemType, ?string $sku = null, ?string $customLabel = null, ?string $title = null): array {
  $itemType = strtoupper((string)$itemType);
  return match ($itemType) {
    'G' => ['GRAPHICS'],
    'T' => ['GRAPHICS', 'PLASTICS'],
    'M' => ['GRAPHICS', 'PLASTICS'],
    'P' => ['PLASTICS'],
    'S' => ['SEATCOVER'],
    'F' => ['FITTING'],
    default => [],
  };
}

function oi_is_shipping_or_payment_line(array $r): bool {
  $type = strtoupper((string)oi_trim($r['item_type_code'] ?? null));
  if (in_array($type, ['G','T','M','P','S','F'], true)) return false;

  $sku = oi_trim($r['item_sku'] ?? null);
  $customLabel = oi_trim($r['custom_label'] ?? null);
  if (oi_detect_item_type($sku, $customLabel, $r['item_name'] ?? null, null) !== null) return false;

  $name = mb_strtolower((string)oi_trim($r['item_name'] ?? null));
  $shippingMethod = oi_trim($r['shipping_method'] ?? null);
  $shippingPrice = oi_trim($r['shipping_price'] ?? null);

  $shippingWords = ['shipping', 'delivery', 'doprava', 'preprava', 'transport', 'versand', 'livraison', 'spedizione', 'envío', 'postage'];
  foreach ($shippingWords as $w) {
    if ($name !== '' && str_contains($name, $w)) return true;
  }

  if ($sku === null && $customLabel === null && $shippingMethod !== null && $shippingPrice !== null) return true;
  return false;
}

function oi_merge_options_json(array $r): ?string {
  $opts = [];

  $optionsRaw = oi_trim($r['options_json'] ?? null);
  if ($optionsRaw) {
    $decoded = json_decode($optionsRaw, true);
    if (is_array($decoded)) $opts = $decoded;
    else $opts['_options_raw'] = $optionsRaw;
  }

  $extra = [
    'category_info' => $r['category_info'] ?? null,
    'item_variant' => $r['item_variant'] ?? null,
    'item_manufacturer' => $r['item_manufacturer'] ?? null,
    'item_unit' => $r['item_unit'] ?? null,
    'item_weight' => $r['item_weight'] ?? null,
    'item_status' => $r['item_status'] ?? null,
    'item_unit_price_with_vat' => $r['item_unit_price_with_vat'] ?? null,
    'item_unit_price_without_vat' => $r['item_unit_price_without_vat'] ?? null,
    'item_vat_rate' => $r['item_vat_rate'] ?? null,
    'item_total_price_with_vat' => $r['item_total_price_with_vat'] ?? null,
    'item_total_price_without_vat' => $r['item_total_price_without_vat'] ?? null,
    'item_ean' => $r['item_ean'] ?? null,
    'item_plu' => $r['item_plu'] ?? null,
    'item_supplier' => $r['item_supplier'] ?? null,
  ];

  foreach ($extra as $k => $v) {
    $v = is_scalar($v) ? trim((string)$v) : null;
    if ($v !== null && $v !== '') $opts['_item'][$k] = $v;
  }

  $raw = oi_trim($r['source_raw_json'] ?? null);
  if ($raw) {
    $decodedRaw = json_decode($raw, true);
    $opts['_source_raw'] = is_array($decodedRaw) ? $decodedRaw : $raw;
  }

  return $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}

function oi_insert_item_unified(mysqli $conn, int $orderId, ?int $lineNo, ?string $sku, ?string $title, ?string $customLabel, ?string $itemTypeCode, int $qty, ?string $optionsJson): int {
  $sku = oi_trim($sku);
  $title = oi_trim($title);
  $customLabel = oi_trim($customLabel);
  $itemTypeCode = oi_trim($itemTypeCode);

  $stmt = $conn->prepare('
    INSERT INTO order_items (order_id, line_no, sku, title, custom_label, item_type_code, qty, options_json)
    VALUES (?,?,?,?,?,?,?,?)
  ');
  $stmt->bind_param('iissssis', $orderId, $lineNo, $sku, $title, $customLabel, $itemTypeCode, $qty, $optionsJson);
  $stmt->execute();
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id;
}

function oi_upsert_shipment_from_unified_row(mysqli $conn, int $orderId, array $r, bool $seenShippingLine): void {
  $method = oi_trim($r['shipping_method'] ?? null);
  $tracking = oi_trim($r['tracking_number'] ?? null);
  $price = oi_parse_money($r['shipping_price'] ?? null);

  if ($method === null && $tracking === null && $price === null && !$seenShippingLine) return;

  // Keep this conservative because shipment table structures vary.
  // Your current older importer inserts: order_id, carrier, tracking_number, status, source.
  $stmt = $conn->prepare('SELECT id FROM shipments WHERE order_id=? AND (tracking_number <=> ?) LIMIT 1');
  if ($stmt) {
    $stmt->bind_param('is', $orderId, $tracking);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return;
  }

  $carrier = $method;
  $status = $tracking ? 'SHIPPED' : 'NEW';
  $source = 'CSV';
  $stmt = $conn->prepare('INSERT INTO shipments (order_id, carrier, tracking_number, status, source) VALUES (?,?,?,?,?)');
  if ($stmt) {
    $stmt->bind_param('issss', $orderId, $carrier, $tracking, $status, $source);
    $stmt->execute();
    $stmt->close();
  }
}
