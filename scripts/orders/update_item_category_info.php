<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

function out(array $payload): void
{
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

if ((int) ($_SESSION['permission'] ?? 0) < 1) {
  out(['ok' => false, 'error' => 'No permission']);
}

$itemId = (int) ($_POST['item_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

$categoryInfo = trim((string) ($_POST['category_info'] ?? ''));
$brand = trim((string) ($_POST['category_brand'] ?? ''));
$model = trim((string) ($_POST['category_model'] ?? ''));
$year = trim((string) ($_POST['category_year_range'] ?? ''));
$modelCode = trim((string) ($_POST['category_modelcode'] ?? ''));

if ($itemId <= 0) {
  out(['ok' => false, 'error' => 'Invalid item']);
}

if ($categoryInfo !== '') {
  $parts = array_map('trim', explode('|', $categoryInfo));
  if ($brand === '' && isset($parts[0])) {
    $brand = $parts[0];
  }
  if ($model === '' && isset($parts[1])) {
    $model = $parts[1];
  }
  if ($year === '' && isset($parts[2])) {
    $year = $parts[2];
  }
  if ($modelCode === '' && isset($parts[3])) {
    $modelCode = $parts[3];
  }
}

if ($categoryInfo === '') {
  $parts = array_values(array_filter([$brand, $model, $year], static function (string $value): bool {
    return $value !== '';
  }));
  if ($parts) {
    $categoryInfo = implode(' | ', $parts);
    if ($modelCode !== '') {
      $categoryInfo .= ' | ' . $modelCode;
    }
  }
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

$oldJson = (string) ($old['options_json'] ?? '{}');
$data = json_decode($oldJson !== '' ? $oldJson : '{}', true);
if (!is_array($data)) {
  $data = [];
}

$oldData = $data;

$setAliases = static function (array &$target, array $keys, string $value): void {
  foreach ($keys as $key) {
    if ($value === '') {
      unset($target[$key]);
    } else {
      $target[$key] = $value;
    }
  }
};

$setAliases($data, ['category_info', 'Category Info'], $categoryInfo);
$setAliases($data, ['category_brand', 'brand'], $brand);
$setAliases($data, ['category_model', 'model'], $model);
$setAliases($data, ['category_year_range', 'year'], $year);
$setAliases($data, ['category_modelcode', 'modelcode', 'model_code', 'design_code'], $modelCode);

$newJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($newJson === false) {
  out(['ok' => false, 'error' => 'Could not encode options JSON']);
}

$oldNormalizedJson = json_encode($oldData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($oldNormalizedJson === $newJson) {
  out(['ok' => true, 'unchanged' => true, 'order_id' => (int) $old['order_id'], 'category_info' => $categoryInfo]);
}

$stmt = $conn->prepare("
  UPDATE order_items
  SET options_json = ?,
      updated_by = ?,
      updated_at = NOW()
  WHERE id = ?
");
$stmt->bind_param('sii', $newJson, $userId, $itemId);
$stmt->execute();
$stmt->close();

log_order_activity(
  $conn,
  (int) $old['order_id'],
  $userId,
  'item_category_info_updated',
  'order_item',
  $itemId,
  [
    'old' => [
      'category_info' => $oldData['category_info'] ?? ($oldData['Category Info'] ?? null),
      'category_brand' => $oldData['category_brand'] ?? ($oldData['brand'] ?? null),
      'category_model' => $oldData['category_model'] ?? ($oldData['model'] ?? null),
      'category_year_range' => $oldData['category_year_range'] ?? ($oldData['year'] ?? null),
      'category_modelcode' => $oldData['category_modelcode'] ?? ($oldData['modelcode'] ?? ($oldData['model_code'] ?? null)),
    ],
    'new' => [
      'category_info' => $categoryInfo,
      'category_brand' => $brand,
      'category_model' => $model,
      'category_year_range' => $year,
      'category_modelcode' => $modelCode,
    ],
  ],
  'Category Info updated'
);

out([
  'ok' => true,
  'order_id' => (int) $old['order_id'],
  'item_id' => $itemId,
  'category_info' => $categoryInfo,
]);
