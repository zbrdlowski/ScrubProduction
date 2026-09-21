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
  $parsedOfficialNumber = customOrdersParseOfficialNumberInput($prefix, $requestedSequenceRaw);
  $prefix = (string) $parsedOfficialNumber['prefix'];
  $requestedSequenceValue = (int) $parsedOfficialNumber['sequence_value'];
  $requestedSuffix = (string) $parsedOfficialNumber['suffix'];
  $order = customOrdersGetOrder($conn, $orderId);
  if ($order && (int) ($order['production_order_id'] ?? 0) > 0) {
    throw new RuntimeException('Official number cannot be changed after export to Production.');
  }
  $number = customOrdersAssignOfficialNumber($conn, $orderId, $prefix, $userId, $requestedSequenceValue, $requestedSuffix);
  customOrdersFlash('success', 'Official number assigned: ' . $number);
} catch (Throwable $e) {
  customOrdersFlash('danger', $e->getMessage());
}

customOrdersRedirect($orderId);
