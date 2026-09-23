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
require_once __DIR__ . '/../../includes/order_item_assignment_helpers.php';
require_once __DIR__ . '/../../includes/orders_plastics_gate_helpers.php';
require_once __DIR__ . '/activity_helper.php';

$orderId = (int)($_POST['order_id'] ?? 0);
$itemId = (int)($_POST['item_id'] ?? 0);
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

function takeOrderAssignmentConflictPayload(
  mysqli $conn,
  int $orderId,
  string $deptCode,
  int $perm,
  int $userId,
  string $error,
  string $conflictCode = '',
  array $extra = []
): array {
  return array_merge([
    'ok' => false,
    'error' => $error,
    'conflict_code' => $conflictCode,
    'order_id' => $orderId,
    'dept_code' => $deptCode,
    'avatars_html' => render_assigned_users_html($conn, $orderId),
    'take_assign_html' => render_order_take_assign_html($conn, $orderId, $deptCode, $perm, $userId),
  ], $extra);
}

try {
  $conn->begin_transaction();

  // lock order row to avoid two takes at once
  $lock = $conn->prepare("SELECT id, status FROM orders WHERE id=? FOR UPDATE");
  $lock->bind_param('i', $orderId);
  $lock->execute();
  $orderRow = $lock->get_result()->fetch_assoc();
  $lock->close();
  if (!$orderRow) {
    $conn->rollback();
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Order not found']);
    exit;
  }

  if (ordersPlasticsGateHasBlockedDependants($conn, $orderId)) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>'Finish plastics stock check before taking this order']);
    exit;
  }

  if ($itemId > 0) {
    $itemStmt = $conn->prepare("
      SELECT id, order_id, item_type_code, sku, title
      FROM order_items
      WHERE id = ?
        AND order_id = ?
        AND deleted_at IS NULL
      LIMIT 1
      FOR UPDATE
    ");
    $itemStmt->bind_param('ii', $itemId, $orderId);
    $itemStmt->execute();
    $itemRow = $itemStmt->get_result()->fetch_assoc();
    $itemStmt->close();

    if (!$itemRow) {
      $conn->rollback();
      http_response_code(404);
      echo json_encode(['ok'=>false,'error'=>'Item not found']);
      exit;
    }

    $itemTypeCode = strtoupper(trim((string)($itemRow['item_type_code'] ?? '')));
    $itemDeptCode = orderItemDepartmentCode($itemTypeCode);

    if ($itemDeptCode === '' || $itemDeptCode !== $deptCode) {
      $conn->rollback();
      http_response_code(403);
      echo json_encode(['ok'=>false,'error'=>'This item belongs to another department']);
      exit;
    }

    $itemTaken = $conn->prepare("
      SELECT oia.employee_id,
             CONCAT(e.firstname,' ',e.lastname) AS emp_name,
             oia.assignment_role
      FROM order_item_assignments oia
      JOIN employees e ON e.id = oia.employee_id
      WHERE oia.item_id = ?
        AND oia.assignment_role IN ('PREPARED', 'CHECKED')
        AND oia.removed_at IS NULL
        AND oia.employee_id <> ?
      ORDER BY
        CASE oia.assignment_role
          WHEN 'PREPARED' THEN 1
          WHEN 'CHECKED' THEN 2
          ELSE 3
        END,
        oia.id
      LIMIT 1
      FOR UPDATE
    ");
    $itemTaken->bind_param('ii', $itemId, $userId);
    $itemTaken->execute();
    $itemTakenRow = $itemTaken->get_result()->fetch_assoc();
    $itemTaken->close();

    if ($itemTakenRow) {
      $conn->rollback();
      http_response_code(409);
      $takenByName = trim((string)$itemTakenRow['emp_name']);
      echo json_encode(takeOrderAssignmentConflictPayload(
        $conn,
        $orderId,
        $deptCode,
        $perm,
        $userId,
        $takenByName !== ''
          ? 'Túto položku medzitým prevzal(a) ' . $takenByName . '.'
          : 'Túto položku medzitým prevzal niekto iný.',
        'ITEM_ALREADY_TAKEN',
        [
          'item_id' => $itemId,
          'taken_by' => (int)$itemTakenRow['employee_id'],
          'taken_by_name' => $takenByName,
          'assignment_role' => (string)$itemTakenRow['assignment_role'],
        ]
      ), JSON_UNESCAPED_UNICODE);
      exit;
    }

    $itemAssignmentChanged = false;
    if (!$itemTakenRow) {
      $itemAssignmentChanged = orderItemSetRoleAssignment($conn, $orderId, $itemId, $userId, 'PREPARED');
    }

    $upd = $conn->prepare("
      UPDATE orders
      SET status = 'IN_PROGRESS'
      WHERE id = ?
        AND status = 'NEW'
    ");
    $upd->bind_param('i', $orderId);
    $upd->execute();
    $upd->close();

    if ($itemAssignmentChanged) {
      log_order_activity(
        $conn,
        $orderId,
        $userId,
        'order_item_taken',
        'order_item',
        $itemId,
        [
          'role' => $rolePrimary,
          'item_type_code' => $itemTypeCode,
          'sku' => $itemRow['sku'],
          'title' => $itemRow['title']
        ],
        'Order item taken'
      );
    }

    $conn->commit();
    echo json_encode([
      'ok' => true,
      'role' => $rolePrimary,
      'order_id' => $orderId,
      'item_id' => $itemId,
      'dept_code' => $deptCode,
      'avatars_html' => render_assigned_users_html($conn, $orderId),
      'take_assign_html' => render_order_take_assign_html($conn, $orderId, $deptCode, $perm, $userId),
    ], JSON_UNESCAPED_UNICODE);
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
    http_response_code(409);
    $takenByName = trim((string)$row['emp_name']);
    echo json_encode(takeOrderAssignmentConflictPayload(
      $conn,
      $orderId,
      $deptCode,
      $perm,
      $userId,
      $takenByName !== ''
        ? 'Objednávku už prevzal(a) ' . $takenByName . '.'
        : 'Objednávku už prevzal niekto iný.',
      'ORDER_ALREADY_TAKEN',
      [
        'taken_by' => (int)$row['employee_id'],
        'taken_by_name' => $takenByName,
      ]
    ), JSON_UNESCAPED_UNICODE);
    exit;
  }

  $itemAssignmentState = orderItemWorkflowAssignmentState($conn, $orderId, $deptCode, $userId);
  $blockedItems = $itemAssignmentState['other_items'] ?? [];
  if ($blockedItems) {
    $freeItems = $itemAssignmentState['free_items'] ?? [];
    $firstBlocked = reset($blockedItems);
    $blockingAssignment = is_array($firstBlocked) ? ($firstBlocked['blocking_assignment'] ?? []) : [];
    $takenByName = trim((string)($blockingAssignment['employee_name'] ?? ''));

    $conn->rollback();
    http_response_code(409);
    echo json_encode(takeOrderAssignmentConflictPayload(
      $conn,
      $orderId,
      $deptCode,
      $perm,
      $userId,
      $freeItems
        ? 'Objednávku nie je možné prevziať celú. Niektoré položky už niekto vzal; prevezmi jednu z voľných položiek v detaile.'
        : 'Objednávku nie je možné prevziať. Na položkách už niekto pracuje.',
      $freeItems ? 'FREE_ITEMS_AVAILABLE' : 'ORDER_ITEMS_ALREADY_TAKEN',
      [
        'free_item_count' => count($freeItems),
        'blocked_item_count' => count($blockedItems),
        'taken_by_name' => $takenByName,
      ]
    ), JSON_UNESCAPED_UNICODE);
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

  foreach (($itemAssignmentState['items'] ?? []) as $orderItemId => $_itemRow) {
    orderItemSetRoleAssignment($conn, $orderId, (int)$orderItemId, $userId, 'PREPARED');
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
