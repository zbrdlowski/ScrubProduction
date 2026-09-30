<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/includes/auth.php';

function authorization_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

authorization_test_assert(auth_schema_ready($pdo), 'Authorization schema is not installed');
authorization_test_assert(auth_page_permission('orders') === 'orders.view', 'Orders page permission mapping is missing');
authorization_test_assert(auth_page_permission('custom_orders') === 'custom_orders.view', 'Custom Orders page permission mapping is missing');
authorization_test_assert(auth_page_permission('order_export_reset') === 'orders.export_reset', 'Order Export Reset page permission mapping is missing');
authorization_test_assert(auth_page_permission('staff_attendance') === 'attendance.view_all', 'Staff Attendance page permission mapping is missing');
authorization_test_assert(auth_page_permission('staff_attendance_detail') === 'attendance.view_all', 'Staff Attendance Detail page permission mapping is missing');
authorization_test_assert(auth_page_permission('order_prepare') === 'plastics.purchase', 'Plastics purchase page permission mapping is missing');
authorization_test_assert(auth_page_permission('backup') === 'plastics.manage', 'Plastics maintenance page permission mapping is missing');
authorization_test_assert(auth_page_permission('inventory_report') === null, 'Public warehouse inventory must be available to every signed-in employee');
authorization_test_assert(auth_page_permission('general_items') === null, 'Public plastics catalogue must be available to every signed-in employee');
authorization_test_assert(auth_page_permission('historical_movements') === null, 'Public plastics order archive must be available to every signed-in employee');
authorization_test_assert(auth_page_permission('../includes/conn') === null, 'Unknown page must not receive an authorization mapping');

$employees = $pdo->query('SELECT id, permission, position_id FROM employees WHERE permission <> 900 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
authorization_test_assert(count($employees) === 2, 'Two non-superadmin employees are required for the integration test');

$viewerRoleId = (int) $pdo->query("SELECT id FROM auth_roles WHERE role_key = 'accounting_viewer'")->fetchColumn();
$productionRoleId = (int) $pdo->query("SELECT id FROM auth_roles WHERE role_key = 'production_worker'")->fetchColumn();
$warehouseRoleId = (int) $pdo->query("SELECT id FROM auth_roles WHERE role_key = 'warehouse_worker'")->fetchColumn();
$accessManagerRoleId = (int) $pdo->query("SELECT id FROM auth_roles WHERE role_key = 'access_manager'")->fetchColumn();
$viewPermissionId = (int) $pdo->query("SELECT id FROM auth_permissions WHERE permission_key = 'accounting.view'")->fetchColumn();
$importPermissionId = (int) $pdo->query("SELECT id FROM auth_permissions WHERE permission_key = 'accounting.import'")->fetchColumn();
$ordersViewPermissionId = (int) $pdo->query("SELECT id FROM auth_permissions WHERE permission_key = 'orders.view'")->fetchColumn();
$orderExportResetPermissionId = (int) $pdo->query("SELECT id FROM auth_permissions WHERE permission_key = 'orders.export_reset'")->fetchColumn();
authorization_test_assert($viewerRoleId > 0 && $productionRoleId > 0 && $warehouseRoleId > 0 && $accessManagerRoleId > 0 && $viewPermissionId > 0 && $importPermissionId > 0 && $ordersViewPermissionId > 0 && $orderExportResetPermissionId > 0, 'Seeded authorization records are missing');
$employeeThreeCanManageAccess = (int) $pdo->query("SELECT COUNT(*) FROM auth_employee_roles er JOIN auth_roles r ON r.id = er.role_id JOIN auth_role_permissions rp ON rp.role_id = r.id JOIN auth_permissions p ON p.id = rp.permission_id WHERE er.employee_id = 3 AND r.role_key = 'access_manager' AND p.permission_key = 'access.manage'")->fetchColumn();
authorization_test_assert($employeeThreeCanManageAccess === 1, 'Employee 3 must receive only the dedicated access manager path');

$deniedEmployee = (int) $employees[0]['id'];
$allowedEmployee = (int) $employees[1]['id'];

$pdo->beginTransaction();
try {
    $clearRoles = $pdo->prepare('DELETE FROM auth_employee_roles WHERE employee_id = ?');
    $clearOverrides = $pdo->prepare('DELETE FROM auth_employee_overrides WHERE employee_id = ?');
    foreach ([$deniedEmployee, $allowedEmployee] as $employeeId) {
        $clearRoles->execute([$employeeId]);
        $clearOverrides->execute([$employeeId]);
    }

    $insertRole = $pdo->prepare('INSERT INTO auth_employee_roles (employee_id, role_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)');
    $insertOverride = $pdo->prepare('INSERT INTO auth_employee_overrides (employee_id, permission_id, effect) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE effect = VALUES(effect)');

    $insertRole->execute([$deniedEmployee, $viewerRoleId]);
    $insertRole->execute([$deniedEmployee, $productionRoleId]);
    $insertOverride->execute([$deniedEmployee, $viewPermissionId, 'deny']);
    $insertOverride->execute([$deniedEmployee, $ordersViewPermissionId, 'deny']);
    $_SESSION = [
        'user_id' => $deniedEmployee,
        'permission' => (int) $employees[0]['permission'],
        'dpt' => (int) $employees[0]['position_id'],
    ];
    authorization_test_assert(!auth_can('accounting.view'), 'A direct deny must override a role grant');
    authorization_test_assert(!auth_can('orders.view'), 'A direct Orders deny must override a role grant');
    authorization_test_assert(!auth_can('orders.export_reset'), 'Order Export Reset must default to deny');

    $insertRole->execute([$allowedEmployee, $viewerRoleId]);
    $insertRole->execute([$allowedEmployee, $warehouseRoleId]);
    $insertOverride->execute([$allowedEmployee, $importPermissionId, 'allow']);
    $insertOverride->execute([$allowedEmployee, $orderExportResetPermissionId, 'allow']);
    $_SESSION = [
        'user_id' => $allowedEmployee,
        'permission' => (int) $employees[1]['permission'],
        'dpt' => (int) $employees[1]['position_id'],
    ];
    authorization_test_assert(auth_can('accounting.view'), 'Viewer role must grant accounting.view');
    authorization_test_assert(auth_can('accounting.import'), 'Direct allow must grant accounting.import');
    authorization_test_assert(auth_can('orders.export_reset'), 'Direct allow must grant Order Export Reset');
    authorization_test_assert(!auth_can('accounting.export'), 'Unassigned permission must default to deny');
    authorization_test_assert(auth_can('plastics.work'), 'Warehouse role must grant plastics.work');
    authorization_test_assert(!auth_can('plastics.manage'), 'Warehouse worker must not receive plastics.manage');
} finally {
    $pdo->rollBack();
}

echo "central authorization: OK\n";
