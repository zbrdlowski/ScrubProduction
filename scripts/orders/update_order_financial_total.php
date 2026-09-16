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
$reset = (int) ($_POST['reset'] ?? 0) === 1;
$totalRaw = trim((string) ($_POST['total_value'] ?? ''));

if ($orderId <= 0) {
  out(['ok' => false, 'error' => 'Invalid order ID']);
}

try {
  order_financial_require_schema($conn);

  $conn->begin_transaction();

  $stmt = $conn->prepare("
    SELECT id, order_number, external_order_id, total, currency, source_meta, financial_total_value, financial_total_currency
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

  $oldValue = order_financial_money_value($order['financial_total_value'] ?? null);
  $newValue = null;

  if (!$reset) {
    $normalized = str_replace(',', '.', str_replace(["\u{00A0}", ' '], '', $totalRaw));
    if ($normalized === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
      throw new RuntimeException('Enter a valid total value with max 2 decimal places');
    }

    $newValue = (float) $normalized;
    if ($newValue < 0 || $newValue > 9999999999.99) {
      throw new RuntimeException('Total value is outside allowed range');
    }
  }

  if ($reset) {
    $stmt = $conn->prepare("
      UPDATE orders
      SET financial_total_value = NULL,
          financial_total_currency = 'EUR',
          financial_total_updated_by = ?,
          financial_total_updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    if (!$stmt) {
      throw new RuntimeException($conn->error);
    }
    $stmt->bind_param('ii', $userId, $orderId);
  } else {
    $currency = 'EUR';
    $stmt = $conn->prepare("
      UPDATE orders
      SET financial_total_value = ?,
          financial_total_currency = ?,
          financial_total_updated_by = ?,
          financial_total_updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    if (!$stmt) {
      throw new RuntimeException($conn->error);
    }
    $stmt->bind_param('dsii', $newValue, $currency, $userId, $orderId);
  }

  $stmt->execute();
  $stmt->close();

  log_order_activity(
    $conn,
    $orderId,
    $userId,
    $reset ? 'financial_total_reset' : 'financial_total_updated',
    'order',
    $orderId,
    [
      'old_value' => $oldValue,
      'new_value' => $reset ? null : $newValue,
      'currency' => 'EUR',
    ],
    $reset ? 'Financial total reset' : 'Financial total updated'
  );

  $conn->commit();

  out([
    'ok' => true,
    'order_id' => $orderId,
    'financial_total_value' => $reset ? null : number_format((float) $newValue, 2, '.', ''),
    'currency' => 'EUR',
  ]);
} catch (Throwable $e) {
  if ($conn instanceof mysqli) {
    $conn->rollback();
  }
  out(['ok' => false, 'error' => $e->getMessage()]);
}