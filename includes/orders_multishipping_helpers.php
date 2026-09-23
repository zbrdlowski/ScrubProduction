<?php
declare(strict_types=1);

/**
 * Combined shipping ("multishipping") helpers.
 *
 * A group represents one physical parcel. Position 0 is always the master
 * order used in the FedEx CSV/EOD reference; positions 1..n are its members.
 */

function ordersMultishippingEnsureSchema(mysqli $conn): void
{
  static $ready = false;
  if ($ready) {
    return;
  }

  $queries = [
    "CREATE TABLE IF NOT EXISTS order_multishipping_groups (
      id BIGINT NOT NULL AUTO_INCREMENT,
      status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
      tracking_number VARCHAR(120) DEFAULT NULL,
      created_by INT DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_by INT DEFAULT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      exported_by INT DEFAULT NULL,
      exported_at DATETIME DEFAULT NULL,
      shipped_at DATETIME DEFAULT NULL,
      PRIMARY KEY (id),
      KEY ix_multishipping_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS order_multishipping_orders (
      group_id BIGINT NOT NULL,
      order_id BIGINT NOT NULL,
      position INT NOT NULL DEFAULT 0,
      added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (group_id, order_id),
      UNIQUE KEY uq_multishipping_order (order_id),
      UNIQUE KEY uq_multishipping_position (group_id, position),
      KEY ix_multishipping_group (group_id),
      CONSTRAINT fk_multishipping_group FOREIGN KEY (group_id)
        REFERENCES order_multishipping_groups(id) ON DELETE CASCADE,
      CONSTRAINT fk_multishipping_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS order_fedex_export_locks (
      order_id BIGINT NOT NULL,
      group_id BIGINT DEFAULT NULL,
      export_token VARCHAR(64) NOT NULL,
      exported_by INT DEFAULT NULL,
      exported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      active TINYINT(1) NOT NULL DEFAULT 1,
      released_by INT DEFAULT NULL,
      released_at DATETIME DEFAULT NULL,
      PRIMARY KEY (order_id),
      KEY ix_fedex_export_active (active, exported_at),
      KEY ix_fedex_export_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  ];

  foreach ($queries as $sql) {
    if (!$conn->query($sql)) {
      throw new RuntimeException('Unable to initialize multishipping storage: ' . $conn->error);
    }
  }

  $ready = true;
}

function ordersShippingContainsPlasticsWhereSql(string $orderAlias = 'o'): string
{
  if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $orderAlias)) {
    throw new InvalidArgumentException('Invalid order SQL alias.');
  }

  $directOrderSql = ordersShippingOrderContainsPlasticsWhereSql($orderAlias . '.id');
  $memberOrderSql = ordersShippingOrderContainsPlasticsWhereSql('osms_member.order_id');

  return "(
    $directOrderSql
    OR EXISTS (
      SELECT 1
      FROM order_multishipping_orders osms_current
      JOIN order_multishipping_groups osmsg_scope
        ON osmsg_scope.id = osms_current.group_id
        AND osmsg_scope.status <> 'CANCELLED'
      JOIN order_multishipping_orders osms_member
        ON osms_member.group_id = osms_current.group_id
      WHERE osms_current.order_id = {$orderAlias}.id
        AND $memberOrderSql
    )
  )";
}

function ordersShippingOrderContainsPlasticsWhereSql(string $orderIdSql): string
{
  return "(
    EXISTS (
      SELECT 1
      FROM order_categories osc_scope
      JOIN categories osc_cat ON osc_cat.id = osc_scope.category_id
      WHERE osc_scope.order_id = $orderIdSql
        AND UPPER(TRIM(COALESCE(osc_cat.code, ''))) = 'PLASTICS'
    )
    OR EXISTS (
      SELECT 1
      FROM order_items osi_scope
      WHERE osi_scope.order_id = $orderIdSql
        AND osi_scope.deleted_at IS NULL
        AND (
          UPPER(TRIM(COALESCE(osi_scope.item_type_code, ''))) LIKE '%P%'
          OR UPPER(TRIM(COALESCE(osi_scope.item_type_code, ''))) IN ('T', 'M')
        )
    )
  )";
}

function ordersShippingScopeIsPlastics(int $sessionDept): bool
{
  return $sessionDept === 6;
}

function ordersShippingScopeWhereSql(int $sessionDept, string $orderAlias = 'o'): string
{
  $containsPlasticsSql = ordersShippingContainsPlasticsWhereSql($orderAlias);
  return ordersShippingScopeIsPlastics($sessionDept)
    ? $containsPlasticsSql
    : "NOT ($containsPlasticsSql)";
}

