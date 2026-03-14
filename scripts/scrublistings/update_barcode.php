<?php
// scripts/scrublistings/update_barcode.php
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
$oldBarcode = isset($_POST['old_barcode']) ? strtoupper(trim((string)$_POST['old_barcode'])) : '';
$newBarcode = isset($_POST['new_barcode']) ? strtoupper(trim((string)$_POST['new_barcode'])) : '';

if ($listingId <= 0 || $oldBarcode === '' || $newBarcode === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing listing_id / old_barcode / new_barcode']);
  exit;
}

if (mb_strlen($oldBarcode) > 64) $oldBarcode = mb_substr($oldBarcode, 0, 64);
if (mb_strlen($newBarcode) > 64) $newBarcode = mb_substr($newBarcode, 0, 64);

if (!preg_match('/^[A-Z0-9_\-]+$/', $oldBarcode) || !preg_match('/^[A-Z0-9_\-]+$/', $newBarcode)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid barcode format']);
  exit;
}

if ($oldBarcode === $newBarcode) {
  echo json_encode(['ok' => true, 'message' => 'No change', 'listing_id' => $listingId]);
  exit;
}

$conn->set_charset('utf8mb4');

// check existence of new barcode in this listing
$check = $conn->prepare("SELECT 1 FROM scrub_listing_items WHERE listing_id = ? AND barcode = ? LIMIT 1");
if (!$check) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB prepare failed', 'detail' => $conn->error]);
  exit;
}
$check->bind_param("is", $listingId, $newBarcode);
$check->execute();
$res = $check->get_result();
$exists = ($res && $res->num_rows > 0);
$check->close();

if ($exists) {
  http_response_code(409);
  echo json_encode(['ok' => false, 'error' => 'New barcode already exists in this listing']);
  exit;
}

$stmt = $conn->prepare("UPDATE scrub_listing_items SET barcode = ? WHERE listing_id = ? AND barcode = ?");
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB prepare failed', 'detail' => $conn->error]);
  exit;
}

$stmt->bind_param("sis", $newBarcode, $listingId, $oldBarcode);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB execute failed', 'detail' => $stmt->error]);
  $stmt->close();
  exit;
}

$affected = $stmt->affected_rows;
$stmt->close();

echo json_encode([
  'ok' => true,
  'message' => ($affected > 0 ? 'Barcode updated' : 'Old barcode not found'),
  'listing_id' => $listingId,
  'old_barcode' => $oldBarcode,
  'new_barcode' => $newBarcode
]);
?>