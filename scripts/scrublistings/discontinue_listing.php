<?php
// scripts/scrublistings/discontinue_listing.php
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
$reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : null;

if ($listingId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing listing_id']);
  exit;
}

if ($reason !== null && mb_strlen($reason) > 255) {
  $reason = mb_substr($reason, 0, 255);
}

$conn->set_charset('utf8mb4');

// 1) Soft discontinue (ak existujú stĺpce)
$sqlSoft = "UPDATE scrub_listings
            SET is_active = 0,
                discontinued_at = NOW(),
                discontinued_reason = ?
            WHERE id = ?";

$stmt = $conn->prepare($sqlSoft);

if ($stmt) {
  $stmt->bind_param("si", $reason, $listingId);
  $ok = $stmt->execute();
  $errNo = $stmt->errno;
  $err = $stmt->error;
  $stmt->close();

  if ($ok) {
    echo json_encode(['ok' => true, 'message' => 'Listing discontinued (soft)', 'listing_id' => $listingId]);
    exit;
  }

  // Unknown column => soft schema nie je nasadená => fallback hard delete
  // MySQL errno 1054 = Unknown column
  if ($errNo !== 1054) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error (soft discontinue failed)', 'detail' => $err]);
    exit;
  }
}

// 2) Hard delete fallback
$sqlDelete = "DELETE FROM scrub_listings WHERE id = ?";
$stmt2 = $conn->prepare($sqlDelete);

if (!$stmt2) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB prepare failed', 'detail' => $conn->error]);
  exit;
}

$stmt2->bind_param("i", $listingId);

if (!$stmt2->execute()) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB execute failed', 'detail' => $stmt2->error]);
  $stmt2->close();
  exit;
}

$stmt2->close();

echo json_encode(['ok' => true, 'message' => 'Listing deleted (hard)', 'listing_id' => $listingId]);
?>