<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order delete request.');
  customOrdersRedirect();
}

$order = customOrdersGetOrder($conn, $orderId);
if (!$order) {
  customOrdersFlash('danger', 'Custom order not found.');
  customOrdersRedirect();
}
if ((int) ($order['production_order_id'] ?? 0) > 0) {
  customOrdersFlash('danger', 'Exported custom orders cannot be deleted.');
  customOrdersRedirect($orderId);
}

$displayNumber = trim((string) ($order['official_order_number'] ?: $order['internal_code']));

$conn->begin_transaction();
try {
  $deleteByOrderId = static function (mysqli $conn, string $table, int $orderId): void {
    if (!customOrdersTableExists($conn, $table)) {
      return;
    }
    $stmt = $conn->prepare('DELETE FROM `' . $table . '` WHERE custom_order_id = ?');
    if (!$stmt) {
      throw new RuntimeException('Could not prepare delete for ' . $table . '.');
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $stmt->close();
  };

  $deleteByOrderId($conn, 'custom_order_note_revisions', $orderId);
  $deleteByOrderId($conn, 'custom_order_notes', $orderId);
  $deleteByOrderId($conn, 'custom_order_photos', $orderId);
  $deleteByOrderId($conn, 'custom_order_followups', $orderId);
  $deleteByOrderId($conn, 'custom_order_payments', $orderId);
  $deleteByOrderId($conn, 'custom_order_assignments', $orderId);
  $deleteByOrderId($conn, 'custom_order_item_assignments', $orderId);
  $deleteByOrderId($conn, 'custom_order_items', $orderId);
  $deleteByOrderId($conn, 'custom_order_activity', $orderId);

  $stmt = $conn->prepare('DELETE FROM custom_orders WHERE id = ? AND COALESCE(production_order_id, 0) = 0 LIMIT 1');
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $deleted = $stmt->affected_rows > 0;
  $stmt->close();

  if (!$deleted) {
    throw new RuntimeException('Custom order could not be deleted. It may already be exported.');
  }

  $conn->commit();
  customOrdersFlash('success', 'Custom order ' . $displayNumber . ' deleted.');
} catch (Throwable $e) {
  $conn->rollback();
  customOrdersFlash('danger', $e->getMessage());
  customOrdersRedirect($orderId);
}

customOrdersRedirect();
