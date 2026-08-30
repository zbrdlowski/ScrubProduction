<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
/** @var mysqli $conn */
require_once dirname(__DIR__, 2) . '/includes/orders_status_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/orders_workflow_helpers.php';
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

$allowed = ordersGetOrderStatusCodes($conn, true);

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
$resumeWorkflowAfterPayment = $oldStatus === 'PENDING'
  && in_array($newStatus, ['NEW', 'PLASTICS_IN_STOCK'], true);

if ($oldStatus === $newStatus) {
  out(['ok' => true, 'unchanged' => true]);
}

$stmt = $resumeWorkflowAfterPayment
  ? $conn->prepare("UPDATE orders
      SET status = ?,
          status_override = 0,
          status_override_by = NULL,
          status_override_at = NULL,
          status_override_note = NULL
      WHERE id = ?
      LIMIT 1
    ")
  : $conn->prepare("UPDATE orders
      SET status = ?,
          status_override = 1,
          status_override_by = ?,
          status_override_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

if ($resumeWorkflowAfterPayment) {
  $stmt->bind_param('si', $newStatus, $orderId);
} else {
  $stmt->bind_param('sii', $newStatus, $userId, $orderId);
}
$stmt->execute();
$stmt->close();

if ($resumeWorkflowAfterPayment) {
  recalculateOrderWorkflow($conn, $orderId);
  $newStatus = ordersGetCurrentOrderStatus($conn, $orderId);
}

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

// Button HTML pre JS in-place update status bunky v riadku tabuľky (bez reloadu)
out([
  'ok'          => true,
  'order_id'    => $orderId,
  'order_status'=> $newStatus,
  'status_html' => ordersRenderStatusChip($conn, $newStatus, 'xs'),
]);
