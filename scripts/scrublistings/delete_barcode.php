<?php
// scripts/scrublistings/delete_barcode.php
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
$barcode = isset($_POST['barcode']) ? strtoupper(trim((string)$_POST['barcode'])) : '';

if ($listingId <= 0 || $barcode === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing listing_id or barcode']);
  exit;
}

if (mb_strlen($barcode) > 64) {
  $barcode = mb_substr($barcode, 0, 64);
}

$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("DELETE FROM scrub_listing_items WHERE listing_id = ? AND barcode = ?");
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB prepare failed', 'detail' => $conn->error]);
  exit;
}

$stmt->bind_param("is", $listingId, $barcode);

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
  'message' => ($affected > 0 ? 'Barcode deleted' : 'Barcode not found'),
  'listing_id' => $listingId,
  'barcode' => $barcode
]);
?>