function ordersMultishippingGroupForOrder(mysqli $conn, int $orderId): ?array
{
  ordersMultishippingEnsureSchema($conn);
  $stmt = $conn->prepare("SELECT g.*, m.position
    FROM order_multishipping_orders m
    JOIN order_multishipping_groups g ON g.id = m.group_id
    WHERE m.order_id = ? AND g.status <> 'CANCELLED'
    LIMIT 1");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}

function ordersMultishippingMembers(mysqli $conn, int $groupId): array
{
  ordersMultishippingEnsureSchema($conn);
  $stmt = $conn->prepare("SELECT m.order_id, m.position, o.order_number, o.external_order_id,
      o.status, o.customer_id, cu.name AS customer_name, cu.email AS customer_email
    FROM order_multishipping_orders m
    JOIN orders o ON o.id = m.order_id
    LEFT JOIN customers cu ON cu.id = o.customer_id
    WHERE m.group_id = ?
    ORDER BY m.position ASC, m.order_id ASC");
  $stmt->bind_param('i', $groupId);
  $stmt->execute();
  $rows = [];
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
  }
  $stmt->close();
  return $rows;
}

function ordersMultishippingMap(mysqli $conn, array $orderIds): array
{
  ordersMultishippingEnsureSchema($conn);
  $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
  if (!$orderIds) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
  $types = str_repeat('i', count($orderIds));
  $stmt = $conn->prepare("SELECT m.order_id, m.group_id, m.position, g.status,
      master_o.order_number AS master_order_number,
      master_o.external_order_id AS master_external_order_id,
      counts.member_count
    FROM order_multishipping_orders m
    JOIN order_multishipping_groups g ON g.id = m.group_id AND g.status <> 'CANCELLED'
    JOIN order_multishipping_orders master_m ON master_m.group_id = m.group_id AND master_m.position = 0
    JOIN orders master_o ON master_o.id = master_m.order_id
    JOIN (
      SELECT group_id, COUNT(*) AS member_count
      FROM order_multishipping_orders
      GROUP BY group_id
    ) counts ON counts.group_id = m.group_id
    WHERE m.order_id IN ($placeholders)");
  $stmt->bind_param($types, ...$orderIds);
  $stmt->execute();
  $map = [];
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $map[(int) $row['order_id']] = $row;
  }
  $stmt->close();
  return $map;
}

function ordersMultishippingActiveExportLocks(mysqli $conn, array $orderIds): array
{
  ordersMultishippingEnsureSchema($conn);
  $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
  if (!$orderIds) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
  $types = str_repeat('i', count($orderIds));
  $stmt = $conn->prepare("SELECT order_id, group_id, exported_at
    FROM order_fedex_export_locks
    WHERE active = 1 AND order_id IN ($placeholders)");
  $stmt->bind_param($types, ...$orderIds);
  $stmt->execute();
  $locks = [];
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $locks[(int) $row['order_id']] = $row;
  }
  $stmt->close();
  return $locks;
}

function ordersMultishippingReleaseExportLocks(mysqli $conn, array $orderIds, int $userId): void
{
  $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
  if (!$orderIds) {
    return;
  }
  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
  $types = 'i' . str_repeat('i', count($orderIds));
  $params = array_merge([$userId], $orderIds);
  $stmt = $conn->prepare("UPDATE order_fedex_export_locks
    SET active = 0, released_by = ?, released_at = NOW()
    WHERE order_id IN ($placeholders)");
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $stmt->close();
}

function ordersMultishippingMarkExported(mysqli $conn, array $exportRows, int $userId): string
{
  ordersMultishippingEnsureSchema($conn);
  $token = bin2hex(random_bytes(16));
  $lockRows = [];
  $groupIds = [];

  foreach ($exportRows as $row) {
    $orderId = (int) ($row['order_id'] ?? 0);
    $groupId = (int) ($row['multishipping_group_id'] ?? 0);
    if ($groupId > 0) {
      $groupIds[$groupId] = true;
      foreach (ordersMultishippingMembers($conn, $groupId) as $member) {
        $lockRows[(int) $member['order_id']] = $groupId;
      }
    } elseif ($orderId > 0) {
      $lockRows[$orderId] = 0;
    }
  }

  $stmt = $conn->prepare("INSERT INTO order_fedex_export_locks
      (order_id, group_id, export_token, exported_by, exported_at, active, released_by, released_at)
    VALUES (?, NULLIF(?, 0), ?, ?, NOW(), 1, NULL, NULL)
    ON DUPLICATE KEY UPDATE group_id = VALUES(group_id), export_token = VALUES(export_token),
      exported_by = VALUES(exported_by), exported_at = NOW(), active = 1,
      released_by = NULL, released_at = NULL");
  foreach ($lockRows as $orderId => $groupId) {
    $stmt->bind_param('iisi', $orderId, $groupId, $token, $userId);
    $stmt->execute();
  }
  $stmt->close();

  if ($groupIds) {
    $groupStmt = $conn->prepare("UPDATE order_multishipping_groups
      SET status = 'EXPORTED', exported_by = ?, exported_at = NOW(), updated_by = ?
      WHERE id = ? AND status = 'DRAFT'");
    foreach (array_keys($groupIds) as $groupId) {
      $groupStmt->bind_param('iii', $userId, $userId, $groupId);
      $groupStmt->execute();
    }
    $groupStmt->close();
  }

  return $token;
}

function ordersMultishippingMarkShipped(mysqli $conn, int $groupId, string $trackingNumber, ?string $shippedAt): void
{
  $stmt = $conn->prepare("UPDATE order_multishipping_groups
    SET status = 'SHIPPED', tracking_number = ?, shipped_at = COALESCE(?, NOW())
    WHERE id = ?");
  $stmt->bind_param('ssi', $trackingNumber, $shippedAt, $groupId);
  $stmt->execute();
  $stmt->close();
}
