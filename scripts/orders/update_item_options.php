<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

function out(array $payload): void
{
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ((int) ($_SESSION['permission'] ?? 0) < 300) {
  out(['ok' => false, 'error' => 'No permission']);
}

$itemId = (int) ($_POST['item_id'] ?? 0);
$json = trim((string) ($_POST['options_json'] ?? '{}'));
$userId = (int) ($_SESSION['user_id'] ?? 0);

$data = json_decode($json, true);
if ($itemId <= 0 || !is_array($data)) {
  out(['ok' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
}

$normalizedJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($normalizedJson === false) {
  out(['ok' => false, 'error' => 'Could not encode JSON']);
}

$stmt = $conn->prepare("
  SELECT order_id, options_json
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
  out(['ok' => false, 'error' => 'Item not found']);
}

$oldJson = (string) ($old['options_json'] ?? '');
$oldData = json_decode($oldJson ?: '{}', true);
if (!is_array($oldData)) {
  $oldData = [];
}

$oldNormalizedJson = json_encode($oldData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($oldNormalizedJson === $normalizedJson) {
  out(['ok' => true, 'unchanged' => true]);
}

$stmt = $conn->prepare("
  UPDATE order_items
  SET options_json = ?,
      updated_by = ?,
      updated_at = NOW()
  WHERE id = ?
");
$stmt->bind_param('sii', $normalizedJson, $userId, $itemId);
$stmt->execute();
$stmt->close();

$changedKeys = [];
foreach (array_unique(array_merge(array_keys($oldData), array_keys($data))) as $key) {
  $oldValue = $oldData[$key] ?? null;
  $newValue = $data[$key] ?? null;
  if (json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) {
    $changedKeys[] = (string) $key;
  }
}

$changedLabel = '';
if ($changedKeys) {
  $shownKeys = array_slice($changedKeys, 0, 8);
  $changedLabel = ': ' . implode(', ', $shownKeys);
  if (count($changedKeys) > count($shownKeys)) {
    $changedLabel .= ' +' . (count($changedKeys) - count($shownKeys)) . ' more';
  }
}

log_order_activity(
  $conn,
  (int) $old['order_id'],
  $userId,
  'item_options_updated',
  'order_item',
  $itemId,
  [
    'changed_keys' => $changedKeys,
    'old' => $oldJson,
    'new' => $normalizedJson,
  ],
  'Customer item options updated' . $changedLabel
);

out([
  'ok' => true,
  'changed_keys' => $changedKeys,
]);
