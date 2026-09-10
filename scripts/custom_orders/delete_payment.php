<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function customOrdersPaymentDeleteWantsJson(): bool
{
  return strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest';
}

function customOrdersPaymentDeleteFinish(int $orderId, string $type, string $message, array $payload = []): void
{
  if (customOrdersPaymentDeleteWantsJson()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => $type === 'success', 'message' => $message], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  customOrdersFlash($type, $message);
  customOrdersRedirect($orderId);
}

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$paymentId = (int) ($_POST['payment_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0 || $paymentId <= 0) {
  customOrdersPaymentDeleteFinish($orderId, 'danger', 'Invalid payment delete request.');
}

$deletedPayment = null;
$stmt = $conn->prepare('SELECT payment_kind, amount, currency, note FROM custom_order_payments WHERE id = ? AND custom_order_id = ? LIMIT 1');
$stmt->bind_param('ii', $paymentId, $orderId);
$stmt->execute();
$deletedPayment = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$deletedPayment) {
  customOrdersPaymentDeleteFinish($orderId, 'danger', 'Payment record not found.');
}

$stmt = $conn->prepare('DELETE FROM custom_order_payments WHERE id = ? AND custom_order_id = ?');
$stmt->bind_param('ii', $paymentId, $orderId);
$stmt->execute();
$stmt->close();

customOrdersLog(
  $conn,
  $orderId,
  'payment_deleted',
  $userId,
  [
    'payment_id' => $paymentId,
    'kind' => (string) ($deletedPayment['payment_kind'] ?? ''),
    'amount' => (float) ($deletedPayment['amount'] ?? 0),
    'currency' => (string) ($deletedPayment['currency'] ?? ''),
    'note' => (string) ($deletedPayment['note'] ?? ''),
  ],
  'Payment deleted'
);
customOrdersPaymentDeleteFinish($orderId, 'success', 'Payment deleted.', ['payment_id' => $paymentId]);