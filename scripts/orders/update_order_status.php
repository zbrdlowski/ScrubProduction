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

if (!isset($_SESSION['permission'])) {
  out(['ok' => false, 'error' => 'Not logged in']);
}

$orderId = (int)($_POST['order_id'] ?? 0);
$newStatus = strtoupper(trim((string)($_POST['status'] ?? '')));
$userId = (int)($_SESSION['user_id'] ?? 0);

// toto treba upraviť ak budeme pridávať nové statusy, ale zatiaľ nechcem aby sa to dalo nastaviť na čokoľvek
$allowed = [
  'NEW',
  'IN_PROGRESS',
  'NEED_INFO',
  'DRAFT_REQUESTED',
  'DRAFT_READY',
  'RIPPED',
  'PRINT_QUEUE',
  'PRODUCTION',
  'READY_TO_SHIP',
  'SHIPPED',
  'HOLD',
  'CANCELLED',
];

if ($orderId <= 0 || !in_array($newStatus, $allowed, true)) {
  out(['ok' => false, 'error' => 'Invalid status']);
}

$stmt = $conn->prepare("SELECT status
  FROM orders
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  out(['ok' => false, 'error' => 'Order not found']);
}

$oldStatus = strtoupper((string)($row['status'] ?? ''));

if ($oldStatus === $newStatus) {
  out(['ok' => true, 'unchanged' => true]);
}

$stmt = $conn->prepare("UPDATE orders
  SET status = ?
  WHERE id = ?
  LIMIT 1
");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('si', $newStatus, $orderId);
$stmt->execute();
$stmt->close();

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'status_changed',
  'order',
  $orderId,
  [
    'old' => $oldStatus,
    'new' => $newStatus
  ],
  'Status changed: ' . str_replace('_', ' ', $oldStatus) . ' → ' . str_replace('_', ' ', $newStatus)
);

out(['ok' => true]);