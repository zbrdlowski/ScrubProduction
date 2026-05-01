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
if ($perm < 400) {
  out(['ok' => false, 'error' => 'No permission']);
}

$orderId = (int)($_POST['order_id'] ?? 0);
$note = trim((string)($_POST['production_note'] ?? ''));

if ($orderId <= 0) {
  out(['ok' => false, 'error' => 'Invalid order_id']);
}

$userId = (int)($_SESSION['user_id'] ?? 0);

$stmt = $conn->prepare("
  SELECT production_note
  FROM orders
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$old = $stmt->get_result()->fetch_assoc();
$stmt->close();

$oldNote = (string)($old['production_note'] ?? '');

$stmt = $conn->prepare("UPDATE orders
  SET production_note = ?,
      production_note_updated_by = ?,
      production_note_updated_at = NOW()
  WHERE id = ?
  LIMIT 1
");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('sii', $note, $userId, $orderId);
$stmt->execute();
$stmt->close();

out(['ok' => true]);

require_once __DIR__ . '/activity_helper.php';

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'production_note_updated',
  'order',
  $orderId,
  [
    'old' => $oldNote,
    'new' => $note
  ],
  'Production note updated'
);