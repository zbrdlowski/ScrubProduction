<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

function authSaveRedirect(array $params): void
{
    header('Location: ../../index.php?' . http_build_query(array_merge(['page' => 'access_admin'], $params)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
auth_require('access.manage');

$csrf = (string) ($_POST['csrf_token'] ?? '');
$expected = (string) ($_SESSION['auth_admin_csrf'] ?? '');
if ($expected === '' || !hash_equals($expected, $csrf)) {
    authSaveRedirect(['error' => 'Neplatná alebo expirovaná požiadavka.']);
}

$employeeId = (int) ($_POST['employee_id'] ?? 0);
if ($employeeId <= 0) {
    authSaveRedirect(['error' => 'Vyberte používateľa.']);
}

try {
    $employeeStmt = $pdo->prepare('SELECT id FROM employees WHERE id = ?');
    $employeeStmt->execute([$employeeId]);
    if (!$employeeStmt->fetchColumn()) {
        throw new RuntimeException('Používateľ neexistuje.');
    }

    $validRoleIds = array_map('intval', $pdo->query('SELECT id FROM auth_roles WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
    $postedRoleIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['roles'] ?? [])), static function (int $id) use ($validRoleIds): bool {
        return in_array($id, $validRoleIds, true);
    })));

    $permissionIds = array_map('intval', $pdo->query('SELECT id FROM auth_permissions WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
    $postedOverrides = (array) ($_POST['overrides'] ?? []);
    $overrides = [];
    foreach ($permissionIds as $permissionId) {
        $effect = strtolower(trim((string) ($postedOverrides[$permissionId] ?? 'inherit')));
        if (in_array($effect, ['allow', 'deny'], true)) {
            $overrides[$permissionId] = $effect;
        }
    }

    $oldRoleStmt = $pdo->prepare('SELECT role_id FROM auth_employee_roles WHERE employee_id = ? ORDER BY role_id');
    $oldRoleStmt->execute([$employeeId]);
    $oldOverrideStmt = $pdo->prepare('SELECT permission_id, effect FROM auth_employee_overrides WHERE employee_id = ? ORDER BY permission_id');
    $oldOverrideStmt->execute([$employeeId]);
    $previous = [
        'roles' => array_map('intval', $oldRoleStmt->fetchAll(PDO::FETCH_COLUMN)),
        'overrides' => $oldOverrideStmt->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
    sort($postedRoleIds);
    $current = ['roles' => $postedRoleIds, 'overrides' => $overrides];

    $pdo->beginTransaction();
    $deleteRoles = $pdo->prepare('DELETE FROM auth_employee_roles WHERE employee_id = ?');
    $deleteRoles->execute([$employeeId]);
    $insertRole = $pdo->prepare('INSERT INTO auth_employee_roles (employee_id, role_id, assigned_by) VALUES (?, ?, ?)');
    foreach ($postedRoleIds as $roleId) {
        $insertRole->execute([$employeeId, $roleId, (int) ($_SESSION['user_id'] ?? 0) ?: null]);
    }

    $deleteOverrides = $pdo->prepare('DELETE FROM auth_employee_overrides WHERE employee_id = ?');
    $deleteOverrides->execute([$employeeId]);
    $insertOverride = $pdo->prepare('INSERT INTO auth_employee_overrides (employee_id, permission_id, effect, set_by) VALUES (?, ?, ?, ?)');
    foreach ($overrides as $permissionId => $effect) {
        $insertOverride->execute([$employeeId, $permissionId, $effect, (int) ($_SESSION['user_id'] ?? 0) ?: null]);
    }

    if ($previous !== $current) {
        $audit = $pdo->prepare('INSERT INTO auth_permission_audit (employee_id, changed_by, previous_json, current_json, ip_address) VALUES (?, ?, ?, ?, ?)');
        $audit->execute([
            $employeeId,
            (int) ($_SESSION['user_id'] ?? 0) ?: null,
            json_encode($previous, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
    }
    $pdo->commit();
    authSaveRedirect(['employee_id' => $employeeId, 'saved' => 1]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    authSaveRedirect(['employee_id' => $employeeId, 'error' => $e->getMessage()]);
}
