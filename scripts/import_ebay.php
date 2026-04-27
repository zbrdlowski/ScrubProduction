<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/order_import_lib.php';

oi_set_utf8mb4($conn);

$csvPath = $argv[1] ?? null;
if (!$csvPath || !is_file($csvPath)) {
  fwrite(STDERR, "Usage: php scripts/import_ebay.php /path/to/ebay_export.csv\n");
  exit(1);
}

$rows = oi_csv_read_assoc($csvPath);

$SOURCE_EBAY = oi_db_get_id_by_code($conn, 'order_sources', 'EBAY');
$catIds = [
  'GRAPHICS'  => oi_db_get_id_by_code($conn, 'categories', 'GRAPHICS'),
  'PLASTICS'  => oi_db_get_id_by_code($conn, 'categories', 'PLASTICS'),
  'SEATCOVER' => oi_db_get_id_by_code($conn, 'categories', 'SEATCOVER'),
  'FITTING'   => oi_db_get_id_by_code($conn, 'categories', 'FITTING'),
];

$conn->begin_transaction();

try {
  // group by Order number
  $byOrder = [];
  foreach ($rows as $r) {
    $oid = oi_trim($r['Order number'] ?? null);
    if (!$oid) continue;
    $byOrder[$oid][] = $r;
  }

  $processed = 0;

  foreach ($byOrder as $externalOrderId => $itemsRows) {
    $first = $itemsRows[0];

    $customerId = oi_upsert_customer(
      $conn,
      $first['Buyer name'] ?? null,
      $first['Buyer email'] ?? null,
      $first['Post to phone'] ?? null
    );

    $orderDate = oi_parse_date_any($first['Sale date'] ?? null);
    $note = oi_trim($first['Buyer note'] ?? null);

    $sourceMeta = [
      'sales_record_number' => $first['Sales record number'] ?? null,
      'transaction_id' => $first['Transaction ID'] ?? null,
      'paypal_tx' => $first['PayPal transaction ID'] ?? null,
      'my_item_note' => $first['My item note'] ?? null,
    ];

    $orderId = oi_upsert_order_header_mysqli($conn, $SOURCE_EBAY, $externalOrderId, [
      'order_number' => $externalOrderId,
      'order_date' => $orderDate,
      'currency' => null,
      'total' => null,
      'payment_method' => oi_trim($first['Payment method'] ?? null),
      'shipping_method' => oi_trim($first['Delivery service'] ?? null),
      'note' => $note,
      'source_meta_json' => json_encode($sourceMeta, JSON_UNESCAPED_UNICODE),
      'customer_id' => $customerId,
    ]);

    // snapshot shipping address
    $street = trim((string)($first['Post to address 1'] ?? ''));
    $street2 = trim((string)($first['Post to address 2'] ?? ''));
    $streetFull = trim($street . ' ' . $street2);

    oi_upsert_address($conn, $orderId, 'SHIPPING', [
      'name' => $first['Post to name'] ?? $first['Buyer name'] ?? null,
      'street' => $streetFull ?: null,
      'city' => $first['Post to city'] ?? null,
      'zip' => $first['Post to postcode'] ?? null,
      'country' => null, // eBay: "Latvia" -> ISO2 môžeme dorobiť neskôr
      'email' => $first['Buyer email'] ?? null,
      'phone' => $first['Post to phone'] ?? null,
    ]);

    // refresh items
    oi_delete_items_for_order($conn, $orderId);

    $lineNo = 0;
    foreach ($itemsRows as $r) {
      $lineNo++;
      $title = $r['Item title'] ?? null;
$label = $r['Custom label'] ?? null;
$qty   = (int)($r['Quantity'] ?? 1);

$itemType = oi_detect_item_type($label, $title, null, null);

$itemId = oi_insert_item(
  $conn, $orderId, $line, null, $title, $label, $qty,
  $optionsJson,
  $itemType
);

$catCodes = oi_item_type_to_category_codes($itemType);
$categoryIds = [];
foreach ($catCodes as $code) {
  $categoryIds[] = oi_db_get_id_by_code($conn, 'categories', $code);
}
oi_add_item_categories($conn, $itemId, $categoryIds);

      $options = [
        'item_number' => $r['Item number'] ?? null,
        'variation_details' => $r['Variation details'] ?? null,
        'sold_for' => $r['Sold for'] ?? null,
        'postage' => $r['Postage and packaging'] ?? null,
      ];
      $optionsJson = json_encode(array_filter($options, fn($v)=>$v!==null && $v!==''), JSON_UNESCAPED_UNICODE);

      $itemId = oi_insert_item($conn, $orderId, $lineNo, null, $title, $customLabel, $qty, $optionsJson);

      $letters = oi_parse_category_letters($customLabel, $title);
      $categoryIds = [];
      foreach ($letters as $L) $categoryIds[] = oi_letter_to_category_id($catIds, $L);
      oi_add_item_categories($conn, $itemId, $categoryIds);
    }

    oi_refresh_order_categories($conn, $orderId);

    // tracking (ak je)
    $tracking = oi_trim($first['Tracking number'] ?? null);
    if ($tracking) {
      $carrier = oi_trim($first['Delivery service'] ?? null);
      $stmt = $conn->prepare("INSERT INTO shipments (order_id, carrier, tracking_number, status, source) VALUES (?,?,?,'SHIPPED','CSV')");
      $stmt->bind_param('iss', $orderId, $carrier, $tracking);
      $stmt->execute();
      $stmt->close();
    }

    $processed++;
  }

  $conn->commit();
  echo "OK: Imported eBay orders: $processed\n";
} catch (Throwable $e) {
  $conn->rollback();
  throw $e;
}
?>