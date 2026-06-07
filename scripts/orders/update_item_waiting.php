<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';

if (!isset($_SESSION['permission'])) {
  echo json_encode(['ok' => false, 'error' => 'Not logged in']);
  exit;
}

$itemId = (int) ($_POST['item_id'] ?? 0);
$note = trim((string) ($_POST['note'] ?? ''));
$expectedDate = trim((string) ($_POST['expected_date'] ?? ''));

if ($itemId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'Invalid item_id']);
  exit;
}

$expectedDateForDb = ($expectedDate !== '') ? $expectedDate : null;

$stmt = $conn->prepare("
  UPDATE order_items
  SET
    waiting_note = ?,
    expected_date = ?,
    updated_at = NOW(),
    updated_by = ?
  WHERE id = ?
    AND deleted_at IS NULL
  LIMIT 1
");

$userId = (int) ($_SESSION['user_id'] ?? 0);

if (!$stmt) {
  echo json_encode(['ok' => false, 'error' => $conn->error]);
  exit;
}

$stmt->bind_param('ssii', $note, $expectedDateForDb, $userId, $itemId);

if (!$stmt->execute()) {
  echo json_encode(['ok' => false, 'error' => $stmt->error]);
  exit;
}

echo json_encode([
  'ok' => true,
  'item_id' => $itemId,
  'note' => $note,
  'expected_date' => $expectedDateForDb,
  'affected_rows' => $stmt->affected_rows
]);
exit;