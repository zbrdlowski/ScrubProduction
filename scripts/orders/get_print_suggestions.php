<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload): void
{
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

if (!isset($_SESSION['permission'])) {
  out(403, ['ok' => false, 'error' => 'Not logged in']);
}

$base = dirname(__DIR__, 2);
require_once $base . '/includes/conn.php';

$keyMap = [
  'printer'  => '_printer',
  'material' => '_print_material',
  'finish'   => '_print_finish',
];

$key = trim((string)($_POST['key'] ?? ''));
$q   = trim((string)($_POST['q']   ?? ''));

if (!isset($keyMap[$key])) {
  out(400, ['ok' => false, 'error' => 'Invalid key']);
}

$jsonKey = $keyMap[$key];
$qLike   = '%' . $q . '%';

// Vytiahne hodnoty z internal_options_json, kde kľúč existuje a zodpovedá dotazu
$stmt = $conn->prepare("
  SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(internal_options_json, ?)) AS val
  FROM order_items
  WHERE deleted_at IS NULL
    AND item_type_code = 'G'
    AND JSON_UNQUOTE(JSON_EXTRACT(internal_options_json, ?)) IS NOT NULL
    AND JSON_UNQUOTE(JSON_EXTRACT(internal_options_json, ?)) != ''
    AND JSON_UNQUOTE(JSON_EXTRACT(internal_options_json, ?)) LIKE ?
  ORDER BY val ASC
  LIMIT 20
");

$jsonPath = '$.' . $jsonKey;
$stmt->bind_param('sssss', $jsonPath, $jsonPath, $jsonPath, $jsonPath, $qLike);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
  $val = trim((string)($row['val'] ?? ''));
  if ($val !== '') {
    $items[] = $val;
  }
}
$stmt->close();

// Pre návrhy materiálu a finišu dotiahne aj base-material / graphics-finish z options_json
if ($key === 'material') {
  $extKey = 'base-material';
  $stmt2 = $conn->prepare("
    SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(options_json, ?)) AS val
    FROM order_items
    WHERE deleted_at IS NULL
      AND item_type_code = 'G'
      AND JSON_UNQUOTE(JSON_EXTRACT(options_json, ?)) IS NOT NULL
      AND JSON_UNQUOTE(JSON_EXTRACT(options_json, ?)) != ''
      AND JSON_UNQUOTE(JSON_EXTRACT(options_json, ?)) LIKE ?
    LIMIT 20
  ");
  $extPath = '$."' . $extKey . '"';
  $stmt2->bind_param('sssss', $extPath, $extPath, $extPath, $extPath, $qLike);
  $stmt2->execute();
  $res2 = $stmt2->get_result();
  while ($row = $res2->fetch_assoc()) {
    $val = trim((string)($row['val'] ?? ''));
    if ($val !== '' && !in_array($val, $items, true)) {
      $items[] = $val;
    }
  }
  $stmt2->close();
}

if ($key === 'finish') {
  $extKey = 'graphics-finish';
  $stmt3 = $conn->prepare("
    SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(options_json, ?)) AS val
    FROM order_items
    WHERE deleted_at IS NULL
      AND item_type_code = 'G'
      AND JSON_UNQUOTE(JSON_EXTRACT(options_json, ?)) IS NOT NULL
      AND JSON_UNQUOTE(JSON_EXTRACT(options_json, ?)) != ''
      AND JSON_UNQUOTE(JSON_EXTRACT(options_json, ?)) LIKE ?
    LIMIT 20
  ");
  $extPath = '$."' . $extKey . '"';
  $stmt3->bind_param('sssss', $extPath, $extPath, $extPath, $extPath, $qLike);
  $stmt3->execute();
  $res3 = $stmt3->get_result();
  while ($row = $res3->fetch_assoc()) {
    $val = trim((string)($row['val'] ?? ''));
    if ($val !== '' && !in_array($val, $items, true)) {
      $items[] = $val;
    }
  }
  $stmt3->close();
}

sort($items);

out(200, ['ok' => true, 'items' => array_values($items)]);
