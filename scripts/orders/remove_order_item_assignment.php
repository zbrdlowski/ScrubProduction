<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$permission = (int) ($_SESSION['permission'] ?? 0);
$assignmentId = (int) ($_POST['assignment_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

if ($assignmentId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid assignment id']);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, order_id, item_id, employee_id, assignment_role
    FROM order_item_assignments
    WHERE id = ?
      AND removed_at IS NULL
    LIMIT 1
");
$stmt->bind_param('i', $assignmentId);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assignment) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Assignment not found']);
    exit;
}

$employeeId = (int) $assignment['employee_id'];
if ($permission < 300 && $employeeId !== $userId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No permission']);
    exit;
}

$stmt = $conn->prepare("
    UPDATE order_item_assignments
    SET removed_at = NOW()
    WHERE id = ?
      AND removed_at IS NULL
");
$stmt->bind_param('i', $assignmentId);
$stmt->execute();
$stmt->close();

$orderId = (int) $assignment['order_id'];
$itemId = (int) $assignment['item_id'];

log_order_activity(
    $conn,
    $orderId,
    $userId,
    'item_assignment_removed',
    'order_item',
    $itemId,
    [
        'employee_id' => $employeeId,
        'item_assignment_id' => $assignmentId,
        'assignment_role' => (string) ($assignment['assignment_role'] ?? 'WORKER'),
    ],
    'Item assignment removed'
);

echo json_encode([
    'ok' => true,
    'order_id' => $orderId,
    'item_id' => $itemId,
], JSON_UNESCAPED_UNICODE);
