<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__,2).'/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';


if ((int)($_SESSION['permission'] ?? 0) < 400) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'No permission']);
  exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$tracking = trim($_POST['tracking_number'] ?? '');
$carrier  = trim($_POST['carrier'] ?? '');

if (!$orderId || $tracking === '') {
  echo json_encode(['ok'=>false,'error'=>'Missing data']);
  exit;
}

$stmt = $conn->prepare("
  INSERT INTO order_tracking_numbers
    (order_id, tracking_number, carrier, created_by)
  VALUES (?, ?, ?, ?)
");

$userId = (int)($_SESSION['user_id'] ?? 0);
$stmt->bind_param('issi', $orderId, $tracking, $carrier, $userId);
$stmt->execute();

echo json_encode(['ok'=>true]);

//activity log

$trackingId = (int)$conn->insert_id;

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'tracking_added',
  'tracking',
  $trackingId,
  [
    'tracking_number' => $tracking,
    'carrier' => $carrier
  ],
  'Tracking added'
);