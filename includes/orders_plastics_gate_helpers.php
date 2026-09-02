<?php
declare(strict_types=1);

require_once __DIR__ . '/orders_status_helpers.php';

const ORDERS_PLASTICS_GATE_FALLBACK_BLOCKED_STATUS = 'PLASTICS_IN_STOCK';
const ORDERS_PLASTICS_GATE_FALLBACK_STOCK_CHECK_STATUS = 'CHECK_STOCK';

function ordersApplyPlasticsStockGate(mysqli $conn, int $orderId): bool
{
    $check = $conn->prepare("\n        SELECT 1 FROM order_items\n        WHERE order_id = ? AND deleted_at IS NULL\n          AND UPPER(item_type_code) = 'P'\n        LIMIT 1\n    ");
    $check->bind_param('i', $orderId);
    $check->execute();
    $hasPlastics = (bool)$check->get_result()->fetch_row();
    $check->close();

    if (!$hasPlastics) {
        return false;
    }

    $stockCheckStatus = ordersPlasticsGateStockCheckStatus($conn);
    $blockedStatus = ordersPlasticsGatePrimaryBlockedStatus($conn);

    $update = $conn->prepare("\n        UPDATE order_items\n        SET status = CASE\n              WHEN UPPER(item_type_code) = 'P' THEN ?\n              WHEN UPPER(item_type_code) IN ('G', 'S', 'F') THEN ?\n              ELSE status\n            END,\n            waiting_note = NULL, expected_date = NULL,\n            completed_by = NULL, completed_at = NULL\n        WHERE order_id = ? AND deleted_at IS NULL\n          AND UPPER(item_type_code) IN ('G', 'S', 'P', 'F')\n    ");
    $update->bind_param('ssi', $stockCheckStatus, $blockedStatus, $orderId);
    $update->execute();
    $update->close();
    return true;
}

function ordersPlasticsGateNormalizeStatusCode(?string $status): string
{
    return strtoupper(trim((string)$status));
}

/**
 * @return array<int, string>
 */
function ordersPlasticsGateSplitStatusList(?string $statusList): array
{
    $statuses = [];
    foreach (preg_split('/[,|]+/', (string)$statusList) ?: [] as $status) {
        $status = ordersPlasticsGateNormalizeStatusCode($status);
        if ($status !== '') {
            $statuses[$status] = true;
        }
    }
    return array_keys($statuses);
}

/**
 * @param array<int, string> $statuses
 * @return array<string, bool>
 */
function ordersPlasticsGateStatusMap(array $statuses): array
{
    $map = [];
    foreach ($statuses as $status) {
        $status = ordersPlasticsGateNormalizeStatusCode($status);
        if ($status !== '') {
            $map[$status] = true;
        }
    }
    return $map;
}

/**
 * @param array<int, string> $statuses
 */
function ordersPlasticsGateSqlStatusList(mysqli $conn, array $statuses): string
{
    $map = ordersPlasticsGateStatusMap($statuses);
    if (!$map) {
        $map[ORDERS_PLASTICS_GATE_FALLBACK_BLOCKED_STATUS] = true;
    }

    return implode(',', array_map(static function (string $status) use ($conn): string {
        return "'" . $conn->real_escape_string($status) . "'";
    }, array_keys($map)));
}

/**
 * The preferred blocked status is discovered from Status Policies. A matching
 * policy is one where a plastics item status resolves the order to a non-NEW
 * order status that also exists as a dependent item status for G/S/F.
 *
 * @return array<int, string>
 */
function ordersPlasticsGatePolicyBlockedStatuses(mysqli $conn): array
{
    if (
        !ordersStatusTableExists($conn, 'status_definitions')
        || !ordersStatusTableExists($conn, 'status_workflow_rules')
        || !ordersStatusTableExists($conn, 'status_workflow_rule_conditions')
    ) {
        return [];
    }

    $result = $conn->query("\n        SELECT\n          UPPER(TRIM(r.result_order_status_code)) AS status_code,\n          MIN(r.priority) AS sort_priority,\n          MIN(r.id) AS sort_rule_id\n        FROM status_workflow_rules r\n        INNER JOIN status_workflow_rule_conditions c ON c.rule_id = r.id\n        WHERE r.active = 1\n          AND UPPER(TRIM(COALESCE(r.result_order_status_code, ''))) NOT IN ('', 'NEW')\n          AND UPPER(c.department) = 'P'\n          AND LOWER(COALESCE(c.condition_type, 'status')) = 'status'\n          AND UPPER(COALESCE(c.operator, '')) IN ('=', 'IN')\n          AND EXISTS (\n            SELECT 1\n            FROM status_definitions sd_order\n            WHERE sd_order.scope = 'order'\n              AND UPPER(sd_order.code) = UPPER(r.result_order_status_code)\n              AND sd_order.active = 1\n          )\n          AND EXISTS (\n            SELECT 1\n            FROM status_definitions sd_item\n            WHERE sd_item.scope = 'item'\n              AND UPPER(sd_item.department) IN ('G', 'S', 'F')\n              AND UPPER(sd_item.code) = UPPER(r.result_order_status_code)\n              AND sd_item.active = 1\n          )\n        GROUP BY UPPER(TRIM(r.result_order_status_code))\n        ORDER BY sort_priority ASC, sort_rule_id ASC\n    ");

    if (!$result instanceof mysqli_result) {
        return [];
    }

    $statuses = [];
    while ($row = $result->fetch_assoc()) {
        $status = ordersPlasticsGateNormalizeStatusCode($row['status_code'] ?? null);
        if ($status !== '') {
            $statuses[$status] = true;
        }
    }
    $result->free();

    return array_keys($statuses);
}

