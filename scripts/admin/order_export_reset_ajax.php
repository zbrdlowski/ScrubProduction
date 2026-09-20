<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() !== PHP_SESSION_ACTIVE && empty($_SESSION)) {
  session_start();
}
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/order_export_reset_lib.php';

function orderExportResetJson(array $payload, int $status = 200): void
{
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function orderExportResetOrderNumber(string $value): string
{
  return trim($value);
}

function orderExportResetSupportedSources(): array
{
  return ['CUSTOM', 'SHOPTET', 'MX_LOCKER', 'EBAY'];
}

function orderExportResetSourceLabel(string $sourceCode): string
{
  $labels = [
    'CUSTOM' => 'Custom Orders',
    'SHOPTET' => 'Shoptet',
    'MX_LOCKER' => 'MXLocker',
    'EBAY' => 'eBay',
  ];

  return $labels[$sourceCode] ?? $sourceCode;
}

function orderExportResetIdentifier(string $identifier): string
{
  if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
    throw new InvalidArgumentException('Invalid SQL identifier.');
  }

  return '`' . $identifier . '`';
}

function orderExportResetTableExists(mysqli $conn, string $table): bool
{
  static $cache = [];
  if (array_key_exists($table, $cache)) {
    return $cache[$table];
  }

  $stmt = $conn->prepare('
    SELECT COUNT(*) AS cnt
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ');
  $stmt->bind_param('s', $table);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $cache[$table] = ((int) ($row['cnt'] ?? 0)) > 0;
  return $cache[$table];
}

function orderExportResetCountByOrderId(mysqli $conn, string $table, int $orderId): int
{
  if (!orderExportResetTableExists($conn, $table)) {
    return 0;
  }

  $sql = 'SELECT COUNT(*) AS cnt FROM ' . orderExportResetIdentifier($table) . ' WHERE order_id = ?';
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  return (int) ($row['cnt'] ?? 0);
}

function orderExportResetDeleteByOrderId(mysqli $conn, string $table, int $orderId): int
{
  if (!orderExportResetTableExists($conn, $table)) {
    return 0;
  }

  $sql = 'DELETE FROM ' . orderExportResetIdentifier($table) . ' WHERE order_id = ?';
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $affected = $stmt->affected_rows;
  $stmt->close();

  return max(0, (int) $affected);
}

function orderExportResetItemLinkedCount(mysqli $conn, string $table, string $itemColumn, int $orderId): int
{
  if (!orderExportResetTableExists($conn, $table) || !orderExportResetTableExists($conn, 'order_items')) {
    return 0;
  }

  $sql = '
    SELECT COUNT(*) AS cnt
    FROM ' . orderExportResetIdentifier($table) . ' child
    INNER JOIN order_items oi ON oi.id = child.' . orderExportResetIdentifier($itemColumn) . '
    WHERE oi.order_id = ?
  ';
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  return (int) ($row['cnt'] ?? 0);
}

function orderExportResetDeleteItemLinked(mysqli $conn, string $table, string $itemColumn, int $orderId): int
{
  if (!orderExportResetTableExists($conn, $table) || !orderExportResetTableExists($conn, 'order_items')) {
    return 0;
  }

  $sql = '
    DELETE child
    FROM ' . orderExportResetIdentifier($table) . ' child
    INNER JOIN order_items oi ON oi.id = child.' . orderExportResetIdentifier($itemColumn) . '
    WHERE oi.order_id = ?
  ';
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $affected = $stmt->affected_rows;
  $stmt->close();

  return max(0, (int) $affected);
}

function orderExportResetFindCustomOrder(mysqli $conn, array $order): ?array
{
  if (!orderExportResetTableExists($conn, 'custom_orders')) {
    return null;
  }

  $orderId = (int) ($order['id'] ?? 0);
  $orderNumber = (string) ($order['order_number'] ?? '');
  $externalOrderId = (string) ($order['external_order_id'] ?? '');

  $stmt = $conn->prepare('
    SELECT id, internal_code, official_order_number, status, production_order_id, exported_at, exported_by
    FROM custom_orders
    WHERE production_order_id = ?
       OR official_order_number = ?
       OR internal_code = ?
    ORDER BY
      CASE WHEN production_order_id = ? THEN 0 ELSE 1 END,
      id DESC
    LIMIT 1
  ');
  $stmt->bind_param('issi', $orderId, $orderNumber, $externalOrderId, $orderId);
  $stmt->execute();
  $customOrder = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  return $customOrder ?: null;
}

function orderExportResetFindDetachedCustomOrder(mysqli $conn, string $orderNumber): ?array
{
  if (!orderExportResetTableExists($conn, 'custom_orders')) {
    return null;
  }

  $stmt = $conn->prepare('
    SELECT id, internal_code, official_order_number, status, production_order_id, exported_at, exported_by
    FROM custom_orders
    WHERE official_order_number = ?
       OR internal_code = ?
    ORDER BY id DESC
    LIMIT 1
  ');
  $stmt->bind_param('ss', $orderNumber, $orderNumber);
  $stmt->execute();
  $customOrder = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  return $customOrder ?: null;
}

function orderExportResetFetchContext(mysqli $conn, string $orderNumber): array
{
  $stmt = $conn->prepare('
    SELECT
      o.id,
      o.source_id,
      os.code AS source_code,
      o.external_order_id,
      o.order_number,
      o.imported_at,
      o.order_date,
      o.status,
      o.currency,
      o.total,
      o.payment_method,
      o.shipping_method,
      o.customer_id,
      c.name AS customer_name,
      c.email AS customer_email
    FROM orders o
    INNER JOIN order_sources os ON os.id = o.source_id
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE o.order_number = ?
    ORDER BY o.id DESC
  ');
  $stmt->bind_param('s', $orderNumber);
  $stmt->execute();
  $result = $stmt->get_result();
  $orders = [];
  while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
  }
  $stmt->close();

  if (count($orders) === 0) {
    $customOrder = orderExportResetFindDetachedCustomOrder($conn, $orderNumber);
    return [
      'found' => false,
      'ambiguous' => false,
      'order' => null,
      'orders' => [],
      'custom_order' => $customOrder,
      'counts' => [],
      'can_reset' => false,
      'message' => $customOrder
        ? 'Custom order exists, but no linked Production order was found for this number.'
        : 'No Production order found for this number.',
    ];
  }

  if (count($orders) > 1) {
    return [
      'found' => true,
      'ambiguous' => true,
      'order' => null,
      'orders' => $orders,
      'custom_order' => null,
      'counts' => [],
      'can_reset' => false,
      'message' => 'More than one Production order has this order number. Reset is blocked until this is checked manually.',
    ];
  }

  $order = $orders[0];
  $sourceCode = strtoupper((string) ($order['source_code'] ?? ''));
  $supported = in_array($sourceCode, orderExportResetSupportedSources(), true);
  $orderId = (int) $order['id'];
  $customOrder = $sourceCode === 'CUSTOM' ? orderExportResetFindCustomOrder($conn, $order) : null;

  $counts = [
    'order_addresses' => orderExportResetCountByOrderId($conn, 'order_addresses', $orderId),
    'order_assignments' => orderExportResetCountByOrderId($conn, 'order_assignments', $orderId),
    'order_activity' => orderExportResetCountByOrderId($conn, 'order_activity', $orderId),
    'order_categories' => orderExportResetCountByOrderId($conn, 'order_categories', $orderId),
    'order_financial_adjustments' => orderExportResetCountByOrderId($conn, 'order_financial_adjustments', $orderId),
    'order_invoices' => orderExportResetCountByOrderId($conn, 'order_invoices', $orderId),
    'order_item_assignments' => orderExportResetCountByOrderId($conn, 'order_item_assignments', $orderId),
    'order_item_categories' => orderExportResetItemLinkedCount($conn, 'order_item_categories', 'item_id', $orderId),
    'order_item_statuses' => orderExportResetItemLinkedCount($conn, 'order_item_statuses', 'order_item_id', $orderId),
    'order_items' => orderExportResetCountByOrderId($conn, 'order_items', $orderId),
    'order_photos' => orderExportResetCountByOrderId($conn, 'order_photos', $orderId),
    'order_production_notes' => orderExportResetCountByOrderId($conn, 'order_production_notes', $orderId),
    'order_status_history' => orderExportResetCountByOrderId($conn, 'order_status_history', $orderId),
    'order_tracking_numbers' => orderExportResetCountByOrderId($conn, 'order_tracking_numbers', $orderId),
    'shipments' => orderExportResetCountByOrderId($conn, 'shipments', $orderId),
  ];

  $messages = [];
  if (!$supported) {
    $messages[] = 'Source is not supported by this reset tool.';
  }
  if ($sourceCode === 'CUSTOM' && !$customOrder) {
    $messages[] = 'Custom Orders row was not found, so the export button cannot be safely reset.';
  }

  return [
    'found' => true,
    'ambiguous' => false,
    'order' => $order,
    'orders' => $orders,
    'custom_order' => $customOrder,
    'counts' => $counts,
    'can_reset' => $supported && ($sourceCode !== 'CUSTOM' || (bool) $customOrder),
    'message' => implode(' ', $messages),
  ];
}

function orderExportResetAddCount(array &$counts, string $table, int $affected): void
{
  $counts[$table] = ($counts[$table] ?? 0) + $affected;
}

function orderExportResetLogCustomReset(mysqli $conn, int $customOrderId, int $userId, array $order): void
{
  if (!orderExportResetTableExists($conn, 'custom_order_activity')) {
    return;
  }

  $action = 'export_reset';
  $payload = json_encode([
    'order_number' => (string) ($order['order_number'] ?? ''),
    'production_order_id' => (int) ($order['id'] ?? 0),
    'source_code' => (string) ($order['source_code'] ?? ''),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $note = 'Manual reset: removed broken Production export so Custom Order can be exported again';

  $stmt = $conn->prepare('
    INSERT INTO custom_order_activity (custom_order_id, actor_employee_id, action, payload, note)
    VALUES (?, ?, ?, ?, ?)
  ');
  $stmt->bind_param('iisss', $customOrderId, $userId, $action, $payload, $note);
  $stmt->execute();
  $stmt->close();
}

function orderExportResetDeleteOrder(mysqli $conn, array $context, int $userId): array
{
  $order = $context['order'];
  $customOrder = $context['custom_order'];
  $orderId = (int) $order['id'];
  $orderNumber = (string) $order['order_number'];
  $sourceCode = strtoupper((string) $order['source_code']);
  $counts = [];

  if ($sourceCode === 'CUSTOM' && !$customOrder) {
    throw new RuntimeException('Custom Orders row was not found. Reset blocked.');
  }

  $conn->begin_transaction();

  try {
    if ($sourceCode === 'CUSTOM') {
      $customOrderId = (int) $customOrder['id'];

      $stmt = $conn->prepare('
        UPDATE custom_order_photos
        SET production_photo_id = NULL,
            exported_at = NULL
        WHERE custom_order_id = ?
      ');
      $stmt->bind_param('i', $customOrderId);
      $stmt->execute();
      orderExportResetAddCount($counts, 'custom_order_photos_reset', (int) $stmt->affected_rows);
      $stmt->close();

      $stmt = $conn->prepare("
        UPDATE custom_orders
        SET production_order_id = NULL,
            exported_at = NULL,
            exported_by = NULL,
            updated_by = ?,
            status = CASE WHEN status = 'EXPORTED' THEN 'DEPOSIT_PAID' ELSE status END
        WHERE id = ?
          AND production_order_id = ?
        LIMIT 1
      ");
      $stmt->bind_param('iii', $userId, $customOrderId, $orderId);
      $stmt->execute();
      orderExportResetAddCount($counts, 'custom_orders_reset', (int) $stmt->affected_rows);
      $stmt->close();

      orderExportResetLogCustomReset($conn, $customOrderId, $userId, $order);
    }

    orderExportResetAddCount($counts, 'order_item_statuses', orderExportResetDeleteItemLinked($conn, 'order_item_statuses', 'order_item_id', $orderId));
    orderExportResetAddCount($counts, 'order_item_categories', orderExportResetDeleteItemLinked($conn, 'order_item_categories', 'item_id', $orderId));

    foreach ([
      'order_item_assignments',
      'order_financial_adjustments',
      'order_photos',
      'order_production_notes',
      'order_status_history',
      'shipments',
      'order_tracking_numbers',
      'order_invoices',
      'order_assignments',
      'order_activity',
      'order_categories',
      'order_addresses',
      'order_items',
    ] as $table) {
      orderExportResetAddCount($counts, $table, orderExportResetDeleteByOrderId($conn, $table, $orderId));
    }

    $stmt = $conn->prepare('
      DELETE FROM orders
      WHERE id = ?
        AND order_number = ?
        AND source_id = (SELECT id FROM order_sources WHERE code = ? LIMIT 1)
      LIMIT 1
    ');
    $stmt->bind_param('iss', $orderId, $orderNumber, $sourceCode);
    $stmt->execute();
    $deletedOrderRows = (int) $stmt->affected_rows;
    $stmt->close();

    if ($deletedOrderRows !== 1) {
      throw new RuntimeException('Production order delete did not affect exactly one row. Rollback done.');
    }
    orderExportResetAddCount($counts, 'orders', $deletedOrderRows);

    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    throw $e;
  }

  return $counts;
}

if (!orderExportResetCurrentUserAllowed()) {
  orderExportResetJson(['ok' => false, 'error' => 'No permission'], 403);
}

$action = (string) ($_POST['action'] ?? '');
$orderNumber = orderExportResetOrderNumber((string) ($_POST['order_number'] ?? ''));

if ($orderNumber === '') {
  orderExportResetJson(['ok' => false, 'error' => 'Missing order number.'], 400);
}

try {
  $context = orderExportResetFetchContext($conn, $orderNumber);

  if ($action === 'lookup') {
    orderExportResetJson(['ok' => true] + $context);
  }

  if ($action === 'reset') {
    if (!$context['found'] || $context['ambiguous'] || !$context['can_reset'] || !$context['order']) {
      orderExportResetJson([
        'ok' => false,
        'error' => $context['message'] ?: 'This order cannot be reset safely.',
        'context' => $context,
      ], 409);
    }

    $deleted = orderExportResetDeleteOrder($conn, $context, (int) ($_SESSION['user_id'] ?? 0));
    orderExportResetJson([
      'ok' => true,
      'deleted' => $deleted,
      'order_number' => $orderNumber,
      'source_label' => orderExportResetSourceLabel((string) $context['order']['source_code']),
      'message' => 'Reset complete. The order can be imported/exported again.',
    ]);
  }

  orderExportResetJson(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
  orderExportResetJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
