<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/orders_workflow_helpers.php';
require_once __DIR__ . '/activity_helper.php';

function out(array $payload): void
{
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function orderInvoiceRequestUserCanManage(): bool
{
  return auth_can('orders.admin')
    || auth_can('custom_orders.manage')
    || auth_can('custom_orders.financial')
    || auth_can('accounting.view')
    || auth_can('accounting.export');
}

if (!isset($_SESSION['permission'])) {
  out(['ok' => false, 'error' => 'Not logged in']);
}

if (!orderInvoiceRequestUserCanManage()) {
  http_response_code(403);
  out(['ok' => false, 'error' => 'No permission for invoice request updates.']);
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$invoiceTarget = strtolower(trim((string) ($_POST['invoice_target'] ?? '')));
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($orderId <= 0) {
  out(['ok' => false, 'error' => 'Invalid order_id']);
}

if (!in_array($invoiceTarget, ['', 'company', 'person'], true)) {
  out(['ok' => false, 'error' => 'Invalid invoice target']);
}

$stmt = $conn->prepare("
  SELECT
    o.id,
    o.status,
    o.source_meta,
    os.code AS source_code,
    COALESCE(oa_bill.company, '') AS billing_company,
    COALESCE(oa_bill.company_id, '') AS billing_company_id
  FROM orders o
  JOIN order_sources os ON os.id = o.source_id
  LEFT JOIN order_addresses oa_bill
    ON oa_bill.order_id = o.id AND UPPER(oa_bill.type) = 'BILLING'
  WHERE o.id = ?
  LIMIT 1
");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
  out(['ok' => false, 'error' => 'Order not found']);
}

if (strtoupper(trim((string) ($order['source_code'] ?? ''))) !== 'CUSTOM') {
  out(['ok' => false, 'error' => 'Invoice request can be changed only for Custom Orders.']);
}

$sourceMeta = json_decode((string) ($order['source_meta'] ?? ''), true);
if (!is_array($sourceMeta)) {
  $sourceMeta = [];
}

$oldMeta = $sourceMeta['_invoice_request'] ?? null;
$oldTarget = is_array($oldMeta) ? strtolower(trim((string) ($oldMeta['target'] ?? ''))) : '';
$oldRequired = is_array($oldMeta) && !empty($oldMeta['required']);
$detectedTarget = (
  trim((string) ($order['billing_company'] ?? '')) !== ''
  || trim((string) ($order['billing_company_id'] ?? '')) !== ''
) ? 'company' : 'person';

if ($invoiceTarget === '') {
  unset($sourceMeta['_invoice_request']);
  $newRequired = false;
  $newTarget = '';
} else {
  $sourceMeta['_invoice_request'] = [
    'required' => true,
    'target' => $invoiceTarget,
    'detected_target' => $detectedTarget,
    'updated_by' => $userId,
    'updated_at' => date('c'),
  ];
  $newRequired = true;
  $newTarget = $invoiceTarget;
}

$sourceMetaJson = $sourceMeta
  ? json_encode($sourceMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
  : '{}';

if ($sourceMetaJson === false) {
  out(['ok' => false, 'error' => 'Could not encode order metadata.']);
}

$stmt = $conn->prepare("UPDATE orders SET source_meta = ? WHERE id = ? LIMIT 1");
if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('si', $sourceMetaJson, $orderId);
$stmt->execute();
$stmt->close();

recalculateOrderWorkflow($conn, $orderId);

$statusStmt = $conn->prepare('SELECT status FROM orders WHERE id = ? LIMIT 1');
$status = (string) ($order['status'] ?? '');
if ($statusStmt) {
  $statusStmt->bind_param('i', $orderId);
  $statusStmt->execute();
  $statusRow = $statusStmt->get_result()->fetch_assoc();
  $statusStmt->close();
  $status = (string) ($statusRow['status'] ?? $status);
}

$targetLabels = [
  '' => 'No invoice request',
  'company' => 'Invoice to company',
  'person' => 'Invoice to person',
];

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'invoice_request_updated',
  'order',
  $orderId,
  [
    'old_required' => $oldRequired,
    'old_target' => $oldTarget,
    'new_required' => $newRequired,
    'new_target' => $newTarget,
    'detected_target' => $detectedTarget,
  ],
  'Invoice request changed: ' . ($targetLabels[$oldTarget] ?? 'No invoice request') . ' -> ' . ($targetLabels[$newTarget] ?? 'No invoice request')
);

out([
  'ok' => true,
  'order_id' => $orderId,
  'order_status' => $status,
  'invoice_request' => [
    'required' => $newRequired,
    'target' => $newTarget,
    'detected_target' => $detectedTarget,
  ],
]);