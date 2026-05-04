<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

if ((int)($_SESSION['permission'] ?? 0) < 300) {
  echo json_encode(['ok' => false, 'error' => 'No permission']);
  exit;
}

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

$itemId = (int)($_POST['item_id'] ?? 0);
$url = trim((string)($_POST['product_url'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($itemId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'Invalid item']);
  exit;
}

if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
  echo json_encode(['ok' => false, 'error' => 'Invalid URL']);
  exit;
}

$stmt = $conn->prepare("
  SELECT order_id, product_url
  FROM order_items
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param('i', $itemId);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) {
  echo json_encode(['ok' => false, 'error' => 'Item not found']);
  exit;
}

$orderId = (int)$item['order_id'];
$oldUrl = (string)($item['product_url'] ?? '');

$stmt = $conn->prepare("
  UPDATE order_items
  SET product_url = ?,
      updated_by = ?,
      updated_at = NOW()
  WHERE id = ?
");
$stmt->bind_param('sii', $url, $userId, $itemId);
$stmt->execute();
$stmt->close();

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'item_product_url_updated',
  'order_item',
  $itemId,
  [
    'old_url' => $oldUrl,
    'new_url' => $url
  ],
  'Product URL updated'
);

echo json_encode(['ok' => true]);