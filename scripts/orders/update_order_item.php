<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';
require_once __DIR__ . '/category_sync_helper.php';
require_once dirname(__DIR__, 2) . '/includes/orders_workflow_helpers.php';

function out($p){ echo json_encode($p); exit; }
/*
if ((int)($_SESSION['permission'] ?? 0) < 300) {
  out(['ok'=>false,'error'=>'No permission']);
}
*/
$itemId = (int)($_POST['item_id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$type  = strtoupper(trim((string)($_POST['type'] ?? '')));
$qty   = max(1, (int)($_POST['qty'] ?? 1));
$sku   = trim((string)($_POST['sku'] ?? ''));
$customLabel = trim((string)($_POST['custom_label'] ?? ''));
$unitPriceRaw = trim((string)($_POST['unit_price'] ?? ''));
$unitPrice = ($unitPriceRaw !== '') ? round((float)$unitPriceRaw, 2) : null;

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($itemId <= 0 || $title === '' || $type === '') {
  out(['ok'=>false,'error'=>'Invalid data']);
}

$stmt = $conn->prepare("
  SELECT order_id, title, item_type_code, qty, sku, custom_label, unit_price
  FROM order_items
  WHERE id=? AND deleted_at IS NULL
");
$stmt->bind_param('i',$itemId);
$stmt->execute();
$old = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$old) out(['ok'=>false,'error'=>'Item not found']);

$stmt = $conn->prepare("
  UPDATE order_items
  SET title=?,
      item_type_code=?,
      qty=?,
      sku=?,
      custom_label=?,
      unit_price=?,
      updated_by=?,
      updated_at=NOW()
  WHERE id=?
");
$stmt->bind_param(
  'ssissdii',
  $title,
  $type,
  $qty,
  $sku,
  $customLabel,
  $unitPrice,
  $userId,
  $itemId
);
$stmt->execute();
$stmt->close();
sync_order_categories($conn, (int)$old['order_id']);

log_order_activity(
  $conn,
  $old['order_id'],
  $userId,
  'item_updated',
  'order_item',
  $itemId,
  ['old'=>$old,'new'=>[
    'title'=>$title,
    'type'=>$type,
    'qty'=>$qty,
    'sku'=>$sku,
    'custom_label' => $customLabel,
    'unit_price'   => $unitPrice,
  ]],
  'Item updated'
);
recalculateOrderWorkflow($conn, (int)$old['order_id']);
out(['ok'=>true]);