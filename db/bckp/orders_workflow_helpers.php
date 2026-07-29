<?php

require_once __DIR__ . '/orders_status_helpers.php';

function seatCoverOptionIsPositive($value): bool
{
    if (is_array($value)) {
        return false;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }

    $negativeValues = ['no', 'nie', 'nein', 'non', 'false', '0', 'n/a', '-', 'x'];
    return !in_array(mb_strtolower($value), $negativeValues, true);
}

function seatCoverOperationIsConfirmed($value): bool
{
    if ($value === true) {
        return true;
    }

    if (is_array($value) || is_object($value) || $value === null) {
        return false;
    }

    $normalized = trim(mb_strtolower((string) $value, 'UTF-8'));
    return in_array($normalized, ['1', 'true', 'yes'], true);
}

function seatCoverResolvedConfirmationValue(array $options, array $internal, array $confirmed, string $optionKey, string $jsonKey, string $internalKey)
{
    if (array_key_exists($internalKey, $internal)) {
        return $internal[$internalKey];
    }

    if (array_key_exists($optionKey, $confirmed)) {
        return $confirmed[$optionKey];
    }

    return $options[$jsonKey] ?? null;
}

function seatCoverOperationsStateFromJsonStrings(?string $optionsJson, ?string $internalOptionsJson): array
{
    $requiredMap = [
        'waterproof-seams' => ['code' => 'WS', 'json_key' => 'waterproof-seams', 'internal_key' => '_seat_waterproof_seams'],
        'enduro-pocket' => ['code' => 'EP', 'json_key' => 'enduro-pocket', 'internal_key' => '_seat_enduro_pocket'],
        'side-brand-patches' => ['code' => 'SP', 'json_key' => 'side-brand-patches', 'internal_key' => '_seat_side_brand_patches'],
        'patch-applied' => ['code' => 'PA', 'json_key' => 'patch-style', 'internal_key' => '_seat_patch_applied'],
    ];

    $options = json_decode((string) $optionsJson, true);
    $internal = json_decode((string) $internalOptionsJson, true);

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
    foreach ($requiredMap as $optionKey => $meta) {
        $jsonKey = $meta['json_key'];
        if (seatCoverOptionIsPositive($options[$jsonKey] ?? null)) {
            $required[$optionKey] = [
                'code' => $meta['code'],
                'confirmed' => seatCoverOperationIsConfirmed(
                    seatCoverResolvedConfirmationValue($options, $internal, $confirmed, $optionKey, $jsonKey, $meta['internal_key'])
                ),
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
        // POZN.: CUT a PRINTED su medzikroky pred READY (pozri status_definitions,
        // sort_order 40/50 vs READY=60). Kedysi tu boli natvrdo zapisane ako
        // "hotovo" ekvivalentne s READY, co sposobovalo, ze polozka so statusom
        // Printed/Cut bola vyhodnotena ako READY aj v semafore aj v recalculateOrderWorkflow()
        // (viedlo to k nespravnemu Ready To Invoice namiesto In Progress).
        return $status === 'READY';
    }

    if ($type === 'F') {
        // Fitting ma iba New / Ready / Reprint - DONE tu nikdy nebol realny
        // status, takze ho tu netreba (bol to rovnaky typ hardcoded skratu
        // ako predtym CUT/PRINTED pri Graphics).
        return $status === 'READY';
    }

    if ($type === 'S') {
        if ($status !== 'READY') {
            return false;
        }

        // DOČASNE VYPNUTÉ:
        // Ready validácia seat-cover operácií je zatiaľ bypassnutá aj pre
        // workflow/semafor, aby bolo správanie konzistentné s
        // scripts/orders/update_item_status.php. Po zavedení translation flat
        // file / unifikácie vstupných hodnôt treba znovu zapnúť vyhodnocovanie
        // nižšie a doladiť nad normalizovanými dátami.
        if (false) {
            $seatCoverState = seatCoverOperationsStateFromJsonStrings($optionsJson, $internalOptionsJson);
            return $seatCoverState['all_confirmed'];
        }

        return true;
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
        $status = strtoupper((string) ($item['status'] ?? ''));
        $optionsJson = (string) ($item['options_json'] ?? '');
        $internalOptionsJson = (string) ($item['internal_options_json'] ?? '');

        if ($status === 'WAITING') {
            $waiting++;
            $started++;
        }

        if (isOrderItemReady($type, $status, $optionsJson, $internalOptionsJson)) {
            $ready++;
            $started++;
        }

        // Any non-empty status other than NEW counts as work that has started.
        // This keeps fallback workflow compatible with custom department statuses
        // such as FITTING_REGAL created in status_definitions.
        if ($status !== '' && $status !== 'NEW') {
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
        $status = strtoupper((string) ($item['status'] ?? 'NEW'));
        $optionsJson = (string) ($item['options_json'] ?? '');
        $internalOptionsJson = (string) ($item['internal_options_json'] ?? '');

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
            $matchedActiveStatuses[$status] = (int) ($definitions[$status]['sort_order'] ?? 0);
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
        return (string) array_key_first($matchedActiveStatuses);
    }

    if ($hasStarted) {
        return 'PROCESSING';
    }

    return 'NEW';
}

function ordersResolveDepartmentStatusesFromGroups(mysqli $conn, array $groups): array
{
    $departmentStatuses = [];

    foreach ($groups as $type => $items) {
        $department = ordersNormalizeDepartmentCode((string) $type);
        if ($department === '' || !is_array($items) || !$items) {
            continue;
        }

        $departmentStatuses[$department] = ordersDepartmentWorkflowStatus($conn, $department, $items);
    }

    return $departmentStatuses;
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

function ordersWorkflowRuleConditionMatches(array $condition, array $departmentStatuses): bool
{
    $department = ordersNormalizeDepartmentCode($condition['department'] ?? null);
    if ($department === '') {
        return false;
    }

    $conditionType = strtolower(trim((string) ($condition['condition_type'] ?? 'status')));
    $operator = strtoupper(trim((string) ($condition['operator'] ?? '')));
    $departmentPresent = array_key_exists($department, $departmentStatuses);

    if ($conditionType === 'presence') {
        if ($operator === 'PRESENT') {
            return $departmentPresent;
        }

        if ($operator === 'ABSENT') {
            return !$departmentPresent;
        }

        return false;
    }

    $actualStatus = strtoupper((string) ($departmentStatuses[$department] ?? 'NEW'));
    return ordersWorkflowConditionMatches($actualStatus, $operator, (string) ($condition['status_code'] ?? ''));
}

function ordersGetCurrentOrderStatus(mysqli $conn, int $orderId): string
{
    $currentStatus = '';

    $stmt = $conn->prepare("
        SELECT status
        FROM orders
        WHERE id = ?
        LIMIT 1
    "
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $stmt->bind_result($currentStatus);
    $stmt->fetch();
    $stmt->close();

    return strtoupper(trim((string) $currentStatus));
}

function ordersWorkflowAllowedStatusScopeAvailable(mysqli $conn): bool
{
    return ordersWorkflowTableAvailable($conn, 'status_workflow_rule_allowed_order_statuses');
}

function ordersWorkflowCurrentStatusIsAllowedByAnyRule(mysqli $conn, string $currentOrderStatus): bool
{
    $currentOrderStatus = strtoupper(trim($currentOrderStatus));
    if ($currentOrderStatus === '') {
        return false;
    }

    if (!ordersWorkflowAllowedStatusScopeAvailable($conn)) {
        return true;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM status_workflow_rules r
        INNER JOIN status_workflow_rule_allowed_order_statuses aos
            ON aos.rule_id = r.id
        WHERE r.active = 1
          AND UPPER(aos.order_status_code) = ?
        LIMIT 1
    "
    );
    $stmt->bind_param('s', $currentOrderStatus);
    $stmt->execute();
    $result = $stmt->get_result();
    $allowed = $result instanceof mysqli_result && $result->num_rows > 0;
    $stmt->close();

    return $allowed;
}

function ordersWorkflowFetchAllowedStatusesByRule(mysqli $conn): array
{
    if (!ordersWorkflowAllowedStatusScopeAvailable($conn)) {
        return [];
    }

    $result = $conn->query("
        SELECT rule_id, order_status_code
        FROM status_workflow_rule_allowed_order_statuses
        ORDER BY rule_id ASC, order_status_code ASC
    "
    );

    if (!$result instanceof mysqli_result) {
        return [];
    }

    $allowedStatuses = [];
    while ($row = $result->fetch_assoc()) {
        $ruleId = (int) ($row['rule_id'] ?? 0);
        $statusCode = strtoupper(trim((string) ($row['order_status_code'] ?? '')));

        if ($ruleId <= 0 || $statusCode === '') {
            continue;
        }

        if (!isset($allowedStatuses[$ruleId])) {
            $allowedStatuses[$ruleId] = [];
        }

        $allowedStatuses[$ruleId][] = $statusCode;
    }

    $result->free();
    return $allowedStatuses;
}

function ordersResolveStatusFromRules(mysqli $conn, array $departmentStatuses, string $currentOrderStatus = ''): ?string
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
            c.condition_type,
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
        $ruleId = (int) ($row['id'] ?? 0);
        if ($ruleId <= 0) {
            continue;
        }

        if (!isset($rules[$ruleId])) {
            $rules[$ruleId] = [
                'result_order_status_code' => strtoupper(trim((string) ($row['result_order_status_code'] ?? ''))),
                'stop_on_match' => (int) ($row['stop_on_match'] ?? 1) === 1,
                'allowed_statuses' => [],
                'conditions' => [],
            ];
        }

        $department = ordersNormalizeDepartmentCode($row['department'] ?? null);
        $conditionType = trim((string) ($row['condition_type'] ?? 'status'));
        $operator = trim((string) ($row['operator'] ?? ''));
        $statusCode = trim((string) ($row['status_code'] ?? ''));

        if ($department !== '' && $operator !== '') {
            $rules[$ruleId]['conditions'][] = [
                'department' => $department,
                'condition_type' => $conditionType,
                'operator' => $operator,
                'status_code' => $statusCode,
            ];
        }
    }

    $result->free();

    $scopeAvailable = ordersWorkflowAllowedStatusScopeAvailable($conn);
    $currentOrderStatus = strtoupper(trim($currentOrderStatus));

    if ($scopeAvailable) {
        $allowedStatusesByRule = ordersWorkflowFetchAllowedStatusesByRule($conn);
        foreach ($allowedStatusesByRule as $ruleId => $allowedStatuses) {
            if (isset($rules[$ruleId])) {
                $rules[$ruleId]['allowed_statuses'] = array_values(array_unique($allowedStatuses));
            }
        }
    }

    $resolvedStatus = null;

    foreach ($rules as $rule) {
        if ($scopeAvailable) {
            $allowedStatuses = $rule['allowed_statuses'] ?? [];
            if (!$allowedStatuses || !in_array($currentOrderStatus, $allowedStatuses, true)) {
                continue;
            }
        }

        if (empty($rule['result_order_status_code']) || empty($rule['conditions'])) {
            continue;
        }

        $matched = true;

        foreach ($rule['conditions'] as $condition) {
            if (!ordersWorkflowRuleConditionMatches($condition, $departmentStatuses)) {
                $matched = false;
                break;
            }
        }

        if (!$matched) {
            continue;
        }

        // Kazda zhoda prepise predchadzajucu (poradie je podla priority ASC,
        // takze pravidlo s vyssim cislom priority - teda nizsou prioritou -
        // moze prepisat vysledok skoreho, vseobecnejsieho pravidla).
        $resolvedStatus = $rule['result_order_status_code'];

        if ($rule['stop_on_match']) {
            // Stop On Match = Yes - toto je definitivny vysledok, dalej sa
            // uz nepokracuje.
            return $resolvedStatus;
        }

        // Stop On Match = No - berieme tento vysledok len ako "zatial platny"
        // a pokracujeme dalej, ci nesedi este presnejsie (nizsia priorita) pravidlo.
    }

    return $resolvedStatus;
}

function ordersOrderSourceMeta(mysqli $conn, int $orderId): array
{
    $stmt = $conn->prepare("
        SELECT source_meta
        FROM orders
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $decoded = json_decode((string) ($row['source_meta'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function ordersOrderDoNotInvoice(mysqli $conn, int $orderId): bool
{
    $sourceMeta = ordersOrderSourceMeta($conn, $orderId);
    $followupMeta = $sourceMeta['_followup'] ?? null;

    if (!is_array($followupMeta)) {
        return false;
    }

    return !empty($followupMeta['do_not_invoice']);
}

function ordersHasManualStatusOverride(mysqli $conn, int $orderId): bool
{
    $stmt = $conn->prepare("
        SELECT status_override
        FROM orders
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['status_override'] ?? 0) === 1;
}
function recalculateOrderWorkflow(mysqli $conn, int $orderId): void
{
    $currentOrderStatus = ordersGetCurrentOrderStatus($conn, $orderId);
     if (ordersHasManualStatusOverride($conn, $orderId)) {
        return;
    }

    /* finálne stavy workflow nikdy neprepisuje */
    /*
    if (
        in_array($currentOrderStatus, [
            'SHIPPED',
            'CANCELLED',
            'DELIVERED',
            'PENDING'
        ], true)
    ) {
        return;
    }
    */
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
        $type = ordersNormalizeDepartmentCode((string) $item['item_type_code']);
        $status = strtoupper((string) ($item['status'] ?? 'NEW'));

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
    $allGreen = true;
    $hasOrange = false;
    $hasGreen = false;
    $firstBlocker = '';

    foreach ($groups as $type => $statuses) {
        $state = itemTrafficState($type, $statuses);
        $summary[$type] = $state;

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

    $departmentStatuses = ordersResolveDepartmentStatusesFromGroups($conn, $groups);

    // Semafor (traffic light) na urovni celej objednavky je iba vizualny
    // indikator a pocita sa vzdy nezavisle od toho, aky overall status
    // objednavka nakoniec dostane - nesuvisi s dynamickymi status policies.
    if (!$groups) {
        $traffic = 'RED';
    } elseif ($allGreen) {
        $traffic = 'GREEN';
    } elseif ($hasOrange || $hasGreen) {
        $traffic = 'ORANGE';
    } else {
        $traffic = 'RED';
    }

    $currentStatusIsWorkflowScoped = ordersWorkflowCurrentStatusIsAllowedByAnyRule($conn, $currentOrderStatus);
    $ruleBasedOrderStatus = $currentStatusIsWorkflowScoped
        ? ordersResolveStatusFromRules($conn, $departmentStatuses, $currentOrderStatus)
        : null;

    if ($ruleBasedOrderStatus !== null) {
        // Niektora aktivna status policy sedi na aktualnu kombinaciu
        // department statusov - jej vysledok je zdrojom pravdy.
        $orderStatus = $ruleBasedOrderStatus;
    } elseif ($currentOrderStatus !== '') {
        // Ziadna status policy nesedi na aktualnu kombinaciu department
        // statusov (alebo current status nie je v scope ziadnej policy).
        // Povodne tu bol stary hardcoded fallback (all green => Ready To
        // Invoice / nejaky orange => In Progress), ktory vedel ticho
        // prepisat status aj bez zodpovedajucej policy. Namiesto toho
        // status objednavky teraz jednoducho ostava nezmeneny - treba
        // doplnit chybajucu policy pre danu kombinaciu.
        $orderStatus = $currentOrderStatus;
    } else {
        // Objednavka bez akehokolvek doterajsieho statusu (edge-case,
        // typicky len tesne po importe) - bezpecny minimalny default.
        $orderStatus = 'NEW';
    }

    // Warranty / no-invoice production objednavky nikdy nemaju skoncit v
    // Ready To Invoice (nie je co fakturovat) - bez ohladu na to, ci tento
    // status prisiel z policy alebo ostal nezmeneny. Predtym bola tato
    // kontrola iba vnutri stareho hardcoded fallbacku a teraz by inak tichoo
    // prestala fungovat.
    if ($orderStatus === 'READY_TO_INVOICE' && ordersOrderDoNotInvoice($conn, $orderId)) {
        $orderStatus = 'READY';
    }

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