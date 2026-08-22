<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$customOrderId = (int) ($_POST['custom_order_id'] ?? 0);
$photoId = (int) ($_POST['photo_id'] ?? 0);
if ($customOrderId <= 0 || $photoId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
  exit;
}
if (!customOrdersTableExists($conn, 'custom_order_photos')) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Custom order photo storage is not available.']);
  exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$photoStmt = $conn->prepare('
  SELECT production_photo_id
  FROM custom_order_photos
  WHERE id = ? AND custom_order_id = ? AND deleted_at IS NULL
  LIMIT 1
');
$photoStmt->bind_param('ii', $photoId, $customOrderId);
$photoStmt->execute();
$photo = $photoStmt->get_result()->fetch_assoc();
$photoStmt->close();
if (!$photo) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Photo not found.']);
  exit;
}

$conn->begin_transaction();
$stmt = $conn->prepare('
  UPDATE custom_order_photos
  SET deleted_at = NOW(), deleted_by = ?
  WHERE id = ? AND custom_order_id = ? AND deleted_at IS NULL
  LIMIT 1
');
$stmt->bind_param('iii', $userId, $photoId, $customOrderId);
$stmt->execute();
$deleted = $stmt->affected_rows > 0;
$stmt->close();

if (!$deleted) {
  $conn->rollback();
  http_response_code(409);
  echo json_encode(['ok' => false, 'error' => 'Photo could not be deleted.']);
  exit;
}
$productionPhotoId = (int) ($photo['production_photo_id'] ?? 0);
if ($productionPhotoId > 0 && customOrdersTableExists($conn, 'order_photos')) {
  $productionStmt = $conn->prepare('
    UPDATE order_photos
    SET deleted_at = NOW(), deleted_by = ?
    WHERE id = ? AND deleted_at IS NULL
    LIMIT 1
  ');
  $productionStmt->bind_param('ii', $userId, $productionPhotoId);
  $productionStmt->execute();
  $productionStmt->close();
}
$conn->commit();
customOrdersLog($conn, $customOrderId, 'photo_deleted', $userId, ['photo_id' => $photoId], 'Custom order photo deleted');
echo json_encode(['ok' => true, 'deleted' => true]);
