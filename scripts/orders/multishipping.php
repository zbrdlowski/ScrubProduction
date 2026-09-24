<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once dirname(__DIR__, 2) . '/includes/orders_multishipping_helpers.php';
require_once __DIR__ . '/activity_helper.php';

function multishippingJson(array $payload, int $status = 200): void
{
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ((int) ($_SESSION['permission'] ?? 0) < 1) {
  multishippingJson(['ok' => false, 'error' => 'No permission.'], 403);
}

try {
  ordersMultishippingEnsureSchema($conn);
} catch (Throwable $e) {
  multishippingJson(['ok' => false, 'error' => $e->getMessage()], 500);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$sessionDept = (int) ($_SESSION['dpt'] ?? 0);
$action = trim((string) ($_REQUEST['action'] ?? 'fetch'));

function multishippingOrderLabel(array $row): string
{
  $label = trim((string) ($row['order_number'] ?? ''));
  return $label !== '' ? $label : trim((string) ($row['external_order_id'] ?? ''));
}

function multishippingLoadOrder(mysqli $conn, int $orderId, bool $forUpdate = false): ?array
{
  $sql = "SELECT o.id, o.order_number, o.external_order_id, o.status, o.customer_id,
      cu.name AS customer_name, cu.email AS customer_email,
      COALESCE(sa.name, cu.name, '') AS ship_name,
      COALESCE(sa.street, '') AS ship_street,
      COALESCE(sa.city, '') AS ship_city,
      COALESCE(sa.zip, '') AS ship_zip,
      COALESCE(sa.country, '') AS ship_country
    FROM orders o
    LEFT JOIN customers cu ON cu.id = o.customer_id
    LEFT JOIN order_addresses sa ON sa.order_id = o.id AND UPPER(sa.type) = 'SHIPPING'
    WHERE o.id = ? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}

function multishippingOrderMatchesShippingScope(mysqli $conn, int $orderId, int $sessionDept): bool
{
  if ($orderId <= 0) {
    return false;
  }

  $scopeWhere = ordersShippingScopeWhereSql($sessionDept, 'o');
  $stmt = $conn->prepare("SELECT 1 FROM orders o WHERE o.id = ? AND $scopeWhere LIMIT 1");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $ok = (bool) $stmt->get_result()->fetch_row();
  $stmt->close();
  return $ok;
}

if ($action === 'fetch') {
  $orderId = (int) ($_GET['order_id'] ?? 0);
  $base = multishippingLoadOrder($conn, $orderId);
  if (!$base) {
    multishippingJson(['ok' => false, 'error' => 'Order not found.'], 404);
  }
  if (!multishippingOrderMatchesShippingScope($conn, $orderId, $sessionDept)) {
    multishippingJson(['ok' => false, 'error' => 'This order belongs to the other shipping workplace.'], 403);
  }

  $group = ordersMultishippingGroupForOrder($conn, $orderId);
  $groupId = (int) ($group['id'] ?? 0);
  $selectedMap = [];
  if ($groupId > 0) {
    foreach (ordersMultishippingMembers($conn, $groupId) as $member) {
      $selectedMap[(int) $member['order_id']] = (int) $member['position'];
    }
  }

  $customerId = (int) ($base['customer_id'] ?? 0);
  $customerEmail = trim((string) ($base['customer_email'] ?? ''));
  $customerName = trim((string) ($base['customer_name'] ?? ''));

  $clauses = ["o.status = 'READY_TO_SHIP'"];
  $types = '';
  $params = [];
  $identityClauses = [];
  if ($customerId > 0) {
    $identityClauses[] = 'o.customer_id = ?';
    $types .= 'i';
    $params[] = $customerId;
  }
  if ($customerEmail !== '') {
    $identityClauses[] = 'LOWER(TRIM(cu.email)) = LOWER(TRIM(?))';
    $types .= 's';
    $params[] = $customerEmail;
  }
  if ($customerName !== '') {
    $identityClauses[] = 'LOWER(TRIM(cu.name)) = LOWER(TRIM(?))';
    $types .= 's';
    $params[] = $customerName;
  }
  if (!$identityClauses) {
    multishippingJson(['ok' => false, 'error' => 'The order has no customer identity to match.'], 409);
  }
  $clauses[] = '(' . implode(' OR ', $identityClauses) . ')';

  $sql = "SELECT o.id, o.order_number, o.external_order_id, o.status, o.customer_id,
      cu.name AS customer_name, cu.email AS customer_email,
      COALESCE(sa.name, cu.name, '') AS ship_name,
      COALESCE(sa.street, '') AS ship_street,
      COALESCE(sa.city, '') AS ship_city,
      COALESCE(sa.zip, '') AS ship_zip,
      COALESCE(sa.country, '') AS ship_country
    FROM orders o
    LEFT JOIN customers cu ON cu.id = o.customer_id
    LEFT JOIN order_addresses sa ON sa.order_id = o.id AND UPPER(sa.type) = 'SHIPPING'
    WHERE " . implode(' AND ', $clauses) . "
    ORDER BY o.id ASC";
  $stmt = $conn->prepare($sql);
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $candidates = [];
  $candidateIds = [];
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $candidateIds[] = (int) $row['id'];
    $candidates[(int) $row['id']] = $row;
  }
  $stmt->close();

  // Keep existing members visible even if their status changed unexpectedly.
  foreach (array_keys($selectedMap) as $selectedId) {
    if (!isset($candidates[$selectedId])) {
      $member = multishippingLoadOrder($conn, $selectedId);
      if ($member) {
        $candidateIds[] = $selectedId;
        $candidates[$selectedId] = $member;
      }
    }
  }

  $groupMap = ordersMultishippingMap($conn, $candidateIds);
  $locks = ordersMultishippingActiveExportLocks($conn, $candidateIds);
  $baseAddressKey = mb_strtolower(implode('|', array_map('trim', [
    (string) ($base['ship_name'] ?? ''), (string) ($base['ship_street'] ?? ''),
    (string) ($base['ship_city'] ?? ''), (string) ($base['ship_zip'] ?? ''),
    (string) ($base['ship_country'] ?? ''),
  ])));
  $output = [];
  foreach ($candidates as $candidateId => $row) {
    $membership = $groupMap[$candidateId] ?? null;
    $otherGroup = $membership && (int) $membership['group_id'] !== $groupId;
    $output[] = [
      'id' => $candidateId,
      'label' => multishippingOrderLabel($row),
      'status' => (string) $row['status'],
      'customer' => (string) ($row['customer_name'] ?: $row['customer_email']),
      'address' => trim(implode(', ', array_filter([
        (string) $row['ship_name'], (string) $row['ship_street'],
        (string) $row['ship_city'], (string) $row['ship_zip'], (string) $row['ship_country'],
      ], static fn($v) => trim($v) !== ''))),
      'selected' => array_key_exists($candidateId, $selectedMap),
      'master' => ($selectedMap[$candidateId] ?? -1) === 0,
      'blocked_group' => $otherGroup,
      'blocked_group_master' => $otherGroup ? (string) ($membership['master_order_number'] ?: $membership['master_external_order_id']) : '',
      'export_locked' => isset($locks[$candidateId]) && (!$group || (int) ($locks[$candidateId]['group_id'] ?? 0) !== $groupId),
      'exported_at' => (string) ($locks[$candidateId]['exported_at'] ?? ''),
      'address_mismatch' => $baseAddressKey !== mb_strtolower(implode('|', array_map('trim', [
        (string) ($row['ship_name'] ?? ''), (string) ($row['ship_street'] ?? ''),
        (string) ($row['ship_city'] ?? ''), (string) ($row['ship_zip'] ?? ''),
        (string) ($row['ship_country'] ?? ''),
      ]))),
    ];
  }

  multishippingJson([
    'ok' => true,
    'group' => $group ? [
      'id' => $groupId,
      'status' => (string) $group['status'],
      'tracking_number' => (string) ($group['tracking_number'] ?? ''),
    ] : null,
    'requested_order_id' => $orderId,
    'candidates' => $output,
  ]);
}

if ($action === 'save') {
  $masterId = (int) ($_POST['master_order_id'] ?? 0);
  $rawIds = $_POST['order_ids'] ?? [];
  if (is_string($rawIds)) {
    $decoded = json_decode($rawIds, true);
    $rawIds = is_array($decoded) ? $decoded : [];
  }
  $orderIds = array_values(array_unique(array_filter(array_map('intval', (array) $rawIds))));
  if ($masterId <= 0 || !in_array($masterId, $orderIds, true) || count($orderIds) < 2) {
    multishippingJson(['ok' => false, 'error' => 'Choose one master and at least one additional order.'], 400);
  }
  sort($orderIds, SORT_NUMERIC);

  $conn->begin_transaction();
  try {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $types = str_repeat('i', count($orderIds));
    $stmt = $conn->prepare("SELECT o.id, o.status, o.customer_id, cu.name AS customer_name, cu.email AS customer_email
      FROM orders o LEFT JOIN customers cu ON cu.id = o.customer_id
      WHERE o.id IN ($placeholders) ORDER BY o.id FOR UPDATE");
    $stmt->bind_param($types, ...$orderIds);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $rows[(int) $row['id']] = $row;
    }
    $stmt->close();
    if (count($rows) !== count($orderIds)) {
      throw new RuntimeException('One or more selected orders no longer exist.');
    }
    foreach ($rows as $row) {
      if (strtoupper((string) $row['status']) !== 'READY_TO_SHIP') {
        throw new RuntimeException('All selected orders must be READY_TO_SHIP.');
      }
    }

    $scopeWhere = ordersShippingScopeWhereSql($sessionDept, 'o');
    $scopeStmt = $conn->prepare("SELECT o.id FROM orders o WHERE o.id IN ($placeholders) AND $scopeWhere");
    $scopeStmt->bind_param($types, ...$orderIds);
    $scopeStmt->execute();
    $scopeRows = [];
    $scopeRes = $scopeStmt->get_result();
    while ($scopeRow = $scopeRes->fetch_assoc()) {
      $scopeRows[(int) $scopeRow['id']] = true;
    }
    $scopeStmt->close();
    if (!$scopeRows) {
      throw new RuntimeException('Selected orders must include at least one order from this shipping workplace.');
    }

    $customerIds = array_values(array_unique(array_filter(array_map(
      static fn($row) => (int) ($row['customer_id'] ?? 0),
      $rows
    ))));
    $customerNames = array_values(array_unique(array_filter(array_map(
      static fn($row) => mb_strtolower(trim((string) ($row['customer_name'] ?? ''))),
      $rows
    ))));
    $customerEmails = array_values(array_unique(array_filter(array_map(
      static fn($row) => mb_strtolower(trim((string) ($row['customer_email'] ?? ''))),
      $rows
    ))));
    $sameCustomerId = count($customerIds) === 1;
    $sameEmail = count($customerEmails) === 1 && count(array_filter($rows, static fn($row) => trim((string) ($row['customer_email'] ?? '')) !== '')) === count($rows);
    $sameName = count($customerNames) === 1 && count(array_filter($rows, static fn($row) => trim((string) ($row['customer_name'] ?? '')) !== '')) === count($rows);
    if (!$sameCustomerId && !$sameEmail && !$sameName) {
      throw new RuntimeException('Selected orders do not belong to the same customer.');
    }

    $memberships = ordersMultishippingMap($conn, $orderIds);
    $existingGroupIds = [];
    foreach ($memberships as $membership) {
      $existingGroupIds[(int) $membership['group_id']] = true;
    }
    if (count($existingGroupIds) > 1) {
      throw new RuntimeException('Selected orders already belong to different multishipping groups.');
    }
    $groupId = $existingGroupIds ? (int) array_key_first($existingGroupIds) : 0;
    if ($groupId > 0) {
      $group = ordersMultishippingGroupForOrder($conn, (int) array_key_first($memberships));
      if (!$group || strtoupper((string) $group['status']) !== 'DRAFT') {
        throw new RuntimeException('This multishipping group is locked because its CSV was already generated.');
      }
    }

    $locks = ordersMultishippingActiveExportLocks($conn, $orderIds);
    if ($locks) {
      $lockedIds = implode(', ', array_map('strval', array_keys($locks)));
      throw new RuntimeException('Orders already included in a FedEx CSV: ' . $lockedIds . '. Release their export lock first.');
    }

    if ($groupId === 0) {
      $stmt = $conn->prepare("INSERT INTO order_multishipping_groups (status, created_by, updated_by)
        VALUES ('DRAFT', ?, ?)");
      $stmt->bind_param('ii', $userId, $userId);
      $stmt->execute();
      $groupId = (int) $conn->insert_id;
      $stmt->close();
    } else {
      $stmt = $conn->prepare('DELETE FROM order_multishipping_orders WHERE group_id = ?');
      $stmt->bind_param('i', $groupId);
      $stmt->execute();
      $stmt->close();
    }

    $orderedIds = array_values(array_diff($orderIds, [$masterId]));
    array_unshift($orderedIds, $masterId);
    $stmt = $conn->prepare('INSERT INTO order_multishipping_orders (group_id, order_id, position) VALUES (?, ?, ?)');
    foreach ($orderedIds as $position => $orderId) {
      $stmt->bind_param('iii', $groupId, $orderId, $position);
      $stmt->execute();
    }
    $stmt->close();

    $stmt = $conn->prepare("UPDATE order_multishipping_groups SET updated_by = ?, status = 'DRAFT' WHERE id = ?");
    $stmt->bind_param('ii', $userId, $groupId);
    $stmt->execute();
    $stmt->close();

    foreach ($orderedIds as $position => $orderId) {
      log_order_activity($conn, $orderId, $userId, 'multishipping_updated', 'multishipping', $groupId, [
        'group_id' => $groupId,
        'master_order_id' => $masterId,
        'order_ids' => $orderedIds,
        'role' => $position === 0 ? 'master' : 'member',
      ], $position === 0 ? 'Multishipping master selected' : 'Added to multishipping');
    }

    $conn->commit();
    multishippingJson(['ok' => true, 'group_id' => $groupId]);
  } catch (Throwable $e) {
    $conn->rollback();
    multishippingJson(['ok' => false, 'error' => $e->getMessage()], 409);
  }
}

if (in_array($action, ['cancel', 'unlock_group'], true)) {
  $orderId = (int) ($_POST['order_id'] ?? 0);
  $conn->begin_transaction();
  try {
    $order = multishippingLoadOrder($conn, $orderId, true);
    $group = ordersMultishippingGroupForOrder($conn, $orderId);
    if (!$order || !$group) {
      throw new RuntimeException('Multishipping group not found.');
    }
    $groupId = (int) $group['id'];
    $members = ordersMultishippingMembers($conn, $groupId);
    $memberIds = array_map(static fn($row) => (int) $row['order_id'], $members);

    if ($action === 'unlock_group') {
      if (strtoupper((string) $group['status']) !== 'EXPORTED') {
        throw new RuntimeException('Only an exported group can be unlocked.');
      }
      ordersMultishippingReleaseExportLocks($conn, $memberIds, $userId);
      $stmt = $conn->prepare("UPDATE order_multishipping_groups
        SET status = 'DRAFT', exported_by = NULL, exported_at = NULL, updated_by = ? WHERE id = ?");
      $stmt->bind_param('ii', $userId, $groupId);
      $stmt->execute();
      $stmt->close();
      foreach ($memberIds as $memberId) {
        log_order_activity($conn, $memberId, $userId, 'multishipping_unlocked', 'multishipping', $groupId, [], 'FedEx export lock released');
      }
    } else {
      if (strtoupper((string) $group['status']) !== 'DRAFT') {
        throw new RuntimeException('Only a draft group can be cancelled. Unlock it first.');
      }
      foreach ($memberIds as $memberId) {
        log_order_activity($conn, $memberId, $userId, 'multishipping_cancelled', 'multishipping', $groupId, [], 'Removed from multishipping');
      }
      $stmt = $conn->prepare('DELETE FROM order_multishipping_groups WHERE id = ?');
      $stmt->bind_param('i', $groupId);
      $stmt->execute();
      $stmt->close();
    }
    $conn->commit();
    multishippingJson(['ok' => true]);
  } catch (Throwable $e) {
    $conn->rollback();
    multishippingJson(['ok' => false, 'error' => $e->getMessage()], 409);
  }
}

if ($action === 'unlock_order') {
  $orderId = (int) ($_POST['order_id'] ?? 0);
  $conn->begin_transaction();
  try {
    $order = multishippingLoadOrder($conn, $orderId, true);
    if (!$order || strtoupper((string) $order['status']) !== 'READY_TO_SHIP') {
      throw new RuntimeException('Only a READY_TO_SHIP order can be unlocked.');
    }
    if (ordersMultishippingGroupForOrder($conn, $orderId)) {
      throw new RuntimeException('Use Unlock group for a multishipping order.');
    }
    ordersMultishippingReleaseExportLocks($conn, [$orderId], $userId);
    log_order_activity($conn, $orderId, $userId, 'fedex_export_unlocked', 'order', $orderId, [], 'FedEx export lock released');
    $conn->commit();
    multishippingJson(['ok' => true]);
  } catch (Throwable $e) {
    $conn->rollback();
    multishippingJson(['ok' => false, 'error' => $e->getMessage()], 409);
  }
}

multishippingJson(['ok' => false, 'error' => 'Unknown action.'], 400);
