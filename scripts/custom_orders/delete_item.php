<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$itemId = (int) ($_POST['custom_item_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($orderId <= 0 || $itemId <= 0) {
  customOrdersFlash('danger', 'Invalid item delete request.');
  customOrdersRedirect($orderId);
}

$deletedItem = null;
$stmt = $conn->prepare('SELECT title, item_type_code, qty, unit_price FROM custom_order_items WHERE id = ? AND custom_order_id = ? LIMIT 1');
$stmt->bind_param('ii', $itemId, $orderId);
$stmt->execute();
$deletedItem = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

$stmt = $conn->prepare('DELETE FROM custom_order_items WHERE id = ? AND custom_order_id = ?');
$stmt->bind_param('ii', $itemId, $orderId);
$stmt->execute();
$stmt->close();

customOrdersLog(
  $conn,
  $orderId,
  'item_deleted',
  $userId,
  [
    'item_id' => $itemId,
    'title' => (string) ($deletedItem['title'] ?? ''),
    'item_type_code' => (string) ($deletedItem['item_type_code'] ?? ''),
    'qty' => (int) ($deletedItem['qty'] ?? 0),
    'unit_price' => (float) ($deletedItem['unit_price'] ?? 0),
  ],
  'Custom item deleted'
);
customOrdersFlash('success', 'Item deleted.');
customOrdersRedirect($orderId);
