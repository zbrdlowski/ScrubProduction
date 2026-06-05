<?php

require_once __DIR__ . '/orders_status_helpers.php';

function seatCoverOptionIsPositive($value): bool
{
    if (is_array($value)) {
        return false;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return false;
    }

    $negativeValues = ['no', 'nie', 'nein', 'non', 'false', '0', 'n/a', '-', 'x'];
    return !in_array(mb_strtolower($value), $negativeValues, true);
}

function seatCoverOperationsStateFromJsonStrings(?string $optionsJson, ?string $internalOptionsJson): array
{
    $requiredMap = [
        'waterproof-seams' => 'WS',
        'enduro-pocket' => 'EP',
        'side-brand-patches' => 'SP',
    ];

    $options = json_decode((string)$optionsJson, true);
    $internal = json_decode((string)$internalOptionsJson, true);

    if (!is_array($options)) {
        $options = [];
    }
    if (!is_array($internal)) {
        $internal = [];
    }

    $confirmed = $internal['_seat_cover_ops_confirmed'] ?? [];
    if (!is_array($confirmed)) {
        $confirmed = [];
    }

    $required = [];
    foreach ($requiredMap as $optionKey => $shortCode) {
        if (seatCoverOptionIsPositive($options[$optionKey] ?? null)) {
            $required[$optionKey] = [
                'code' => $shortCode,
                'confirmed' => !empty($confirmed[$optionKey]),
            ];
        }
    }

    $allConfirmed = true;
    foreach ($required as $data) {
        if (empty($data['confirmed'])) {
            $allConfirmed = false;
            break;
        }
    }

    return [
        'required' => $required,
        'all_confirmed' => $allConfirmed,
    ];
}

function isOrderItemReady(string $type, string $status, ?string $optionsJson = null, ?string $internalOptionsJson = null): bool
{
    $type = strtoupper($type);
    $status = strtoupper($status);

    if ($type === 'G') {
        return in_array($status, ['PRINTED', 'CUT', 'READY'], true);
    }

    if ($type === 'F') {
        return in_array($status, ['DONE', 'READY'], true);
    }

    if ($type === 'S') {
        if ($status !== 'READY') {
            return false;
        }

        $seatCoverState = seatCoverOperationsStateFromJsonStrings($optionsJson, $internalOptionsJson);
        return $seatCoverState['all_confirmed'];
    }

    return $status === 'READY';
}

function itemTrafficState(string $type, array $items): string
{
    $total = count($items);
    $ready = 0;
    $started = 0;
    $waiting = 0;

    foreach ($items as $item) {
        $status = strtoupper((string)($item['status'] ?? ''));
        $optionsJson = (string)($item['options_json'] ?? '');
        $internalOptionsJson = (string)($item['internal_options_json'] ?? '');

        if ($status === 'WAITING') {
            $waiting++;
            $started++;
        }

        if (isOrderItemReady($type, $status, $optionsJson, $internalOptionsJson)) {
            $ready++;
            $started++;
        }

        if (in_array($status, ['PROCESSING', 'RTP', 'PRINT_QUEUE', 'PRINTED', 'CUT', 'DONE', 'READY'], true)) {
            $started++;
        }
    }

    if ($total > 0 && $ready === $total) {
        return 'GREEN';
    }

    if ($waiting > 0 || $started > 0) {
        return 'ORANGE';
    }

    return 'RED';
}

function ordersWorkflowTableAvailable(mysqli $conn, string $tableName): bool
{
    return ordersStatusTableExists($conn, $tableName);
}