/**
 * @return array<int, string>
 */
function ordersPlasticsGateBlockedStatuses(mysqli $conn): array
{
    $statuses = ordersPlasticsGatePolicyBlockedStatuses($conn);
    $statuses[] = ORDERS_PLASTICS_GATE_FALLBACK_BLOCKED_STATUS;
    return array_keys(ordersPlasticsGateStatusMap($statuses));
}

function ordersPlasticsGatePrimaryBlockedStatus(mysqli $conn): string
{
    $statuses = ordersPlasticsGatePolicyBlockedStatuses($conn);
    return $statuses[0] ?? ORDERS_PLASTICS_GATE_FALLBACK_BLOCKED_STATUS;
}

/**
 * @return array<int, string>
 */
function ordersPlasticsGateStockCheckStatuses(mysqli $conn): array
{
    if (
        !ordersStatusTableExists($conn, 'status_workflow_rules')
        || !ordersStatusTableExists($conn, 'status_workflow_rule_conditions')
    ) {
        return [ORDERS_PLASTICS_GATE_FALLBACK_STOCK_CHECK_STATUS];
    }

    $blockedMap = ordersPlasticsGateStatusMap(ordersPlasticsGateBlockedStatuses($conn));
    $result = $conn->query("\n        SELECT r.result_order_status_code, c.status_code\n        FROM status_workflow_rules r\n        INNER JOIN status_workflow_rule_conditions c ON c.rule_id = r.id\n        WHERE r.active = 1\n          AND UPPER(c.department) = 'P'\n          AND LOWER(COALESCE(c.condition_type, 'status')) = 'status'\n          AND UPPER(COALESCE(c.operator, '')) IN ('=', 'IN')\n        ORDER BY r.priority ASC, r.id ASC, c.sort_order ASC, c.id ASC\n    ");

    if (!$result instanceof mysqli_result) {
        return [ORDERS_PLASTICS_GATE_FALLBACK_STOCK_CHECK_STATUS];
    }

    $statuses = [];
    while ($row = $result->fetch_assoc()) {
        $resultStatus = ordersPlasticsGateNormalizeStatusCode($row['result_order_status_code'] ?? null);
        if (!isset($blockedMap[$resultStatus])) {
            continue;
        }
        foreach (ordersPlasticsGateSplitStatusList($row['status_code'] ?? null) as $status) {
            $statuses[$status] = true;
        }
    }
    $result->free();

    if (!$statuses) {
        $statuses[ORDERS_PLASTICS_GATE_FALLBACK_STOCK_CHECK_STATUS] = true;
    }

    return array_keys($statuses);
}

function ordersPlasticsGateStockCheckStatus(mysqli $conn): string
{
    $statuses = ordersPlasticsGateStockCheckStatuses($conn);
    return $statuses[0] ?? ORDERS_PLASTICS_GATE_FALLBACK_STOCK_CHECK_STATUS;
}

/**
 * @return array<string, bool>
 */
function ordersPlasticsGateIgnoredDefaultStatusMap(?mysqli $conn = null): array
{
    $statuses = [
        ORDERS_PLASTICS_GATE_FALLBACK_STOCK_CHECK_STATUS,
        ORDERS_PLASTICS_GATE_FALLBACK_BLOCKED_STATUS,
    ];

    if ($conn instanceof mysqli) {
        $statuses = array_merge(
            $statuses,
            ordersPlasticsGateStockCheckStatuses($conn),
            ordersPlasticsGateBlockedStatuses($conn)
        );
    }

    return ordersPlasticsGateStatusMap($statuses);
}

/**
 * @return array<int, string>
 */
