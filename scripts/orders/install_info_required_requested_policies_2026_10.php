<?php
declare(strict_types=1);

/**
 * Installs G/S item policies for the generic INFO_REQUIRED and INFO_REQUESTED
 * statuses and removes the obsolete G/S COMMUNICATION policies.
 *
 * Browser dry-run:
 *   /scripts/orders/install_info_required_requested_policies_2026_10.php?dry_run=1
 * Browser apply:
 *   /scripts/orders/install_info_required_requested_policies_2026_10.php
 *
 * CLI:
 *   php scripts/orders/install_info_required_requested_policies_2026_10.php --dry-run
 *   php scripts/orders/install_info_required_requested_policies_2026_10.php
 *
 * Safe to run more than once.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$base = dirname(__DIR__, 2);
require_once $base . '/includes/conn.php';
require_once $base . '/includes/auth.php';
require_once $base . '/includes/orders_workflow_helpers.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (empty($_SESSION['user_id'])) {
        http_response_code(403);
        exit("Unauthorized - prihlas sa najprv do administracie.\n");
    }
    auth_require('orders.admin', "No permission for this Orders admin action.\n");
}

$dryRun = PHP_SAPI === 'cli'
    ? in_array('--dry-run', $argv ?? [], true)
    : isset($_GET['dry_run']) && (string) $_GET['dry_run'] === '1';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function infoPolicyTableExists(mysqli $conn, string $tableName): bool
{
    $safeTableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTableName}'");
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    return $exists;
}

function infoPolicyRequireTables(mysqli $conn, array $tableNames): void
{
    foreach ($tableNames as $tableName) {
        if (!infoPolicyTableExists($conn, $tableName)) {
            throw new RuntimeException("Required table is missing: {$tableName}");
        }
    }
}

function infoPolicyRequireDefinition(
    mysqli $conn,
    string $scope,
    ?string $department,
    string $code
): array {
    if ($department === null) {
        $stmt = $conn->prepare("
            SELECT id, label, sort_order, active
            FROM status_definitions
            WHERE scope = ?
              AND (department IS NULL OR department = '')
              AND UPPER(code) = ?
            LIMIT 1
        ");
        $stmt->bind_param('ss', $scope, $code);
    } else {
        $stmt = $conn->prepare("
            SELECT id, label, sort_order, active
            FROM status_definitions
            WHERE scope = ?
              AND UPPER(department) = ?
              AND UPPER(code) = ?
            LIMIT 1
        ");
        $stmt->bind_param('sss', $scope, $department, $code);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $definitionName = $scope . '/' . ($department ?? '-') . '/' . $code;
        throw new RuntimeException(
            "Status definition {$definitionName} does not exist. Create it first in Status Definitions."
        );
    }

    return $row;
}

function infoPolicySetItemPriority(
    mysqli $conn,
    string $department,
    string $code,
    int $sortOrder
): void {
    infoPolicyRequireDefinition($conn, 'item', $department, $code);

    $stmt = $conn->prepare("
        UPDATE status_definitions
        SET sort_order = ?, active = 1
        WHERE scope = 'item'
          AND UPPER(department) = ?
          AND UPPER(code) = ?
    ");
    $stmt->bind_param('iss', $sortOrder, $department, $code);
    $stmt->execute();
    $stmt->close();
}

function infoPolicyDeleteRuleById(mysqli $conn, int $ruleId): void
{
    $stmt = $conn->prepare("DELETE FROM status_workflow_rule_allowed_order_statuses WHERE rule_id = ?");
    $stmt->bind_param('i', $ruleId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM status_workflow_rule_conditions WHERE rule_id = ?");
    $stmt->bind_param('i', $ruleId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM status_workflow_rules WHERE id = ?");
    $stmt->bind_param('i', $ruleId);
    $stmt->execute();
    $stmt->close();
}

function infoPolicyDeleteRuleByName(mysqli $conn, string $name): int
{
    $stmt = $conn->prepare("SELECT id FROM status_workflow_rules WHERE name = ?");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $result = $stmt->get_result();

    $ruleIds = [];
    while ($row = $result->fetch_assoc()) {
        $ruleIds[] = (int) $row['id'];
    }
    $stmt->close();

    foreach ($ruleIds as $ruleId) {
        infoPolicyDeleteRuleById($conn, $ruleId);
    }

    return count($ruleIds);
}

function infoPolicyUpsertRule(mysqli $conn, array $policy, array $allowedStatuses): string
{
    $name = (string) $policy['name'];
    $resultStatus = (string) $policy['result'];
    $priority = (int) $policy['priority'];
    $department = (string) $policy['department'];
    $statusCodes = implode(',', (array) $policy['statuses']);
    $description = 'G/S generic info status policies (2026-10)';

    $find = $conn->prepare("
        SELECT id
        FROM status_workflow_rules
        WHERE name = ?
        ORDER BY id ASC
    ");
    $find->bind_param('s', $name);
    $find->execute();
    $result = $find->get_result();

    $existingIds = [];
    while ($row = $result->fetch_assoc()) {
        $existingIds[] = (int) $row['id'];
    }
    $find->close();

    $active = 1;
    $stopOnMatch = 1;
    if ($existingIds) {
        $ruleId = array_shift($existingIds);
        foreach ($existingIds as $duplicateRuleId) {
            infoPolicyDeleteRuleById($conn, $duplicateRuleId);
        }

        $update = $conn->prepare("
            UPDATE status_workflow_rules
            SET description = ?, result_order_status_code = ?, priority = ?, active = ?, stop_on_match = ?
            WHERE id = ?
        ");
        $update->bind_param(
            'ssiiii',
            $description,
            $resultStatus,
            $priority,
            $active,
            $stopOnMatch,
            $ruleId
        );
        $update->execute();
        $update->close();
        $action = 'UPDATED';
    } else {
        $insert = $conn->prepare("
            INSERT INTO status_workflow_rules
                (name, description, result_order_status_code, priority, active, stop_on_match)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->bind_param(
            'sssiii',
            $name,
            $description,
            $resultStatus,
            $priority,
            $active,
            $stopOnMatch
        );
        $insert->execute();
        $ruleId = (int) $insert->insert_id;
        $insert->close();
        $action = 'CREATED';
    }

    $stmt = $conn->prepare("DELETE FROM status_workflow_rule_conditions WHERE rule_id = ?");
    $stmt->bind_param('i', $ruleId);
    $stmt->execute();
    $stmt->close();

    $conditionType = 'status';
    $operator = 'IN';
    $sortOrder = 10;
    $stmt = $conn->prepare("
        INSERT INTO status_workflow_rule_conditions
            (rule_id, department, condition_type, operator, status_code, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'issssi',
        $ruleId,
        $department,
        $conditionType,
        $operator,
        $statusCodes,
        $sortOrder
    );
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM status_workflow_rule_allowed_order_statuses WHERE rule_id = ?");
    $stmt->bind_param('i', $ruleId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO status_workflow_rule_allowed_order_statuses
            (rule_id, order_status_code)
        VALUES (?, ?)
    ");
    foreach ($allowedStatuses as $allowedStatus) {
        $stmt->bind_param('is', $ruleId, $allowedStatus);
        $stmt->execute();
    }
    $stmt->close();

    return "{$action} #{$ruleId}: {$name} -> {$resultStatus} [{$statusCodes}]";
}

try {
    infoPolicyRequireTables($conn, [
        'status_definitions',
        'status_workflow_rules',
        'status_workflow_rule_conditions',
        'status_workflow_rule_allowed_order_statuses',
        'orders',
        'order_items',
    ]);

    infoPolicyRequireDefinition($conn, 'order', null, 'INFO_REQUIRED');
    infoPolicyRequireDefinition($conn, 'order', null, 'INFO_REQUESTED');

    // Run the extension DDL before opening the rollbackable policy transaction.
    if (!statusDefinitionEnsureExtensions($conn)) {
        throw new RuntimeException('Could not initialize status definition extensions.');
    }

    $conn->begin_transaction();

    // The department resolver uses the lowest sort_order as its blocker.
    // These values make either generic info status visible when any G/S item has it.
    infoPolicySetItemPriority($conn, 'G', 'INFO_REQUIRED', 1);
    infoPolicySetItemPriority($conn, 'S', 'INFO_REQUIRED', 1);
    infoPolicySetItemPriority($conn, 'G', 'INFO_REQUESTED', 2);
    infoPolicySetItemPriority($conn, 'S', 'INFO_REQUESTED', 2);

    // Renaming a definition does not rewrite values already stored on order_items.
    $stmt = $conn->prepare("
        UPDATE order_items
        SET status = 'INFO_REQUIRED'
        WHERE deleted_at IS NULL
          AND UPPER(TRIM(item_type_code)) IN ('G', 'S')
          AND UPPER(TRIM(status)) = 'COMMUNICATION'
    ");
    $stmt->execute();
    $migratedLegacyItems = (int) $stmt->affected_rows;
    $stmt->close();

    $allowedStatuses = [
        'NEW',
        'IN_PROGRESS',
        'READY_TO_SHIP',
        'READY_TO_INVOICE',
        'INFO_REQUIRED',
        'INFO_REQUESTED',
        'COMMUNICATION',
        'HOLD',
        'DELAY',
        'PLASTICS_IN_STOCK',
    ];

    $policies = [
        [
            'name' => 'Graphics - Info Required',
            'priority' => 1,
            'result' => 'INFO_REQUIRED',
            'department' => 'G',
            'statuses' => ['INFO_REQUIRED', 'MODEL_REQUIRED', 'IMG_REQUIRED', 'N_N_REQUIRED'],
        ],
        [
            'name' => 'Seat Cover - Info Required',
            'priority' => 2,
            'result' => 'INFO_REQUIRED',
            'department' => 'S',
            'statuses' => ['INFO_REQUIRED', 'MODEL_REQUIRED', 'IMG_REQUIRED', 'N_N_REQUIRED'],
        ],
        [
            'name' => 'Graphics - Info Requested',
            'priority' => 10,
            'result' => 'INFO_REQUESTED',
            'department' => 'G',
            'statuses' => ['INFO_REQUESTED', 'MODEL_REQUESTED', 'IMG_REQUESTED', 'N_N_REQUESTED'],
        ],
        [
            'name' => 'Seat Cover - Info Requested',
            'priority' => 30,
            'result' => 'INFO_REQUESTED',
            'department' => 'S',
            'statuses' => ['INFO_REQUESTED', 'MODEL_REQUESTED', 'IMG_REQUESTED'],
        ],
    ];

    echo $dryRun ? "MODE: DRY RUN\n" : "MODE: APPLY\n";
    echo "--- Policies ---\n";
    foreach ($policies as $policy) {
        echo infoPolicyUpsertRule($conn, $policy, $allowedStatuses) . "\n";
    }

    $removedCommunicationRules = 0;
    $removedCommunicationRules += infoPolicyDeleteRuleByName($conn, 'Graphics - Communication');
    $removedCommunicationRules += infoPolicyDeleteRuleByName($conn, 'Seat Cover - Communication');

    $affectedOrderIds = [];
    $affectedResult = $conn->query("
        SELECT DISTINCT o.id
        FROM orders o
        JOIN order_items oi
          ON oi.order_id = o.id
         AND oi.deleted_at IS NULL
         AND UPPER(TRIM(oi.item_type_code)) IN ('G', 'S')
        WHERE UPPER(TRIM(oi.status)) IN ('INFO_REQUIRED', 'INFO_REQUESTED')
           OR UPPER(TRIM(COALESCE(o.status, ''))) = 'COMMUNICATION'
        ORDER BY o.id ASC
    ");
    while ($row = $affectedResult->fetch_assoc()) {
        $affectedOrderIds[] = (int) $row['id'];
    }
    $affectedResult->free();

    $resultCounts = [];
    foreach ($affectedOrderIds as $orderId) {
        recalculateOrderWorkflow($conn, $orderId);
        $status = ordersGetCurrentOrderStatus($conn, $orderId);
        $resultCounts[$status] = ($resultCounts[$status] ?? 0) + 1;
    }
    ksort($resultCounts);

    echo "--- Data migration ---\n";
    echo "Legacy G/S COMMUNICATION items migrated to INFO_REQUIRED: {$migratedLegacyItems}\n";
    echo "Obsolete Communication policies removed: {$removedCommunicationRules}\n";
    echo "Affected orders recalculated: " . count($affectedOrderIds) . "\n";
    foreach ($resultCounts as $status => $count) {
        echo "  {$status}: {$count}\n";
    }

    if ($dryRun) {
        $conn->rollback();
        echo "DRY RUN complete. All changes were rolled back.\n";
    } else {
        $conn->commit();
        echo "Done. Changes committed.\n";
    }
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // The original error below is the useful one.
    }

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}