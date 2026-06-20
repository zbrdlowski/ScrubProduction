<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$ownerEmployeeId = (int) ($_POST['owner_employee_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($orderId <= 0 || $ownerEmployeeId <= 0) {
  customOrdersFlash('danger', 'Invalid owner assignment.');
  customOrdersRedirect($orderId);
}

try {
  customOrdersAssignOwner($conn, $orderId, $ownerEmployeeId, $userId);
  customOrdersFlash('success', 'Custom order owner updated.');
} catch (Throwable $e) {
  customOrdersFlash('danger', $e->getMessage());
}

customOrdersRedirect($orderId);
