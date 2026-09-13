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
  out(['ok' => false, 'error' => 'Permission level 400 or higher is required to confirm payment']);
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$receivedAmountRaw = trim((string) ($_POST['received_amount'] ?? ''));
$receivedAmountNormalized = str_replace(',', '.', str_replace(["\u{00A0}", ' '], '', $receivedAmountRaw));

if ($orderId <= 0) {
  out(['ok' => false, 'error' => 'Invalid order ID']);
}

if ($receivedAmountNormalized === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $receivedAmountNormalized)) {
  out(['ok' => false, 'error' => 'Enter a valid received amount with no more than 2 decimal places']);
}

$receivedAmount = (float) $receivedAmountNormalized;
if ($receivedAmount <= 0 || $receivedAmount > 9999999999.99) {
  out(['ok' => false, 'error' => 'Received amount must be greater than zero']);
}

foreach (['production_started_at', 'payment_received_amount'] as $requiredColumn) {
  $escapedColumn = $conn->real_escape_string($requiredColumn);
  $columnCheck = $conn->query("SHOW COLUMNS FROM orders LIKE '{$escapedColumn}'");
  if (!$columnCheck || $columnCheck->num_rows === 0) {
    out(['ok' => false, 'error' => "Database migration for {$requiredColumn} has not been installed"]);
  }
  $columnCheck->free();
}

try {
  $conn->begin_transaction();

  $stmt = $conn->prepare("
    SELECT status, order_date, production_started_at, total, currency, source_meta
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
  if ($oldStatus !== 'PENDING') {
    throw new RuntimeException('Only a PENDING order can be released after payment');
  }

  $stmt = $conn->prepare("
    UPDATE orders
    SET status = 'NEW',
        production_started_at = NOW(),
        payment_received_amount = ?,
        status_override = 0,
        status_override_by = NULL,
        status_override_at = NULL,
        status_override_note = NULL
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }
  $stmt->bind_param('di', $receivedAmount, $orderId);
  $stmt->execute();
  $stmt->close();

  recalculateOrderWorkflow($conn, $orderId);

  $stmt = $conn->prepare("
    SELECT status, production_started_at, payment_received_amount
    FROM orders
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $updated = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();

  $newStatus = strtoupper(trim((string) ($updated['status'] ?? 'NEW')));
  $productionStartedAt = (string) ($updated['production_started_at'] ?? '');
  $sourceMeta = json_decode((string) ($order['source_meta'] ?? ''), true);
  $expectedRaw = is_array($sourceMeta) ? ($sourceMeta['total_price_with_vat'] ?? null) : null;
  $expectedNormalized = trim(str_replace(["\u{00A0}", ' '], '', (string) $expectedRaw));
  if (substr_count($expectedNormalized, ',') === 1 && substr_count($expectedNormalized, '.') === 0) {
    $expectedNormalized = str_replace(',', '.', $expectedNormalized);
  } elseif (substr_count($expectedNormalized, ',') > 0 && substr_count($expectedNormalized, '.') === 1) {
    $expectedNormalized = str_replace(',', '', $expectedNormalized);
  }
  $expectedAmount = is_numeric($expectedNormalized)
    ? (float) $expectedNormalized
    : (isset($order['total']) ? (float) $order['total'] : null);
  $paymentDifference = $expectedAmount === null ? null : round($receivedAmount - $expectedAmount, 2);

  log_order_activity(
    $conn,
    $orderId,
    $userId,
    'payment_confirmed',
    'order',
    $orderId,
    [
      'old_status' => $oldStatus,
      'new_status' => $newStatus,
      'production_started_at' => $productionStartedAt,
      'expected_amount' => $expectedAmount,
      'received_amount' => $receivedAmount,
      'difference' => $paymentDifference,
      'currency' => (string) ($order['currency'] ?? ''),
      'automatic_workflow' => true,
    ],
    'Payment confirmed; order released to production as ' . str_replace('_', ' ', $newStatus)
  );

  $conn->commit();

  out([
    'ok' => true,
    'order_id' => $orderId,
    'order_status' => $newStatus,
    'production_started_at' => $productionStartedAt,
    'payment_received_amount' => number_format($receivedAmount, 2, '.', ''),
    'payment_difference' => $paymentDifference,
    'status_html' => ordersRenderStatusChip($conn, $newStatus, 'xs'),
  ]);
} catch (Throwable $e) {
  $conn->rollback();
  out(['ok' => false, 'error' => $e->getMessage()]);
}
