<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$finish = static function (array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
};

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$requestedStatus = strtoupper(trim((string) ($_POST['status'] ?? '')));
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($orderId <= 0) {
  $finish(['ok' => false, 'message' => 'Invalid custom order.'], 422);
}

$statuses = customOrdersOrderStatuses();
if ($requestedStatus === '' || !isset($statuses[$requestedStatus])) {
  $finish(['ok' => false, 'message' => 'Unknown custom order status.'], 422);
}

$existing = customOrdersGetOrder($conn, $orderId);
if (!$existing) {
  $finish(['ok' => false, 'message' => 'Custom order not found.'], 404);
}

$customOrdersCanManage = ((int) ($_SESSION['permission'] ?? 0)) >= 300;
if (!$customOrdersCanManage && !customOrdersCanWorkerSetOrderStatus($requestedStatus)) {
  $finish(['ok' => false, 'message' => 'This status belongs to customer service and cannot be changed here.'], 403);
}

try {
  $autoAssignedOfficialNumber = '';
  if ($requestedStatus === 'DRAFT_X' && trim((string) ($existing['official_order_number'] ?? '')) === '') {
    $autoAssignedOfficialNumber = customOrdersAssignOfficialNumber($conn, $orderId, 'SO', $userId);
  }

  $stmt = $conn->prepare('UPDATE custom_orders SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
  if (!$stmt) {
    throw new RuntimeException('Could not prepare custom order status update.');
  }
  $stmt->bind_param('sii', $requestedStatus, $userId, $orderId);
  $stmt->execute();
  $stmt->close();

  customOrdersLog(
    $conn,
    $orderId,
    'header_updated',
    $userId,
    [
      'status' => $requestedStatus,
      'changes' => customOrdersActivityCollectChanges($existing, ['status' => $requestedStatus], ['status']),
    ],
    'Custom order status updated'
  );

  $finish([
    'ok' => true,
    'status' => $requestedStatus,
    'label' => (string) ($statuses[$requestedStatus] ?? $requestedStatus),
    'internal_code' => (string) ($existing['internal_code'] ?? ''),
    'official_order_number' => $autoAssignedOfficialNumber !== ''
      ? $autoAssignedOfficialNumber
      : (string) ($existing['official_order_number'] ?? ''),
    'message' => $autoAssignedOfficialNumber !== ''
      ? 'Custom order status saved. Official SO assigned: ' . $autoAssignedOfficialNumber . '.'
      : 'Custom order status saved.',
  ]);
} catch (Throwable $e) {
  $finish(['ok' => false, 'message' => $e->getMessage()], 500);
}
