<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

if (!isset($_SESSION['permission']) || (int) $_SESSION['permission'] < 300) {
  out(403, ['ok' => false, 'error' => 'Forbidden']);
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$photoId = (int) ($_POST['photo_id'] ?? 0);
if ($orderId <= 0 || $photoId <= 0) {
  out(400, ['ok' => false, 'error' => 'Invalid request']);
}

$base = dirname(__DIR__, 2);
$connFile = $base . '/includes/conn.php';
if (!is_file($connFile)) {
  out(500, ['ok' => false, 'error' => 'conn.php not found']);
}
require_once $connFile;

$deletedBy = (int) ($_SESSION['user_id'] ?? 0);
$stmt = $conn->prepare('
  UPDATE order_photos
  SET deleted_at = NOW(), deleted_by = ?
  WHERE id = ? AND order_id = ? AND deleted_at IS NULL
  LIMIT 1
');
if (!$stmt) {
  out(500, ['ok' => false, 'error' => 'SQL prepare failed: ' . mysqli_error($conn)]);
}
$stmt->bind_param('iii', $deletedBy, $photoId, $orderId);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

out(200, ['ok' => true, 'deleted' => $affected > 0]);