function ordersDepartmentWorkflowStatus(mysqli $conn, string $department, array $items): string
{
    $department = ordersNormalizeDepartmentCode($department);
    if (!$items) {
        return 'NEW';
    }

    $allReady = true;
    $hasWaiting = false;
    $hasStarted = false;
    $matchedActiveStatuses = [];
    $definitions = ordersGetItemStatusDefinitions($conn, $department, true);

    foreach ($items as $item) {
        $status = strtoupper((string)($item['status'] ?? 'NEW'));
        $optionsJson = (string)($item['options_json'] ?? '');
        $internalOptionsJson = (string)($item['internal_options_json'] ?? '');

        if ($status === 'WAITING') {
            $hasWaiting = true;
        }

        if (!isOrderItemReady($department, $status, $optionsJson, $internalOptionsJson)) {
            $allReady = false;
        }

        if (!in_array($status, ['NEW', 'WAITING'], true)) {
            $hasStarted = true;
        }

        if (isset($definitions[$status])) {
            $matchedActiveStatuses[$status] = (int)($definitions[$status]['sort_order'] ?? 0);
        }
    }

    if ($hasWaiting) {
        return 'WAITING';
    }

    if ($allReady) {
        return 'READY';
    }

    if ($matchedActiveStatuses) {
        arsort($matchedActiveStatuses, SORT_NUMERIC);
        return (string)array_key_first($matchedActiveStatuses);
    }

    if ($hasStarted) {
        return 'PROCESSING';
    }

    return 'NEW';
}

function ordersWorkflowConditionMatches(string $actualStatus, string $operator, string $expectedStatus): bool
{
    $actualStatus = strtoupper(trim($actualStatus));
    $operator = strtoupper(trim($operator));
    $expectedStatus = strtoupper(trim($expectedStatus));

    $expectedList = array_values(array_filter(array_map('trim', preg_split('/[,\|]+/', $expectedStatus))));
    if (!$expectedList) {
        $expectedList = [$expectedStatus];
    }

    switch ($operator) {
        case '=':
            return $actualStatus === $expectedList[0];
        case '!=':
            return $actualStatus !== $expectedList[0];
        case 'IN':
            return in_array($actualStatus, $expectedList, true);
        case 'NOT IN':
            return !in_array($actualStatus, $expectedList, true);
        default:
            return false;
    }
}

function ordersResolveStatusFromRules(mysqli $conn, array $departmentStatuses): ?string
{
    if (
        !ordersWorkflowTableAvailable($conn, 'status_workflow_rules')
        || !ordersWorkflowTableAvailable($conn, 'status_workflow_rule_conditions')
    ) {
        return null;
    }

    $sql = "
        SELECT
            r.id,
            r.result_order_status_code,
            r.stop_on_match,
            c.department,
            c.operator,
            c.status_code
        FROM status_workflow_rules r
        LEFT JOIN status_workflow_rule_conditions c
            ON c.rule_id = r.id
        WHERE r.active = 1
        ORDER BY r.priority ASC, r.id ASC, c.sort_order ASC, c.id ASC
    ";
    $result = $conn->query($sql);

    if (!$result instanceof mysqli_result) {
        return null;
    }

    $rules = [];
    while ($row = $result->fetch_assoc()) {
        $ruleId = (int)($row['id'] ?? 0);
        if ($ruleId <= 0) {
            continue;
        }

        if (!isset($rules[$ruleId])) {
            $rules[$ruleId] = [
                'result_order_status_code' => strtoupper(trim((string)($row['result_order_status_code'] ?? ''))),
                'stop_on_match' => (int)($row['stop_on_match'] ?? 1) === 1,
                'conditions' => [],
            ];
        }

        $department = ordersNormalizeDepartmentCode($row['department'] ?? null);
        $operator = trim((string)($row['operator'] ?? ''));
        $statusCode = trim((string)($row['status_code'] ?? ''));

        if ($department !== '' && $operator !== '' && $statusCode !== '') {
            $rules[$ruleId]['conditions'][] = [
                'department' => $department,
                'operator' => $operator,
                'status_code' => $statusCode,
            ];
        }
    }

    $result->free();

    foreach ($rules as $rule) {
        if (empty($rule['result_order_status_code']) || empty($rule['conditions'])) {
            continue;
        }

        $matched = true;

        foreach ($rule['conditions'] as $condition) {
            $department = $condition['department'];
            $actualStatus = strtoupper((string)($departmentStatuses[$department] ?? 'NEW'));

            if (!ordersWorkflowConditionMatches($actualStatus, $condition['operator'], $condition['status_code'])) {
                $matched = false;
                break;
            }
        }

        if ($matched) {
            return $rule['result_order_status_code'];
        }
    }

    return null;
}

