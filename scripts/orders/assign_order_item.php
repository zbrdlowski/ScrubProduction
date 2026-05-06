<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['ok' => false, 'error' => 'Not logged in']);
  exit;
}

$itemId = (int)($_POST['item_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$dpt = (int)($_SESSION['dpt'] ?? 0);

$deptTypeMap = [
  2 => 'G',
  6 => 'P',
  8 => 'S',
  9 => 'F',
];

if ($itemId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'Invalid item']);
  exit;
}

if (!isset($deptTypeMap[$dpt])) {
  echo json_encode(['ok' => false, 'error' => 'This department cannot assign items']);
  exit;
}

$expectedType = $deptTypeMap[$dpt];

$stmt = $conn->prepare("
  SELECT id, order_id, item_type_code, sku, title
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
  echo json_encode(['ok' => false, 'error' => 'Item not found']);
  exit;
}

$orderId = (int)$item['order_id'];
$itemType = strtoupper((string)$item['item_type_code']);

if ($itemType !== $expectedType) {
  echo json_encode(['ok' => false, 'error' => 'This item belongs to another department']);
  exit;
}

$stmt = $conn->prepare("
  INSERT INTO order_item_assignments
    (order_id, item_id, employee_id, assigned_by)
  VALUES
    (?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
    removed_at = NULL,
    assigned_by = VALUES(assigned_by),
    assigned_at = NOW()
");
$stmt->bind_param('iiii', $orderId, $itemId, $userId, $userId);
$stmt->execute();
$stmt->close();

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'item_assigned',
  'order_item',
  $itemId,
  [
    'employee_id' => $userId,
    'item_type_code' => $itemType,
    'sku' => $item['sku'],
    'title' => $item['title']
  ],
  'User assigned to item'
);

echo json_encode(['ok' => true]);