<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';
require_once __DIR__ . '/category_sync_helper.php';
require_once dirname(__DIR__, 2) . '/includes/orders_workflow_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/orders_status_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/orders_plastics_gate_helpers.php';
require_once __DIR__ . '/manual_item_builder_helper.php';

function out(array $payload): void {
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

if (!auth_can('orders.manage')) {
  out(['ok' => false, 'error' => 'No permission']);
}

$orderId = (int)($_POST['order_id'] ?? 0);
$type = strtoupper(trim((string)($_POST['item_type_code'] ?? '')));
$title = trim((string)($_POST['title'] ?? ''));
$sku = trim((string)($_POST['sku'] ?? 'MANUAL'));
$customLabel = trim((string)($_POST['custom_label'] ?? ''));
$qty = max(1, (int)($_POST['qty'] ?? 1));
$unitPriceRaw = trim((string)($_POST['unit_price'] ?? '0'));
$unitPrice = $unitPriceRaw !== '' ? round((float) str_replace(',', '.', $unitPriceRaw), 2) : 0.0;
$reason = trim((string)($_POST['reason'] ?? ''));
$requestedItemStatus = strtoupper(trim((string)($_POST['item_status'] ?? '')));

$userId = (int)($_SESSION['user_id'] ?? 0);

$allowedTypes = ['G', 'P', 'S', 'F', 'T', 'M'];

if ($type === 'F') {
  if ($title === '') {
    $title = 'Fitting';
  }
  if ($unitPrice <= 0) {
    $unitPrice = 39.90;
  }
}

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
$optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($optionsJson === false) {
  $optionsJson = '{}';
}
$internalOptionsJson = (string) ($payload['internal_options_json'] ?? '{}');

if ($sku === '') {
  $sku = 'MANUAL';
}

$statusItem = [
  'item_type_code' => $type,
  'sku' => $sku,
  'custom_label' => $customLabel,
  'options_json' => $optionsJson,
  'internal_options_json' => $internalOptionsJson,
];
$activeStatusDefinitions = ordersGetItemStatusDefinitionsForItem($conn, $statusItem, true);
$allStatusDefinitions = ordersGetItemStatusDefinitionsForItem($conn, $statusItem, false);
if ($requestedItemStatus !== '' && isset($allStatusDefinitions[$requestedItemStatus])) {
  $itemStatus = $requestedItemStatus;
} else {
  $firstStatus = array_key_first($activeStatusDefinitions);
  $itemStatus = is_string($firstStatus) && $firstStatus !== '' ? $firstStatus : 'NEW';
}

$stmt = $conn->prepare("INSERT INTO order_items
    (order_id, line_no, sku, title, custom_label, item_type_code, qty, unit_price, options_json, internal_options_json, status, created_by, updated_by, updated_at)
  VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param(
  'iissssidsssii',
  $orderId,
  $lineNo,
  $sku,
  $title,
  $customLabel,
  $type,
  $qty,
  $unitPrice,
  $optionsJson,
  $internalOptionsJson,
  $itemStatus,
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

$autoPlasticsItemId = 0;
if (dept_is_moto_carpet_graphics_item($type, $sku, $customLabel, $internalOptionsJson)) {
  $autoPlasticsLineNo = $lineNo + 1;
  $autoPlasticsType = 'P';
  $autoPlasticsUnitPrice = null;
  $autoPlasticsOptionsJson = dept_moto_carpet_plastics_options_json($optionsJson, $lineNo);
  $autoPlasticsInternalOptionsJson = '{}';
  $autoPlasticsStatus = ordersPlasticsGateDefaultStatusForItem($conn, [
    'item_type_code' => $autoPlasticsType,
    'sku' => $sku,
    'custom_label' => $customLabel,
    'options_json' => $autoPlasticsOptionsJson,
    'internal_options_json' => $autoPlasticsInternalOptionsJson,
  ]);

  $autoStmt = $conn->prepare("INSERT INTO order_items
      (order_id, line_no, sku, title, custom_label, item_type_code, qty, unit_price, options_json, internal_options_json, status, created_by, updated_by, updated_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
  ");
  if (!$autoStmt) {
    $error = $conn->error ?: 'Moto Carpet plastics item could not be prepared';
    $cleanupStmt = $conn->prepare('DELETE FROM order_items WHERE id = ? AND order_id = ?');
    if ($cleanupStmt) {
      $cleanupStmt->bind_param('ii', $itemId, $orderId);
      $cleanupStmt->execute();
      $cleanupStmt->close();
    }
    out(['ok' => false, 'error' => $error]);
  }
  $autoStmt->bind_param(
    'iissssidsssii',
    $orderId,
    $autoPlasticsLineNo,
    $sku,
    $title,
    $customLabel,
    $autoPlasticsType,
    $qty,
    $autoPlasticsUnitPrice,
    $autoPlasticsOptionsJson,
    $autoPlasticsInternalOptionsJson,
    $autoPlasticsStatus,
    $userId,
    $userId
  );
  if (!$autoStmt->execute()) {
    $error = $autoStmt->error ?: $conn->error ?: 'Moto Carpet plastics item could not be created';
    $autoStmt->close();
    $cleanupStmt = $conn->prepare('DELETE FROM order_items WHERE id = ? AND order_id = ?');
    if ($cleanupStmt) {
      $cleanupStmt->bind_param('ii', $itemId, $orderId);
      $cleanupStmt->execute();
      $cleanupStmt->close();
    }
    out(['ok' => false, 'error' => $error]);
  }
  $autoPlasticsItemId = (int) $autoStmt->insert_id;
  $autoStmt->close();
}

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
    'custom_label' => $customLabel,
    'type' => $type,
    'qty' => $qty,
    'unit_price' => $unitPrice,
    'status' => $itemStatus,
    'auto_plastics_item_id' => $autoPlasticsItemId ?: null,
    'reason' => $reason
  ],
  'Item added: ' . $title . ' (' . $qty . ' × ' . number_format($unitPrice, 2, '.', '') . ' EUR)'
);

recalculateOrderWorkflow($conn, $orderId);
out([
  'ok' => true,
  'item_id' => $itemId,
  'auto_plastics_item_id' => $autoPlasticsItemId ?: null,
  'order_id' => $orderId,
]);
