<?php

function ordersStatusTableExists(mysqli $conn, string $tableName): bool
{
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $safeName = $conn->real_escape_string($tableName);
    $sql = "SHOW TABLES LIKE '" . $safeName . "'";
    $result = $conn->query($sql);

    $cache[$tableName] = $result instanceof mysqli_result && $result->num_rows > 0;

    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return $cache[$tableName];
}

function ordersStatusDefinitionFallbacks(): array
{
    return [
        'order' => [
            'PENDING' => ['code' => 'PENDING', 'label' => 'Pending payment', 'color' => '#7c3aed', 'sort_order' => 5, 'active' => 1],
            'NEW' => ['code' => 'NEW', 'label' => 'New', 'color' => '#17a2b8', 'sort_order' => 10, 'active' => 1],
            'IN_PROGRESS' => ['code' => 'IN_PROGRESS', 'label' => 'In Progress', 'color' => '#ffc107', 'sort_order' => 20, 'active' => 1],
            'NEED_INFO' => ['code' => 'NEED_INFO', 'label' => 'Need Info', 'color' => '#dc3545', 'sort_order' => 30, 'active' => 1],
            'DRAFT_REQUESTED' => ['code' => 'DRAFT_REQUESTED', 'label' => 'Draft Requested', 'color' => '#17a2b8', 'sort_order' => 35, 'active' => 1],
            'DRAFT_READY' => ['code' => 'DRAFT_READY', 'label' => 'Draft Ready', 'color' => '#20c997', 'sort_order' => 40, 'active' => 1],
            'RIPPED' => ['code' => 'RIPPED', 'label' => 'Ripped', 'color' => '#0d6efd', 'sort_order' => 45, 'active' => 1],
            'PRINT_QUEUE' => ['code' => 'PRINT_QUEUE', 'label' => 'Print Queue', 'color' => '#0d6efd', 'sort_order' => 50, 'active' => 1],
            'PRODUCTION' => ['code' => 'PRODUCTION', 'label' => 'Production', 'color' => '#ffc107', 'sort_order' => 60, 'active' => 1],
            'READY_TO_INVOICE' => ['code' => 'READY_TO_INVOICE', 'label' => 'Ready to Invoice', 'color' => '#28a745', 'sort_order' => 70, 'active' => 1],
            'READY_TO_SHIP' => ['code' => 'READY_TO_SHIP', 'label' => 'Ready to Ship', 'color' => '#28a745', 'sort_order' => 80, 'active' => 1],
            'DONE' => ['code' => 'DONE', 'label' => 'Done', 'color' => '#28a745', 'sort_order' => 90, 'active' => 1],
            'SHIPPED' => ['code' => 'SHIPPED', 'label' => 'Shipped', 'color' => '#28a745', 'sort_order' => 100, 'active' => 1],
            'HOLD' => ['code' => 'HOLD', 'label' => 'Hold', 'color' => '#6c757d', 'sort_order' => 110, 'active' => 1],
            'CANCELLED' => ['code' => 'CANCELLED', 'label' => 'Cancelled', 'color' => '#6c757d', 'sort_order' => 120, 'active' => 1],
        ],
        'item' => [
            'G' => [
                'NEW' => ['code' => 'NEW', 'label' => 'New', 'color' => '#17a2b8', 'sort_order' => 10, 'active' => 1],
                'RTP' => ['code' => 'RTP', 'label' => 'RTP', 'color' => '#17a2b8', 'sort_order' => 20, 'active' => 1],
                'PRINT_QUEUE' => ['code' => 'PRINT_QUEUE', 'label' => 'Print Queue', 'color' => '#0d6efd', 'sort_order' => 30, 'active' => 1],
                'PRINTED' => ['code' => 'PRINTED', 'label' => 'Printed', 'color' => '#20c997', 'sort_order' => 40, 'active' => 1],
                'CUT' => ['code' => 'CUT', 'label' => 'Cut', 'color' => '#fd7e14', 'sort_order' => 50, 'active' => 1],
                'READY' => ['code' => 'READY', 'label' => 'Ready', 'color' => '#28a745', 'sort_order' => 60, 'active' => 1],
                'WAITING' => ['code' => 'WAITING', 'label' => 'Waiting', 'color' => '#dc3545', 'sort_order' => 70, 'active' => 1],
            ],
            'S' => [
                'NEW' => ['code' => 'NEW', 'label' => 'New', 'color' => '#17a2b8', 'sort_order' => 10, 'active' => 1],
                'PROCESSING' => ['code' => 'PROCESSING', 'label' => 'Processing', 'color' => '#ffc107', 'sort_order' => 20, 'active' => 1],
                'READY' => ['code' => 'READY', 'label' => 'Ready', 'color' => '#28a745', 'sort_order' => 30, 'active' => 1],
                'WAITING' => ['code' => 'WAITING', 'label' => 'Waiting', 'color' => '#dc3545', 'sort_order' => 40, 'active' => 1],
            ],
            'P' => [
                'NEW' => ['code' => 'NEW', 'label' => 'New', 'color' => '#17a2b8', 'sort_order' => 10, 'active' => 1],
                'PROCESSING' => ['code' => 'PROCESSING', 'label' => 'Processing', 'color' => '#ffc107', 'sort_order' => 20, 'active' => 1],
                'READY' => ['code' => 'READY', 'label' => 'Ready', 'color' => '#28a745', 'sort_order' => 30, 'active' => 1],
                'WAITING' => ['code' => 'WAITING', 'label' => 'Waiting', 'color' => '#dc3545', 'sort_order' => 40, 'active' => 1],
            ],
            'F' => [
                'NEW' => ['code' => 'NEW', 'label' => 'New', 'color' => '#17a2b8', 'sort_order' => 10, 'active' => 1],
                'PROCESSING' => ['code' => 'PROCESSING', 'label' => 'Processing', 'color' => '#ffc107', 'sort_order' => 20, 'active' => 1],
                'DONE' => ['code' => 'DONE', 'label' => 'Done', 'color' => '#20c997', 'sort_order' => 30, 'active' => 1],
                'READY' => ['code' => 'READY', 'label' => 'Ready', 'color' => '#28a745', 'sort_order' => 40, 'active' => 1],
                'WAITING' => ['code' => 'WAITING', 'label' => 'Waiting', 'color' => '#dc3545', 'sort_order' => 50, 'active' => 1],
            ],
        ],
    ];
}

