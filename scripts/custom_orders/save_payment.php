<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function customOrdersPaymentWantsJson(): bool
{
  return strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest';
}

function customOrdersPaymentFinish(int $orderId, string $type, string $message, array $payload = []): void
{
  if (customOrdersPaymentWantsJson()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => $type === 'success', 'message' => $message], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  customOrdersFlash($type, $message);
  customOrdersRedirect($orderId);
}

function customOrdersPaymentNormalizeDateTime(?string $value): ?string
{
  $value = trim((string) $value);
  if ($value === '') {
    return null;
  }
  $value = str_replace('T', ' ', $value);
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
    $value .= ':00';
  }
  return $value;
}

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$paymentId = (int) ($_POST['payment_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
  customOrdersPaymentFinish(0, 'danger', 'Invalid custom order.');
}

$kind = strtoupper(trim((string) ($_POST['payment_kind'] ?? 'DEPOSIT')));
if (!isset(customOrdersPaymentKinds()[$kind])) {
  $kind = 'DEPOSIT';
}

$paypalId = trim((string) ($_POST['paypal_transaction_id'] ?? ''));
$amount = (float) ($_POST['amount'] ?? 0);
$currency = strtoupper(trim((string) ($_POST['currency'] ?? 'EUR')));
if ($currency === '') {
  $currency = 'EUR';
}
$receivedAt = customOrdersPaymentNormalizeDateTime($_POST['received_at'] ?? null);
$note = trim((string) ($_POST['note'] ?? ''));

if ($paymentId > 0) {
  $existingPayment = null;
  $stmt = $conn->prepare('SELECT payment_kind, paypal_transaction_id, amount, currency, received_at, note FROM custom_order_payments WHERE id = ? AND custom_order_id = ? LIMIT 1');
  $stmt->bind_param('ii', $paymentId, $orderId);
  $stmt->execute();
  $existingPayment = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();

  if (!$existingPayment) {
    customOrdersPaymentFinish($orderId, 'danger', 'Payment record not found.');
  }

  $stmt = $conn->prepare('
    UPDATE custom_order_payments
    SET payment_kind = ?, paypal_transaction_id = ?, amount = ?, currency = ?, received_at = ?, note = ?
    WHERE id = ? AND custom_order_id = ?
  ');
  $stmt->bind_param('ssdsssii', $kind, $paypalId, $amount, $currency, $receivedAt, $note, $paymentId, $orderId);
  $stmt->execute();
  $stmt->close();

  customOrdersLog(
    $conn,
    $orderId,
    'payment_updated',
    $userId,
    [
      'payment_id' => $paymentId,
      'kind' => $kind,
      'amount' => $amount,
      'currency' => $currency,
      'previous' => $existingPayment,
      'note' => $note,
    ],
    'Payment updated'
  );
  customOrdersPaymentFinish($orderId, 'success', 'Payment updated.', ['payment_id' => $paymentId]);
}

$stmt = $conn->prepare('
  INSERT INTO custom_order_payments
    (custom_order_id, payment_kind, paypal_transaction_id, amount, currency, received_at, note, created_by)
  VALUES
    (?, ?, ?, ?, ?, ?, ?, ?)
');
$stmt->bind_param('issdsssi', $orderId, $kind, $paypalId, $amount, $currency, $receivedAt, $note, $userId);
$stmt->execute();
$paymentId = (int) $stmt->insert_id;
$stmt->close();

customOrdersLog(
  $conn,
  $orderId,
  'payment_added',
  $userId,
  [
    'payment_id' => $paymentId,
    'kind' => $kind,
    'amount' => $amount,
    'currency' => $currency,
    'note' => $note,
  ],
  'Payment added'
);
customOrdersPaymentFinish($orderId, 'success', 'Payment added.', ['payment_id' => $paymentId]);