function ordersResolveFallbackOrderStatus(array $groups, bool $allGreen, bool $hasOrange, bool $hasGreen): array
{
    if (!$groups) {
        return ['NEW', 'RED'];
    }

    if ($allGreen) {
        return ['READY_TO_INVOICE', 'GREEN'];
    }

    if ($hasOrange || $hasGreen) {
        return ['IN_PROGRESS', 'ORANGE'];
    }

    return ['NEW', 'RED'];
}

function recalculateOrderWorkflow(mysqli $conn, int $orderId): void
{
    $stmt = $conn->prepare("
        SELECT item_type_code, status, options_json, internal_options_json
        FROM order_items
        WHERE order_id = ?
          AND deleted_at IS NULL
          AND item_type_code IS NOT NULL
          AND item_type_code <> ''
        ORDER BY id ASC
    ");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $res = $stmt->get_result();

    $groups = [];

    while ($item = $res->fetch_assoc()) {
        $type = ordersNormalizeDepartmentCode((string)$item['item_type_code']);
        $status = strtoupper((string)($item['status'] ?? 'NEW'));

        if ($type === '') {
            continue;
        }

        if (!isset($groups[$type])) {
            $groups[$type] = [];
        }

        $groups[$type][] = [
            'status' => $status,
            'options_json' => $item['options_json'] ?? null,
            'internal_options_json' => $item['internal_options_json'] ?? null,
        ];
    }

    $stmt->close();

    $summary = [];
    $departmentStatuses = [];
    $allGreen = true;
    $hasOrange = false;
    $hasGreen = false;
    $firstBlocker = '';

    foreach ($groups as $type => $statuses) {
        $state = itemTrafficState($type, $statuses);
        $summary[$type] = $state;
        $departmentStatuses[$type] = ordersDepartmentWorkflowStatus($conn, $type, $statuses);

        if ($state === 'GREEN') {
            $hasGreen = true;
        }

        if ($state !== 'GREEN') {
            $allGreen = false;
            if ($firstBlocker === '') {
                $firstBlocker = $type;
            }
        }

        if ($state === 'ORANGE') {
            $hasOrange = true;
        }
    }

    [$fallbackOrderStatus, $traffic] = ordersResolveFallbackOrderStatus($groups, $allGreen, $hasOrange, $hasGreen);
    $ruleBasedOrderStatus = ordersResolveStatusFromRules($conn, $departmentStatuses);
    $orderStatus = $ruleBasedOrderStatus ?: $fallbackOrderStatus;

    $summaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("
        UPDATE orders
        SET status = ?,
            traffic_light = ?,
            traffic_blocker = ?,
            traffic_summary_json = ?
        WHERE id = ?
    ");
    $stmt->bind_param('ssssi', $orderStatus, $traffic, $firstBlocker, $summaryJson, $orderId);
    $stmt->execute();
    $stmt->close();
}

function addOrderActivity(
    mysqli $conn,
    int $orderId,
    ?int $actorEmployeeId,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    array $payload = [],
    ?string $note = null
): void {
    $payloadJson = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $stmt = $conn->prepare("
        INSERT INTO order_activity
        (order_id, actor_employee_id, action, entity_type, entity_id, payload, note)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'iississ',
        $orderId,
        $actorEmployeeId,
        $action,
        $entityType,
        $entityId,
        $payloadJson,
        $note
    );
    $stmt->execute();
    $stmt->close();
}
