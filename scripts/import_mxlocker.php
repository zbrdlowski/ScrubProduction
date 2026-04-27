<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/order_import_lib.php';

oi_set_utf8mb4($conn);

$csvPath = $argv[1] ?? null;
if (!$csvPath || !is_file($csvPath)) {
  fwrite(STDERR, "Usage: php scripts/import_mxlocker.php /path/to/mxlocker_export.csv\n");
  exit(1);
}

$rows = oi_csv_read_assoc($csvPath);

$SOURCE = oi_db_get_id_by_code($conn, 'order_sources', 'MX_LOCKER');
$catIds = [
  'GRAPHICS'  => oi_db_get_id_by_code($conn, 'categories', 'GRAPHICS'),
  'PLASTICS'  => oi_db_get_id_by_code($conn, 'categories', 'PLASTICS'),
  'SEATCOVER' => oi_db_get_id_by_code($conn, 'categories', 'SEATCOVER'),
  'FITTING'   => oi_db_get_id_by_code($conn, 'categories', 'FITTING'),
];

$conn->begin_transaction();

try {
  $byOrder = [];
  foreach ($rows as $r) {
    $oid = oi_trim($r['Order Number'] ?? null);
if (!$oid) continue;

$oid = trim((string)$oid); // ✅
$byOrder[$oid][] = $r;
  }

  $processed = 0;

  foreach ($byOrder as $externalOrderId => $itemRows) {
  $externalOrderId = trim((string)$externalOrderId); // ✅
  $first = $itemRows[0];

    $customerId = oi_upsert_customer(
      $conn,
      $first['Buyer Name'] ?? null,
      $first['Buyer Email'] ?? null,
      (string)($first['Customer Phone Number'] ?? '')
    );

    $orderDate = oi_parse_date_any($first['Sale Date'] ?? null);
    $currency = 'USD'; // MX Locker ukážka je US (môžeš upraviť, ak máš aj iné)

    // sum totals
    $gross = 0.0;
    foreach ($itemsRows as $r) {
      $g = $r['Gross Selling Price'] ?? null;
      if ($g !== null && $g !== '') $gross += (float)$g;
    }

    $meta = [
      'processing' => $first['Processing'] ?? null,
      'tax' => $first['Tax'] ?? null,
      'tax_remitted' => $first['Tax Remitted'] ?? null,
      'mx_fee' => $first['Mx Locker Fee'] ?? null,
      'item_state' => $first['Item State'] ?? null,
      'expedited_shipping' => $first['Expedited Shipping'] ?? null,
    ];

    $orderId = oi_upsert_order_header($conn, $sourceId, $externalOrderId, [
      'order_number' => $externalOrderId,
      'order_date' => $orderDate,
      'currency' => $currency,
      'total' => $gross,
      'payment_method' => null,
      'shipping_method' => null,
      'note' => null,
      'source_meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
      'customer_id' => $customerId,
    ]);

    // shipping address
    $street = trim((string)($first['Address Line One'] ?? ''));
    $street2 = trim((string)($first['Address Line Two'] ?? ''));
    $streetFull = trim($street . ' ' . $street2);

    oi_upsert_address($conn, $orderId, 'SHIPPING', [
      'name' => $first['Buyer Name'] ?? null,
      'street' => $streetFull ?: null,
      'city' => $first['City'] ?? null,
      'zip' => (string)($first['Postal Code'] ?? ''),
      'country' => oi_trim((string)($first['Country'] ?? '')), // "US"
      'email' => $first['Buyer Email'] ?? null,
      'phone' => (string)($first['Customer Phone Number'] ?? ''),
    ]);

    // refresh items
    oi_delete_items_for_order($conn, $orderId);

    $lineNo = 0;
    foreach ($itemsRows as $r) {
      $lineNo++;
     $sku   = $r['Sku'] ?? null;
$title = $r['Item title'] ?? ($r['Item Name'] ?? null);
$qty   = (int)($r['Quantity'] ?? 1);

$itemType = oi_detect_item_type(null, $title, $sku, null);

$itemId = oi_insert_item(
  $conn, $orderId, $line, $sku, $title, null, $qty,
  $optionsJson,
  $itemType
);

$catCodes = oi_item_type_to_category_codes($itemType);

      $options = [
        'item_state' => $r['Item State'] ?? null,
        'shipping_price' => $r['Shipping Price'] ?? null,
        'gross_selling_price' => $r['Gross Selling Price'] ?? null,
        'processing' => $r['Processing'] ?? null,
        'tax' => $r['Tax'] ?? null,
        'mx_fee' => $r['Mx Locker Fee'] ?? null,
      ];
      $optionsJson = json_encode(array_filter($options, fn($v)=>$v!==null && $v!==''), JSON_UNESCAPED_UNICODE);

      $itemId = oi_insert_item($conn, $orderId, $lineNo, $sku, $title, null, $qty, $optionsJson);

      // categories prefer SKU, fallback title
      $letters = oi_parse_category_letters($sku, $title);
      $categoryIds = [];
      foreach ($letters as $L) $categoryIds[] = oi_letter_to_category_id($catIds, $L);
      oi_add_item_categories($conn, $itemId, $categoryIds);
    }

    oi_refresh_order_categories($conn, $orderId);

    $processed++;
  }

  $conn->commit();
  echo "OK: Imported MX Locker orders: $processed\n";
} catch (Throwable $e) {
  $conn->rollback();
  throw $e;
}
?>