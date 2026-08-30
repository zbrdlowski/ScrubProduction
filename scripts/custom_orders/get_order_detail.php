<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$orderId = (int) ($_POST['custom_order_id'] ?? $_POST['order_id'] ?? 0);
$editItemId = (int) ($_POST['edit_item_id'] ?? 0);
$focusNoteId = max(0, (int) ($_POST['focus_note_id'] ?? 0));
if ($orderId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid custom order.']);
  exit;
}

$_GET = [
  'page' => 'custom_orders',
  'custom_order_id' => (string) $orderId,
];
if ($editItemId > 0) {
  $_GET['edit_item_id'] = (string) $editItemId;
}
if ($focusNoteId > 0) {
  $_GET['focus_note_id'] = (string) $focusNoteId;
}

define('CUSTOM_ORDERS_DETAIL_REQUEST', true);

ob_start();
include dirname(__DIR__, 2) . '/includes/custom_orders.php';
$moduleHtml = (string) ob_get_clean();

if (empty($selectedOrder) || (int) ($selectedOrder['id'] ?? 0) !== $orderId) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Custom order not found.']);
  exit;
}

$startMarker = '<!-- CUSTOM_ORDER_DETAIL_START -->';
$endMarker = '<!-- CUSTOM_ORDER_DETAIL_END -->';
$start = strpos($moduleHtml, $startMarker);
$end = strpos($moduleHtml, $endMarker);

if ($start === false || $end === false || $end <= $start) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Custom order detail could not be rendered.']);
  exit;
}

$start += strlen($startMarker);
$html = trim(substr($moduleHtml, $start, $end - $start));

echo json_encode([
  'ok' => true,
  'order_id' => $orderId,
  'html' => $html,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
