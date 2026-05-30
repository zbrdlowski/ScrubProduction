<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['ok' => false, 'error' => 'Not logged in']);
  exit;
}

$itemId = (int)($_POST['item_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$dpt = (int)($_SESSION['dpt'] ?? 0);

$deptTypeMap = [
  2 => 'G',
  6 => 'P',
  8 => 'S',
  9 => 'F',
];

$perm = (int)($_SESSION['permission'] ?? 0);
$isAdmin = $perm >= 400;

// Admin môže assignovať kohokoľvek — department check preskočíme
// Admin nemá department — fallback na null, typ sa nekontroluje nižšie
$expectedType = $deptTypeMap[$dpt] ?? null;

$stmt = $conn->prepare("
  SELECT id, order_id, item_type_code, sku, title
  FROM order_items
  WHERE id = ?
    AND deleted_at IS NULL
  LIMIT 1
");
$stmt->bind_param('i', $itemId);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) {
  echo json_encode(['ok' => false, 'error' => 'Item not found']);
  exit;
}

$orderId  = (int)$item['order_id'];
$itemType = strtoupper((string)$item['item_type_code']);

if (!$isAdmin && $itemType !== 'F' && !isset($deptTypeMap[$dpt])) {
  echo json_encode(['ok' => false, 'error' => 'This department cannot assign items']);
  exit;
}

if (!$isAdmin && $itemType === 'F') {
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
    echo json_encode(['ok' => false, 'error' => 'You are not enabled for personal orders']);
    exit;
  }
}

// Kontrola departmentu:
// - Admin (perm >= 400): môže na akúkoľvek položku
// - Fitting položka (F): môže ktokoľvek
// - Inak: musí sedieť s departmentom
if (!$isAdmin && $itemType !== 'F' && $itemType !== $expectedType) {
  echo json_encode(['ok' => false, 'error' => 'This item belongs to another department']);
  exit;
}

if ($perm < 400) {
  $deptRoleMap = [
    2 => ['PRIMARY_GRAPHICS',  'COLLAB_GRAPHICS'],
    6 => ['PRIMARY_PLASTICS',  'COLLAB_PLASTICS'],
    8 => ['PRIMARY_SEATCOVER', 'COLLAB_SEATCOVER'],
    9 => ['PRIMARY_FITTING',   'COLLAB_FITTING'],
  ];

  $allowedRoles = $deptRoleMap[$dpt] ?? [];

  // Pre Fitting môže ktokoľvek — aj bez order assignment
  if ($itemType === 'F') {
    $allowedRoles = [
      'PRIMARY_GRAPHICS',  'COLLAB_GRAPHICS',
      'PRIMARY_PLASTICS',  'COLLAB_PLASTICS',
      'PRIMARY_SEATCOVER', 'COLLAB_SEATCOVER',
      'PRIMARY_FITTING',   'COLLAB_FITTING',
    ];
  }

  // Pre Fitting: namiesto order_assignment check stačí byť prihlásený
  if ($itemType === 'F') {
    // skip order assignment check — môže ktokoľvek
  } else {
    $placeholders = implode(',', array_fill(0, count($allowedRoles), '?'));
    $types  = 'ii' . str_repeat('s', count($allowedRoles));
    $params = array_merge([$orderId, $userId], $allowedRoles);

    $stmt = $conn->prepare("
      SELECT 1
      FROM order_assignments
      WHERE order_id = ?
        AND employee_id = ?
        AND role IN ($placeholders)
        AND removed_at IS NULL
      LIMIT 1
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $hasOrderAssignment = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();

    if (!$hasOrderAssignment) {
      echo json_encode(['ok' => false, 'error' => 'You must be assigned or invited to this order first']);
      exit;
    }
  }
}

$stmt = $conn->prepare("
  INSERT INTO order_item_assignments
    (order_id, item_id, employee_id, assigned_by)
  VALUES
    (?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
    removed_at = NULL,
    assigned_by = VALUES(assigned_by),
    assigned_at = NOW()
");
$stmt->bind_param('iiii', $orderId, $itemId, $userId, $userId);
$stmt->execute();
$stmt->close();

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'item_assigned',
  'order_item',
  $itemId,
  [
    'employee_id' => $userId,
    'item_type_code' => $itemType,
    'sku' => $item['sku'],
    'title' => $item['title']
  ],
  'User assigned to item'
);

echo json_encode(['ok' => true]);
