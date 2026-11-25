<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/custom_orders/repair_exported_status.php';
require_once dirname(__DIR__) . '/scripts/custom_orders/export_lock.php';
require dirname(__DIR__) . '/includes/conn.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
  $checks++;
  if (!$condition) throw new RuntimeException($message);
};

// Every table used below is connection-local. No application rows are modified.
foreach (['custom_orders', 'custom_order_activity', 'orders'] as $table) {
  $ddl = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
  $ddl = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl);
  $ddl = preg_replace('/^\s*CONSTRAINT[^\n]*\n/m', '', $ddl);
  $ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
  $conn->query($ddl);
  $assert(strpos($conn->query("SHOW CREATE TABLE `$table`")->fetch_row()[1], 'CREATE TEMPORARY TABLE') === 0,
    'Fixture must be temporary: ' . $table);
}
$fixtures = [
  [1, 'DEPOSIT_PAID', 101, '2026-11-01 10:00:00'],
  [2, 'DRAFT_SENT', 102, null],
  [3, 'CANCELLED', null, '2026-11-02 10:00:00'],
  [4, 'EXPORTED', 104, '2026-11-03 10:00:00'],
  [5, 'LEAD', null, null],
  [6, 'EXPORTED', null, null], // A manually selected label is not proof of export.
  [7, 'DEPOSIT_PAID', null, null], // An intentionally reset export stays editable.
  [8, 'DEAD', 0, '2026-11-04 10:00:00'],
  [9, 'exported', 109, null],
  [10, 'LEAD', 0, null],
];
$insert = $conn->prepare("INSERT INTO custom_orders
  (id, internal_code, official_order_number, status, production_order_id, exported_at,
   customer_name, currency, shipping_price, created_by, updated_by, updated_at)
  VALUES (?, ?, ?, ?, ?, ?, 'Repair fixture', 'EUR', 12.50, 3, 3, '2026-01-01 00:00:00')");
foreach ($fixtures as [$id, $status, $productionId, $exportedAt]) {
  $internal = 'CO-REPAIR-' . $id;
  $official = 'SO-REPAIR-' . $id;
  $insert->bind_param('isssis', $id, $internal, $official, $status, $productionId, $exportedAt);
  $insert->execute();
}
$insert->close();
$conn->query("INSERT INTO orders (id, source_id, external_order_id, order_number, status, total)
  VALUES (101, 1, 'CO-REPAIR-1', 'SO-REPAIR-1', 'IN_PROGRESS', 150)");
$snapshot = static function (string $table) use ($conn): array {
  return $conn->query("SELECT * FROM `$table` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
};
$before = $snapshot('custom_orders');
$productionBefore = $snapshot('orders');
$expectedIds = [1, 2, 3, 8, 9];
$ids = static function (array $rows): array { return array_map('intval', array_column($rows, 'id')); };

$preview = customOrdersExportedStatusRepairRows($conn);
$assert($ids($preview) === $expectedIds, 'Preview must select only exported orders with an incorrect status');
$assert($snapshot('custom_orders') === $before && $snapshot('custom_order_activity') === [], 'Preview must not write');
$repaired = customOrdersRepairExportedStatuses($conn, 7);
$assert($repaired === $preview, 'Applied rows must match the current preview');
foreach ($snapshot('custom_orders') as $index => $row) {
  $original = $before[$index];
  if (in_array((int) $row['id'], $expectedIds, true)) {
    $assert($row['status'] === 'EXPORTED', 'Wrong repaired status');
    $assert((int) $row['updated_by'] === 7, 'Record the repairing user');
    $assert(customOrdersIsExported($row), 'Repaired order must remain locked');
    foreach (['status', 'updated_by', 'updated_at'] as $field) unset($row[$field], $original[$field]);
  }
  $assert($row === $original, 'Unexpected change to an order or its non-status fields');
}
$assert($snapshot('orders') === $productionBefore, 'Production orders must not change');
$activity = $snapshot('custom_order_activity');
$assert(count($activity) === count($expectedIds), 'Every repair needs exactly one history entry');
foreach ($activity as $entry) {
  $oldRow = $before[(int) $entry['custom_order_id'] - 1];
  $payload = json_decode($entry['payload'], true, 512, JSON_THROW_ON_ERROR);
  $assert($entry['action'] === 'header_updated' && (int) $entry['actor_employee_id'] === 7, 'History action and actor');
  $assert($payload['previous_status'] === $oldRow['status'] && $payload['changes'][0]['to'] === 'EXPORTED', 'History preserves the old status');
}
$after = $snapshot('custom_orders');
$assert(customOrdersExportedStatusRepairRows($conn) === [], 'No incorrect exported statuses may remain');
$assert(customOrdersRepairExportedStatuses($conn, 7) === [], 'Second run must be a no-op');
$assert($snapshot('custom_orders') === $after && $snapshot('custom_order_activity') === $activity, 'Repeated run must not change timestamps or duplicate history');

// Fail on the second audit insert, after the first update + audit succeeded.
$conn->query('ALTER TABLE custom_order_activity ADD UNIQUE KEY test_repair_once (custom_order_id)');
$conn->query('DELETE FROM custom_order_activity WHERE custom_order_id = 1');
$conn->query("UPDATE custom_orders SET status = 'DRAFT_SENT' WHERE id IN (1, 2)");
$failureBefore = $snapshot('custom_orders');
$failureActivity = $snapshot('custom_order_activity');
$failed = false;
try {
  customOrdersRepairExportedStatuses($conn, 7);
} catch (mysqli_sql_exception $e) {
  if ($e->getCode() !== 1062) throw $e;
  $failed = true;
}
$assert($failed, 'Fixture must simulate an audit write failure');
$assert($snapshot('custom_orders') === $failureBefore, 'Failure must roll back all status updates');
$assert($snapshot('custom_order_activity') === $failureActivity, 'Failure must roll back earlier audit inserts');

// A fresh apply rechecks eligibility after a preview (an admin may reset export).
$conn->query('DELETE FROM custom_order_activity WHERE custom_order_id = 2');
$assert($ids(customOrdersExportedStatusRepairRows($conn)) === [1, 2], 'Stale-preview setup');
$conn->query('UPDATE custom_orders SET production_order_id = NULL, exported_at = NULL WHERE id = 2');
$repaired = customOrdersRepairExportedStatuses($conn, null);
$assert($ids($repaired) === [1], 'An export reset after preview must be excluded');
$row = $conn->query('SELECT * FROM custom_orders WHERE id = 1')->fetch_assoc();
$assert((int) $row['updated_by'] === 7, 'CLI repair preserves the previous updated_by');
$assert($conn->query('SELECT actor_employee_id FROM custom_order_activity WHERE custom_order_id = 1')->fetch_row()[0] === null,
  'CLI history must not invent an employee');
$assert($conn->query('SELECT status FROM custom_orders WHERE id = 2')->fetch_row()[0] === 'DRAFT_SENT', 'Reset order remains unchanged');
$assert($snapshot('orders') === $productionBefore, 'Production data must remain unchanged throughout');

echo 'custom_order_exported_status_repair: ', $checks, " checks passed\n";
