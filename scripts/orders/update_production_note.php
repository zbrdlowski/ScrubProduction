<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';

function out(array $payload): void {
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$perm = (int)($_SESSION['permission'] ?? 0);
if ($perm < 0) {
  out(['ok' => false, 'error' => 'No permission']);
}

$orderId = (int)($_POST['order_id'] ?? 0);
$note = trim((string)($_POST['production_note'] ?? ''));

if ($orderId <= 0) {
  out(['ok' => false, 'error' => 'Invalid order_id']);
}

if ($note === '') {
  out(['ok' => false, 'error' => 'Note cannot be empty']);
}

$userId = (int)($_SESSION['user_id'] ?? 0);

// Make sure the order actually exists before we insert a note against it.
$stmt = $conn->prepare("SELECT id FROM orders WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$orderExists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$orderExists) {
  out(['ok' => false, 'error' => 'Order not found']);
}

$stmt = $conn->prepare("
  INSERT INTO order_production_notes (order_id, user_id, note, created_at)
  VALUES (?, ?, ?, NOW())
");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('iis', $orderId, $userId, $note);
$stmt->execute();
$newNoteId = (int) $stmt->insert_id;
$stmt->close();

// Pull the note back with author info so the front-end can append it to the
// thread directly from this response, without a full detail reload.
$stmt = $conn->prepare("
  SELECT
    n.id,
    n.note,
    n.created_at,
    e.firstname,
    e.lastname,
    e.photo
  FROM order_production_notes n
  LEFT JOIN employees e ON e.id = n.user_id
  WHERE n.id = ?
  LIMIT 1
");
$stmt->bind_param('i', $newNoteId);
$stmt->execute();
$newNote = $stmt->get_result()->fetch_assoc();
$stmt->close();

require_once __DIR__ . '/activity_helper.php';

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'production_note_added',
  'order',
  $orderId,
  [
    'note' => $note
  ],
  'Production note added'
);

$authorName = trim((string) ($newNote['firstname'] ?? '') . ' ' . (string) ($newNote['lastname'] ?? ''));

out([
  'ok' => true,
  'note' => [
    'id' => $newNoteId,
    'text' => (string) ($newNote['note'] ?? $note),
    'created_at' => (string) ($newNote['created_at'] ?? ''),
    'author_name' => $authorName,
    'author_photo' => (string) ($newNote['photo'] ?? ''),
  ],
]);