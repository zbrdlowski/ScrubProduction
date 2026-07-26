<?php
declare(strict_types=1);
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'PHP Fatal: ' . $err['message']]);
  } else {
    ob_end_flush();
  }
});

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/activity_helper.php';
require_once __DIR__ . '/../../includes/orders_workflow_helpers.php';

if ((int)($_SESSION['permission'] ?? 0) < 1) {
  echo json_encode(['ok' => false, 'error' => 'No permission']);
  exit;
}

$itemId = (int)($_POST['item_id'] ?? 0);
$json = trim((string)($_POST['internal_options_json'] ?? '{}'));
$userId = (int)($_SESSION['user_id'] ?? 0);

$data = json_decode($json, true);

if ($itemId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'Invalid item_id']);
  exit;
}

if (!is_array($data)) {
  echo json_encode(['ok' => false, 'error' => 'Invalid internal_options_json']);
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

// testujemee, jestli se nám vrátil řádek, a pokud ano, načteme z něj order_id a internal_options_json do proměnných $oldOrderId a $oldInternalOptionsJson

$stmt->bind_result($oldOrderId, $oldInternalOptionsJson);

$old = null;
if ($stmt->fetch()) {
  $old = [
    'order_id' => $oldOrderId,
    'internal_options_json' => $oldInternalOptionsJson
  ];
}

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
$affected = $stmt->affected_rows;
$error = $stmt->error;
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

try {
  recalculateOrderWorkflow($conn, (int)$old['order_id']);
} catch (\Throwable $e) {
  // Neblokuj odpoveď — workflow chyba nie je fatálna pre save
  error_log('recalculateOrderWorkflow error: ' . $e->getMessage());
}

echo json_encode([
  'ok' => true,
  'item_id' => $itemId,
  'saved_json' => $normalizedJson,
  'affected_rows' => $affected,
  'sql_error' => $error
]);
