<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$itemId = (int) ($_POST['custom_item_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order.');
  customOrdersRedirect();
}

$type = strtoupper(trim((string) ($_POST['item_type_code'] ?? 'G')));
$allowedTypes = array_keys(customOrdersAllowedItemTypes());
if (!in_array($type, $allowedTypes, true)) {
  $type = 'G';
}

$title = trim((string) ($_POST['title'] ?? ''));
if ($title === '') {
  customOrdersFlash('danger', 'Item title is required.');
  customOrdersRedirect($orderId);
}

$payload = customOrdersItemPayloadFromPost();
$sku = trim((string) ($_POST['sku'] ?? 'MANUAL'));
$customLabel = trim((string) ($_POST['custom_label'] ?? ''));
$qty = max(1, (int) ($_POST['qty'] ?? 1));
$unitPrice = (float) ($_POST['unit_price'] ?? 0);
$isUpsell = isset($_POST['is_upsell']) ? 1 : 0;
$upsellSource = trim((string) ($_POST['upsell_source'] ?? ''));

if ($itemId > 0) {
  $stmt = $conn->prepare('
    UPDATE custom_order_items
    SET item_type_code = ?, sku = ?, title = ?, custom_label = ?, qty = ?, unit_price = ?,
        is_upsell = ?, upsell_source = ?, options_json = ?, internal_options_json = ?, updated_by = ?
    WHERE id = ? AND custom_order_id = ?
  ');
  $stmt->bind_param('ssssidisssiii', $type, $sku, $title, $customLabel, $qty, $unitPrice, $isUpsell, $upsellSource, $payload['options_json'], $payload['internal_options_json'], $userId, $itemId, $orderId);
  $stmt->execute();
  $stmt->close();
  customOrdersLog($conn, $orderId, 'item_updated', $userId, ['item_id' => $itemId, 'title' => $title], 'Custom item updated');
  customOrdersFlash('success', 'Item updated.');
  customOrdersRedirect($orderId);
}

$lineNo = customOrdersNextLineNo($conn, $orderId);
$stmt = $conn->prepare('
  INSERT INTO custom_order_items
    (custom_order_id, line_no, item_type_code, sku, title, custom_label, qty, unit_price, is_upsell, upsell_source, options_json, internal_options_json, created_by, updated_by)
  VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$stmt->bind_param('iissssidisssii', $orderId, $lineNo, $type, $sku, $title, $customLabel, $qty, $unitPrice, $isUpsell, $upsellSource, $payload['options_json'], $payload['internal_options_json'], $userId, $userId);
$stmt->execute();
$newItemId = (int) $stmt->insert_id;
$stmt->close();

customOrdersLog($conn, $orderId, 'item_added', $userId, ['item_id' => $newItemId, 'title' => $title], 'Custom item added');
customOrdersFlash('success', 'Item added.');
customOrdersRedirect($orderId);
