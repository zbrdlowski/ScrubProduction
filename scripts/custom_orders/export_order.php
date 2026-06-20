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
  $productionOrderId = customOrdersExportToProduction($conn, $orderId, $userId);
  customOrdersFlash('success', 'Exported to production order ID ' . $productionOrderId . '.');
} catch (Throwable $e) {
  $message = $e->getMessage();
  $fields = [];
  if (strpos($message, '||FIELDS||') !== false) {
    [$message, $fieldJson] = explode('||FIELDS||', $message, 2);
    $decoded = json_decode($fieldJson, true);
    if (is_array($decoded)) {
      $fields = $decoded;
    }
  }
  customOrdersFlash('danger', trim($message), ['invalid_fields' => $fields]);
}

customOrdersRedirect($orderId);
