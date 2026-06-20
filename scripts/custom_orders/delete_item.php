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

$stmt = $conn->prepare('DELETE FROM custom_order_items WHERE id = ? AND custom_order_id = ?');
$stmt->bind_param('ii', $itemId, $orderId);
$stmt->execute();
$stmt->close();

customOrdersLog($conn, $orderId, 'item_deleted', $userId, ['item_id' => $itemId], 'Custom item deleted');
customOrdersFlash('success', 'Item deleted.');
customOrdersRedirect($orderId);
