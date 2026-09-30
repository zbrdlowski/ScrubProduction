<?php
declare(strict_types=1);

function auth_schema_ready(?PDO $pdo = null): bool
{
    static $cache = [];
    if (!$pdo instanceof PDO) {
        global $pdo;
    }
    if (!$pdo instanceof PDO) {
        return false;
    }
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('auth_permissions','auth_roles','auth_role_permissions','auth_employee_roles','auth_employee_overrides')");
        return $cache[$key] = ((int) $stmt->fetchColumn() === 5);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function auth_legacy_can(string $permissionKey): bool
{
    $level = (int) ($_SESSION['permission'] ?? 0);
    $department = (int) ($_SESSION['dpt'] ?? 0);
    if ($permissionKey === 'access.manage') {
        return $level === 900;
    }
    if ($permissionKey === 'attendance.view_all') {
        return $level === 900 || (int) ($_SESSION['user_id'] ?? 0) === 21;
    }
    if (strpos($permissionKey, 'accounting.') === 0) {
        return $level === 900 || in_array($department, [1, 3], true);
    }
    if (in_array($permissionKey, ['orders.view', 'orders.work', 'custom_orders.view', 'custom_orders.work'], true)) {
        return $level >= 1;
    }
    if (in_array($permissionKey, ['orders.manage', 'custom_orders.manage', 'custom_orders.financial', 'custom_orders.export', 'custom_orders.delete'], true)) {
        return $level >= 300;
    }
    if ($permissionKey === 'custom_orders.audit') {
        return $level === 900 || in_array((int) ($_SESSION['user_id'] ?? 0), [3, 5], true);
    }
    if (in_array($permissionKey, ['orders.financial', 'orders.shipping'], true)) {
        return $level >= 400;
    }
    if ($permissionKey === 'orders.export_reset') {
        return $level === 900 || in_array((int) ($_SESSION['user_id'] ?? 0), [1], true);
    }
    if ($permissionKey === 'orders.admin') {
        return $level === 900;
    }
    if (strpos($permissionKey, 'plastics.') === 0) {
        if ($permissionKey === 'plastics.manage') {
            return $level >= 500;
        }
        return $level >= 500 || $department === 6;
    }
    return false;
}

function auth_page_permission(string $page): ?string
{
    $map = [
        'orders_dashboard' => 'orders.view',
        'orders' => 'orders.view',
        'order_export_reset' => 'orders.export_reset',
        'staff_attendance' => 'attendance.view_all',
        'staff_attendance_detail' => 'attendance.view_all',
        'custom_orders' => 'custom_orders.view',
        'plastics_dashboard' => 'plastics.view',
        'items' => 'plastics.view',
        'pato_items' => 'plastics.view',
        'shelves' => 'plastics.view',
        'display_stock' => 'plastics.view',
        'search_item' => 'plastics.view',
        'plastics_orders_active' => 'plastics.view',
        'plastics_orders_sent' => 'plastics.view',
        'plastics_orders_all' => 'plastics.view',
        'scan_form' => 'plastics.work',
        'scan_form_out' => 'plastics.work',
        'bulk_scan_in' => 'plastics.work',
        'bulk_scan_in_2' => 'plastics.work',
        'bulk_scan_in_redesign' => 'plastics.work',
        'reset_location' => 'plastics.work',
        'relocate_item' => 'plastics.work',
        'kit_diss' => 'plastics.work',
        'order_prepare' => 'plastics.purchase',
        'order_prepare_form' => 'plastics.purchase',
        'order_prepare_form_out' => 'plastics.purchase',
        'intake_print' => 'plastics.receive',
        'receive_supply' => 'plastics.receive',
        'add_item' => 'plastics.manage',
        'upload_items' => 'plastics.manage',
        'suppliers' => 'plastics.manage',
        'stock_levels' => 'plastics.manage',
        'upload_csv' => 'plastics.manage',
        'backup' => 'plastics.manage',
        'logs' => 'plastics.manage',
        'cleanup' => 'plastics.manage',
        'stock_movements' => 'plastics.reports',
        'archive_stock_movements' => 'plastics.reports',
        'shelf_utilization' => 'plastics.reports',
        'year_to_year_statistics' => 'plastics.reports',
    ];
    return $map[$page] ?? null;
}

function auth_access_map(?int $employeeId = null, ?PDO $pdo = null): array
{
    static $cache = [];
    if (!$pdo instanceof PDO) {
        global $pdo;
    }
    $employeeId = $employeeId ?? (int) ($_SESSION['user_id'] ?? 0);
    if (!$pdo instanceof PDO || $employeeId <= 0 || !auth_schema_ready($pdo)) {
        return [];
    }
    $cacheKey = spl_object_id($pdo) . ':' . $employeeId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $permissions = [];
    foreach ($pdo->query('SELECT id, permission_key FROM auth_permissions WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $permissions[(string) $row['permission_key']] = [
            'permission_id' => (int) $row['id'],
            'role_allowed' => false,
            'override' => null,
        ];
    }

    $roleStmt = $pdo->prepare('
        SELECT DISTINCT p.permission_key
        FROM auth_employee_roles er
        JOIN auth_roles r ON r.id = er.role_id AND r.is_active = 1
        JOIN auth_role_permissions rp ON rp.role_id = r.id
        JOIN auth_permissions p ON p.id = rp.permission_id AND p.is_active = 1
        WHERE er.employee_id = ?
    ');
    $roleStmt->execute([$employeeId]);
    foreach ($roleStmt->fetchAll(PDO::FETCH_COLUMN) as $permissionKey) {
        if (isset($permissions[$permissionKey])) {
            $permissions[$permissionKey]['role_allowed'] = true;
        }
    }

    $overrideStmt = $pdo->prepare('
        SELECT p.permission_key, o.effect
        FROM auth_employee_overrides o
        JOIN auth_permissions p ON p.id = o.permission_id AND p.is_active = 1
        WHERE o.employee_id = ?
    ');
    $overrideStmt->execute([$employeeId]);
    foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $permissionKey = (string) $row['permission_key'];
        if (isset($permissions[$permissionKey])) {
            $permissions[$permissionKey]['override'] = (string) $row['effect'];
        }
    }

    foreach ($permissions as &$permission) {
        $permission['allowed'] = $permission['override'] === 'deny'
            ? false
            : ($permission['override'] === 'allow' || $permission['role_allowed']);
    }
    unset($permission);
    return $cache[$cacheKey] = $permissions;
}

function auth_can(string $permissionKey): bool
{
    global $pdo;
    if (!auth_schema_ready($pdo instanceof PDO ? $pdo : null)) {
        return auth_legacy_can($permissionKey);
    }
    $access = auth_access_map(null, $pdo);
    if (!isset($access[$permissionKey])) {
        if ($permissionKey === 'attendance.view_all') {
            return auth_legacy_can($permissionKey);
        }
        return false;
    }
    if ((int) ($_SESSION['permission'] ?? 0) === 900) {
        return true;
    }
    return (bool) $access[$permissionKey]['allowed'];
}

function auth_require(string $permissionKey, string $message = 'Na túto akciu nemáte oprávnenie.'): void
{
    if (auth_can($permissionKey)) {
        return;
    }
    http_response_code(403);
    exit($message);
}
