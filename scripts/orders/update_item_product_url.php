<?php
declare(strict_types=1);
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function (): void {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    while (ob_get_level() > 0) {
      ob_end_clean();
    }
    http_response_code(500);
    echo json_encode(
      ['ok' => false, 'error' => 'PHP Fatal: ' . $err['message']],
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
  } elseif (ob_get_level() > 0) {
    ob_end_flush();
  }
});

function out_product_url(array $payload, int $status = 200): void
{
  while (ob_get_level() > 0) {
    ob_end_clean();
  }
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!auth_can('orders.manage')) {
  out_product_url(['ok' => false, 'error' => 'No permission'], 403);
}

require_once __DIR__ . '/activity_helper.php';

$itemId = (int)($_POST['item_id'] ?? 0);
$url = trim((string)($_POST['product_url'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($itemId <= 0) {
  out_product_url(['ok' => false, 'error' => 'Invalid item']);
}

if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
  out_product_url(['ok' => false, 'error' => 'Invalid URL']);
}

$stmt = $conn->prepare("
  SELECT order_id, product_url
  FROM order_items
  WHERE id = ?
    AND deleted_at IS NULL
  LIMIT 1
");
$stmt->bind_param('i', $itemId);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) {
  out_product_url(['ok' => false, 'error' => 'Item not found']);
}

$orderId = (int)$item['order_id'];
$oldUrl = (string)($item['product_url'] ?? '');

$stmt = $conn->prepare("
  UPDATE order_items
  SET product_url = ?,
      updated_by = ?,
      updated_at = NOW()
  WHERE id = ?
");
$stmt->bind_param('sii', $url, $userId, $itemId);
$stmt->execute();
$stmt->close();

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'item_product_url_updated',
  'order_item',
  $itemId,
  [
    'old_url' => $oldUrl,
    'new_url' => $url
  ],
  'Product URL updated'
);

out_product_url(['ok' => true, 'order_id' => $orderId]);
