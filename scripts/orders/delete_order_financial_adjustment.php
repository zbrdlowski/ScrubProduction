<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';
require_once __DIR__ . '/financial_helpers.php';

function out(array $payload): void
{
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if ((int) ($_SESSION['permission'] ?? 0) < 400) {
  http_response_code(403);
  out(['ok' => false, 'error' => 'No permission']);
}

$id = (int) ($_POST['id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($id <= 0) {
  out(['ok' => false, 'error' => 'Missing adjustment ID']);
}

try {
  order_financial_require_schema($conn);

  $stmt = $conn->prepare("
    SELECT id, order_id, type, reference, purpose, amount, currency
    FROM order_financial_adjustments
    WHERE id = ?
      AND deleted_at IS NULL
    LIMIT 1
  ");
  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $adjustment = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$adjustment) {
    throw new RuntimeException('Financial adjustment not found');
  }

  $stmt = $conn->prepare("
    UPDATE order_financial_adjustments
    SET deleted_at = NOW(), deleted_by = ?
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }
  $stmt->bind_param('ii', $userId, $id);
  $stmt->execute();
  $stmt->close();

  log_order_activity(
    $conn,
    (int) $adjustment['order_id'],
    $userId,
    'financial_adjustment_deleted',
    'financial_adjustment',
    $id,
    [
      'type' => $adjustment['type'],
      'reference' => $adjustment['reference'],
      'purpose' => $adjustment['purpose'],
      'amount' => (float) $adjustment['amount'],
      'currency' => $adjustment['currency'],
    ],
    ((string) $adjustment['type'] === 'REFUND' ? 'Refund' : 'Payment') . ' deleted: ' . (string) $adjustment['purpose'] . ' (' . ((float) $adjustment['amount'] > 0 ? '+' : '') . number_format((float) $adjustment['amount'], 2, '.', '') . ' EUR), ref. ' . (string) $adjustment['reference']
  );

  out(['ok' => true, 'order_id' => (int) $adjustment['order_id']]);
} catch (Throwable $e) {
  out(['ok' => false, 'error' => $e->getMessage()]);
}
