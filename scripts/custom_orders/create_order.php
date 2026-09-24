<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$recentCreate = $_SESSION['custom_orders_recent_create'] ?? null;
if (is_array($recentCreate)) {
  $recentOrderId = (int) ($recentCreate['order_id'] ?? 0);
  $recentCreatedAt = (int) ($recentCreate['created_at'] ?? 0);
  if ($recentOrderId > 0 && $recentCreatedAt > 0 && time() - $recentCreatedAt <= 8 && customOrdersGetOrder($conn, $recentOrderId)) {
    customOrdersFlash('warning', 'A new custom lead was already created. Opening it instead.');
    customOrdersRedirect($recentOrderId, 0, ['detail' => '1'], false);
  }
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$orderId = customOrdersCreateSkeleton($conn, $userId);
$_SESSION['custom_orders_recent_create'] = [
  'order_id' => $orderId,
  'created_at' => time(),
];
customOrdersFlash('success', 'Custom lead created. Opening it now.');
customOrdersRedirect($orderId, 0, ['detail' => '1'], false);
