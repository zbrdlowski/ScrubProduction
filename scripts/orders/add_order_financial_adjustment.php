<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';
require_once __DIR__ . '/financial_helpers.php';

function out(array $payload): void
{
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if ((int) ($_SESSION['permission'] ?? 0) < 400) {
  http_response_code(403);
  out(['ok' => false, 'error' => 'No permission']);
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$type = strtoupper(trim((string) ($_POST['type'] ?? 'PAYMENT')));
$reference = trim((string) ($_POST['reference'] ?? ''));
$purpose = trim((string) ($_POST['purpose'] ?? ''));
$amountRaw = trim((string) ($_POST['amount'] ?? ''));

if ($orderId <= 0) {
  out(['ok' => false, 'error' => 'Invalid order ID']);
}

if (!in_array($type, ['PAYMENT', 'REFUND'], true)) {
  out(['ok' => false, 'error' => 'Invalid movement type']);
}

if ($reference === '') {
  out(['ok' => false, 'error' => 'Payment reference is required']);
}

if ($purpose === '') {
  out(['ok' => false, 'error' => 'Purpose is required']);
}

$normalized = str_replace(',', '.', str_replace(["\u{00A0}", ' '], '', $amountRaw));
if ($normalized === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
  out(['ok' => false, 'error' => 'Enter a valid amount with max 2 decimal places']);
}

$amount = (float) $normalized;
if ($amount <= 0 || $amount > 9999999999.99) {
  out(['ok' => false, 'error' => 'Amount must be greater than zero']);
}

if ($type === 'REFUND') {
  $amount *= -1;
}

$reference = mb_substr($reference, 0, 120, 'UTF-8');
$purpose = mb_substr($purpose, 0, 190, 'UTF-8');
$currency = 'EUR';

try {
  order_financial_require_schema($conn);

  $stmt = $conn->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1');
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

  $stmt = $conn->prepare("
    INSERT INTO order_financial_adjustments
      (order_id, type, reference, purpose, amount, currency, created_by)
    VALUES
      (?, ?, ?, ?, ?, ?, ?)
  ");
  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }
  $stmt->bind_param('isssdsi', $orderId, $type, $reference, $purpose, $amount, $currency, $userId);
  $stmt->execute();
  $adjustmentId = (int) $conn->insert_id;
  $stmt->close();

  log_order_activity(
    $conn,
    $orderId,
    $userId,
    'financial_adjustment_added',
    'financial_adjustment',
    $adjustmentId,
    [
      'type' => $type,
      'reference' => $reference,
      'purpose' => $purpose,
      'amount' => $amount,
      'currency' => $currency,
    ],
    ($type === 'REFUND' ? 'Refund' : 'Payment') . ' added: ' . $purpose . ' (' . ($amount > 0 ? '+' : '') . number_format($amount, 2, '.', '') . ' EUR), ref. ' . $reference
  );

  out(['ok' => true, 'order_id' => $orderId, 'id' => $adjustmentId]);
} catch (Throwable $e) {
  out(['ok' => false, 'error' => $e->getMessage()]);
}
