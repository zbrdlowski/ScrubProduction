<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/includes/orders_multishipping_helpers.php';

// All writes are confined to connection-local temporary tables. Never initialize
// the application schema here: these fixtures must not touch application data.
$tables = [
  'orders' => "id BIGINT PRIMARY KEY, status VARCHAR(32), shipped_at DATETIME NULL, delivered_at DATETIME NULL,
    status_override TINYINT DEFAULT 0, status_override_by INT NULL, status_override_at DATETIME NULL, status_override_note VARCHAR(255) NULL",
  'order_multishipping_groups' => "id BIGINT PRIMARY KEY, status VARCHAR(20), tracking_number VARCHAR(120) NULL,
    shipped_at DATETIME NULL, exported_at DATETIME NULL, exported_by INT NULL, updated_by INT NULL",
  'order_multishipping_orders' => 'group_id BIGINT, order_id BIGINT, position INT, PRIMARY KEY (group_id, order_id),
    UNIQUE KEY (order_id), UNIQUE KEY (group_id, position)',
  'order_fedex_export_locks' => 'order_id BIGINT PRIMARY KEY, group_id BIGINT, active TINYINT, released_by INT NULL, released_at DATETIME NULL',
  'order_tracking_numbers' => 'id BIGINT PRIMARY KEY, order_id BIGINT, tracking_number VARCHAR(120), carrier VARCHAR(40), deleted_at DATETIME NULL',
];
foreach ($tables as $table => $columns) {
  $conn->query("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB");
}