function ordersPlasticsGateNewPolicyStatuses(mysqli $conn, string $department): array
{
    $department = ordersNormalizeDepartmentCode($department);
    if (
        $department === ''
        || !ordersStatusTableExists($conn, 'status_workflow_rules')
        || !ordersStatusTableExists($conn, 'status_workflow_rule_conditions')
    ) {
        return [];
    }

    $stmt = $conn->prepare("\n        SELECT c.status_code\n        FROM status_workflow_rules r\n        INNER JOIN status_workflow_rule_conditions c ON c.rule_id = r.id\n        WHERE r.active = 1\n          AND UPPER(r.result_order_status_code) = 'NEW'\n          AND UPPER(c.department) = ?\n          AND LOWER(COALESCE(c.condition_type, 'status')) = 'status'\n          AND UPPER(COALESCE(c.operator, '')) IN ('=', 'IN')\n        ORDER BY r.priority ASC, r.id ASC, c.sort_order ASC, c.id ASC\n    ");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('s', $department);
    $stmt->execute();
    $result = $stmt->get_result();
    $statuses = [];
    while ($row = $result->fetch_assoc()) {
        foreach (ordersPlasticsGateSplitStatusList($row['status_code'] ?? null) as $status) {
            $statuses[$status] = true;
        }
    }
    $stmt->close();

    return array_keys($statuses);
}

/**
 * @return array<int, string>
 */
function ordersPlasticsGateConfirmedStatuses(mysqli $conn): array
{
    $ignored = ordersPlasticsGateIgnoredDefaultStatusMap($conn);
    $statuses = [];

    foreach (ordersPlasticsGateNewPolicyStatuses($conn, 'P') as $status) {
        if (!isset($ignored[$status])) {
            $statuses[$status] = true;
        }
    }

    foreach (['PK_✗', 'PK_X', 'PK X'] as $fallbackStatus) {
        $statuses[ordersPlasticsGateNormalizeStatusCode($fallbackStatus)] = true;
    }

    return array_keys($statuses);
}

function ordersPlasticsGateDefaultStatusForItem(mysqli $conn, array $item): string
{
    $department = ordersNormalizeDepartmentCode((string)($item['item_type_code'] ?? ''));
    $ignored = ordersPlasticsGateIgnoredDefaultStatusMap($conn);
    $policyStatuses = ordersPlasticsGateNewPolicyStatuses($conn, $department);
    $policyStatusMap = array_fill_keys($policyStatuses, true);
    $definitions = ordersGetItemStatusDefinitionsForItem($conn, $item, true);

    if ($policyStatusMap) {
        foreach ($definitions as $code => $_meta) {
            $code = ordersPlasticsGateNormalizeStatusCode((string)$code);
            if ($code !== '' && isset($policyStatusMap[$code]) && !isset($ignored[$code])) {
                return $code;
            }
        }

        foreach ($policyStatuses as $code) {
            $code = ordersPlasticsGateNormalizeStatusCode($code);
            if ($code !== '' && !isset($ignored[$code])) {
                return $code;
            }
        }
    }

    foreach ($definitions as $code => $_meta) {
        $code = ordersPlasticsGateNormalizeStatusCode((string)$code);
        if ($code !== '' && !isset($ignored[$code])) {
            return $code;
        }
    }

    return 'NEW';
}

