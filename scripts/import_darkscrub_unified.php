<?php
declare(strict_types=1);
if (!function_exists('str_contains')) {
  function str_contains($haystack, $needle) {
    return $needle !== '' && strpos($haystack, $needle) !== false;
  }
}

require_once __DIR__ . '/orders/department_config.php';

/**
 * Unified DarkScrub CSV importer.
 * Input: DARKSCRUB_IMPORT.csv generated from Google Sheets / Apps Script.
 * One row = one order line. Rows are grouped by source + external_order_id.
 */

function oi_json_decode_assoc_safe(?string $json): array {
  $json = $json !== null ? trim($json) : '';
  if ($json === '') {
    return [];
  }

  $decoded = json_decode($json, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
  return is_array($decoded) ? $decoded : [];
}

function oi_detect_import_source_from_row(array $r): string {
  $source = strtoupper((string) oi_trim($r['source'] ?? null));
  if ($source !== '') {
    return $source;
  }

  if (
    array_key_exists('code', $r)
    && array_key_exists('date', $r)
    && array_key_exists('itemCode', $r)
  ) {
    return 'SHOPTET';
  }

  if (
    array_key_exists('Sale Date', $r)
    && array_key_exists('Order Number', $r)
  ) {
    return 'MX_LOCKER';
  }

  if (
    array_key_exists('Order number', $r)
    && array_key_exists('Sale date', $r)
  ) {
    return 'EBAY';
  }

  return '';
}

function oi_country_to_iso2(?string $value): ?string {
  $value = oi_trim($value);
  if ($value === null) {
    return null;
  }

  $upper = strtoupper($value);
  if (preg_match('/^[A-Z]{2}$/', $upper)) {
    return $upper;
  }

  static $map = [
    'UNITED STATES' => 'US',
    'UNITED STATES OF AMERICA' => 'US',
    'USA' => 'US',
    'GREAT BRITAIN' => 'GB',
    'UNITED KINGDOM' => 'GB',
    'ENGLAND' => 'GB',
    'SCOTLAND' => 'GB',
    'WALES' => 'GB',
    'NORTHERN IRELAND' => 'GB',
    'SLOVAKIA' => 'SK',
    'SLOVAK REPUBLIC' => 'SK',
    'CZECH REPUBLIC' => 'CZ',
    'CZECHIA' => 'CZ',
    'GERMANY' => 'DE',
    'AUSTRIA' => 'AT',
    'ITALY' => 'IT',
    'FRANCE' => 'FR',
    'SPAIN' => 'ES',
    'PORTUGAL' => 'PT',
    'NETHERLANDS' => 'NL',
    'BELGIUM' => 'BE',
    'LUXEMBOURG' => 'LU',
    'POLAND' => 'PL',
    'HUNGARY' => 'HU',
    'SLOVENIA' => 'SI',
    'CROATIA' => 'HR',
    'ROMANIA' => 'RO',
    'BULGARIA' => 'BG',
    'LATVIA' => 'LV',
    'LITHUANIA' => 'LT',
    'ESTONIA' => 'EE',
    'FINLAND' => 'FI',
    'SWEDEN' => 'SE',
    'NORWAY' => 'NO',
    'DENMARK' => 'DK',
    'IRELAND' => 'IE',
    'SWITZERLAND' => 'CH',
    'GREECE' => 'GR',
    'CANADA' => 'CA',
    'AUSTRALIA' => 'AU',
    'NEW ZEALAND' => 'NZ',
  ];

  return $map[$upper] ?? $upper;
}

function oi_normalize_unified_import_row(array $r): array {
  $normalized = $r;
  $detectedSource = oi_detect_import_source_from_row($r);

  $aliases = [
    'external_order_id' => ['code', 'externalOrderId', 'Order Number', 'Order number'],
    'order_date' => ['date', 'orderDate', 'Sale Date', 'Sale date'],
    'status' => ['statusName', 'Item State'],
    'exchange_rate' => ['exchangeRate'],
    'bill_name' => ['billFullName', 'Buyer Name', 'Buyer name'],
    'bill_company' => ['billCompany'],
    'bill_street' => ['billStreet', 'Address Line One', 'Post to address 1'],
    'bill_house_number' => ['billHouseNumber'],
    'bill_city' => ['billCity', 'City'],
    'bill_zip' => ['billZip', 'Postal Code'],
    'bill_country' => ['billCountryName', 'Country', 'Post to country'],
    'bill_company_id' => ['companyId'],
    'delivery_name' => ['deliveryFullName', 'Buyer Name', 'Post to name', 'Buyer name'],
    'delivery_company' => ['deliveryCompany'],
    'delivery_company_id' => ['deliveryCompanyId'],
    'delivery_street' => ['deliveryStreet', 'Address Line One', 'Post to address 1'],
    'delivery_house_number' => ['deliveryHouseNumber'],
    'delivery_city' => ['deliveryCity', 'City', 'Post to city'],
    'delivery_zip' => ['deliveryZip', 'Postal Code', 'Post to postcode'],
    'delivery_country' => ['deliveryCountryName', 'Country', 'Post to country'],
    'customer_ip' => ['customerIpAddress'],
    'customer_note' => ['remark', 'Buyer note'],
    'internal_note' => ['shopRemark'],
    'package_number' => ['packageNumber'],
    'total_weight' => ['weight'],
    'total_price_with_vat' => ['totalPriceWithVat', 'Gross Selling Price'],
    'total_price_without_vat' => ['totalPriceWithoutVat'],
    'total_vat' => ['totalPriceVat'],
    'price_to_pay' => ['priceToPay'],
    'amount_paid' => ['amountPaid'],
    'source_name' => ['sourceName'],
    'sales_channel' => ['salesChannelName'],
    'item_name' => ['itemName', 'Item title', 'Item Title'],
    'item_qty' => ['itemAmount', 'Quantity', 'Item Qty'],
    'item_sku' => ['itemCode', 'Sku', 'Item number'],
    'custom_label' => ['Custom label'],
    'item_variant' => ['itemVariantName', 'Variation details'],
    'item_manufacturer' => ['itemManufacturer'],
    'item_unit' => ['itemUnit'],
    'item_weight' => ['itemWeight'],
    'item_status' => ['itemStatusName', 'Item State'],
    'item_unit_price_with_vat' => ['itemUnitPriceWithVat', 'Sold for', 'Gross Selling Price'],
    'item_unit_price_without_vat' => ['itemUnitPriceWithoutVat'],
    'item_unit_price_vat' => ['itemUnitPriceVat'],
    'item_vat_rate' => ['itemVatRate'],
    'item_total_price_with_vat' => ['itemTotalPriceWithVat'],
    'item_total_price_without_vat' => ['itemTotalPriceWithoutVat'],
    'item_total_price_vat' => ['itemTotalPriceVat'],
    'item_ean' => ['itemEan'],
    'item_plu' => ['itemPlu'],
    'item_supplier' => ['itemSupplier'],
    'shipping_method' => ['shippingMethod', 'Delivery service'],
    'shipping_price' => ['shippingPrice', 'Shipping Price', 'Postage and packaging'],
    'tracking_number' => ['trackingNumber', 'Tracking number'],
    'category_info' => ['Category Info'],
  ];

  foreach ($aliases as $targetKey => $sourceKeys) {
    $current = oi_trim($normalized[$targetKey] ?? null);
    if ($current !== null) {
      continue;
    }

    foreach ($sourceKeys as $sourceKey) {
      $candidate = $r[$sourceKey] ?? null;
      if ($candidate !== null && $candidate !== '') {
        $normalized[$targetKey] = $candidate;
        break;
      }
    }
  }

  if (($normalized['source'] ?? null) === null || trim((string) $normalized['source']) === '') {
    $normalized['source'] = $detectedSource;
  }

  if (($normalized['source_raw_json'] ?? null) === null || trim((string) $normalized['source_raw_json']) === '') {
    $normalized['source_raw_json'] = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  if (isset($normalized['bill_country'])) {
    $normalized['bill_country'] = oi_country_to_iso2((string) $normalized['bill_country']);
  }
  if (isset($normalized['delivery_country'])) {
    $normalized['delivery_country'] = oi_country_to_iso2((string) $normalized['delivery_country']);
  }

  if (!isset($normalized['phone']) || oi_trim($normalized['phone']) === null) {
    $normalized['phone'] = $r['Customer Phone Number'] ?? ($r['Post to phone'] ?? ($r['phone'] ?? null));
  }
  if (!isset($normalized['email']) || oi_trim($normalized['email']) === null) {
    $normalized['email'] = $r['Buyer Email'] ?? ($r['Buyer email'] ?? ($r['email'] ?? null));
  }

  if ($detectedSource === 'EBAY') {
    $sourceMeta = [
      'sales_record_number' => $r['Sales record number'] ?? null,
      'transaction_id' => $r['Transaction ID'] ?? null,
      'paypal_tx' => $r['PayPal transaction ID'] ?? null,
      'my_item_note' => $r['My item note'] ?? null,
    ];
    $sourceMeta = array_filter($sourceMeta, static fn($v) => $v !== null && $v !== '');
    if ($sourceMeta) {
      $normalized['source_raw_json'] = json_encode(array_merge($r, $sourceMeta), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
  }

  return $normalized;
}

function import_darkscrub_unified_csv(mysqli $conn, string $csvPath): array {
  if (!function_exists('oi_csv_read_assoc')) {
    throw new RuntimeException('Missing order import library. Require order_import_lib.php first.');
  }

  $rows = array_map('oi_normalize_unified_import_row', oi_csv_read_assoc($csvPath));
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
    'skipped_locked_orders' => 0,
    'skipped_locked_order_refs' => [],
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

    $existingOrderId = oi_find_order_id($conn, $sourceId, $externalOrderId);
    $beforeExists = $existingOrderId !== null;

    if ($beforeExists) {
      $lockInfo = oi_get_order_reimport_lock_info($conn, (int) $existingOrderId);
      if (!empty($lockInfo['locked'])) {
        $stats['skipped_locked_orders']++;
        $stats['skipped_locked_order_refs'][] = $sourceCode . ':' . $externalOrderId;
        continue;
      }
    }

    $shippingMethod = oi_extract_shipping_method_from_rows($itemRows);
    $paymentMethod = oi_extract_payment_method_from_rows($itemRows);

    // Detect unpaid Shoptet orders: source_raw_json->paid is empty or "0"
    $initialStatus = 'NEW';
    if ($sourceCode === 'SHOPTET') {
      $rawJson = oi_trim($first['source_raw_json'] ?? null);
      if ($rawJson !== null) {
        $rawDecoded = oi_json_decode_assoc_safe($rawJson);
        if (is_array($rawDecoded)) {
          $paidVal = trim((string)($rawDecoded['paid'] ?? ''));
          if ($paidVal === '' || $paidVal === '0') {
            $initialStatus = 'PENDING';
          }
        }
      }
    }

    $orderId = oi_upsert_order_header_mysqli($conn, $sourceId, $externalOrderId, [
      'order_number' => $externalOrderId,
      'order_date' => oi_parse_date_any($first['order_date'] ?? null),
      'currency' => oi_trim($first['currency'] ?? null),
      'total' => oi_parse_money($first['price_to_pay'] ?? null),
      'payment_method' => $paymentMethod,
      'shipping_method' => $shippingMethod,
      'note' => oi_first_nonempty($first['customer_note'] ?? null, $first['internal_note'] ?? null),
      'source_meta_json' => oi_json_clean($sourceMeta),
      'customer_id' => $customerId,
      'initial_status' => $initialStatus,
    ]);

    if ($beforeExists) $stats['updated']++; else $stats['created']++;

    oi_upsert_address($conn, $orderId, 'BILLING', [
      'name' => $first['bill_name'] ?? null,
      'company' => $first['bill_company'] ?? null,
      'company_id' => $first['bill_company_id'] ?? null,
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
      'company_id' => oi_first_nonempty($first['delivery_company_id'] ?? null, $first['bill_company_id'] ?? null),
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
    $autoLineNo = 1000; // synthetic line numbers for auto-generated items start here
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
      $skuUpper = strtoupper((string)$sku);
      if (str_starts_with($skuUpper, 'SHIPPING') || str_starts_with($skuUpper, 'BILLING')) {
        $stats['skipped_shipping_items']++;
        $seenShipping = true;
        continue;
      }
      $title = oi_trim($r['item_name'] ?? null);
      $variant = oi_trim($r['item_variant'] ?? null);
      if ($variant) $title = trim((string)$title . ' / ' . $variant);

      $optionsJson = oi_merge_options_json($r);

      // --- Cena položky podľa zdroja ---
      // eBay / MXLocker: item_unit_price_with_vat  (napr. "EU355.98", "399.9")
      // Shoptet:         item_total_price_with_vat  (napr. "64,90") — celková, nie jednotková
      //                  pri qty>1 vydelíme qty
      $rawUnitPrice = null;
      if ($sourceCode === 'SHOPTET') {
        $rawUnitPrice = oi_parse_money($r['item_total_price_with_vat'] ?? null);
        if ($rawUnitPrice !== null && $qty > 1) {
          $rawUnitPrice = round($rawUnitPrice / $qty, 2);
        }
      } else {
        // EBAY, MXLOCKER aj ostatné — unit price priamo
        $rawUnitPrice = oi_parse_money($r['item_unit_price_with_vat'] ?? null);
      }

      // --- Department + subcategory detekcia cez department_config.php ---
      $departments = dept_get_departments($customLabel, $sku);

      // Fallback na starú heuristiku ak config prefix nenašiel zhodu
      if (empty($departments)) {
        $legacyType = oi_detect_item_type($sku, $customLabel, $title, $r['item_type_code'] ?? null);
        if ($legacyType !== null) {
          $departments = [$legacyType];
        }
      }

      // Primárny department = prvý v poli (napr. GFP → G)
      $primaryDept = $departments[0] ?? 'G';

      // Subcategory pre Graphics položky
      $graphicsSubcat = null;
      if (in_array('G', $departments, true)) {
        $graphicsSubcat = dept_get_graphics_subcat($customLabel, $sku);
      }

      // Zostavíme internal_options pre subcategory
      $internalOptions = [];
      if ($graphicsSubcat !== null) {
        $internalOptions['_subcat'] = $graphicsSubcat;
      }
      $internalOptionsJson = $internalOptions ? json_encode($internalOptions, JSON_UNESCAPED_UNICODE) : null;

      // --- Primárna položka (vždy jeden riadok pre primárny department) ---
      $itemId = oi_insert_item_unified_with_internal(
        $conn, $orderId, $lineNo ?: null,
        $sku, $title, $customLabel, $primaryDept, $qty, $optionsJson, $rawUnitPrice, $internalOptionsJson
      );

      $categoryCodes = dept_to_category_codes($departments);
      $categoryIds = [];
      foreach ($categoryCodes as $code) {
        if (isset($catIds[$code])) $categoryIds[] = $catIds[$code];
      }
      if ($categoryIds) oi_add_item_categories($conn, $itemId, array_values(array_unique($categoryIds)));
      $stats['items']++;

      // --- Multi-department expansion ---
      // Pre každý ďalší department (okrem primárneho) vytvoríme samostatnú
      // sub-položku. Toto nahradzuje pôvodnú hardcoded GFP logiku.
      $secondaryDepts = array_slice($departments, 1); // všetky okrem prvého
      foreach ($secondaryDepts as $secDept) {
        $autoTag = 'AUTO_' . implode('', $departments) . '_' . $secDept;
        // Mapuj na pôvodné auto-tagy pre spätnú kompatibilitu
        if ($departments === ['G', 'P', 'F'] || $departments === ['G', 'P', 'F', 'S']) {
          if ($secDept === 'P') $autoTag = 'GFP_AUTO_PLASTICS';
          if ($secDept === 'F') $autoTag = 'GFP_AUTO_FITTING';
          if ($secDept === 'S') $autoTag = 'GFP_AUTO_SEATCOVER';
        }
        $secItemId = oi_insert_item_unified_with_internal(
          $conn, $orderId, $autoLineNo++,
          $sku, $title, $customLabel,
          $secDept, $qty,
          oi_auto_item_options_json($optionsJson, $autoTag),
          null,  // cena len na primárnej položke
          null
        );
        $secCatCode = dept_to_category_codes([$secDept]);
        $secCatIds = [];
        foreach ($secCatCode as $code) {
          if (isset($catIds[$code])) $secCatIds[] = $catIds[$code];
        }
        if ($secCatIds) oi_add_item_categories($conn, $secItemId, array_values(array_unique($secCatIds)));
        $stats['items']++;
      }

      // --- Seat Cover patch auto-item (zachovaná pôvodná logika) ---
      if ($primaryDept === 'S' && oi_has_positive_option_value(oi_json_option_value($optionsJson, 'patch-style'))) {
        $patchItemId = oi_insert_item_unified_with_internal(
          $conn, $orderId, $autoLineNo++,
          $sku, 'Patch', $customLabel,
          'G', $qty,
          oi_auto_item_options_json($optionsJson, 'SEAT_PATCH_AUTO_GRAPHICS'),
          null,
          null
        );
        oi_add_item_categories($conn, $patchItemId, [$catIds['GRAPHICS']]);
        $stats['items']++;
      }

      // --- Shoptet variant expansion ---
      $extraItems = oi_extract_shoptet_variant_items($r, $qty, $autoLineNo);
      foreach ($extraItems as $extra) {
        $autoLineNo++;
        $extraItemId = oi_insert_item_unified_with_internal(
          $conn, $orderId,
          $extra['line_no'],
          $sku, $extra['title'], $customLabel,
          $extra['item_type'], $extra['qty'],
          oi_auto_item_options_json($optionsJson, $extra['auto_tag']),
          null,  // extra varianty nemajú vlastnú cenu
          null
        );
        $extraCatCode = dept_to_category_codes([$extra['item_type']]);
        $extraCatIds = [];
        foreach ($extraCatCode as $code) {
          if (isset($catIds[$code])) $extraCatIds[] = $catIds[$code];
        }
        if ($extraCatIds) oi_add_item_categories($conn, $extraItemId, array_values(array_unique($extraCatIds)));
        $stats['items']++;
      }
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
  if (!empty($stats['skipped_locked_orders'])) {
    $stats['note'] .= '; skipped locked orders: ' . (int) $stats['skipped_locked_orders'];
  }
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
      $nested = oi_json_decode_assoc_safe((string)oi_json_clean($v));
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

  switch ($itemType) {
    case 'G':
      return ['GRAPHICS'];

    case 'T':
    case 'M':
      return ['GRAPHICS', 'PLASTICS'];

    case 'P':
      return ['PLASTICS'];

    case 'S':
      return ['SEATCOVER'];

    case 'F':
      return ['FITTING'];

    default:
      return [];
  }
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
    $decoded = oi_json_decode_assoc_safe($optionsRaw);
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

  // -----------------------------------------------------------------------
  // Merge source_raw_json variant fields to the top level of options_json.
  //
  // This makes all Shoptet/MxLocker variant keys (e.g. "rim-tapes-color",
  // "rim-tapes-size-2", "spoke-coats-color" …) visible to controlls.php
  // so they can be mapped to product spec dropdowns.
  //
  // Rules:
  //   1. Only scalar values are merged (no nested objects).
  //   2. Keys starting with "_" are skipped (internal tags).
  //   3. Order-level metadata keys are skipped (they belong to the order
  //      header, not to the product options form).
  //   4. Already-set keys in $opts (from the CSV options_json column, i.e.
  //      what the customer explicitly chose in the Shoptet form) take
  //      priority — raw values only FILL IN missing keys.
  // -----------------------------------------------------------------------
  $raw = oi_trim($r['source_raw_json'] ?? null);
  if ($raw) {
    $decodedRaw = oi_json_decode_assoc_safe($raw);
    if (is_array($decodedRaw)) {
      // Keys that carry order-level metadata, not product option values.
      static $orderMetaKeys = [
        'code', 'date', 'statusName', 'currency', 'exchangeRate',
        'email', 'phone', 'billFullName', 'billCompany', 'billStreet',
        'billHouseNumber', 'billCity', 'billZip', 'billCountryName',
        'companyId', 'vatId', 'customerIdentificationNumber',
        'deliveryFullName', 'deliveryCompany', 'deliveryVatId',
        'deliveryStreet', 'deliveryHouseNumber', 'deliveryCity',
        'deliveryZip', 'deliveryCountryName', 'customerIpAddress',
        'remark', 'shopRemark', 'packageNumber',
        'varchar1', 'varchar2', 'varchar3', 'text1', 'text2', 'text3',
        'weight', 'totalPriceWithVat', 'totalPriceWithoutVat',
        'totalPriceVat', 'rounding', 'priceToPay', 'amountPaid', 'paid',
        'itemName', 'itemAmount', 'itemCode', 'itemVariantName',
        'itemManufacturer', 'itemUnit', 'itemWeight', 'itemStatusName',
        'itemUnitPriceWithVat', 'itemUnitPriceWithoutVat', 'itemUnitPriceVat',
        'itemVatRate', 'itemTotalPriceWithVat', 'itemTotalPriceWithoutVat',
        'itemTotalPriceVat', 'itemEan', 'itemPlu', 'itemSupplier',
        'itemUnitDiscountPriceWithVat', 'itemUnitDiscountPriceWithoutVat',
        'sourceName', 'salesChannelName', 'billVatIdValidationStatus',
        // MxLocker order-level keys
        'Sale Date', 'Order Number', 'Item Title', 'Item Qty', 'Item State',
        'Buyer Name', 'Buyer Email', 'Gross Selling Price', 'Processing',
        'Tax', 'Tax Remitted', 'Mx Locker Fee', 'Customer Phone Number',
        'Sku', 'Shipping Price', 'Expedited Shipping',
        'Address Line One', 'Address Line Two', 'City', 'State Province',
        'Postal Code', 'Country',
      ];
      static $orderMetaIndex = null;
      if ($orderMetaIndex === null) {
        $orderMetaIndex = array_flip($orderMetaKeys);
      }

      foreach ($decodedRaw as $rawKey => $rawValue) {
        // Skip internal keys and non-scalar values.
        if (!is_string($rawKey) || $rawKey === '' || $rawKey[0] === '_') continue;
        if (!is_scalar($rawValue)) continue;
        // Skip order-level metadata.
        if (isset($orderMetaIndex[$rawKey])) continue;
        // Only fill in keys not already set by the explicit options_json.
        if (!array_key_exists($rawKey, $opts)) {
          $opts[$rawKey] = $rawValue;
        }
      }

      // Keep the full raw blob accessible for debugging / future use.
      $opts['_source_raw'] = $decodedRaw;
    } else {
      // Fallback: store as string if JSON was malformed.
      $opts['_source_raw'] = $raw;
    }
  }

  return $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}

function oi_json_option_value(?string $json, string $key): ?string {
  if ($json === null || trim($json) === '') return null;

  $decoded = oi_json_decode_assoc_safe($json);
  if (!is_array($decoded)) return null;

  $value = $decoded[$key] ?? null;
  if (is_array($value)) return null;

  return oi_trim((string)$value);
}

function oi_has_positive_option_value(?string $value): bool {
  $value = oi_trim($value);
  if ($value === null) return false;

  $negativeValues = ['no', 'nie', 'nein', 'non', 'false', '0', 'n/a', '-', 'x'];
  return !in_array(mb_strtolower($value), $negativeValues, true);
}

function oi_insert_item_unified(mysqli $conn, int $orderId, ?int $lineNo, ?string $sku, ?string $title, ?string $customLabel, ?string $itemTypeCode, int $qty, ?string $optionsJson, ?float $unitPrice = null): int {
  return oi_insert_item_unified_with_internal($conn, $orderId, $lineNo, $sku, $title, $customLabel, $itemTypeCode, $qty, $optionsJson, $unitPrice, null);
}

function oi_insert_item_unified_with_internal(mysqli $conn, int $orderId, ?int $lineNo, ?string $sku, ?string $title, ?string $customLabel, ?string $itemTypeCode, int $qty, ?string $optionsJson, ?float $unitPrice = null, ?string $internalOptionsJson = null): int {
  $sku = oi_trim($sku);
  $title = oi_trim($title);
  $customLabel = oi_trim($customLabel);
  $itemTypeCode = oi_trim($itemTypeCode);

  $stmt = $conn->prepare('
    INSERT INTO order_items (order_id, line_no, sku, title, custom_label, item_type_code, qty, unit_price, options_json, internal_options_json)
    VALUES (?,?,?,?,?,?,?,?,?,?)
  ');
  $stmt->bind_param('iissssidss', $orderId, $lineNo, $sku, $title, $customLabel, $itemTypeCode, $qty, $unitPrice, $optionsJson, $internalOptionsJson);
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
function oi_extract_shipping_method_from_rows(array $rows): ?string {
  foreach ($rows as $r) {
    $sku = strtoupper((string)oi_trim($r['item_sku'] ?? null));
    if (str_starts_with($sku, 'SHIPPING')) {
      return oi_trim($r['item_name'] ?? null);
    }
  }
  return oi_trim($rows[0]['shipping_method'] ?? null);
}

function oi_extract_payment_method_from_rows(array $rows): ?string {
  foreach ($rows as $r) {
    $sku = strtoupper((string)oi_trim($r['item_sku'] ?? null));
    if (str_starts_with($sku, 'BILLING')) {
      return oi_trim($r['item_name'] ?? null);
    }
  }
  return oi_trim($rows[0]['payment_method'] ?? null);
}

/**
 * Wraps existing options_json with an extra _auto_generated tag so the UI
 * can visually distinguish auto-created items from the original CSV line.
 */
function oi_auto_item_options_json(?string $baseOptionsJson, string $autoTag): ?string {
  $opts = [];
  if ($baseOptionsJson !== null) {
    $decoded = oi_json_decode_assoc_safe($baseOptionsJson);
    if (is_array($decoded)) $opts = $decoded;
  }
  $opts['_auto_generated'] = $autoTag;
  return json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * For Shoptet orders: inspects source_raw_json on a single item row and returns
 * a list of extra department items that should be auto-created.
 *
 * Rules:
 *   "applyinggraphics" has a non-empty, non-"No" value  → FITTING item
 *   "seat-cover"       has a non-empty, non-"No" value  → SEATCOVER item
 *   "mid-forks"        has a non-empty, non-"No" value  → GRAPHICS item  (title = value of the field)
 *   "grip"             has a non-empty, non-"No" value  → GRAPHICS item  (title = value of the field)
 *
 * Returns array of items:
 *   [
 *     ['line_no' => int, 'title' => string, 'item_type' => string,
 *      'qty' => int, 'auto_tag' => string],
 *     ...
 *   ]
 *
 * @param array $r         Single CSV row (assoc).
 * @param int   $qty       Quantity to copy from the parent item.
 * @param int   $startLineNo  Starting synthetic line number (will be incremented inside this function).
 */
function oi_extract_shoptet_variant_items(array $r, int $qty, int &$startLineNo): array {
  $source = strtoupper((string)oi_trim($r['source'] ?? null));
  if ($source !== 'SHOPTET') return [];

  // Decode source_raw_json — that is where Shoptet puts all variant fields.
  $rawJson = oi_trim($r['source_raw_json'] ?? null);
  if ($rawJson === null) return [];

  $raw = oi_json_decode_assoc_safe($rawJson);
  if (!is_array($raw)) return [];

  /**
   * Values considered "not selected" / "no" across all 6 Shoptet language
   * mutations (EN, SK, DE, FR, IT, ES) plus common boolean strings.
   */
  $negativeValues = ['no', 'nie', 'nein', 'non', 'no', 'no', 'false', '0', 'n/a', '-'];

  $isPositive = function(?string $val) use ($negativeValues): bool {
    $val = oi_trim($val);
    if ($val === null) return false;
    return !in_array(mb_strtolower($val), $negativeValues, true);
  };

  $items = [];

  // --- applyinggraphics → FITTING ---
  $applyVal = oi_trim($raw['applyinggraphics'] ?? null);
  if ($isPositive($applyVal)) {
    $items[] = [
      'line_no'   => $startLineNo++,
      'title'     => 'Fitting / Applying Graphics' . ($applyVal !== null && $applyVal !== '' ? ' - ' . $applyVal : ''),
      'item_type' => 'F',
      'qty'       => $qty,
      'auto_tag'  => 'SHOPTET_AUTO_FITTING',
    ];
  }

  // --- seat-cover → SEATCOVER ---
  $seatVal = oi_trim($raw['seat-cover'] ?? null);
  if ($isPositive($seatVal)) {
    $items[] = [
      'line_no'   => $startLineNo++,
      'title'     => 'Seat Cover' . ($seatVal !== null && $seatVal !== '' ? ' - ' . $seatVal : ''),
      'item_type' => 'S',
      'qty'       => $qty,
      'auto_tag'  => 'SHOPTET_AUTO_SEATCOVER',
    ];
  }

  // --- mid-forks → GRAPHICS ---
  $midForksVal = oi_trim($raw['mid-forks'] ?? null);
  if ($isPositive($midForksVal)) {
    $items[] = [
      'line_no'   => $startLineNo++,
      'title'     => 'Mid-Forks Stickers - ' . $midForksVal,
      'item_type' => 'G',
      'qty'       => $qty,
      'auto_tag'  => 'SHOPTET_AUTO_MIDFORKS',
    ];
  }
/*
  // --- grip → GRAPHICS ---
  //Gip sa uz načítava ako dropdown do grafiky a už nie je treba aby to bola samostatna polozka
  $gripVal = oi_trim($raw['grip'] ?? null);
  if ($isPositive($gripVal)) {
    $items[] = [
      'line_no'   => $startLineNo++,
      'title'     => 'Grip Stickers - ' . $gripVal,
      'item_type' => 'G',
      'qty'       => $qty,
      'auto_tag'  => 'SHOPTET_AUTO_GRIP',
    ];
  }
*/
  return $items;
}
