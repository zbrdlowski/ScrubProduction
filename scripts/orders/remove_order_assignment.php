<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

if ((int)($_SESSION['permission'] ?? 0) < 300) {
  echo json_encode(['ok' => false, 'error' => 'No permission']);
  exit;
}

$assignmentId = (int)($_POST['assignment_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($assignmentId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'Invalid assignment']);
  exit;
}

$stmt = $conn->prepare("
  SELECT order_id, employee_id, role
  FROM order_assignments
  WHERE id = ?
    AND removed_at IS NULL
  LIMIT 1
");
$stmt->bind_param('i', $assignmentId);
$stmt->execute();
$a = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$a) {
  echo json_encode(['ok' => false, 'error' => 'Assignment not found']);
  exit;
}

$orderId = (int)$a['order_id'];
$employeeId = (int)$a['employee_id'];

$stmt = $conn->prepare("
  UPDATE order_assignments
  SET removed_at = NOW()
  WHERE id = ?
");
$stmt->bind_param('i', $assignmentId);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare("
  UPDATE order_item_assignments oia
  JOIN order_items oi ON oi.id = oia.item_id
  SET oia.removed_at = NOW()
  WHERE oi.order_id = ?
    AND oia.employee_id = ?
    AND oia.removed_at IS NULL
");
$stmt->bind_param('ii', $orderId, $employeeId);
$stmt->execute();
$stmt->close();

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'assignment_removed',
  'assignment',
  $assignmentId,
  [
    'employee_id' => (int)$a['employee_id'],
    'role' => $a['role']
  ],
  'Assignment removed'
);

echo json_encode(['ok' => true]);