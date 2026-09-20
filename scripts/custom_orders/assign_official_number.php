<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$prefix = trim((string) ($_POST['official_prefix'] ?? 'SO'));
$requestedSequenceRaw = trim((string) ($_POST['official_sequence_value'] ?? ''));
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order.');
  customOrdersRedirect();
}

try {
  if ($requestedSequenceRaw === '' || !ctype_digit($requestedSequenceRaw) || (int) $requestedSequenceRaw <= 0 || (int) $requestedSequenceRaw > 2147483647) {
    throw new RuntimeException('Enter a whole official number between 1 and 2147483647.');
  }
  $requestedSequenceValue = (int) $requestedSequenceRaw;
  $order = customOrdersGetOrder($conn, $orderId);
  if ($order && (int) ($order['production_order_id'] ?? 0) > 0) {
    throw new RuntimeException('Official number cannot be changed after export to Production.');
  }
  $number = customOrdersAssignOfficialNumber($conn, $orderId, $prefix, $userId, $requestedSequenceValue);
  customOrdersFlash('success', 'Official number assigned: ' . $number);
} catch (Throwable $e) {
  customOrdersFlash('danger', $e->getMessage());
}

customOrdersRedirect($orderId);
