<?php

require_once __DIR__ . '/status_definition_extensions.php';

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

    $extensionsAvailable = statusDefinitionEnsureExtensions($conn);

    $sql = "
        SELECT id, scope, department, code, label, color, sort_order, active
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
            'id' => (int)($row['id'] ?? 0),
            'code' => $code,
            'label' => trim((string)($row['label'] ?? '')) !== '' ? trim((string)$row['label']) : str_replace('_', ' ', $code),
            'color' => trim((string)($row['color'] ?? '')) ?: null,
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'active' => (int)($row['active'] ?? 1),
            'department' => ordersNormalizeDepartmentCode($row['department'] ?? null),
            'targets' => ['ALL'],
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

    if ($extensionsAvailable) {
        $targetResult = $conn->query("
            SELECT status_definition_id, target_type, subcategory_code
            FROM status_definition_targets
            ORDER BY status_definition_id, target_type, subcategory_code
        ");
        if ($targetResult instanceof mysqli_result) {
            $targetsByDefinition = [];
            while ($targetRow = $targetResult->fetch_assoc()) {
                $definitionId = (int)($targetRow['status_definition_id'] ?? 0);
                $targetType = strtoupper(trim((string)($targetRow['target_type'] ?? '')));
                $subcategory = strtoupper(trim((string)($targetRow['subcategory_code'] ?? '')));
                if ($definitionId <= 0 || $targetType === '') {
                    continue;
                }
                $targetKey = $targetType === 'SUBCATEGORY' && $subcategory !== ''
                    ? 'SUBCATEGORY:' . $subcategory
                    : $targetType;
                $targetsByDefinition[$definitionId][$targetKey] = true;
            }
            $targetResult->free();

            foreach ($cache['item'] as &$departmentStatuses) {
                foreach ($departmentStatuses as &$statusMeta) {
                    $definitionId = (int)($statusMeta['id'] ?? 0);
                    $statusMeta['targets'] = !empty($targetsByDefinition[$definitionId])
                        ? array_keys($targetsByDefinition[$definitionId])
                        : ['ALL'];
                }
                unset($statusMeta);
            }
            unset($departmentStatuses);
        }
    }

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

function ordersResolveGraphicsSubcategory(array $item): string
{
    $internal = json_decode((string)($item['internal_options_json'] ?? ''), true);
    $stored = is_array($internal) ? strtoupper(trim((string)($internal['_subcat'] ?? ''))) : '';
    if ($stored !== '' && isset(GRAPHICS_SUBCAT_LABELS[$stored])) {
        return $stored;
    }

    return strtoupper(trim((string)dept_get_graphics_subcat(
        (string)($item['custom_label'] ?? ''),
        (string)($item['sku'] ?? '')
    )));
}

function ordersStatusDefinitionAppliesToItem(array $definition, array $item): bool
{
    $department = ordersNormalizeDepartmentCode((string)($item['item_type_code'] ?? ''));
    if ($department !== 'G') {
        return true;
    }

    $targets = $definition['targets'] ?? ['ALL'];
    if (!is_array($targets) || !$targets || in_array('ALL', $targets, true)) {
        return true;
    }

    $subcategory = ordersResolveGraphicsSubcategory($item);
    if ($subcategory === '') {
        return in_array('MAIN', $targets, true);
    }

    return in_array('SUBCATEGORY:' . $subcategory, $targets, true);
}

function ordersGetItemStatusDefinitionsForItem(mysqli $conn, array $item, bool $activeOnly = true): array
{
    $department = ordersNormalizeDepartmentCode((string)($item['item_type_code'] ?? ''));
    $definitions = ordersGetItemStatusDefinitions($conn, $department, $activeOnly);
    return array_filter($definitions, static function (array $definition) use ($item): bool {
        return ordersStatusDefinitionAppliesToItem($definition, $item);
    });
}

function ordersGetItemStatusLabelsForItem(mysqli $conn, array $item, bool $activeOnly = true): array
{
    $labels = [];
    foreach (ordersGetItemStatusDefinitionsForItem($conn, $item, $activeOnly) as $code => $meta) {
        $labels[$code] = (string)($meta['label'] ?? $code);
    }
    return $labels;
}

function ordersGetItemStatusCodesForItem(mysqli $conn, array $item, bool $activeOnly = true): array
{
    return array_keys(ordersGetItemStatusDefinitionsForItem($conn, $item, $activeOnly));
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

/**
 * Vráti HTML pre solid status chip — jednotný štýl naprieč orders.php, profile.php a všetkými ostatnými stránkami.
 * Nahrádza priame použitie ordersGetOrderStatusButtonClass() ktorá vracia btn-outline-* triedy.
 *
 * @param mysqli $conn
 * @param string $status   Kód statusu (napr. 'SHIPPED', 'IN_PROGRESS')
 * @param string $size     Bootstrap btn veľkosť: 'xs', 'sm', '' (default 'xs')
 * @param array  $extraDataAttrs  Asociatívne pole ďalších data-* atribútov pre <td> wrapper (nepoužíva sa tu, len pre td)
 * @return string  Hotový <button> HTML
 */
/**
 * Vypočíta kontrastnú farbu textu (#000 alebo #fff) pre dané hex pozadie.
 * Používa relatívnu luminanciu podľa WCAG 2.1.
 *
 * @param string $hexColor  Hex farba pozadia (napr. '#ffc107' alebo 'ffc107')
 * @return string  '#000000' pre svetlé pozadie, '#ffffff' pre tmavé
 */
function ordersContrastColor(string $hexColor): string
{
    $hex = ltrim($hexColor, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return '#ffffff';
    }

    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;

    // Linearizácia (sRGB gamma)
    $r = $r <= 0.03928 ? $r / 12.92 : (($r + 0.055) / 1.055) ** 2.4;
    $g = $g <= 0.03928 ? $g / 12.92 : (($g + 0.055) / 1.055) ** 2.4;
    $b = $b <= 0.03928 ? $b / 12.92 : (($b + 0.055) / 1.055) ** 2.4;

    // Relatívna luminancia (WCAG)
    $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

    return $luminance > 0.179 ? '#000000' : '#ffffff';
}

function ordersRenderStatusChip(mysqli $conn, string $status, string $size = 'xs'): string
{
    $label = ordersGetStatusLabel($conn, 'order', $status);
    $color = ordersGetStatusColor($conn, 'order', $status) ?: '#6c757d';
    $safeColor = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
    $safeLabel = htmlspecialchars($label ?: '-', ENT_QUOTES, 'UTF-8');
    $textColor = ordersContrastColor($color);
    $sizeClass = $size !== '' ? ' btn-' . $size : '';
    $style = 'background-color:' . $safeColor . ';border-color:' . $safeColor . ';color:' . $textColor . ';pointer-events:none;';

    return '<button type="button" class="btn' . $sizeClass . ' orders-status-chip" style="' . $style . '">'
        . $safeLabel
        . '</button>';
}