<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/../../includes/render_assigned_users.php';
require_once __DIR__ . '/../../includes/order_item_assignment_helpers.php';
require_once __DIR__ . '/activity_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$permission = (int) ($_SESSION['permission'] ?? 0);
$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$itemId = (int) ($_POST['item_id'] ?? 0);
$orderAssignmentId = (int) ($_POST['order_assignment_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

if ($assignmentId <= 0 && ($itemId <= 0 || $orderAssignmentId <= 0)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid assignment id']);
    exit;
}

$assignment = null;
if ($assignmentId > 0) {
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
}

if ($assignment) {
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
        'avatars_html' => render_assigned_users_html($conn, $orderId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($itemId <= 0 || $orderAssignmentId <= 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Assignment not found']);
    exit;
}

$stmt = $conn->prepare("
    SELECT
        oa.id,
        oa.order_id,
        oa.employee_id,
        oa.role,
        oi.item_type_code
    FROM order_assignments oa
    JOIN order_items oi
      ON oi.order_id = oa.order_id
     AND oi.id = ?
     AND oi.deleted_at IS NULL
    WHERE oa.id = ?
      AND oa.removed_at IS NULL
    LIMIT 1
");
$stmt->bind_param('ii', $itemId, $orderAssignmentId);
$stmt->execute();
$orderAssignment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$orderAssignment) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Assignment not found']);
    exit;
}

$orderId = (int) $orderAssignment['order_id'];
$employeeId = (int) $orderAssignment['employee_id'];

if ($permission < 300 && $employeeId !== $userId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No permission']);
    exit;
}

$assignmentDeptCode = (string) preg_replace('/^(PRIMARY_|COLLAB_)/', '', (string) $orderAssignment['role']);
$itemDeptCode = orderItemDepartmentCode((string) ($orderAssignment['item_type_code'] ?? ''));

if ($assignmentDeptCode === '' || $itemDeptCode === '' || $assignmentDeptCode !== $itemDeptCode) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Assignment does not belong to this item']);
    exit;
}

$assignmentRole = 'PREPARED';
$stmt = $conn->prepare("
    INSERT INTO order_item_assignments
        (order_id, item_id, employee_id, assignment_role, assigned_by, assigned_at, removed_at)
    VALUES
        (?, ?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        order_id = VALUES(order_id),
        assigned_by = VALUES(assigned_by),
        assigned_at = NOW(),
        removed_at = NOW()
");
$stmt->bind_param('iiisi', $orderId, $itemId, $employeeId, $assignmentRole, $userId);
$stmt->execute();
$stmt->close();

log_order_activity(
    $conn,
    $orderId,
    $userId,
    'item_assignment_removed',
    'order_item',
    $itemId,
    [
        'employee_id' => $employeeId,
        'order_assignment_id' => $orderAssignmentId,
        'assignment_role' => $assignmentRole,
    ],
    'Item assignment removed'
);

echo json_encode([
    'ok' => true,
    'order_id' => $orderId,
    'item_id' => $itemId,
    'avatars_html' => render_assigned_users_html($conn, $orderId),
], JSON_UNESCAPED_UNICODE);
