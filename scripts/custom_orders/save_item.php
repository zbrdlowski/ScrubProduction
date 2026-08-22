<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$itemId = (int) ($_POST['custom_item_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$inlineEdit = (int) ($_POST['inline_edit'] ?? 0) === 1;
$finish = static function (bool $ok, string $message, int $responseItemId = 0) use ($inlineEdit, $orderId): void {
  if ($inlineEdit) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$ok) {
      http_response_code(422);
    }
    echo json_encode([
      'ok' => $ok,
      'message' => $message,
      'custom_order_id' => $orderId,
      'custom_item_id' => $responseItemId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }

  customOrdersFlash($ok ? 'success' : 'danger', $message);
  customOrdersRedirect($orderId);
};

if ($orderId <= 0) {
  $finish(false, 'Invalid custom order.');
}

$type = strtoupper(trim((string) ($_POST['item_type_code'] ?? 'G')));
$allowedTypes = array_keys(customOrdersAllowedItemTypes());
if (!in_array($type, $allowedTypes, true)) {
  $type = 'G';
}

$title = trim((string) ($_POST['title'] ?? ''));
if ($title === '') {
  $finish(false, 'Item title is required.', $itemId);
}

$payload = customOrdersItemPayloadFromPost($conn, $type);
$sku = trim((string) ($_POST['sku'] ?? 'MANUAL'));
$customLabel = trim((string) ($_POST['custom_label'] ?? ''));
$qty = max(1, (int) ($_POST['qty'] ?? 1));
$unitPrice = (float) ($_POST['unit_price'] ?? 0);
$isUpsell = isset($_POST['is_upsell']) ? 1 : 0;
$upsellSource = trim((string) ($_POST['upsell_source'] ?? ''));
$statusItem = [
  'item_type_code' => $type,
  'sku' => $sku,
  'custom_label' => $customLabel,
  'options_json' => $payload['options_json'],
  'internal_options_json' => $payload['internal_options_json'],
];
$itemStatus = customOrdersResolveItemStatus($conn, $statusItem, (string) ($_POST['item_status'] ?? ''));

if ($itemId > 0) {
  $existingItem = null;
  $stmt = $conn->prepare('SELECT * FROM custom_order_items WHERE id = ? AND custom_order_id = ? LIMIT 1');
  $stmt->bind_param('ii', $itemId, $orderId);
  $stmt->execute();
  $existingItem = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();
  if (!$existingItem) {
    $finish(false, 'Custom item not found.', $itemId);
  }

  $stmt = $conn->prepare('
    UPDATE custom_order_items
    SET item_type_code = ?, sku = ?, title = ?, custom_label = ?, qty = ?, unit_price = ?,
        is_upsell = ?, upsell_source = ?, options_json = ?, internal_options_json = ?, status = ?, updated_by = ?
    WHERE id = ? AND custom_order_id = ?
  ');
  $stmt->bind_param('ssssidissssiii', $type, $sku, $title, $customLabel, $qty, $unitPrice, $isUpsell, $upsellSource, $payload['options_json'], $payload['internal_options_json'], $itemStatus, $userId, $itemId, $orderId);
  $stmt->execute();
  $stmt->close();

  $itemAfter = [
    'item_type_code' => $type,
    'sku' => $sku,
    'title' => $title,
    'custom_label' => $customLabel,
    'qty' => $qty,
    'unit_price' => $unitPrice,
    'is_upsell' => $isUpsell,
    'upsell_source' => $upsellSource,
    'status' => $itemStatus,
  ];
  $itemChanges = customOrdersActivityCollectChanges((array) $existingItem, $itemAfter, array_keys($itemAfter));
  customOrdersLog(
    $conn,
    $orderId,
    'item_updated',
    $userId,
    [
      'item_id' => $itemId,
      'title' => $title,
      'item_type_code' => $type,
      'qty' => $qty,
      'unit_price' => $unitPrice,
      'changes' => $itemChanges,
    ],
    $itemChanges ? ('Updated item fields: ' . count($itemChanges)) : 'Custom item updated'
  );
  $finish(true, 'Item updated.', $itemId);
}

$lineNo = customOrdersNextLineNo($conn, $orderId);
$stmt = $conn->prepare('
  INSERT INTO custom_order_items
    (custom_order_id, line_no, item_type_code, sku, title, custom_label, qty, unit_price, is_upsell, upsell_source, options_json, internal_options_json, status, created_by, updated_by)
  VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$stmt->bind_param('iissssidissssii', $orderId, $lineNo, $type, $sku, $title, $customLabel, $qty, $unitPrice, $isUpsell, $upsellSource, $payload['options_json'], $payload['internal_options_json'], $itemStatus, $userId, $userId);
$stmt->execute();
$newItemId = (int) $stmt->insert_id;
$stmt->close();

customOrdersLog(
  $conn,
  $orderId,
  'item_added',
  $userId,
  [
    'item_id' => $newItemId,
    'title' => $title,
    'item_type_code' => $type,
    'qty' => $qty,
    'unit_price' => $unitPrice,
    'status' => $itemStatus,
  ],
  'Custom item added'
);
$finish(true, 'Item added.', $newItemId);
