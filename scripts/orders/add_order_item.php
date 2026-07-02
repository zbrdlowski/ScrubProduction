<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';
require_once __DIR__ . '/category_sync_helper.php';
require_once dirname(__DIR__, 2) . '/includes/orders_workflow_helpers.php';
require_once __DIR__ . '/manual_item_builder_helper.php';

function out(array $payload): void {
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if ((int)($_SESSION['permission'] ?? 0) < 300) {
  out(['ok' => false, 'error' => 'No permission']);
}

$orderId = (int)($_POST['order_id'] ?? 0);
$type = strtoupper(trim((string)($_POST['item_type_code'] ?? '')));
$title = trim((string)($_POST['title'] ?? ''));
$sku = trim((string)($_POST['sku'] ?? 'MANUAL'));
$qty = max(1, (int)($_POST['qty'] ?? 1));
$reason = trim((string)($_POST['reason'] ?? ''));

$userId = (int)($_SESSION['user_id'] ?? 0);

$allowedTypes = ['G','P','S','F','T','M'];

if ($orderId <= 0 || $title === '' || !in_array($type, $allowedTypes, true)) {
  out(['ok' => false, 'error' => 'Missing or invalid data']);
}

$stmt = $conn->prepare("
  SELECT COALESCE(MAX(line_no), 0) + 1 AS next_line
  FROM order_items
  WHERE order_id = ?
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$lineNo = (int)($row['next_line'] ?? 1);

$payload = manualItemPayloadFromPost($conn, $type);
$options = json_decode((string) ($payload['options_json'] ?? '{}'), true);
if (!is_array($options)) {
  $options = [];
}
$options['created_by'] = $userId;
if ($reason !== '') {
  $options['reason'] = $reason;
}
$optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$internalOptionsJson = (string) ($payload['internal_options_json'] ?? '{}');

if ($sku === '') {
  $sku = 'MANUAL';
}

$stmt = $conn->prepare("INSERT INTO order_items
    (order_id, line_no, sku, title, custom_label, item_type_code, qty, options_json, internal_options_json, created_by, updated_by, updated_at)
  VALUES
    (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, NOW())
");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param(
  'iisssissii',
  $orderId,
  $lineNo,
  $sku,
  $title,
  $type,
  $qty,
  $optionsJson,
  $internalOptionsJson,
  $userId,
  $userId
);

$ok = $stmt->execute();
if (!$ok) {
  $error = $stmt->error ?: $conn->error ?: 'Unknown DB error';
  $stmt->close();
  out(['ok' => false, 'error' => $error]);
}
$itemId = (int)$conn->insert_id;
$stmt->close();
sync_order_categories($conn, $orderId);
log_order_activity(
  $conn,
  $orderId,
  $userId,
  'item_added',
  'order_item',
  $itemId,
  [
    'line_no' => $lineNo,
    'sku' => $sku,
    'title' => $title,
    'type' => $type,
    'qty' => $qty,
    'reason' => $reason
  ],
  'Manual item added: ' . $title
);

recalculateOrderWorkflow($conn, $orderId);
out(['ok' => true, 'item_id' => $itemId]);