$audit = [];
function log_order_activity(mysqli $conn, ?int $orderId, ?int $actorId, string $action, string $entityType = '', int $entityId = 0, array $payload = [], string $note = ''): void
{
  global $audit;
  $audit[] = compact('orderId', 'actorId', 'action', 'entityType', 'entityId', 'payload', 'note');
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
  $checks++;
  if (!$condition) throw new RuntimeException($message);
};
$seed = static function (string $status = 'SHIPPED') use ($conn, $tables, &$audit): void {
  foreach (array_keys($tables) as $table) $conn->query("DELETE FROM $table");
  $audit = [];
  $conn->query("INSERT INTO orders (id, status, shipped_at) VALUES
    (1, 'SHIPPED', '2026-11-20 10:00:00'), (2, 'SHIPPED', '2026-11-20 10:00:00'),
    (3, 'SHIPPED', '2026-11-20 10:00:00'), (4, 'SHIPPED', '2026-11-20 10:00:00')");
  $stmt = $conn->prepare("INSERT INTO order_multishipping_groups VALUES
    (10, ?, 'TRACK-GROUP', '2026-11-20 10:00:00', '2026-11-19 10:00:00', 7, 7)");
  $stmt->bind_param('s', $status);
  $stmt->execute();
  $stmt->close();
  $conn->query('INSERT INTO order_multishipping_orders VALUES (10, 1, 0), (10, 2, 1), (10, 3, 2), (20, 4, 0)');
  $conn->query('INSERT INTO order_fedex_export_locks (order_id, group_id, active) VALUES (1,10,1), (2,10,1), (3,10,1), (4,20,1)');
  $conn->query("INSERT INTO order_tracking_numbers VALUES
    (1,1,'TRACK-GROUP','FedEx',NULL), (2,2,'TRACK-GROUP','FedEx',NULL),
    (3,3,'TRACK-GROUP','FedEx',NULL), (4,4,'OTHER','FedEx',NULL),
    (5,2,'EXTRA','FedEx',NULL), (6,2,'OLD','FedEx','2026-11-01 09:00:00')");
};
$release = static function (array $ids, bool $commit = true) use ($conn): array {
  $conn->begin_transaction();
  try {
    $group = $conn->query('SELECT * FROM order_multishipping_groups WHERE id = 10 FOR UPDATE')->fetch_assoc();
    $members = $conn->query('SELECT m.*, o.status FROM order_multishipping_orders m JOIN orders o ON o.id = m.order_id
      WHERE group_id = 10 ORDER BY position FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    $result = ordersMultishippingReleaseOrders($conn, $group, $members, $ids, 99);
    if ($commit) $conn->commit(); else $conn->rollback();
    return $result;
  } catch (Throwable $e) {
    $conn->rollback();
    throw $e;
  }
};
$scalar = static fn(string $sql) => $conn->query($sql)->fetch_row()[0];

try {
  $seed();
  $assert($release([2, 2]) === [2], 'Duplicate selections release an order once');
  $released = $conn->query('SELECT * FROM orders WHERE id = 2')->fetch_assoc();
  $assert($released['status'] === 'READY_TO_SHIP', 'Released order is ready for a new label');
  $assert($released['shipped_at'] === null && $released['delivered_at'] === null, 'Shipment dates are cleared');
  $assert((int) $released['status_override'] === 1 && (int) $released['status_override_by'] === 99, 'Reshipment state is preserved by workflow override');
  $assert((int) $scalar('SELECT COUNT(*) FROM order_tracking_numbers WHERE order_id = 2 AND deleted_at IS NULL') === 0, 'All active tracking for the selected order is removed');
  $assert($scalar('SELECT deleted_at FROM order_tracking_numbers WHERE id = 6') === '2026-11-01 09:00:00', 'Previously deleted tracking is unchanged');
  $assert((int) $scalar('SELECT active FROM order_fedex_export_locks WHERE order_id = 2') === 0, 'Selected export lock is released');
  $assert((int) $scalar('SELECT released_by FROM order_fedex_export_locks WHERE order_id = 2') === 99, 'Unlock actor is recorded');
  $assert((int) $scalar('SELECT COUNT(*) FROM order_multishipping_orders WHERE order_id = 2') === 0, 'Selected order is detached');
  $assert($scalar('SELECT status FROM orders WHERE id = 1') === 'SHIPPED', 'Remaining order status is unchanged');
  $assert((int) $scalar('SELECT COUNT(*) FROM order_tracking_numbers WHERE order_id IN (1,3,4) AND deleted_at IS NULL') === 3, 'Other orders keep tracking');
  $assert((int) $scalar('SELECT SUM(active) FROM order_fedex_export_locks WHERE order_id IN (1,3,4)') === 3, 'Other orders keep locks');
  $assert($scalar('SELECT tracking_number FROM order_multishipping_groups WHERE id = 10') === 'TRACK-GROUP', 'Remaining group keeps tracking');
  $assert((int) $scalar('SELECT position FROM order_multishipping_orders WHERE order_id = 3') === 1, 'Positions remain contiguous');
  $deletedAudit = array_values(array_filter($audit, static fn($row) => $row['action'] === 'tracking_deleted'));
  $assert(count($deletedAudit) === 2 && $deletedAudit[0]['orderId'] === 2, 'Tracking removals are audited');
  $assert(count(array_filter($audit, static fn($row) => $row['action'] === 'multishipping_released')) === 1, 'Release is audited once');
  $conn->query('INSERT INTO order_multishipping_orders VALUES (30, 2, 0)');
  $assert((int) $scalar('SELECT group_id FROM order_multishipping_orders WHERE order_id = 2') === 30, 'Released order can join a different shipment');

  $seed();
  $release([1]);
  $assert((int) $scalar('SELECT position FROM order_multishipping_orders WHERE order_id = 2') === 0, 'Removing master promotes next member');
  $assert((int) $scalar('SELECT position FROM order_multishipping_orders WHERE order_id = 3') === 1, 'Master removal preserves unique positions');
  $release([2]);
  $assert((int) $scalar('SELECT position FROM order_multishipping_orders WHERE order_id = 3') === 0, 'Single remaining member remains master');
  $assert($scalar('SELECT status FROM order_multishipping_groups WHERE id = 10') === 'SHIPPED', 'Single remaining member retains shipment status');
  $release([3]);
  $assert($scalar('SELECT status FROM order_multishipping_groups WHERE id = 10') === 'CANCELLED', 'Empty group is cancelled');
  $assert($scalar('SELECT tracking_number FROM order_multishipping_groups WHERE id = 10') === null, 'Empty group tracking is cleared');

  $seed();
  $release([1,2,3]);
  $assert((int) $scalar('SELECT COUNT(*) FROM order_multishipping_orders WHERE group_id = 10') === 0, 'Whole selection detaches every member');
  $assert((int) $scalar("SELECT COUNT(*) FROM orders WHERE status = 'READY_TO_SHIP'") === 3, 'Whole selection readies all selected orders');

  $seed('EXPORTED');
  $conn->query("UPDATE orders SET status = 'READY_TO_INVOICE', shipped_at = NULL WHERE id = 2");
  $conn->query('DELETE FROM order_tracking_numbers WHERE order_id = 2');
  $conn->query('DELETE FROM order_fedex_export_locks WHERE order_id = 2');
  $release([2]);
  $assert($scalar('SELECT status FROM orders WHERE id = 2') === 'READY_TO_SHIP', 'Release works without tracking or an export-lock record');

  foreach ([[], [2,4], [999]] as $invalidIds) {
    $seed();
    try { $release($invalidIds); throw new LogicException('Expected invalid selection rejection'); }
    catch (RuntimeException $e) { $assert(true, 'Invalid selection rejected'); }
    $assert((int) $scalar('SELECT COUNT(*) FROM order_tracking_numbers WHERE order_id = 2 AND deleted_at IS NULL') === 2, 'Invalid batch leaves valid selected order untouched');
  }
  foreach (['DELIVERED', 'CANCELLED', 'PENDING', 'IN_PROGRESS'] as $status) {
    $seed();
    $conn->query("UPDATE orders SET status = '$status' WHERE id = 2");
    try { $release([1,2]); throw new LogicException('Expected status rejection'); }
    catch (RuntimeException $e) { $assert(true, 'Invalid member status rejected'); }
    $assert($scalar('SELECT status FROM orders WHERE id = 1') === 'SHIPPED', 'Invalid batch is atomic');
  }
  foreach (['DRAFT', 'CANCELLED'] as $status) {
    $seed($status);
    try { $release([2]); throw new LogicException('Expected group rejection'); }
    catch (RuntimeException $e) { $assert(true, 'Non-exported group cannot use reshipment action'); }
  }

  $seed();
  $conn->query('ALTER TABLE order_multishipping_groups ADD CONSTRAINT release_test_failure CHECK (updated_by <> 99)');
  try { $release([1,2]); throw new LogicException('Expected database failure'); }
  catch (mysqli_sql_exception $e) { $assert(true, 'Late database failure propagates'); }
  $assert((int) $scalar('SELECT COUNT(*) FROM order_multishipping_orders WHERE group_id = 10') === 3, 'Database failure restores detached members');
  $assert((int) $scalar('SELECT position FROM order_multishipping_orders WHERE order_id = 3') === 2, 'Database failure restores original master positions');
  $assert((int) $scalar('SELECT COUNT(*) FROM order_tracking_numbers WHERE deleted_at IS NULL') === 5, 'Database failure restores tracking');
  $assert((int) $scalar('SELECT SUM(active) FROM order_fedex_export_locks') === 4, 'Database failure restores locks');
  $assert($scalar('SELECT status FROM orders WHERE id = 2') === 'SHIPPED', 'Database failure restores order status');
  $conn->query('ALTER TABLE order_multishipping_groups DROP CONSTRAINT release_test_failure');

  $seed();
  $release([1,2], false);
  $assert((int) $scalar('SELECT COUNT(*) FROM order_multishipping_orders WHERE group_id = 10') === 3, 'Rollback restores memberships');
  $assert((int) $scalar('SELECT COUNT(*) FROM order_tracking_numbers WHERE deleted_at IS NULL') === 5, 'Rollback restores tracking');
  $assert((int) $scalar('SELECT SUM(active) FROM order_fedex_export_locks') === 4, 'Rollback restores export locks');
  $assert($scalar('SELECT status FROM orders WHERE id = 2') === 'SHIPPED', 'Rollback restores status');
  $seed();
  $conn->query('ALTER TABLE orders DROP COLUMN shipped_at, DROP COLUMN delivered_at');
  $release([2]);
  $assert($scalar('SELECT status FROM orders WHERE id = 2') === 'READY_TO_SHIP', 'Older schemas without shipment timestamps are supported');
} finally {
  foreach (array_keys($tables) as $table) $conn->query("DROP TEMPORARY TABLE IF EXISTS $table");
}
echo 'orders_multishipping_release: ', $checks, " checks passed\n";