<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

function out(array $payload): void {
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if ((int)($_SESSION['permission'] ?? 0) < 400) {
  out(['ok' => false, 'error' => 'No permission']);
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  out(['ok' => false, 'error' => 'Missing tracking id']);
}

$stmt = $conn->prepare("
  SELECT order_id, tracking_number, carrier
  FROM order_tracking_numbers
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$tracking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tracking) {
  out(['ok' => false, 'error' => 'Tracking not found']);
}

$stmt = $conn->prepare("UPDATE order_tracking_numbers
  SET deleted_at = NOW()
  WHERE id = ?
  LIMIT 1
");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();

//activity log
$userId = (int)($_SESSION['user_id'] ?? 0);

log_order_activity(
  $conn,
  (int)$tracking['order_id'],
  $userId,
  'tracking_deleted',
  'tracking',
  $id,
  [
    'tracking_number' => $tracking['tracking_number'],
    'carrier' => $tracking['carrier']
  ],
  'Tracking deleted'
);
out(['ok' => true]);


