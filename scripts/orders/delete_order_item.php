<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';
require_once __DIR__ . '/category_sync_helper.php';

function out(array $payload): void {
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if ((int)($_SESSION['permission'] ?? 0) < 300) {
  out(['ok' => false, 'error' => 'No permission']);
}

$itemId = (int)($_POST['item_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($itemId <= 0) {
  out(['ok' => false, 'error' => 'Invalid item id']);
}

$stmt = $conn->prepare("
  SELECT id, order_id, sku, title, item_type_code, qty
  FROM order_items
  WHERE id = ?
    AND deleted_at IS NULL
  LIMIT 1
");
$stmt->bind_param('i', $itemId);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) {
  out(['ok' => false, 'error' => 'Item not found']);
}

$orderId = (int)$item['order_id'];

$stmt = $conn->prepare("
  UPDATE order_items
  SET deleted_at = NOW(),
      updated_by = ?,
      updated_at = NOW()
  WHERE id = ?
  LIMIT 1
");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('ii', $userId, $itemId);
$stmt->execute();
$stmt->close();
sync_order_categories($conn, $orderId);

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'item_deleted',
  'order_item',
  $itemId,
  $item,
  'Item deleted: ' . (string)($item['title'] ?? '')
);

out(['ok' => true, 'order_id' => $orderId]);