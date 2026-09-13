<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
/** @var mysqli $conn */
require_once dirname(__DIR__, 2) . '/includes/orders_status_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/orders_workflow_helpers.php';
require_once __DIR__ . '/activity_helper.php';

function out(array $payload): void
{
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if ((int) ($_SESSION['permission'] ?? 0) < 400) {
  http_response_code(403);
  out(['ok' => false, 'error' => 'Permission level 400 or higher is required to resume automatic workflow']);
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($orderId <= 0) {
  out(['ok' => false, 'error' => 'Invalid order ID']);
}

try {
  $conn->begin_transaction();

  $stmt = $conn->prepare("
    SELECT status, status_override
    FROM orders
    WHERE id = ?
    LIMIT 1
    FOR UPDATE
  ");
  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $order = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$order) {
    throw new RuntimeException('Order not found');
  }

  $oldStatus = strtoupper(trim((string) ($order['status'] ?? '')));
  if ($oldStatus === 'PENDING') {
    throw new RuntimeException('Use Payment confirmed to release a PENDING order');
  }
  if (in_array($oldStatus, ['SHIPPED', 'CANCELLED', 'DELIVERED'], true)) {
    throw new RuntimeException('Automatic workflow cannot be resumed for a final order');
  }

  if ((int) ($order['status_override'] ?? 0) !== 1) {
    $conn->commit();
    out(['ok' => true, 'unchanged' => true, 'order_status' => $oldStatus]);
  }

  $stmt = $conn->prepare("
    UPDATE orders
    SET status_override = 0,
        status_override_by = NULL,
        status_override_at = NULL,
        status_override_note = NULL
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $stmt->close();

  recalculateOrderWorkflow($conn, $orderId);
  $newStatus = ordersGetCurrentOrderStatus($conn, $orderId);

  log_order_activity(
    $conn,
    $orderId,
    $userId,
    'status_override_cleared',
    'order',
    $orderId,
    [
      'old_status' => $oldStatus,
      'new_status' => $newStatus,
      'automatic_workflow' => true,
    ],
    'Manual status cleared; automatic workflow resumed as ' . str_replace('_', ' ', $newStatus)
  );

  $conn->commit();

  out([
    'ok' => true,
    'order_id' => $orderId,
    'order_status' => $newStatus,
    'status_html' => ordersRenderStatusChip($conn, $newStatus, 'xs'),
  ]);
} catch (Throwable $e) {
  $conn->rollback();
  out(['ok' => false, 'error' => $e->getMessage()]);
}
