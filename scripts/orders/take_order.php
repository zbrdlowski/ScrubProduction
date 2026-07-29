<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['permission'])) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Not logged in']);
  exit;
}

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/../../includes/render_assigned_users.php';
require_once __DIR__ . '/activity_helper.php';

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Invalid order_id']);
  exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$perm  = (int)($_SESSION['permission'] ?? 0);
$dpt   = (int)($_SESSION['dpt'] ?? 0);

$deptMap = [
  2 => 'GRAPHICS',
  6 => 'PLASTICS',
  8 => 'SEATCOVER',
  9 => 'FITTING',
];

$sessionDeptCode = $deptMap[$dpt] ?? null;
$deptCode = $sessionDeptCode;
$postDeptCode = strtoupper(trim((string)($_POST['dept_code'] ?? '')));
$validDeptCodes = ['GRAPHICS', 'PLASTICS', 'SEATCOVER', 'FITTING'];

if (in_array($postDeptCode, $validDeptCodes, true)) {
  if ($perm >= 400 || $postDeptCode === $sessionDeptCode || $postDeptCode === 'FITTING') {
    $deptCode = $postDeptCode;
  }
}

// Fitting môže vziať ktokoľvek — ak objednávka je Fitting type, povolíme
// Departmentový check prebehne až po načítaní objednávky
if (!$deptCode) {
  // Skontrolujeme či je objednávka Fitting — ak áno, povolíme
  $fittingCheck = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM order_items
    WHERE order_id = ? AND item_type_code = 'F' AND deleted_at IS NULL
  ");
  $fittingCheck->bind_param('i', $orderId);
  $fittingCheck->execute();
  $fittingRow = $fittingCheck->get_result()->fetch_assoc();
  $fittingCheck->close();

  if ((int)($fittingRow['cnt'] ?? 0) === 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'This department cannot take orders']);
    exit;
  }
  // Je to Fitting objednávka — nastavíme deptCode
  $deptCode = 'FITTING';
}

if ($perm < 400 && $deptCode !== $sessionDeptCode && $deptCode !== 'FITTING') {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'This department cannot take orders']);
  exit;
}

if ($deptCode === 'FITTING') {
  $empCheck = $conn->prepare("
    SELECT active, personal_orders
    FROM employees
    WHERE id = ?
    LIMIT 1
  ");
  $empCheck->bind_param('i', $userId);
  $empCheck->execute();
  $emp = $empCheck->get_result()->fetch_assoc();
  $empCheck->close();

  if (!$emp || (string)$emp['active'] !== 'Active' || (int)($emp['personal_orders'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You are not enabled for personal orders']);
    exit;
  }

  $fittingCheck = $conn->prepare("
    SELECT 1
    FROM order_items
    WHERE order_id = ?
      AND item_type_code = 'F'
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $fittingCheck->bind_param('i', $orderId);
  $fittingCheck->execute();
  $hasFitting = (bool)$fittingCheck->get_result()->fetch_row();
  $fittingCheck->close();

  if (!$hasFitting) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'This order has no fitting item']);
    exit;
  }
}

$rolePrimary = 'PRIMARY_' . $deptCode;

try {
  $conn->begin_transaction();

  // lock order row to avoid two takes at once
  $lock = $conn->prepare("SELECT id FROM orders WHERE id=? FOR UPDATE");
  $lock->bind_param('i', $orderId);
  $lock->execute();
  $ok = (bool)$lock->get_result()->fetch_row();
  $lock->close();
  if (!$ok) {
    $conn->rollback();
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Order not found']);
    exit;
  }

  // check existing primary
  $q = $conn->prepare("SELECT oa.employee_id,
                              CONCAT(e.firstname,' ',e.lastname) AS emp_name
                       FROM order_assignments oa
                       JOIN employees e ON e.id=oa.employee_id
                       WHERE oa.order_id=? AND oa.role=? AND oa.removed_at IS NULL
                       LIMIT 1");
  $q->bind_param('is', $orderId, $rolePrimary);
  $q->execute();
  $row = $q->get_result()->fetch_assoc();
  $q->close();

  if ($row) {
    $conn->rollback();
    echo json_encode([
      'ok'=>false,
      'error'=>'Already taken',
      'taken_by'=>(int)$row['employee_id'],
      'taken_by_name'=>(string)$row['emp_name']
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $roleCollab = 'COLLAB_' . $deptCode;

$rm = $conn->prepare("
  UPDATE order_assignments
  SET removed_at = NOW()
  WHERE order_id = ?
    AND employee_id = ?
    AND role = ?
    AND removed_at IS NULL
");
$rm->bind_param('iis', $orderId, $userId, $roleCollab);
$rm->execute();
$rm->close();

  // insert assignment (uq_order_employee prevents duplicates for same employee+order)
  $ins = $conn->prepare("INSERT INTO order_assignments
        (order_id, employee_id, role, state, assigned_by)
        VALUES (?, ?, ?, 'ASSIGNED', ?)");
  $ins->bind_param('iisi', $orderId, $userId, $rolePrimary, $userId);
  $ins->execute();
  $ins->close();

  $itemTypeMap = [
  'GRAPHICS' => 'G',
  'PLASTICS' => 'P',
  'SEATCOVER' => 'S',
  'FITTING' => 'F',
];

$itemType = $itemTypeMap[$deptCode] ?? '';

if ($itemType !== '') {
$stmtItems = $conn->prepare("
  SELECT id
  FROM order_items
  WHERE order_id = ?
    AND deleted_at IS NULL
    AND item_type_code = ?
");
$stmtItems->bind_param('is', $orderId, $itemType);
$stmtItems->execute();
$itemsRes = $stmtItems->get_result();

$itemIds = [];
while ($itemRow = $itemsRes->fetch_assoc()) {
  $itemIds[] = (int)$itemRow['id'];
}
$stmtItems->close();

if (count($itemIds) === 1) {
  $orderItemId = $itemIds[0];

  $stmtAssignItem = $conn->prepare("
    INSERT INTO order_item_assignments
      (order_id, item_id, employee_id, assigned_by)
    VALUES
      (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      removed_at = NULL,
      assigned_by = VALUES(assigned_by),
      assigned_at = NOW()
  ");
  $stmtAssignItem->bind_param('iiii', $orderId, $orderItemId, $userId, $userId);
  $stmtAssignItem->execute();
  $stmtAssignItem->close();
}
}

  // optional activity log 
$upd = $conn->prepare("
  UPDATE orders
  SET status = 'IN_PROGRESS'
  WHERE id = ?
    AND status = 'NEW'
");
$upd->bind_param('i', $orderId);
$upd->execute();
$upd->close();

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'order_taken',
  'assignment',
  0,
  [
    'role' => $rolePrimary
  ],
  'Order taken'
);

  $conn->commit();
  echo json_encode([
    'ok' => true,
    'role' => $rolePrimary,
    'order_id' => $orderId,
    'dept_code' => $deptCode,
    'avatars_html' => render_assigned_users_html($conn, $orderId),
    'take_assign_html' => render_order_take_assign_html($conn, $orderId, $deptCode, $perm, $userId),
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Server error: '.$e->getMessage()]);
}
?>