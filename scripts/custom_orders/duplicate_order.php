<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order.');
  customOrdersRedirect();
}

try {
  $duplicateOrderId = customOrdersDuplicateOrder($conn, $orderId, $userId);
  $duplicate = customOrdersGetOrder($conn, $duplicateOrderId);
  $duplicateNumber = trim((string) ($duplicate['official_order_number'] ?? ''));
  if ($duplicateNumber === '') {
    $duplicateNumber = (string) ($duplicate['internal_code'] ?? ('#' . $duplicateOrderId));
  }
  customOrdersFlash('success', 'Duplicate order created: ' . $duplicateNumber . '.');
  customOrdersRedirect($duplicateOrderId);
} catch (Throwable $e) {
  customOrdersFlash('danger', $e->getMessage());
  customOrdersRedirect($orderId);
}
