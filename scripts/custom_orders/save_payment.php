<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order.');
  customOrdersRedirect();
}

$kind = strtoupper(trim((string) ($_POST['payment_kind'] ?? 'DEPOSIT')));
if (!isset(customOrdersPaymentKinds()[$kind])) {
  $kind = 'DEPOSIT';
}

$paypalId = trim((string) ($_POST['paypal_transaction_id'] ?? ''));
$amount = (float) ($_POST['amount'] ?? 0);
$currency = trim((string) ($_POST['currency'] ?? 'EUR'));
$receivedAt = trim((string) ($_POST['received_at'] ?? '')) ?: null;
$note = trim((string) ($_POST['note'] ?? ''));

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

customOrdersLog($conn, $orderId, 'payment_added', $userId, ['payment_id' => $paymentId, 'kind' => $kind, 'amount' => $amount], 'Payment added');
customOrdersFlash('success', 'Payment added.');
customOrdersRedirect($orderId);
