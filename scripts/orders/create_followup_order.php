<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';
require_once __DIR__ . '/category_sync_helper.php';
require_once dirname(__DIR__, 2) . '/includes/orders_workflow_helpers.php';

function out_followup(int $code, array $payload): void
{
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function followup_decode_json_map(?string $raw): array
{
  $decoded = json_decode((string) $raw, true);
  return is_array($decoded) ? $decoded : [];
}

function followup_build_order_number(mysqli $conn, string $baseNumber, string $followupCode): string
{
  $baseNumber = trim($baseNumber);
  if ($baseNumber === '') {
    $baseNumber = 'ORDER';
  }

  $prefix = $baseNumber . '-' . $followupCode;
  $stmt = $conn->prepare("
    SELECT order_number
    FROM orders
    WHERE order_number LIKE CONCAT(?, '%')
    ORDER BY id DESC
  ");
  if (!$stmt) {
    return $prefix . '1';
  }

  $stmt->bind_param('s', $prefix);
  $stmt->execute();
  $res = $stmt->get_result();

  $maxSuffix = 0;
  while ($row = $res->fetch_assoc()) {
    $candidate = trim((string) ($row['order_number'] ?? ''));
    if (!preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $candidate, $m)) {
      continue;
    }

    $maxSuffix = max($maxSuffix, (int) $m[1]);
  }
  $stmt->close();

  return $prefix . ($maxSuffix + 1);
}

if ((int) ($_SESSION['permission'] ?? 0) < 300) {
  out_followup(403, ['ok' => false, 'error' => 'No permission']);
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$followupType = strtoupper(trim((string) ($_POST['followup_type'] ?? '')));
$reason = trim((string) ($_POST['reason'] ?? ''));
$doNotInvoice = (int) ($_POST['do_not_invoice'] ?? 0) === 1 ? 1 : 0;
$selectedItemsRaw = $_POST['selected_items'] ?? [];
$userId = (int) ($_SESSION['user_id'] ?? 0);

$allowedTypes = [
  'REPEAT' => 'R',
  'WARRANTY' => 'W',
  'CRASH' => 'C',
];

if ($orderId <= 0 || !isset($allowedTypes[$followupType])) {
  out_followup(400, ['ok' => false, 'error' => 'Invalid follow-up request']);
}

if ($followupType === 'WARRANTY' && $doNotInvoice !== 1) {
  $doNotInvoice = 1;
}

if (!is_array($selectedItemsRaw) || !$selectedItemsRaw) {
  out_followup(400, ['ok' => false, 'error' => 'Select at least one item']);
}

$selectedItems = [];
foreach ($selectedItemsRaw as $itemIdRaw => $qtyRaw) {
  $itemId = (int) $itemIdRaw;
  $qty = max(0, (int) $qtyRaw);
  if ($itemId > 0 && $qty > 0) {
    $selectedItems[$itemId] = $qty;
  }
}

if (!$selectedItems) {
  out_followup(400, ['ok' => false, 'error' => 'Select at least one item']);
}

$stmt = $conn->prepare("
  SELECT *
  FROM orders
  WHERE id = ?
  LIMIT 1
");
if (!$stmt) {
  out_followup(500, ['ok' => false, 'error' => $conn->error]);
}
$stmt->bind_param('i', $orderId);
$stmt->execute();
$sourceOrder = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sourceOrder) {
  out_followup(404, ['ok' => false, 'error' => 'Source order not found']);
}

$stmt = $conn->prepare("
  SELECT *
  FROM order_items
  WHERE order_id = ?
    AND deleted_at IS NULL
  ORDER BY COALESCE(line_no, 999999), id
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$itemRes = $stmt->get_result();
$sourceItems = [];
while ($row = $itemRes->fetch_assoc()) {
  $sourceItems[(int) $row['id']] = $row;
}
$stmt->close();

$itemsToClone = [];
foreach ($selectedItems as $itemId => $qty) {
  if (!isset($sourceItems[$itemId])) {
    continue;
  }

  $sourceQty = max(1, (int) ($sourceItems[$itemId]['qty'] ?? 1));
  $cloneQty = min($qty, $sourceQty);
  if ($cloneQty <= 0) {
    continue;
  }

  $sourceItems[$itemId]['followup_qty'] = $cloneQty;
  $itemsToClone[] = $sourceItems[$itemId];
}

if (!$itemsToClone) {
  out_followup(400, ['ok' => false, 'error' => 'No valid items selected']);
}

$stmt = $conn->prepare("
  SELECT type, name, company, company_id, street, city, zip, country, email, phone
  FROM order_addresses
  WHERE order_id = ?
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$addressRes = $stmt->get_result();
$addresses = [];
while ($row = $addressRes->fetch_assoc()) {
  $addresses[] = $row;
}
$stmt->close();

$sourceMeta = followup_decode_json_map((string) ($sourceOrder['source_meta'] ?? ''));
$followupCode = $allowedTypes[$followupType];
$newOrderNumber = followup_build_order_number(
  $conn,
  (string) ($sourceOrder['order_number'] ?? $sourceOrder['external_order_id'] ?? ('ORDER' . $orderId)),
  $followupCode
);

$baseNote = trim((string) ($sourceOrder['note'] ?? ''));
$followupLabelMap = [
  'REPEAT' => 'Repeat Order',
  'WARRANTY' => 'Warranty Claim',
  'CRASH' => 'Crash Replacement',
];
$followupLabel = $followupLabelMap[$followupType] ?? $followupType;

$selectedItemIds = array_map(static fn(array $item): int => (int) $item['id'], $itemsToClone);
$sourceMeta['_followup'] = [
  'is_followup' => true,
  'parent_order_id' => $orderId,
  'parent_order_number' => (string) ($sourceOrder['order_number'] ?? ''),
  'type' => $followupType,
  'label' => $followupLabel,
  'do_not_invoice' => $doNotInvoice === 1,
  'reason' => $reason,
  'created_at' => date('c'),
  'created_by' => $userId,
  'selected_item_ids' => $selectedItemIds,
];
$sourceMetaJson = json_encode($sourceMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$newTotal = 0.0;
foreach ($itemsToClone as $item) {
  $unitPrice = $doNotInvoice === 1 ? 0.0 : (float) ($item['unit_price'] ?? 0);
  $newTotal += $unitPrice * (int) ($item['followup_qty'] ?? 0);
}

$shippingMethod = trim((string) ($sourceOrder['shipping_method'] ?? ''));
$paymentMethod = trim((string) ($sourceOrder['payment_method'] ?? ''));
if ($doNotInvoice === 1) {
  $paymentMethod = 'DO NOT INVOICE';
}

$newNoteParts = [
  '[FOLLOW-UP] ' . $followupLabel,
  'Parent order #' . (string) ($sourceOrder['order_number'] ?? $orderId),
];
if ($doNotInvoice === 1) {
  $newNoteParts[] = 'Do not invoice';
}
if ($reason !== '') {
  $newNoteParts[] = $reason;
}
if ($baseNote !== '') {
  $newNoteParts[] = $baseNote;
}
$newNote = implode(' | ', $newNoteParts);

$conn->begin_transaction();

try {
  $stmt = $conn->prepare("
    INSERT INTO orders
      (source_id, external_order_id, order_number, imported_at, order_date, status, currency, total, payment_method, shipping_method, note, source_meta, customer_id, manual_types_override)
    VALUES
      (?, ?, ?, NOW(), NOW(), 'NEW', ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }

  $newExternalOrderId = 'FOLLOWUP:' . $orderId . ':' . $followupType . ':' . time();
  $currency = (string) ($sourceOrder['currency'] ?? 'EUR');
  $manualTypes = (string) ($sourceOrder['manual_types_override'] ?? '');
  $sourceId = (int) ($sourceOrder['source_id'] ?? 0);
  $customerIdDb = ((int) ($sourceOrder['customer_id'] ?? 0) > 0)
    ? (int) $sourceOrder['customer_id']
    : null;

  $stmt->bind_param(
    'isssdssssis',
    $sourceId,
    $newExternalOrderId,
    $newOrderNumber,
    $currency,
    $newTotal,
    $paymentMethod,
    $shippingMethod,
    $newNote,
    $sourceMetaJson,
    $customerIdDb,
    $manualTypes
  );
  $stmt->execute();
  $newOrderId = (int) $stmt->insert_id;
  $stmt->close();

  if ($addresses) {
    $stmt = $conn->prepare("
      INSERT INTO order_addresses
        (order_id, type, name, company, company_id, street, city, zip, country, email, phone)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
      throw new RuntimeException($conn->error);
    }

    foreach ($addresses as $address) {
      $type = strtoupper(trim((string) ($address['type'] ?? '')));
      $name = (string) ($address['name'] ?? '');
      $company = (string) ($address['company'] ?? '');
      $companyId = (string) ($address['company_id'] ?? '');
      $street = (string) ($address['street'] ?? '');
      $city = (string) ($address['city'] ?? '');
      $zip = (string) ($address['zip'] ?? '');
      $country = (string) ($address['country'] ?? '');
      $email = (string) ($address['email'] ?? '');
      $phone = (string) ($address['phone'] ?? '');

      $stmt->bind_param('issssssssss', $newOrderId, $type, $name, $company, $companyId, $street, $city, $zip, $country, $email, $phone);
      $stmt->execute();
    }
    $stmt->close();
  }

  $stmt = $conn->prepare("
    INSERT INTO order_items
      (order_id, line_no, sku, title, custom_label, item_type_code, qty, unit_price, options_json, internal_options_json, created_by, updated_by, updated_at, status)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'NEW')
  ");
  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }

  $lineNo = 1;
  foreach ($itemsToClone as $item) {
    $sku = (string) ($item['sku'] ?? '');
    $title = (string) ($item['title'] ?? '');
    $customLabel = (string) ($item['custom_label'] ?? '');
    $itemTypeCode = strtoupper(trim((string) ($item['item_type_code'] ?? 'M')));
    $qty = (int) ($item['followup_qty'] ?? 1);
    $unitPrice = $doNotInvoice === 1 ? 0.0 : (float) ($item['unit_price'] ?? 0);
    $optionsJson = (string) ($item['options_json'] ?? '{}');
    $internalOptions = followup_decode_json_map((string) ($item['internal_options_json'] ?? '{}'));
    $internalOptions['_followup_parent_item_id'] = (int) ($item['id'] ?? 0);
    $internalOptionsJson = json_encode($internalOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt->bind_param(
      'iissssidssii',
      $newOrderId,
      $lineNo,
      $sku,
      $title,
      $customLabel,
      $itemTypeCode,
      $qty,
      $unitPrice,
      $optionsJson,
      $internalOptionsJson,
      $userId,
      $userId
    );
    $stmt->execute();
    $lineNo++;
  }
  $stmt->close();

  sync_order_categories($conn, $newOrderId);
  recalculateOrderWorkflow($conn, $newOrderId);

  log_order_activity(
    $conn,
    $newOrderId,
    $userId,
    'followup_created',
    'order',
    $orderId,
    [
      'parent_order_id' => $orderId,
      'parent_order_number' => (string) ($sourceOrder['order_number'] ?? ''),
      'followup_type' => $followupType,
      'do_not_invoice' => $doNotInvoice === 1 ? 1 : 0,
      'reason' => $reason,
    ],
    'Follow-up order created from order #' . (string) ($sourceOrder['order_number'] ?? $orderId)
  );

  log_order_activity(
    $conn,
    $orderId,
    $userId,
    'followup_spawned',
    'order',
    $newOrderId,
    [
      'new_order_id' => $newOrderId,
      'new_order_number' => $newOrderNumber,
      'followup_type' => $followupType,
      'do_not_invoice' => $doNotInvoice === 1 ? 1 : 0,
      'reason' => $reason,
    ],
    'Created follow-up order #' . $newOrderNumber
  );

  $conn->commit();
  out_followup(200, [
    'ok' => true,
    'new_order_id' => $newOrderId,
    'order_number' => $newOrderNumber,
    'do_not_invoice' => $doNotInvoice,
  ]);
} catch (Throwable $e) {
  $conn->rollback();
  out_followup(500, ['ok' => false, 'error' => $e->getMessage()]);
}