function ordersNormalizeDepartmentCode(?string $code): string
{
    $code = strtoupper(trim((string)$code));

    if ($code === 'T' || $code === 'M') {
        return 'P';
    }

    return $code;
}

function ordersLoadStatusDefinitions(mysqli $conn): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $fallbacks = ordersStatusDefinitionFallbacks();
    $cache = [
        'order' => $fallbacks['order'],
        'item' => $fallbacks['item'],
    ];

    if (!ordersStatusTableExists($conn, 'status_definitions')) {
        return $cache;
    }

    $sql = "
        SELECT scope, department, code, label, color, sort_order, active
        FROM status_definitions
        ORDER BY scope ASC, department ASC, sort_order ASC, id ASC
    ";
    $result = $conn->query($sql);

    if (!$result instanceof mysqli_result) {
        return $cache;
    }

    $cache = [
        'order' => [],
        'item' => [],
    ];

    while ($row = $result->fetch_assoc()) {
        $scope = strtolower(trim((string)($row['scope'] ?? '')));
        $code = strtoupper(trim((string)($row['code'] ?? '')));

        if ($scope !== 'order' && $scope !== 'item') {
            continue;
        }
        if ($code === '') {
            continue;
        }

        $meta = [
            'code' => $code,
            'label' => trim((string)($row['label'] ?? '')) !== '' ? trim((string)$row['label']) : str_replace('_', ' ', $code),
            'color' => trim((string)($row['color'] ?? '')) ?: null,
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'active' => (int)($row['active'] ?? 1),
            'department' => ordersNormalizeDepartmentCode($row['department'] ?? null),
        ];

        if ($scope === 'order') {
            $cache['order'][$code] = $meta;
            continue;
        }

        $department = $meta['department'];
        if ($department === '') {
            continue;
        }

        if (!isset($cache['item'][$department])) {
            $cache['item'][$department] = [];
        }

        $cache['item'][$department][$code] = $meta;
    }

    $result->free();

    foreach (['G', 'S', 'P', 'F'] as $department) {
        if (empty($cache['item'][$department])) {
            $cache['item'][$department] = $fallbacks['item'][$department] ?? [];
        }
    }

    if (empty($cache['order'])) {
        $cache['order'] = $fallbacks['order'];
    }

    return $cache;
}

function ordersGetOrderStatusDefinitions(mysqli $conn, bool $activeOnly = true): array
{
    $definitions = ordersLoadStatusDefinitions($conn);
    $statuses = $definitions['order'] ?? [];

    if (!$activeOnly) {
        return $statuses;
    }

    return array_filter($statuses, static function (array $meta): bool {
        return (int)($meta['active'] ?? 1) === 1;
    });
}

function ordersGetItemStatusDefinitions(mysqli $conn, string $itemType, bool $activeOnly = true): array
{
    $department = ordersNormalizeDepartmentCode($itemType);
    $definitions = ordersLoadStatusDefinitions($conn);
    $statuses = $definitions['item'][$department] ?? [];

    if (!$activeOnly) {
        return $statuses;
    }

    return array_filter($statuses, static function (array $meta): bool {
        return (int)($meta['active'] ?? 1) === 1;
    });
}

function ordersGetOrderStatusCodes(mysqli $conn, bool $activeOnly = true): array
{
    return array_keys(ordersGetOrderStatusDefinitions($conn, $activeOnly));
}

