<?php
// scripts/scrublistings/add_barcode.php
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

// základná validácia (povolíme A-Z0-9 _ -)
if (!preg_match('/^[A-Z0-9_\-]+$/', $barcode)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid barcode format']);
  exit;
}

$conn->set_charset('utf8mb4');

// Next sort_order
$nextSort = 1;
$stmtSort = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort
                            FROM scrub_listing_items
                            WHERE listing_id = ?");
if ($stmtSort) {
  $stmtSort->bind_param("i", $listingId);
  if ($stmtSort->execute()) {
    $res = $stmtSort->get_result();
    if ($row = $res->fetch_assoc()) {
      $nextSort = (int)$row['next_sort'];
      if ($nextSort <= 0) $nextSort = 1;
    }
  }
  $stmtSort->close();
}

$sql = "INSERT INTO scrub_listing_items (listing_id, barcode, sort_order)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE sort_order = sort_order"; // nič nemení, len zabráni duplicate erroru

$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB prepare failed', 'detail' => $conn->error]);
  exit;
}

$stmt->bind_param("isi", $listingId, $barcode, $nextSort);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB execute failed', 'detail' => $stmt->error]);
  $stmt->close();
  exit;
}

$stmt->close();

echo json_encode([
  'ok' => true,
  'message' => 'Barcode added',
  'listing_id' => $listingId,
  'barcode' => $barcode,
  'sort_order' => $nextSort
]);
?>