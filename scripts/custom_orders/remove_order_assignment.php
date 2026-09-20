<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$orderId = (int) ($_POST['custom_order_id'] ?? $_POST['order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$permission = (int) ($_SESSION['permission'] ?? 0);

$finish = static function (bool $ok, string $message, array $extra = []) use (&$orderId): void {
  if (!$ok) {
    http_response_code(422);
  }
  echo json_encode(array_merge([
    'ok' => $ok,
    'message' => $message,
    'custom_order_id' => $orderId,
  ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
};

if ($assignmentId <= 0 || $userId <= 0 || $permission < 1) {
  $finish(false, 'Invalid assignment remove request.');
}

if (!customOrdersTableExists($conn, 'custom_order_assignments')) {
  $finish(false, 'Assignment not found.');
}

$stmt = $conn->prepare('
  SELECT
    coa.id,
    coa.custom_order_id,
    coa.employee_id,
    TRIM(CONCAT_WS(\' \', e.firstname, e.lastname)) AS employee_name
  FROM custom_order_assignments coa
  LEFT JOIN employees e ON e.id = coa.employee_id
  WHERE coa.id = ?
  LIMIT 1
');
if (!$stmt) {
  $finish(false, 'Assignment lookup could not be prepared.');
}
$stmt->bind_param('i', $assignmentId);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$assignment) {
  $finish(false, 'Assignment not found.');
}

$orderId = (int) ($assignment['custom_order_id'] ?? 0);
$employeeId = (int) ($assignment['employee_id'] ?? 0);

if ($permission < 300 && $employeeId !== $userId) {
  $finish(false, 'No permission to remove this assignment.');
}

$stmt = $conn->prepare('DELETE FROM custom_order_assignments WHERE id = ? LIMIT 1');
if (!$stmt) {
  $finish(false, 'Assignment remove could not be prepared.');
}
$stmt->bind_param('i', $assignmentId);
$stmt->execute();
$removed = $stmt->affected_rows > 0;
$stmt->close();

if (!$removed) {
  $finish(false, 'Assignment could not be removed.');
}

customOrdersLog(
  $conn,
  $orderId,
  'order_assignment_removed',
  $userId,
  [
    'assignment_id' => $assignmentId,
    'employee_id' => $employeeId,
    'employee_name' => (string) ($assignment['employee_name'] ?? ''),
  ],
  'Custom order assignment removed'
);

$finish(true, 'Assignment removed.', [
  'assignment_html' => customOrdersRenderOrderAssignmentHtml([], $orderId, true, $userId),
]);