function ordersPlasticsGateHasBlockedDependants(mysqli $conn, int $orderId): bool
{
    $blockedStatusSql = ordersPlasticsGateSqlStatusList($conn, ordersPlasticsGateBlockedStatuses($conn));
    $stmt = $conn->prepare("\n        SELECT 1\n        FROM order_items\n        WHERE order_id = ? AND deleted_at IS NULL\n          AND UPPER(item_type_code) IN ('G', 'S', 'F')\n          AND UPPER(COALESCE(status, '')) IN ({$blockedStatusSql})\n        LIMIT 1\n    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $blocked = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $blocked;
}

function ordersPlasticsGateIsIgnoredAssignmentTransition(mysqli $conn, array $item, ?string $oldStatus, ?string $newStatus): bool
{
    $department = ordersNormalizeDepartmentCode((string)($item['item_type_code'] ?? ''));
    $oldStatus = ordersPlasticsGateNormalizeStatusCode($oldStatus);
    $newStatus = ordersPlasticsGateNormalizeStatusCode($newStatus);
    if ($department === '' || $oldStatus === '' || $newStatus === '' || $oldStatus === $newStatus) {
        return false;
    }

    if ($department === 'P') {
        $stockCheckMap = ordersPlasticsGateStatusMap(ordersPlasticsGateStockCheckStatuses($conn));
        $confirmedMap = ordersPlasticsGateStatusMap(ordersPlasticsGateConfirmedStatuses($conn));
        return isset($stockCheckMap[$oldStatus]) && isset($confirmedMap[$newStatus]);
    }

    if (in_array($department, ['G', 'S', 'F'], true)) {
        $blockedMap = ordersPlasticsGateStatusMap(ordersPlasticsGateBlockedStatuses($conn));
        if (!isset($blockedMap[$oldStatus])) {
            return false;
        }

        $defaultStatus = ordersPlasticsGateNormalizeStatusCode(ordersPlasticsGateDefaultStatusForItem($conn, $item));
        return $defaultStatus !== '' && $newStatus === $defaultStatus;
    }

    return false;
}

function ordersPlasticsGateIsStockCheckReleaseTransition(mysqli $conn, int $orderId, array $item, ?string $oldStatus, ?string $newStatus): bool
{
    return ordersPlasticsGateIsIgnoredAssignmentTransition($conn, $item, $oldStatus, $newStatus)
        && ordersPlasticsGateHasBlockedDependants($conn, $orderId);
}

/**
 * @return array<int, array{id:int,item_type_code:string,old_status:string,new_status:string}>
 */
function ordersReleasePlasticsDependantsIfReady(mysqli $conn, int $orderId, int $userId): array
{
    $confirmedStatusMap = array_fill_keys(ordersPlasticsGateConfirmedStatuses($conn), true);
    $plasticsStmt = $conn->prepare("\n        SELECT status FROM order_items\n        WHERE order_id = ? AND deleted_at IS NULL\n          AND UPPER(item_type_code) = 'P'\n        ORDER BY id ASC FOR UPDATE\n    ");
    $plasticsStmt->bind_param('i', $orderId);
    $plasticsStmt->execute();
    $plasticsResult = $plasticsStmt->get_result();
    $plasticsCount = 0;
    $allConfirmed = true;
    while ($plasticsItem = $plasticsResult->fetch_assoc()) {
        $plasticsCount++;
        $status = ordersPlasticsGateNormalizeStatusCode($plasticsItem['status'] ?? null);
        if ($status === '' || !isset($confirmedStatusMap[$status])) {
            $allConfirmed = false;
        }
    }
    $plasticsStmt->close();

    if ($plasticsCount === 0 || !$allConfirmed) {
        return [];
    }

    $blockedStatusSql = ordersPlasticsGateSqlStatusList($conn, ordersPlasticsGateBlockedStatuses($conn));
    $dependentStmt = $conn->prepare("\n        SELECT id, UPPER(item_type_code) AS item_type_code, status, sku, custom_label, options_json, internal_options_json\n        FROM order_items\n        WHERE order_id = ? AND deleted_at IS NULL\n          AND UPPER(item_type_code) IN ('G', 'S', 'F')\n          AND UPPER(COALESCE(status, '')) IN ({$blockedStatusSql})\n        ORDER BY id ASC FOR UPDATE\n    ");
    $dependentStmt->bind_param('i', $orderId);
    $dependentStmt->execute();
    $dependentResult = $dependentStmt->get_result();
    $dependentItems = [];
    while ($dependentItem = $dependentResult->fetch_assoc()) {
        $dependentItems[] = $dependentItem;
    }
    $dependentStmt->close();

    if (!$dependentItems) {
        return [];
    }

    $updateStmt = $conn->prepare("\n        UPDATE order_items\n        SET status = ?, waiting_note = NULL, expected_date = NULL,\n            completed_by = NULL, completed_at = NULL\n        WHERE id = ?\n          AND UPPER(COALESCE(status, '')) IN ({$blockedStatusSql})\n    ");
    $historyStmt = $conn->prepare("\n        INSERT INTO order_item_statuses\n            (order_item_id, old_status, new_status, note, expected_date, changed_by)\n        VALUES (?, ?, ?, 'Released after plastics stock confirmation', NULL, ?)\n    ");

    $released = [];
    foreach ($dependentItems as $dependentItem) {
        $itemId = (int)$dependentItem['id'];
        $itemType = ordersNormalizeDepartmentCode((string)($dependentItem['item_type_code'] ?? ''));
        $oldStatus = (string)($dependentItem['status'] ?? '');
        $newStatus = ordersPlasticsGateDefaultStatusForItem($conn, $dependentItem);
        if ($newStatus === '' || $newStatus === ordersPlasticsGateNormalizeStatusCode($oldStatus)) {
            continue;
        }

        $updateStmt->bind_param('si', $newStatus, $itemId);
        $updateStmt->execute();
        if ($updateStmt->affected_rows !== 1) {
            continue;
        }

        $historyStmt->bind_param('issi', $itemId, $oldStatus, $newStatus, $userId);
        $historyStmt->execute();
        $released[] = [
            'id' => $itemId,
            'item_type_code' => $itemType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ];
    }
    $historyStmt->close();
    $updateStmt->close();

    if ($released && function_exists('log_order_activity')) {
        log_order_activity(
            $conn, $orderId, $userId, 'plastics_stock_gate_released',
            'order', $orderId, ['released_items' => $released],
            'Dependent items released after all plastics were confirmed in stock'
        );
    }
    return $released;
}