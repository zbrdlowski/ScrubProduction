<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$itemId = (int) ($_POST['custom_item_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

$finish = static function (bool $ok, string $message) use ($orderId, $itemId): void {
  if (!$ok) {
    http_response_code(422);
  }
  echo json_encode([
    'ok' => $ok,
    'message' => $message,
    'custom_order_id' => $orderId,
    'custom_item_id' => $itemId,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
};

if ($orderId <= 0 || $itemId <= 0 || $userId <= 0) {
  $finish(false, 'Invalid item take request.');
}

if (!customOrdersTableExists($conn, 'custom_order_item_assignments')) {
  customOrdersEnsureSchema($conn);
}

$stmt = $conn->prepare("
  SELECT coi.id, coi.custom_order_id, coi.item_type_code, coi.sku, coi.title,
         cia.employee_id AS assigned_employee_id,
         TRIM(CONCAT_WS(' ', e.firstname, e.lastname)) AS assigned_employee_name
  FROM custom_order_items coi
  LEFT JOIN custom_order_item_assignments cia ON cia.custom_order_item_id = coi.id
  LEFT JOIN employees e ON e.id = cia.employee_id
  WHERE coi.id = ? AND coi.custom_order_id = ?
  LIMIT 1
");
if (!$stmt) {
  $finish(false, 'Item lookup could not be prepared.');
}
$stmt->bind_param('ii', $itemId, $orderId);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$item) {
  $finish(false, 'Custom item not found.');
}

$assignedEmployeeId = (int) ($item['assigned_employee_id'] ?? 0);
if ($assignedEmployeeId > 0 && $assignedEmployeeId !== $userId) {
  $assignedName = trim((string) ($item['assigned_employee_name'] ?? 'another user'));
  $finish(false, 'This item is already taken by ' . ($assignedName !== '' ? $assignedName : 'another user') . '.');
}

if ($assignedEmployeeId === $userId) {
  $finish(true, 'Item already taken by you.');
}

$stmt = $conn->prepare('
  INSERT INTO custom_order_item_assignments
    (custom_order_id, custom_order_item_id, employee_id, assigned_by)
  VALUES
    (?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
    employee_id = IF(employee_id = VALUES(employee_id), VALUES(employee_id), employee_id),
    assigned_by = IF(employee_id = VALUES(employee_id), VALUES(assigned_by), assigned_by),
    assigned_at = IF(employee_id = VALUES(employee_id), NOW(), assigned_at)
');
if (!$stmt) {
  $finish(false, 'Item take could not be prepared.');
}
$stmt->bind_param('iiii', $orderId, $itemId, $userId, $userId);
$stmt->execute();
$stmt->close();

$checkStmt = $conn->prepare('SELECT employee_id FROM custom_order_item_assignments WHERE custom_order_item_id = ? LIMIT 1');
$checkStmt->bind_param('i', $itemId);
$checkStmt->execute();
$assignedNow = (int) ($checkStmt->get_result()->fetch_assoc()['employee_id'] ?? 0);
$checkStmt->close();
if ($assignedNow !== $userId) {
  $finish(false, 'This item was taken by another user.');
}

customOrdersLog(
  $conn,
  $orderId,
  'item_taken',
  $userId,
  [
    'item_id' => $itemId,
    'employee_id' => $userId,
    'item_type_code' => (string) ($item['item_type_code'] ?? ''),
    'sku' => (string) ($item['sku'] ?? ''),
    'title' => (string) ($item['title'] ?? ''),
  ],
  'Custom order item taken'
);

$finish(true, 'Item taken.');