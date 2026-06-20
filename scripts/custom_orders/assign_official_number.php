<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$prefix = trim((string) ($_POST['official_prefix'] ?? 'SO'));
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order.');
  customOrdersRedirect();
}

try {
  $number = customOrdersAssignOfficialNumber($conn, $orderId, $prefix, $userId);
  customOrdersFlash('success', 'Official number assigned: ' . $number);
} catch (Throwable $e) {
  customOrdersFlash('danger', $e->getMessage());
}

customOrdersRedirect($orderId);
