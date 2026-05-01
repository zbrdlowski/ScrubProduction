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

$deptCode = $deptMap[$dpt] ?? null;
if (!$deptCode) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'This department cannot take orders']);
  exit;
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

  // insert assignment (uq_order_employee prevents duplicates for same employee+order)
  $ins = $conn->prepare("INSERT INTO order_assignments
        (order_id, employee_id, role, state, assigned_by)
        VALUES (?, ?, ?, 'ASSIGNED', ?)");
  $ins->bind_param('iisi', $orderId, $userId, $rolePrimary, $userId);
  $ins->execute();
  $ins->close();

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
  echo json_encode(['ok'=>true,'role'=>$rolePrimary], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Server error: '.$e->getMessage()]);
}
?>