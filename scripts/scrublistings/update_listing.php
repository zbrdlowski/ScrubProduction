<?php
// scripts/scrublistings/update_listing.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../includes/conn.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

session_start();

if (!isset($_SESSION['permission']) || (int)$_SESSION['permission'] < 300) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden']);
  exit;
}

$listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
$listingName = isset($_POST['listing_name']) ? trim((string)$_POST['listing_name']) : '';

if ($listingId <= 0 || $listingName === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing listing_id or listing_name']);
  exit;
}

if (mb_strlen($listingName) > 255) {
  $listingName = mb_substr($listingName, 0, 255);
}

// Voliteľne: ak chceš editovať aj price/model_code, môžeš poslať v POST
$price = isset($_POST['price']) && $_POST['price'] !== '' ? (float)$_POST['price'] : null;
$modelCode = isset($_POST['model_code']) ? trim((string)$_POST['model_code']) : null;
if ($modelCode !== null && $modelCode !== '' && mb_strlen($modelCode) > 32) {
  $modelCode = mb_substr($modelCode, 0, 32);
}
if ($modelCode !== null && $modelCode === '') {
  $modelCode = null;
}

$conn->set_charset('utf8mb4');

// Dynamicky poskladáme UPDATE podľa toho, čo prišlo
$fields = ['listing_name = ?'];
$params = [$listingName];
$types = 's';

if ($modelCode !== null) {
  $fields[] = 'model_code = ?';
  $params[] = $modelCode;
  $types .= 's';
}
if ($price !== null) {
  $fields[] = 'price = ?';
  $params[] = $price;
  $types .= 'd';
}

$fieldsSql = implode(', ', $fields);
$sql = "UPDATE scrub_listings SET {$fieldsSql} WHERE id = ?";

$params[] = $listingId;
$types .= 'i';

$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB prepare failed', 'detail' => $conn->error]);
  exit;
}

// bind_param potrebuje referencie
$bind = [];
$bind[] = $types;
for ($i = 0; $i < count($params); $i++) {
  $bind[] = &$params[$i];
}
call_user_func_array([$stmt, 'bind_param'], $bind);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB execute failed', 'detail' => $stmt->error]);
  $stmt->close();
  exit;
}

$stmt->close();

echo json_encode([
  'ok' => true,
  'message' => 'Listing updated',
  'listing_id' => $listingId
]);
?>