function ordersGetItemStatusCodes(mysqli $conn, string $itemType, bool $activeOnly = true): array
{
    return array_keys(ordersGetItemStatusDefinitions($conn, $itemType, $activeOnly));
}

function ordersGetOrderStatusLabels(mysqli $conn, bool $activeOnly = true): array
{
    $labels = [];

    foreach (ordersGetOrderStatusDefinitions($conn, $activeOnly) as $code => $meta) {
        $labels[$code] = (string)($meta['label'] ?? $code);
    }

    return $labels;
}

function ordersGetItemStatusLabels(mysqli $conn, string $itemType, bool $activeOnly = true): array
{
    $labels = [];

    foreach (ordersGetItemStatusDefinitions($conn, $itemType, $activeOnly) as $code => $meta) {
        $labels[$code] = (string)($meta['label'] ?? $code);
    }

    return $labels;
}

function ordersGetStatusMeta(mysqli $conn, string $scope, string $code, ?string $department = null): ?array
{
    $scope = strtolower(trim($scope));
    $code = strtoupper(trim($code));

    if ($scope === 'order') {
        $statuses = ordersGetOrderStatusDefinitions($conn, false);
        return $statuses[$code] ?? null;
    }

    if ($scope === 'item') {
        $statuses = ordersGetItemStatusDefinitions($conn, (string)$department, false);
        return $statuses[$code] ?? null;
    }

    return null;
}

function ordersGetStatusLabel(mysqli $conn, string $scope, string $code, ?string $department = null): string
{
    $meta = ordersGetStatusMeta($conn, $scope, $code, $department);
    if ($meta && trim((string)($meta['label'] ?? '')) !== '') {
        return (string)$meta['label'];
    }

    return str_replace('_', ' ', strtoupper(trim($code)));
}

function ordersGetStatusColor(mysqli $conn, string $scope, string $code, ?string $department = null): ?string
{
    $meta = ordersGetStatusMeta($conn, $scope, $code, $department);
    $color = trim((string)($meta['color'] ?? ''));

    return $color !== '' ? $color : null;
}

function ordersGetOrderStatusButtonClass(string $status): string
{
    switch (strtoupper(trim($status))) {
        case 'NEW':
            return 'btn-outline-danger';
        case 'PENDING':
            return 'btn-outline-pending';
        case 'IN_PROGRESS':
        case 'READY_TO_INVOICE':
        case 'WAITING_PARTS':
        case 'PRODUCTION':
            return 'btn-outline-warning';
        case 'DRAFT_REQUESTED':
        case 'DRAFT_READY':
            return 'btn-outline-info';
        case 'RIPPED':
        case 'PRINT_QUEUE':
            return 'btn-outline-primary';
        case 'DONE':
        case 'COMPLETED':
        case 'SHIPPED':
        case 'READY':
        case 'READY_TO_SHIP':
            return 'btn-outline-success';
        case 'NEED_INFO':
            return 'btn-outline-danger';
        case 'HOLD':
        case 'CANCELLED':
            return 'btn-outline-secondary';
        default:
            return 'btn-outline-secondary';
    }
}

function ordersGetOrderStatusBadgeClass(string $status): string
{
    switch (strtoupper(trim($status))) {
        case 'NEW':
            return 'bg-info';
        case 'PENDING':
            return 'bg-pending';
        case 'IN_PROGRESS':
        case 'READY_TO_INVOICE':
        case 'PRODUCTION':
            return 'bg-warning';
        case 'NEED_INFO':
            return 'bg-danger';
        case 'DRAFT_REQUESTED':
        case 'DRAFT_READY':
            return 'bg-info';
        case 'DONE':
        case 'COMPLETED':
        case 'SHIPPED':
        case 'READY_TO_SHIP':
            return 'bg-success';
        case 'HOLD':
        case 'CANCELLED':
            return 'bg-secondary';
        default:
            return 'bg-secondary';
    }
}

function ordersGetOrderStatusAccentColor(mysqli $conn, string $status): string
{
    $dbColor = ordersGetStatusColor($conn, 'order', $status);
    if ($dbColor !== null) {
        return $dbColor;
    }

    switch (strtoupper(trim($status))) {
        case 'NEW':
            return '#17a2b8';
        case 'PENDING':
            return '#7c3aed';
        case 'IN_PROGRESS':
        case 'READY_TO_INVOICE':
        case 'PRODUCTION':
            return '#ffc107';
        case 'NEED_INFO':
            return '#dc3545';
        case 'DRAFT_REQUESTED':
        case 'DRAFT_READY':
            return '#20c997';
        case 'RIPPED':
        case 'PRINT_QUEUE':
            return '#0d6efd';
        case 'DONE':
        case 'COMPLETED':
        case 'READY_TO_SHIP':
        case 'SHIPPED':
            return '#28a745';
        case 'HOLD':
        case 'CANCELLED':
            return '#6c757d';
        default:
            return '#3f9eff';
    }
}
