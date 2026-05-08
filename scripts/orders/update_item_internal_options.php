<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

if ((int)($_SESSION['permission'] ?? 0) < 300) {
  echo json_encode(['ok' => false, 'error' => 'No permission']);
  exit;
}

$itemId = (int)($_POST['item_id'] ?? 0);
$json = trim((string)($_POST['internal_options_json'] ?? '{}'));
$userId = (int)($_SESSION['user_id'] ?? 0);

$data = json_decode($json, true);

if ($itemId <= 0 || !is_array($data)) {
  echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
  exit;
}

$normalizedJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$stmt = $conn->prepare("
  SELECT order_id, internal_options_json
  FROM order_items
  WHERE id = ?
    AND deleted_at IS NULL
  LIMIT 1
");
$stmt->bind_param('i', $itemId);
$stmt->execute();
$old = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$old) {
  echo json_encode(['ok' => false, 'error' => 'Item not found']);
  exit;
}

$stmt = $conn->prepare("
  UPDATE order_items
  SET internal_options_json = ?,
      updated_by = ?,
      updated_at = NOW()
  WHERE id = ?
");
$stmt->bind_param('sii', $normalizedJson, $userId, $itemId);
$stmt->execute();
$stmt->close();

log_order_activity(
  $conn,
  (int)$old['order_id'],
  $userId,
  'item_internal_options_updated',
  'order_item',
  $itemId,
  [
    'old' => $old['internal_options_json'],
    'new' => $normalizedJson
  ],
  'Internal product options updated'
);

echo json_encode(['ok' => true]);