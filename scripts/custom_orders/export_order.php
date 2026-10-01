<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order.');
  customOrdersRedirect();
}

$orderNumber = '';
$orderNumberStmt = $conn->prepare('SELECT official_order_number FROM custom_orders WHERE id = ? LIMIT 1');
if ($orderNumberStmt) {
  $orderNumberStmt->bind_param('i', $orderId);
  $orderNumberStmt->execute();
  $orderNumberRow = $orderNumberStmt->get_result()->fetch_assoc() ?: [];
  $orderNumberStmt->close();
  $orderNumber = trim((string) ($orderNumberRow['official_order_number'] ?? ''));
}

try {
  $productionOrderId = customOrdersExportToProduction($conn, $orderId, $userId);
  $params = ['page' => 'orders'];
  if ($orderNumber !== '') {
    $params['q'] = $orderNumber;
  }

  header('Location: ../../index.php?' . http_build_query($params) . '#order-' . (int) $productionOrderId);
  exit;
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
