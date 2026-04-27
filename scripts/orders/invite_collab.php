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

$orderId = (int)($_POST['order_id'] ?? 0);
$employeeIdToInvite = (int)($_POST['employee_id'] ?? 0);

if ($orderId <= 0 || $employeeIdToInvite <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Bad input']);
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
  echo json_encode(['ok'=>false,'error'=>'This department cannot invite']);
  exit;
}

$rolePrimary = 'PRIMARY_' . $deptCode;
$roleCollab  = 'COLLAB_'  . $deptCode;

try {
  $conn->begin_transaction();

  // allow invite if: (a) user is PRIMARY for this dept, OR (b) admin/moderator permission
  if ($perm < 400) {
    $chk = $conn->prepare("SELECT 1 FROM order_assignments
                           WHERE order_id=? AND role=? AND employee_id=? AND removed_at IS NULL
                           LIMIT 1");
    $chk->bind_param('isi', $orderId, $rolePrimary, $userId);
    $chk->execute();
    $isPrimary = (bool)$chk->get_result()->fetch_row();
    $chk->close();

    if (!$isPrimary) {
      $conn->rollback();
      http_response_code(403);
      echo json_encode(['ok'=>false,'error'=>'Only PRIMARY can invite']);
      exit;
    }
  }

  // insert or update via uq_order_employee
  $sql = "INSERT INTO order_assignments
            (order_id, employee_id, role, state, invited_by)
          VALUES (?, ?, ?, 'INVITED', ?)
          ON DUPLICATE KEY UPDATE
            role=VALUES(role),
            state='INVITED',
            invited_by=VALUES(invited_by),
            removed_at=NULL,
            accepted_at=NULL";

  $st = $conn->prepare($sql);
  $st->bind_param('iisi', $orderId, $employeeIdToInvite, $roleCollab, $userId);
  $st->execute();
  $st->close();

  $conn->commit();
  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Server error: '.$e->getMessage()]);
}
?>