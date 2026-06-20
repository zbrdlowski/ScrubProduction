<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$paymentId = (int) ($_POST['payment_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0 || $paymentId <= 0) {
  customOrdersFlash('danger', 'Invalid payment delete request.');
  customOrdersRedirect($orderId);
}

$stmt = $conn->prepare('DELETE FROM custom_order_payments WHERE id = ? AND custom_order_id = ?');
$stmt->bind_param('ii', $paymentId, $orderId);
$stmt->execute();
$stmt->close();

customOrdersLog($conn, $orderId, 'payment_deleted', $userId, ['payment_id' => $paymentId], 'Payment deleted');
customOrdersFlash('success', 'Payment deleted.');
customOrdersRedirect($orderId);
