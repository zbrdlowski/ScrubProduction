<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/order_import_lib.php';

oi_set_utf8mb4($conn);

$csvPath = $argv[1] ?? null;
if (!$csvPath || !is_file($csvPath)) {
  fwrite(STDERR, "Usage: php scripts/import_shoptet.php /path/to/shoptet_export.csv\n");
  exit(1);
}

$rows = oi_csv_read_assoc($csvPath);

$SOURCE = oi_db_get_id_by_code($conn, 'order_sources', 'SHOPTET');
$catIds = [
  'GRAPHICS'  => oi_db_get_id_by_code($conn, 'categories', 'GRAPHICS'),
  'PLASTICS'  => oi_db_get_id_by_code($conn, 'categories', 'PLASTICS'),
  'SEATCOVER' => oi_db_get_id_by_code($conn, 'categories', 'SEATCOVER'),
  'FITTING'   => oi_db_get_id_by_code($conn, 'categories', 'FITTING'),
];

$conn->begin_transaction();

try {
  // group by code
  $byOrder = [];
  foreach ($rows as $r) {
    $code = oi_trim($r['code'] ?? null);
if (!$code) $code = oi_trim($r['"code"'] ?? null);
if (!$code) continue;

$code = trim((string)$code); // ✅
$byOrder[$code][] = $r;
  }

  $processed = 0;

  foreach ($byOrder as $externalOrderId => $itemRows) {
     $externalOrderId = trim((string)$externalOrderId);
        $first = $itemRows[0];

    $customerId = oi_upsert_customer(
      $conn,
      $first['billFullName'] ?? null,
      $first['email'] ?? null,
      (string)($first['phone'] ?? '')
    );

    $orderDate = oi_parse_date_any($first['date'] ?? null);
    $currency = oi_trim($first['currency'] ?? null);

    // Shoptet money fields: "963,30"
    $total = oi_parse_money($first['totalPriceWithVat'] ?? null);
    $note = oi_trim($first['remark'] ?? null);

    $meta = [
      'statusName' => $first['statusName'] ?? null,
      'sourceName' => $first['sourceName'] ?? null,
      'paid' => $first['paid'] ?? null,
      'amountPaid' => $first['amountPaid'] ?? null,
      'priceToPay' => $first['priceToPay'] ?? null,
      'packageNumber' => $first['packageNumber'] ?? null,
      'shopRemark' => $first['shopRemark'] ?? null,
    ];

     $orderId = oi_upsert_order_header($conn, $sourceId, $externalOrderId, [
      'order_number' => $externalOrderId,
      'order_date' => $orderDate,
      'currency' => $currency,
      'total' => $total,
      'payment_method' => null,
      'shipping_method' => null,
      'note' => $note,
      'source_meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
      'customer_id' => $customerId,
    ]);

    // billing address
    oi_upsert_address($conn, $orderId, 'BILLING', [
      'name' => $first['billFullName'] ?? null,
      'company' => $first['billCompany'] ?? null,
      'street' => trim((string)($first['billStreet'] ?? '') . ' ' . (string)($first['billHouseNumber'] ?? '')),
      'city' => $first['billCity'] ?? null,
      'zip' => (string)($first['billZip'] ?? ''),
      'country' => null, // Shoptet gives country name; ISO2 mapping optional later
      'email' => $first['email'] ?? null,
      'phone' => (string)($first['phone'] ?? ''),
    ]);

    // shipping address
    oi_upsert_address($conn, $orderId, 'SHIPPING', [
      'name' => $first['deliveryFullName'] ?? null,
      'company' => $first['deliveryCompany'] ?? null,
      'street' => trim((string)($first['deliveryStreet'] ?? '') . ' ' . (string)($first['deliveryHouseNumber'] ?? '')),
      'city' => $first['deliveryCity'] ?? null,
      'zip' => (string)($first['deliveryZip'] ?? ''),
      'country' => null,
      'email' => $first['email'] ?? null,
      'phone' => (string)($first['phone'] ?? ''),
    ]);

    // refresh items
    oi_delete_items_for_order($conn, $orderId);

    // Determine where “options columns” start: after Category Info
    $allKeys = array_keys($first);
    $catInfoIndex = array_search('Category Info', $allKeys, true);
    if ($catInfoIndex === false) {
      // fallback: keep only known item option columns by ignoring fixed header block
      $catInfoIndex = array_search('sourceName', $allKeys, true);
      $catInfoIndex = ($catInfoIndex === false) ? 60 : $catInfoIndex;
    }
    $optionKeys = array_slice($allKeys, $catInfoIndex); // includes "Category Info" and all following

    $lineNo = 0;

    foreach ($itemsRows as $r) {
      $lineNo++;

      $itemName = $r['itemName'] ?? null;
      $qty = (int)($r['itemAmount'] ?? 1);
      $itemCode = $r['itemCode'] ?? null;
      $variant = $r['itemVariantName'] ?? null;

      // collect options_json (only non-empty values)
      $opts = [];
      foreach ($optionKeys as $k) {
        $val = $r[$k] ?? null;
        $val = is_string($val) ? trim($val) : $val;
        if ($val === null || $val === '' || (is_float($val) && is_nan($val))) continue;
        $opts[$k] = $val;
      }
      // also include a few useful item fields
      $opts['_item'] = [
        'itemUnit' => $r['itemUnit'] ?? null,
        'itemSupplier' => $r['itemSupplier'] ?? null,
        'itemEan' => $r['itemEan'] ?? null,
        'unitPriceWithVat' => $r['itemUnitPriceWithVat'] ?? null,
        'totalPriceWithVat' => $r['itemTotalPriceWithVat'] ?? null,
        'statusName' => $r['itemStatusName'] ?? null,
      ];

      $optionsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);

      $title = $itemName;
      if ($variant) $title = trim((string)$itemName . ' / ' . (string)$variant);

      $itemId = oi_insert_item($conn, $orderId, $lineNo, $itemCode, $title, null, $qty, $optionsJson);

      // categories:
      // 1) from itemCode prefix letters (e.g., GH00099 => G)
      // 2) fallback Category Info
      // 3) fallback itemName
      $catInfo = $r['Category Info'] ?? null;
      $letters = [];

      $codePrefix = oi_trim($itemCode);
      if ($codePrefix) {
        // pick leading letters only
        if (preg_match('/^([A-Z]{1,6})/', $codePrefix, $m)) {
          $letters = oi_parse_category_letters($m[1], $title);
        }
      }
      if (!$letters) $letters = oi_parse_category_letters($catInfo, $title);
      if (!$letters) $letters = oi_parse_category_letters(null, $title);

      $categoryIds = [];
      foreach ($letters as $L) $categoryIds[] = oi_letter_to_category_id($catIds, $L);
      oi_add_item_categories($conn, $itemId, $categoryIds);
    }

    oi_refresh_order_categories($conn, $orderId);

    $processed++;
  }

  $conn->commit();
  echo "OK: Imported Shoptet orders: $processed\n";
} catch (Throwable $e) {
  $conn->rollback();
  throw $e;
}
?>