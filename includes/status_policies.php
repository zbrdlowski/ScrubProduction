<?php
declare(strict_types=1);

/** @var mysqli $conn */
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/orders_status_helpers.php';
require_once __DIR__ . '/orders_workflow_helpers.php';

if (!isset($conn) || !$conn instanceof mysqli) {
  echo '<div class="alert alert-danger">Database connection error.</div>';
  return;
}

function statusPoliciesEnsureTables(mysqli $conn): void
{
  $conn->query("
    CREATE TABLE IF NOT EXISTS status_workflow_rules (
      id INT(11) NOT NULL AUTO_INCREMENT,
      name VARCHAR(150) NOT NULL,
      description TEXT DEFAULT NULL,
      result_order_status_code VARCHAR(32) NOT NULL,
      priority INT(11) NOT NULL DEFAULT 100,
      active TINYINT(1) NOT NULL DEFAULT 1,
      stop_on_match TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_status_workflow_rules_priority (active, priority)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");

  $conn->query("
    CREATE TABLE IF NOT EXISTS status_workflow_rule_conditions (
      id INT(11) NOT NULL AUTO_INCREMENT,
      rule_id INT(11) NOT NULL,
      department VARCHAR(2) NOT NULL,
      condition_type VARCHAR(20) NOT NULL DEFAULT 'status',
      operator VARCHAR(20) NOT NULL,
      status_code VARCHAR(255) DEFAULT NULL,
      sort_order INT(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      KEY idx_status_workflow_rule_conditions_rule (rule_id, sort_order),
      CONSTRAINT fk_status_workflow_rule_conditions_rule
        FOREIGN KEY (rule_id) REFERENCES status_workflow_rules (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");

  if (!function_exists('statusPoliciesColumnExists')) {
    function statusPoliciesColumnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
      $tableName = trim($tableName);
      $columnName = trim($columnName);

      if ($tableName === '' || $columnName === '') {
        return false;
      }

      $sql = sprintf(
        "SHOW COLUMNS FROM `%s` LIKE '%s'",
        $conn->real_escape_string($tableName),
        $conn->real_escape_string($columnName)
      );
      $result = $conn->query($sql);

      if (!$result instanceof mysqli_result) {
        return false;
      }

      $exists = $result->num_rows > 0;
      $result->free();
      return $exists;
    }
  }

  $ruleColumnUpdates = [
    'description' => "ALTER TABLE status_workflow_rules ADD COLUMN description TEXT DEFAULT NULL AFTER name",
    'updated_at' => "ALTER TABLE status_workflow_rules ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    'created_at' => "ALTER TABLE status_workflow_rules ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER stop_on_match",
    'stop_on_match' => "ALTER TABLE status_workflow_rules ADD COLUMN stop_on_match TINYINT(1) NOT NULL DEFAULT 1 AFTER active",
    'active' => "ALTER TABLE status_workflow_rules ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER priority",
    'priority' => "ALTER TABLE status_workflow_rules ADD COLUMN priority INT(11) NOT NULL DEFAULT 100 AFTER result_order_status_code",
  ];

  foreach ($ruleColumnUpdates as $columnName => $alterSql) {
    if (!statusPoliciesColumnExists($conn, 'status_workflow_rules', $columnName)) {
      $conn->query($alterSql);
    }
  }

  $conditionColumnUpdates = [
    'condition_type' => "ALTER TABLE status_workflow_rule_conditions ADD COLUMN condition_type VARCHAR(20) NOT NULL DEFAULT 'status' AFTER department",
    'sort_order' => "ALTER TABLE status_workflow_rule_conditions ADD COLUMN sort_order INT(11) NOT NULL DEFAULT 0 AFTER status_code",
  ];

  foreach ($conditionColumnUpdates as $columnName => $alterSql) {
    if (!statusPoliciesColumnExists($conn, 'status_workflow_rule_conditions', $columnName)) {
      $conn->query($alterSql);
    }
  }
}

function statusPoliciesDepartmentLabels(): array
{
  return [
    'G' => 'Graphics',
    'P' => 'Plastics',
    'S' => 'Seat Cover',
    'F' => 'Fitting',
  ];
}

function statusPoliciesRedirect(string $query = ''): void
{
  header('Location: index.php?page=status_policies' . $query);
  exit;
}

function statusPoliciesRecalculateAllOrders(mysqli $conn): int
{
  $count = 0;
  $result = $conn->query("SELECT id FROM orders");
  if (!$result instanceof mysqli_result) {
    return $count;
  }

  while ($row = $result->fetch_assoc()) {
    $orderId = (int)($row['id'] ?? 0);
    if ($orderId <= 0) {
      continue;
    }

    recalculateOrderWorkflow($conn, $orderId);
    $count++;
  }

  $result->free();
  return $count;
}


function statusPoliciesTableExists(mysqli $conn, string $tableName): bool
{
  $tableName = trim($tableName);
  if ($tableName === '') {
    return false;
  }

  $sql = sprintf(
    "SHOW TABLES LIKE '%s'",
    $conn->real_escape_string($tableName)
  );
  $result = $conn->query($sql);

  if (!$result instanceof mysqli_result) {
    return false;
  }

  $exists = $result->num_rows > 0;
  $result->free();
  return $exists;
}

function statusPoliciesFetchAllowedOrderStatuses(mysqli $conn, int $ruleId): array
{
  if ($ruleId <= 0 || !statusPoliciesTableExists($conn, 'status_workflow_rule_allowed_order_statuses')) {
    return [];
  }

  $stmt = $conn->prepare("
    SELECT order_status_code
    FROM status_workflow_rule_allowed_order_statuses
    WHERE rule_id = ?
    ORDER BY order_status_code ASC
  ");
  if (!$stmt) {
    return [];
  }

  $stmt->bind_param('i', $ruleId);
  $stmt->execute();
  $result = $stmt->get_result();
  $statuses = [];

  if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
      $statusCode = strtoupper(trim((string)($row['order_status_code'] ?? '')));
      if ($statusCode !== '') {
        $statuses[] = $statusCode;
      }
    }
    $result->free();
  }

  $stmt->close();
  return array_values(array_unique($statuses));
}

function statusPoliciesSaveAllowedOrderStatuses(mysqli $conn, int $ruleId, array $statuses, array $overallStatuses): void
{
  if ($ruleId <= 0 || !statusPoliciesTableExists($conn, 'status_workflow_rule_allowed_order_statuses')) {
    return;
  }

  $normalizedStatuses = [];
  foreach ($statuses as $statusCode) {
    $statusCode = strtoupper(trim((string)$statusCode));
    if ($statusCode === '' || !isset($overallStatuses[$statusCode])) {
      continue;
    }
    $normalizedStatuses[] = $statusCode;
  }
  $normalizedStatuses = array_values(array_unique($normalizedStatuses));

  $stmtDelete = $conn->prepare("DELETE FROM status_workflow_rule_allowed_order_statuses WHERE rule_id = ?");
  if ($stmtDelete) {
    $stmtDelete->bind_param('i', $ruleId);
    $stmtDelete->execute();
    $stmtDelete->close();
  }

  if (!$normalizedStatuses) {
    return;
  }

  $stmtInsert = $conn->prepare("
    INSERT IGNORE INTO status_workflow_rule_allowed_order_statuses
    (rule_id, order_status_code)
    VALUES (?, ?)
  ");
  if (!$stmtInsert) {
    return;
  }

  foreach ($normalizedStatuses as $statusCode) {
    $stmtInsert->bind_param('is', $ruleId, $statusCode);
    $stmtInsert->execute();
  }

  $stmtInsert->close();
}

function statusPoliciesFetchAll(mysqli $conn): array
{
  $sql = "
    SELECT
      r.id,
      r.name,
      r.description,
      r.result_order_status_code,
      r.priority,
      r.active,
      r.stop_on_match,
      r.updated_at,
      c.id AS condition_id,
      c.department,
      c.condition_type,
      c.operator,
      c.status_code,
      c.sort_order
    FROM status_workflow_rules r
    LEFT JOIN status_workflow_rule_conditions c
      ON c.rule_id = r.id
    ORDER BY r.active DESC, r.priority ASC, r.id ASC, c.sort_order ASC, c.id ASC
  ";
  $result = $conn->query($sql);
  if (!$result instanceof mysqli_result) {
    return [];
  }

  $rules = [];
  while ($row = $result->fetch_assoc()) {
    $ruleId = (int)($row['id'] ?? 0);
    if ($ruleId <= 0) {
      continue;
    }

    if (!isset($rules[$ruleId])) {
      $rules[$ruleId] = [
        'id' => $ruleId,
        'name' => trim((string)($row['name'] ?? '')),
        'description' => (string)($row['description'] ?? ''),
        'result_order_status_code' => strtoupper(trim((string)($row['result_order_status_code'] ?? ''))),
        'priority' => (int)($row['priority'] ?? 100),
        'active' => (int)($row['active'] ?? 1),
        'stop_on_match' => (int)($row['stop_on_match'] ?? 1),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'conditions' => [],
      ];
    }

    $conditionId = (int)($row['condition_id'] ?? 0);
    if ($conditionId > 0) {
      $rules[$ruleId]['conditions'][] = [
        'id' => $conditionId,
        'department' => ordersNormalizeDepartmentCode($row['department'] ?? null),
        'condition_type' => strtolower(trim((string)($row['condition_type'] ?? 'status'))),
        'operator' => strtoupper(trim((string)($row['operator'] ?? 'IN'))),
        'status_code' => strtoupper(trim((string)($row['status_code'] ?? ''))),
        'sort_order' => (int)($row['sort_order'] ?? 0),
      ];
    }
  }

  $result->free();
  return $rules;
}

function statusPoliciesBuildConditionSummary(mysqli $conn, array $conditions): string
{
  $departmentLabels = statusPoliciesDepartmentLabels();
  $parts = [];

  foreach ($conditions as $condition) {
    $department = (string)($condition['department'] ?? '');
    $departmentLabel = $departmentLabels[$department] ?? $department;
    $conditionType = strtolower(trim((string)($condition['condition_type'] ?? 'status')));
    $operator = strtoupper(trim((string)($condition['operator'] ?? '')));

    if ($conditionType === 'presence') {
      $parts[] = $departmentLabel . ' ' . ($operator === 'ABSENT' ? 'does not exist' : 'exists');
      continue;
    }

    $rawStatuses = preg_split('/[,\|]+/', (string)($condition['status_code'] ?? '')) ?: [];
    $labels = [];
    foreach ($rawStatuses as $code) {
      $code = strtoupper(trim((string)$code));
      if ($code === '') {
        continue;
      }
      $labels[] = ordersGetStatusLabel($conn, 'item', $code, $department);
    }

    if (!$labels) {
      continue;
    }

    $parts[] = $departmentLabel . ' status ' . ($operator === 'NOT IN' ? 'is not' : 'is') . ' ' . implode(' / ', $labels);
  }

  return $parts ? implode(' AND ', $parts) : 'No conditions';
}

statusPoliciesEnsureTables($conn);

$departmentLabels = statusPoliciesDepartmentLabels();
$overallStatuses = ordersGetOrderStatusDefinitions($conn, true);
$itemStatuses = [
  'G' => ordersGetItemStatusDefinitions($conn, 'G', true),
  'P' => ordersGetItemStatusDefinitions($conn, 'P', true),
  'S' => ordersGetItemStatusDefinitions($conn, 'S', true),
  'F' => ordersGetItemStatusDefinitions($conn, 'F', true),
];

$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? ''));

  if ($action === 'save_policy') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $resultOrderStatusCode = strtoupper(trim((string)($_POST['result_order_status_code'] ?? '')));
    $priority = (int)($_POST['priority'] ?? 100);
    $active = (int)($_POST['active'] ?? 1) === 1 ? 1 : 0;
    $stopOnMatch = (int)($_POST['stop_on_match'] ?? 1) === 1 ? 1 : 0;
    $conditionDepartments = $_POST['condition_department'] ?? [];
    $conditionTypes = $_POST['condition_type'] ?? [];
    $conditionOperators = $_POST['condition_operator'] ?? [];
    $conditionStatuses = $_POST['condition_status_codes'] ?? [];
    $allowedOrderStatuses = $_POST['allowed_order_statuses'] ?? [];

    if ($name === '' || $resultOrderStatusCode === '' || !isset($overallStatuses[$resultOrderStatusCode])) {
      $flashMessage = 'Please fill in policy name and valid overall status.';
      $flashType = 'danger';
    } else {
      if ($ruleId > 0) {
        $stmt = $conn->prepare("
          UPDATE status_workflow_rules
          SET name = ?, description = ?, result_order_status_code = ?, priority = ?, active = ?, stop_on_match = ?
          WHERE id = ?
        ");
        $stmt->bind_param('sssiiii', $name, $description, $resultOrderStatusCode, $priority, $active, $stopOnMatch, $ruleId);
        $stmt->execute();
        $stmt->close();
      } else {
        $stmt = $conn->prepare("
          INSERT INTO status_workflow_rules
          (name, description, result_order_status_code, priority, active, stop_on_match)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('sssiii', $name, $description, $resultOrderStatusCode, $priority, $active, $stopOnMatch);
        $stmt->execute();
        $ruleId = (int)$stmt->insert_id;
        $stmt->close();
      }

      $stmtDelete = $conn->prepare("DELETE FROM status_workflow_rule_conditions WHERE rule_id = ?");
      $stmtDelete->bind_param('i', $ruleId);
      $stmtDelete->execute();
      $stmtDelete->close();

      $sortOrder = 10;
      foreach ($conditionDepartments as $rowKey => $departmentRaw) {
        $department = ordersNormalizeDepartmentCode((string)$departmentRaw);
        $conditionType = strtolower(trim((string)($conditionTypes[$rowKey] ?? 'status')));
        $operator = strtoupper(trim((string)($conditionOperators[$rowKey] ?? 'IN')));
        $statusList = $conditionStatuses[$rowKey] ?? [];

        if (!isset($departmentLabels[$department])) {
          continue;
        }

        if ($conditionType === 'presence') {
          if (!in_array($operator, ['PRESENT', 'ABSENT'], true)) {
            continue;
          }
          $statusCode = null;
        } else {
          if (!in_array($operator, ['IN', 'NOT IN', '=', '!='], true)) {
            $operator = 'IN';
          }

          if (!is_array($statusList)) {
            $statusList = [$statusList];
          }

          $normalizedStatuses = [];
          foreach ($statusList as $statusCodeItem) {
            $statusCodeItem = strtoupper(trim((string)$statusCodeItem));
            if ($statusCodeItem === '' || !isset($itemStatuses[$department][$statusCodeItem])) {
              continue;
            }
            $normalizedStatuses[] = $statusCodeItem;
          }

          $normalizedStatuses = array_values(array_unique($normalizedStatuses));
          if (!$normalizedStatuses) {
            continue;
          }

          $statusCode = implode(',', $normalizedStatuses);
        }

        $stmtInsertCondition = $conn->prepare("
          INSERT INTO status_workflow_rule_conditions
          (rule_id, department, condition_type, operator, status_code, sort_order)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmtInsertCondition->bind_param('issssi', $ruleId, $department, $conditionType, $operator, $statusCode, $sortOrder);
        $stmtInsertCondition->execute();
        $stmtInsertCondition->close();

        $sortOrder += 10;
      }

      statusPoliciesSaveAllowedOrderStatuses($conn, $ruleId, is_array($allowedOrderStatuses) ? $allowedOrderStatuses : [$allowedOrderStatuses], $overallStatuses);

      $recalculatedCount = statusPoliciesRecalculateAllOrders($conn);
      $_SESSION['status_policies_flash_message'] = 'Policy saved. Recalculated ' . $recalculatedCount . ' orders.';
      $_SESSION['status_policies_flash_type'] = 'success';
      statusPoliciesRedirect('&mode=edit&id=' . $ruleId);
    }
  } elseif ($action === 'delete_policy') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    if ($ruleId > 0) {
      $stmt = $conn->prepare("DELETE FROM status_workflow_rules WHERE id = ?");
      $stmt->bind_param('i', $ruleId);
      $stmt->execute();
      $stmt->close();

      $recalculatedCount = statusPoliciesRecalculateAllOrders($conn);
      $_SESSION['status_policies_flash_message'] = 'Policy deleted. Recalculated ' . $recalculatedCount . ' orders.';
      $_SESSION['status_policies_flash_type'] = 'warning';
    }
    statusPoliciesRedirect();
  }
}

if (isset($_SESSION['status_policies_flash_message'])) {
  $flashMessage = (string)$_SESSION['status_policies_flash_message'];
  $flashType = (string)($_SESSION['status_policies_flash_type'] ?? 'success');
  unset($_SESSION['status_policies_flash_message'], $_SESSION['status_policies_flash_type']);
}

$mode = trim((string)($_GET['mode'] ?? 'list'));
$editingId = (int)($_GET['id'] ?? 0);
$allPolicies = statusPoliciesFetchAll($conn);
$editingPolicy = null;

if ($mode === 'edit' && $editingId > 0) {
  $editingPolicy = $allPolicies[$editingId] ?? null;
  if ($editingPolicy === null) {
    $mode = 'list';
    $flashMessage = 'Selected policy was not found.';
    $flashType = 'warning';
  } else {
    $editingPolicy['allowed_order_statuses'] = statusPoliciesFetchAllowedOrderStatuses($conn, (int)$editingPolicy['id']);
  }
} elseif ($mode === 'create') {
  $editingPolicy = [
    'id' => 0,
    'name' => '',
    'description' => '',
    'result_order_status_code' => 'IN_PROGRESS',
    'priority' => 100,
    'active' => 1,
    'stop_on_match' => 1,
    'conditions' => [
      [
        'id' => 0,
        'department' => 'G',
        'condition_type' => 'status',
        'operator' => 'IN',
        'status_code' => '',
        'sort_order' => 10,
      ],
    ],
    'allowed_order_statuses' => [],
  ];
}
?>

<style>
  .status-policy-layout {
    display: grid;
    gap: 1rem;
  }

  .status-policy-card .card-header {
    padding: .7rem .9rem;
  }

  .status-policy-card .table th,
  .status-policy-card .table td {
    vertical-align: middle;
    font-size: .9rem;
  }

  .status-policy-condition-row {
    background: rgba(255, 255, 255, .02);
    border: 1px solid rgba(255, 255, 255, .06);
    border-radius: .35rem;
    padding: .9rem;
    margin-bottom: .75rem;
  }

  .status-policy-condition-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: .75rem;
    align-items: end;
  }

  .status-policy-condition-grid .form-group {
    margin-bottom: 0;
  }

  .status-policy-status-list {
    min-height: 120px;
  }

  .status-policy-help {
    font-size: .82rem;
    color: #adb5bd;
  }
</style>

<div class="container-fluid status-policy-layout">
  <?php if ($flashMessage !== ''): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> mb-0">
      <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <?php if ($mode === 'list'): ?>
    <div class="card card-dark status-policy-card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h3 class="card-title mb-0">Status Policies</h3>
        <a href="index.php?page=status_policies&amp;mode=create" class="btn bg-gradient-success btn-sm">
          <i class="fa fa-plus"></i> Create New Policy
        </a>
      </div>
      <div class="card-body p-0">
        <table class="table table-bordered table-striped mb-0">
          <thead>
            <tr>
              <th style="width:70px;">ID</th>
              <th style="width:240px;">Policy</th>
              <th>Conditions</th>
              <th style="width:180px;">Overall Status</th>
              <th style="width:90px;">Priority</th>
              <th style="width:80px;">Active</th>
              <th style="width:180px;">Tools</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$allPolicies): ?>
              <tr>
                <td colspan="7" class="text-center text-muted py-4">No status policies yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($allPolicies as $policy): ?>
                <tr>
                  <td><?= (int)$policy['id']; ?></td>
                  <td>
                    <strong><?= htmlspecialchars($policy['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if (trim((string)$policy['description']) !== ''): ?>
                      <div class="text-muted small"><?= htmlspecialchars($policy['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars(statusPoliciesBuildConditionSummary($conn, $policy['conditions']), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?= htmlspecialchars(ordersGetStatusLabel($conn, 'order', (string)$policy['result_order_status_code']), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?= (int)$policy['priority']; ?></td>
                  <td><?= (int)$policy['active'] === 1 ? 'Yes' : 'No'; ?></td>
                  <td>
                    <a href="index.php?page=status_policies&amp;mode=edit&amp;id=<?= (int)$policy['id']; ?>" class="btn bg-gradient-primary btn-sm">
                      <i class="fa fa-edit"></i> Edit
                    </a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this policy?');">
                      <input type="hidden" name="action" value="delete_policy">
                      <input type="hidden" name="rule_id" value="<?= (int)$policy['id']; ?>">
                      <button type="submit" class="btn bg-gradient-danger btn-sm">
                        <i class="fa fa-trash"></i> Delete
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($mode === 'create' || $mode === 'edit'): ?>
    <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:.75rem;">
      <div>
        <h3 class="mb-1"><?= $mode === 'create' ? 'Create New Status Policy' : 'Edit Status Policy'; ?></h3>
        <div class="status-policy-help">
          Pouzi status podmienky pre "status je A alebo B" a presence podmienky pre "ma / nema tuto polozku".
        </div>
      </div>
      <a href="index.php?page=status_policies" class="btn bg-gradient-secondary btn-sm">
        <i class="fa fa-arrow-left"></i> Back To Policies
      </a>
    </div>

    <form method="post" class="card card-dark status-policy-card" id="status-policy-form">
      <input type="hidden" name="action" value="save_policy">
      <input type="hidden" name="rule_id" value="<?= (int)$editingPolicy['id']; ?>">

      <div class="card-header">
        <h3 class="card-title mb-0">Policy Setup</h3>
      </div>

      <div class="card-body">
        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label for="status-policy-name">Policy Name</label>
              <input id="status-policy-name" type="text" name="name" class="form-control" required
                value="<?= htmlspecialchars((string)$editingPolicy['name'], ENT_QUOTES, 'UTF-8'); ?>"
                placeholder="Example: Graphics ready + plastics ready = Ready to ship">
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-group">
              <label for="status-policy-priority">Priority</label>
              <input id="status-policy-priority" type="number" name="priority" class="form-control"
                value="<?= (int)$editingPolicy['priority']; ?>" step="1">
              <div class="status-policy-help">Nizsie cislo = vyssia priorita.</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-group">
              <label for="status-policy-result">Overall Status</label>
              <select id="status-policy-result" name="result_order_status_code" class="form-control" required>
                <?php foreach ($overallStatuses as $statusCode => $meta): ?>
                  <option value="<?= htmlspecialchars($statusCode, ENT_QUOTES, 'UTF-8'); ?>" <?= (string)$editingPolicy['result_order_status_code'] === $statusCode ? 'selected' : ''; ?>>
                    <?= htmlspecialchars((string)($meta['label'] ?? $statusCode), ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-8">
            <div class="form-group">
              <label for="status-policy-description">Description</label>
              <textarea id="status-policy-description" name="description" rows="2" class="form-control"
                placeholder="Short note for admins"><?= htmlspecialchars((string)$editingPolicy['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
          </div>
          <div class="col-md-2">
            <div class="form-group">
              <label for="status-policy-active">Active</label>
              <select id="status-policy-active" name="active" class="form-control">
                <option value="1" <?= (int)$editingPolicy['active'] === 1 ? 'selected' : ''; ?>>Yes</option>
                <option value="0" <?= (int)$editingPolicy['active'] === 0 ? 'selected' : ''; ?>>No</option>
              </select>
            </div>
          </div>
          <div class="col-md-2">
            <div class="form-group">
              <label for="status-policy-stop">Stop On Match</label>
              <select id="status-policy-stop" name="stop_on_match" class="form-control">
                <option value="1" <?= (int)$editingPolicy['stop_on_match'] === 1 ? 'selected' : ''; ?>>Yes</option>
                <option value="0" <?= (int)$editingPolicy['stop_on_match'] === 0 ? 'selected' : ''; ?>>No</option>
              </select>
            </div>
          </div>
        </div>

        <hr>

        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h4 class="mb-1">Conditions</h4>
            <div class="status-policy-help">Vsetky podmienky v jednej politike sa vyhodnocuju ako AND.</div>
          </div>
          <button type="button" class="btn bg-gradient-info btn-sm" id="add-status-policy-condition">
            <i class="fa fa-plus"></i> Add Condition
          </button>
        </div>

        <div id="status-policy-conditions">
          <?php foreach ($editingPolicy['conditions'] as $index => $condition): ?>
            <?php
            $rowDepartment = (string)($condition['department'] ?? 'G');
            $rowType = strtolower((string)($condition['condition_type'] ?? 'status'));
            $rowOperator = strtoupper((string)($condition['operator'] ?? 'IN'));
            $selectedStatuses = array_values(array_filter(array_map('trim', preg_split('/[,\|]+/', (string)($condition['status_code'] ?? '')) ?: [])));
            ?>
            <div class="status-policy-condition-row" data-index="<?= (int)$index; ?>">
              <div class="status-policy-condition-grid">
                <div class="form-group">
                  <label>Department</label>
                  <select name="condition_department[<?= (int)$index; ?>]" class="form-control js-policy-department">
                    <?php foreach ($departmentLabels as $departmentCode => $departmentLabel): ?>
                      <option value="<?= htmlspecialchars($departmentCode, ENT_QUOTES, 'UTF-8'); ?>" <?= $rowDepartment === $departmentCode ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($departmentCode . ' - ' . $departmentLabel, ENT_QUOTES, 'UTF-8'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="form-group">
                  <label>Condition Type</label>
                  <select name="condition_type[<?= (int)$index; ?>]" class="form-control js-policy-condition-type">
                    <option value="status" <?= $rowType === 'status' ? 'selected' : ''; ?>>Status</option>
                    <option value="presence" <?= $rowType === 'presence' ? 'selected' : ''; ?>>Has / Has Not</option>
                  </select>
                </div>

                <div class="form-group">
                  <label>Operator</label>
                  <select name="condition_operator[<?= (int)$index; ?>]" class="form-control js-policy-operator">
                    <option value="IN" <?= $rowOperator === 'IN' ? 'selected' : ''; ?>>Is one of</option>
                    <option value="NOT IN" <?= $rowOperator === 'NOT IN' ? 'selected' : ''; ?>>Is not one of</option>
                    <option value="PRESENT" <?= $rowOperator === 'PRESENT' ? 'selected' : ''; ?>>Has item</option>
                    <option value="ABSENT" <?= $rowOperator === 'ABSENT' ? 'selected' : ''; ?>>Does not have item</option>
                  </select>
                </div>

                <div class="form-group js-policy-status-wrap">
                  <label>Allowed Statuses</label>
                  <select name="condition_status_codes[<?= (int)$index; ?>][]" multiple class="form-control status-policy-status-list js-policy-statuses">
                    <?php foreach (($itemStatuses[$rowDepartment] ?? []) as $statusCode => $meta): ?>
                      <option value="<?= htmlspecialchars($statusCode, ENT_QUOTES, 'UTF-8'); ?>" <?= in_array($statusCode, $selectedStatuses, true) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars((string)($meta['label'] ?? $statusCode), ENT_QUOTES, 'UTF-8'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="status-policy-help">Vyber 1 alebo viac statusov. Politika ich berie ako OR.</div>
                </div>

                <div class="form-group">
                  <button type="button" class="btn bg-gradient-danger btn-sm js-remove-policy-condition">
                    <i class="fa fa-trash"></i> Remove
                  </button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card-footer">
        <button type="button" class="btn bg-gradient-success" id="open-status-policy-scope-modal">
          <i class="fa fa-save"></i> Save Policy
        </button>
      </div>

      <div class="modal fade" id="status-policy-scope-modal" tabindex="-1" role="dialog" aria-labelledby="status-policy-scope-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
          <div class="modal-content bg-dark">
            <div class="modal-header">
              <h5 class="modal-title" id="status-policy-scope-modal-title">Apply recalculation only for these order statuses</h5>
              <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
            <div class="modal-body">
              <div class="alert alert-info py-2">
                Vyber current order statusy, pri ktorych sa tato policy smie pouzit pri prepocitani. Nezaskrtnute statusy workflow necha bez zmeny.
              </div>
              <div class="row">
                <?php $allowedOrderStatuses = array_flip((array)($editingPolicy['allowed_order_statuses'] ?? [])); ?>
                <?php foreach ($overallStatuses as $statusCode => $meta): ?>
                  <div class="col-md-6 col-lg-4">
                    <div class="custom-control custom-checkbox mb-2">
                      <input
                        type="checkbox"
                        class="custom-control-input js-policy-allowed-status"
                        id="allowed-order-status-<?= htmlspecialchars($statusCode, ENT_QUOTES, 'UTF-8'); ?>"
                        name="allowed_order_statuses[]"
                        value="<?= htmlspecialchars($statusCode, ENT_QUOTES, 'UTF-8'); ?>"
                        <?= isset($allowedOrderStatuses[$statusCode]) ? 'checked' : ''; ?>
                      >
                      <label class="custom-control-label" for="allowed-order-status-<?= htmlspecialchars($statusCode, ENT_QUOTES, 'UTF-8'); ?>">
                        <?= htmlspecialchars((string)($meta['label'] ?? $statusCode), ENT_QUOTES, 'UTF-8'); ?>
                        <span class="text-muted small">(<?= htmlspecialchars($statusCode, ENT_QUOTES, 'UTF-8'); ?>)</span>
                      </label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="text-danger small d-none" id="status-policy-scope-error">
                Vyber aspon jeden order status, pre ktory sa policy moze prepocitat.
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn bg-gradient-secondary" data-dismiss="modal">Cancel</button>
              <button type="submit" class="btn bg-gradient-success" id="confirm-status-policy-save">
                <i class="fa fa-save"></i> Confirm Save Policy
              </button>
            </div>
          </div>
        </div>
      </div>
    </form>

    <?php if ((int)$editingPolicy['id'] > 0): ?>
      <form method="post" onsubmit="return confirm('Delete this policy?');">
        <input type="hidden" name="action" value="delete_policy">
        <input type="hidden" name="rule_id" value="<?= (int)$editingPolicy['id']; ?>">
        <button type="submit" class="btn bg-gradient-danger">
          <i class="fa fa-trash"></i> Delete Policy
        </button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
  (function () {
    const itemStatuses = <?= json_encode(array_map(static function (array $statuses): array {
      $rows = [];
      foreach ($statuses as $statusCode => $meta) {
        $rows[$statusCode] = (string)($meta['label'] ?? $statusCode);
      }
      return $rows;
    }, $itemStatuses), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const departmentLabels = <?= json_encode($departmentLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const container = document.getElementById('status-policy-conditions');
    const addButton = document.getElementById('add-status-policy-condition');
    const form = document.getElementById('status-policy-form');
    const openScopeModalButton = document.getElementById('open-status-policy-scope-modal');
    const scopeModal = document.getElementById('status-policy-scope-modal');
    const scopeError = document.getElementById('status-policy-scope-error');

    if (form && openScopeModalButton && scopeModal) {
      openScopeModalButton.addEventListener('click', function () {
        if (!form.checkValidity()) {
          form.reportValidity();
          return;
        }

        if (scopeError) {
          scopeError.classList.add('d-none');
        }

        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
          window.jQuery(scopeModal).modal('show');
        } else {
          form.submit();
        }
      });

      form.addEventListener('submit', function (event) {
        if (!event.submitter || event.submitter.id !== 'confirm-status-policy-save') {
          return;
        }

        const checkedStatuses = form.querySelectorAll('.js-policy-allowed-status:checked');
        if (checkedStatuses.length > 0) {
          return;
        }

        event.preventDefault();
        if (scopeError) {
          scopeError.classList.remove('d-none');
        }
      });
    }

    if (!container || !addButton) {
      return;
    }

    function escapeHtml(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function buildStatusOptions(department, selected) {
      const statuses = itemStatuses[department] || {};
      const selectedSet = new Set(Array.isArray(selected) ? selected : []);
      return Object.keys(statuses).map(function (code) {
        const isSelected = selectedSet.has(code) ? ' selected' : '';
        return '<option value="' + escapeHtml(code) + '"' + isSelected + '>' + escapeHtml(statuses[code]) + '</option>';
      }).join('');
    }

    function buildDepartmentOptions(selected) {
      return Object.keys(departmentLabels).map(function (code) {
        const isSelected = selected === code ? ' selected' : '';
        return '<option value="' + escapeHtml(code) + '"' + isSelected + '>' + escapeHtml(code + ' - ' + departmentLabels[code]) + '</option>';
      }).join('');
    }

    function refreshRow(row, preserveSelected) {
      const typeField = row.querySelector('.js-policy-condition-type');
      const operatorField = row.querySelector('.js-policy-operator');
      const statusWrap = row.querySelector('.js-policy-status-wrap');
      const departmentField = row.querySelector('.js-policy-department');
      const statusesField = row.querySelector('.js-policy-statuses');
      const conditionType = typeField ? typeField.value : 'status';
      const department = departmentField ? departmentField.value : 'G';
      const selected = preserveSelected && statusesField
        ? Array.from(statusesField.selectedOptions).map(function (option) { return option.value; })
        : [];

      if (conditionType === 'presence') {
        if (operatorField) {
          const currentOperator = operatorField.value === 'ABSENT' ? 'ABSENT' : 'PRESENT';
          operatorField.innerHTML = '<option value="PRESENT">Has item</option><option value="ABSENT">Does not have item</option>';
          operatorField.value = currentOperator;
        }
        if (statusWrap) {
          statusWrap.style.display = 'none';
        }
      } else {
        if (operatorField) {
          const currentOperator = operatorField.value === 'NOT IN' ? 'NOT IN' : 'IN';
          operatorField.innerHTML = '<option value="IN">Is one of</option><option value="NOT IN">Is not one of</option>';
          operatorField.value = currentOperator;
        }
        if (statusesField) {
          statusesField.innerHTML = buildStatusOptions(department, selected);
        }
        if (statusWrap) {
          statusWrap.style.display = '';
        }
      }
    }

    function createRow(index) {
      const row = document.createElement('div');
      row.className = 'status-policy-condition-row';
      row.setAttribute('data-index', String(index));
      row.innerHTML = ''
        + '<div class="status-policy-condition-grid">'
        + '  <div class="form-group">'
        + '    <label>Department</label>'
        + '    <select name="condition_department[' + index + ']" class="form-control js-policy-department">' + buildDepartmentOptions('G') + '</select>'
        + '  </div>'
        + '  <div class="form-group">'
        + '    <label>Condition Type</label>'
        + '    <select name="condition_type[' + index + ']" class="form-control js-policy-condition-type">'
        + '      <option value="status" selected>Status</option>'
        + '      <option value="presence">Has / Has Not</option>'
        + '    </select>'
        + '  </div>'
        + '  <div class="form-group">'
        + '    <label>Operator</label>'
        + '    <select name="condition_operator[' + index + ']" class="form-control js-policy-operator">'
        + '      <option value="IN" selected>Is one of</option>'
        + '      <option value="NOT IN">Is not one of</option>'
        + '    </select>'
        + '  </div>'
        + '  <div class="form-group js-policy-status-wrap">'
        + '    <label>Allowed Statuses</label>'
        + '    <select name="condition_status_codes[' + index + '][]" multiple class="form-control status-policy-status-list js-policy-statuses">' + buildStatusOptions('G', []) + '</select>'
        + '    <div class="status-policy-help">Vyber 1 alebo viac statusov. Politika ich berie ako OR.</div>'
        + '  </div>'
        + '  <div class="form-group">'
        + '    <button type="button" class="btn bg-gradient-danger btn-sm js-remove-policy-condition"><i class="fa fa-trash"></i> Remove</button>'
        + '  </div>'
        + '</div>';
      return row;
    }

    addButton.addEventListener('click', function () {
      const nextIndex = container.querySelectorAll('.status-policy-condition-row').length;
      container.appendChild(createRow(nextIndex));
    });

    container.addEventListener('click', function (event) {
      const removeButton = event.target.closest('.js-remove-policy-condition');
      if (!removeButton) {
        return;
      }

      const rows = container.querySelectorAll('.status-policy-condition-row');
      if (rows.length <= 1) {
        return;
      }

      removeButton.closest('.status-policy-condition-row').remove();
    });

    container.addEventListener('change', function (event) {
      const row = event.target.closest('.status-policy-condition-row');
      if (!row) {
        return;
      }

      if (
        event.target.classList.contains('js-policy-condition-type') ||
        event.target.classList.contains('js-policy-department')
      ) {
        refreshRow(row, true);
      }
    });

    container.querySelectorAll('.status-policy-condition-row').forEach(function (row) {
      refreshRow(row, true);
    });
  })();
</script>
