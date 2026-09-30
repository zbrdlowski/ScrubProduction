<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (!auth_can('access.manage')) {
    echo '<div class="alert alert-danger">Na správu oprávnení nemáte oprávnenie.</div>';
    return;
}
if (!($pdo instanceof PDO) || !auth_schema_ready($pdo)) {
    echo '<div class="alert alert-warning">Najprv spustite migráciu <code>db/authorization.sql</code>.</div>';
    return;
}
if (empty($_SESSION['auth_admin_csrf'])) {
    $_SESSION['auth_admin_csrf'] = bin2hex(random_bytes(32));
}

function authAdminH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$employees = $pdo->query('
    SELECT e.id, e.firstname, e.lastname, e.employee_id, e.active, e.permission,
           p.description AS department
    FROM employees e
    LEFT JOIN position p ON p.id = e.position_id
    ORDER BY e.firstname, e.lastname
')->fetchAll(PDO::FETCH_ASSOC);

$selectedEmployeeId = (int) ($_GET['employee_id'] ?? 0);
if ($selectedEmployeeId <= 0 && $employees) {
    $selectedEmployeeId = (int) $employees[0]['id'];
}
$selectedEmployee = null;
foreach ($employees as $employee) {
    if ((int) $employee['id'] === $selectedEmployeeId) {
        $selectedEmployee = $employee;
        break;
    }
}
if ($selectedEmployee === null && $employees) {
    $selectedEmployee = $employees[0];
    $selectedEmployeeId = (int) $selectedEmployee['id'];
}

$roles = $pdo->query('
    SELECT r.id, r.role_key, r.label, r.description,
           GROUP_CONCAT(p.label ORDER BY p.sort_order SEPARATOR \' • \') AS permission_labels
    FROM auth_roles r
    LEFT JOIN auth_role_permissions rp ON rp.role_id = r.id
    LEFT JOIN auth_permissions p ON p.id = rp.permission_id AND p.is_active = 1
    WHERE r.is_active = 1
    GROUP BY r.id
    ORDER BY r.sort_order, r.label
')->fetchAll(PDO::FETCH_ASSOC);

$rolePermissionIds = [];
$roleLabels = [];
foreach ($roles as $role) {
    $roleId = (int) $role['id'];
    $rolePermissionIds[$roleId] = [];
    $roleLabels[$roleId] = (string) $role['label'];
}
$rolePermissionRows = $pdo->query('
    SELECT rp.role_id, rp.permission_id
    FROM auth_role_permissions rp
    JOIN auth_roles r ON r.id = rp.role_id AND r.is_active = 1
    JOIN auth_permissions p ON p.id = rp.permission_id AND p.is_active = 1
    ORDER BY rp.role_id, p.sort_order
')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rolePermissionRows as $rolePermissionRow) {
    $roleId = (int) $rolePermissionRow['role_id'];
    if (isset($rolePermissionIds[$roleId])) {
        $rolePermissionIds[$roleId][] = (int) $rolePermissionRow['permission_id'];
    }
}

$permissions = $pdo->query('
    SELECT id, permission_key, module_key, label, description, risk_level
    FROM auth_permissions
    WHERE is_active = 1
    ORDER BY module_key, sort_order, label
')->fetchAll(PDO::FETCH_ASSOC);

$selectedRoleIds = [];
$accessMap = [];
$auditRows = [];
if ($selectedEmployeeId > 0) {
    $roleStmt = $pdo->prepare('SELECT role_id FROM auth_employee_roles WHERE employee_id = ?');
    $roleStmt->execute([$selectedEmployeeId]);
    $selectedRoleIds = array_map('intval', $roleStmt->fetchAll(PDO::FETCH_COLUMN));
    $accessMap = auth_access_map($selectedEmployeeId, $pdo);

    $auditStmt = $pdo->prepare('
        SELECT a.created_at, a.previous_json, a.current_json,
               CONCAT(COALESCE(actor.firstname, \'\'), \' \', COALESCE(actor.lastname, \'\')) AS actor_name
        FROM auth_permission_audit a
        LEFT JOIN employees actor ON actor.id = a.changed_by
        WHERE a.employee_id = ?
        ORDER BY a.id DESC
        LIMIT 10
    ');
    $auditStmt->execute([$selectedEmployeeId]);
    $auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
}

$permissionsByModule = [];
foreach ($permissions as $permission) {
    $permissionsByModule[(string) $permission['module_key']][] = $permission;
}
$isEmergencyAdministrator = (int) ($selectedEmployee['permission'] ?? 0) === 900;
?>

<style>
  .auth-admin-muted { color: #adb5bd; }
  .auth-admin-role { height: 100%; background: #414950; border: 1px solid #59636d; border-radius: .35rem; padding: .85rem; }
  .auth-admin-role:hover { border-color: #20c997; }
  .auth-admin-role label { cursor: pointer; margin-bottom: 0; }
  .auth-admin-module { border-top: 3px solid #20c997; }
  .auth-admin-permission { display: grid; grid-template-columns: minmax(280px, 1fr) minmax(310px, auto) minmax(155px, auto); align-items: center; gap: 1rem; padding: .85rem 1rem; border-bottom: 1px solid rgba(255,255,255,.08); }
  .auth-admin-permission:last-child { border-bottom: 0; }
  .auth-admin-choice { display: flex; flex-wrap: nowrap; gap: .35rem; }
  .auth-admin-choice label { margin: 0; cursor: pointer; }
  .auth-admin-choice input { position: absolute; opacity: 0; pointer-events: none; }
  .auth-admin-choice span { display: inline-block; min-width: 94px; padding: .3rem .55rem; text-align: center; border: 1px solid #6c757d; border-radius: .25rem; color: #ced4da; }
  .auth-admin-choice input:checked + span { color: #fff; border-color: #20c997; background: rgba(32,201,151,.2); box-shadow: inset 0 0 0 1px #20c997; }
  .auth-admin-choice .auth-deny input:checked + span { border-color: #dc3545; background: rgba(220,53,69,.2); box-shadow: inset 0 0 0 1px #dc3545; }
  .auth-admin-effective { min-width: 155px; text-align: right; }
  .auth-admin-user-badges { display: flex; align-items: center; justify-content: flex-start; gap: .5rem; min-height: calc(2.25rem + 2px); }
  .auth-admin-row-badge { display: inline-flex; align-items: center; justify-content: center; min-height: 2rem; padding: .42rem .65rem; font-size: .875rem; font-weight: 700; line-height: 1; border-radius: .25rem; }
  .auth-admin-effective .auth-admin-row-badge { min-width: 150px; white-space: normal; line-height: 1.15; }
  .auth-admin-role-sources { display: flex; flex-wrap: wrap; align-items: center; gap: .3rem; min-height: 1.55rem; }
  .auth-admin-role-source { font-size: .72rem; font-weight: 600; }
  .auth-admin-filter .btn.active { color: #fff; background: #17a2b8; border-color: #17a2b8; }
  .auth-admin-module.is-empty { display: none; }
  .auth-admin-sticky-save { position: sticky; bottom: 0; z-index: 5; padding: .75rem; background: rgba(52,58,64,.96); border-top: 1px solid #59636d; }
  @media (max-width: 991.98px) {
    .auth-admin-permission { grid-template-columns: 1fr; }
    .auth-admin-effective { text-align: left; }
    .auth-admin-choice { flex-wrap: wrap; }
  }
</style>

<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h3 mb-1">Oprávnenia používateľov</h1>
      <div class="auth-admin-muted">Roly určujú základ. Individuálna výnimka môže oprávnenie povoliť alebo zakázať.</div>
    </div>
  </div>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="alert alert-success">Oprávnenia boli uložené. Zmena platí od ďalšieho načítania stránky používateľom.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= authAdminH($_GET['error']) ?></div>
  <?php endif; ?>

  <div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title">Používateľ</h3></div>
    <div class="card-body">
      <form method="get" class="form-row align-items-end">
        <input type="hidden" name="page" value="access_admin">
        <div class="form-group col-lg-6 mb-0">
          <label for="authEmployee">Vyberte používateľa</label>
          <select class="form-control" id="authEmployee" name="employee_id" onchange="this.form.submit()">
            <?php foreach ($employees as $employee): ?>
              <option value="<?= (int) $employee['id'] ?>" <?= (int) $employee['id'] === $selectedEmployeeId ? 'selected' : '' ?>>
                <?= authAdminH(trim((string) $employee['firstname'] . ' ' . (string) $employee['lastname'])) ?> · <?= authAdminH($employee['department'] ?: 'Bez oddelenia') ?><?= $employee['active'] === 'Active' ? '' : ' · neaktívny' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($selectedEmployee): ?>
          <div class="col-lg-6 mt-3 mt-lg-0 auth-admin-user-badges">
            <span class="badge badge-secondary auth-admin-row-badge">Legacy level <?= (int) $selectedEmployee['permission'] ?></span>
            <span class="badge auth-admin-row-badge <?= $selectedEmployee['active'] === 'Active' ? 'badge-success' : 'badge-dark' ?>"><?= authAdminH($selectedEmployee['active']) ?></span>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <?php if ($selectedEmployee): ?>
    <?php if ($isEmergencyAdministrator): ?>
      <div class="alert alert-warning">
        <strong>Núdzový administrátor:</strong> legacy level 900 má vždy plný prístup. Individuálne zákazy sa pri tomto účte zámerne neuplatnia.
      </div>
    <?php endif; ?>
    <form method="post" action="scripts/admin/save_user_permissions.php">
      <input type="hidden" name="csrf_token" value="<?= authAdminH($_SESSION['auth_admin_csrf']) ?>">
      <input type="hidden" name="employee_id" value="<?= $selectedEmployeeId ?>">

      <div class="card card-outline card-info">
        <div class="card-header"><h3 class="card-title">Roly</h3></div>
        <div class="card-body">
          <div class="row">
            <?php foreach ($roles as $role): ?>
              <div class="col-md-6 col-xl-4 mb-3">
                <div class="auth-admin-role">
                  <label class="d-flex align-items-start">
                    <input class="mt-1 mr-2 auth-admin-role-checkbox" type="checkbox" name="roles[]" value="<?= (int) $role['id'] ?>"
                      data-role-label="<?= authAdminH($role['label']) ?>"
                      data-permissions="<?= authAdminH(implode(',', $rolePermissionIds[(int) $role['id']] ?? [])) ?>"
                      <?= in_array((int) $role['id'], $selectedRoleIds, true) ? 'checked' : '' ?>>
                    <span><strong><?= authAdminH($role['label']) ?></strong><br><small class="auth-admin-muted"><?= authAdminH($role['description']) ?></small></span>
                  </label>
                  <?php if (!empty($role['permission_labels'])): ?><div class="small mt-2 text-info"><?= authAdminH($role['permission_labels']) ?></div><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card card-outline card-secondary mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center py-2">
          <div>
            <strong>Detail oprávnení</strong>
            <div class="small auth-admin-muted">„Podľa rolí“ použije horné balíky. Individuálne povolenie alebo zákaz má pred rolami prednosť.</div>
          </div>
          <div class="btn-group btn-group-sm auth-admin-filter mt-2 mt-md-0" role="group" aria-label="Filtrovanie oprávnení">
            <button type="button" class="btn btn-outline-info active" data-auth-filter="all">Všetky</button>
            <button type="button" class="btn btn-outline-info" data-auth-filter="inherited">Zdedené z rolí</button>
            <button type="button" class="btn btn-outline-info" data-auth-filter="overrides">Individuálne výnimky</button>
          </div>
        </div>
      </div>

      <?php foreach ($permissionsByModule as $module => $modulePermissions): ?>
        <div class="card auth-admin-module" data-auth-module>
          <div class="card-header"><h3 class="card-title"><?= authAdminH($module) ?></h3></div>
          <div class="card-body p-0">
            <?php foreach ($modulePermissions as $permission):
              $permissionKey = (string) $permission['permission_key'];
              $state = $accessMap[$permissionKey] ?? ['role_allowed' => false, 'override' => null, 'allowed' => false];
              $override = $state['override'] ?? null;
              $effectiveAllowed = (int) ($selectedEmployee['permission'] ?? 0) === 900 || !empty($state['allowed']);
              $sourceRoleLabels = [];
              foreach ($selectedRoleIds as $selectedRoleId) {
                if (in_array((int) $permission['id'], $rolePermissionIds[$selectedRoleId] ?? [], true)) {
                  $sourceRoleLabels[] = $roleLabels[$selectedRoleId] ?? ('Rola #' . $selectedRoleId);
                }
              }
            ?>
              <div class="auth-admin-permission" data-auth-permission-row data-permission-id="<?= (int) $permission['id'] ?>">
                <div>
                  <strong><?= authAdminH($permission['label']) ?></strong>
                  <code class="ml-1"><?= authAdminH($permissionKey) ?></code>
                  <?php if ($permission['risk_level'] !== 'normal'): ?><span class="badge <?= $permission['risk_level'] === 'critical' ? 'badge-danger' : 'badge-warning' ?> ml-1"><?= authAdminH($permission['risk_level']) ?></span><?php endif; ?>
                  <div class="small auth-admin-muted mt-1"><?= authAdminH($permission['description']) ?></div>
                  <div class="small mt-2 auth-admin-role-sources" data-role-sources>
                    <?php if ($sourceRoleLabels): ?>
                      <span class="auth-admin-muted">Zdedené z:</span>
                      <?php foreach ($sourceRoleLabels as $sourceRoleLabel): ?><span class="badge badge-info auth-admin-role-source"><?= authAdminH($sourceRoleLabel) ?></span><?php endforeach; ?>
                    <?php else: ?>
                      <span class="auth-admin-muted">Neudeľuje žiadna vybraná rola</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="auth-admin-choice" role="radiogroup" aria-label="Výnimka pre <?= authAdminH($permission['label']) ?>">
                  <label><input type="radio" name="overrides[<?= (int) $permission['id'] ?>]" value="inherit" <?= $override === null ? 'checked' : '' ?>><span>Podľa rolí</span></label>
                  <label><input type="radio" name="overrides[<?= (int) $permission['id'] ?>]" value="allow" <?= $override === 'allow' ? 'checked' : '' ?>><span>Povoliť navyše</span></label>
                  <label class="auth-deny"><input type="radio" name="overrides[<?= (int) $permission['id'] ?>]" value="deny" <?= $override === 'deny' ? 'checked' : '' ?>><span>Zakázať výnimkou</span></label>
                </div>
                <div class="auth-admin-effective"><span class="badge auth-admin-row-badge <?= $effectiveAllowed ? 'badge-success' : 'badge-secondary' ?>" data-effective-badge><?= $effectiveAllowed ? 'Má prístup' : 'Bez prístupu' ?></span></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="auth-admin-sticky-save text-right">
        <button class="btn btn-success" type="submit"><i class="fas fa-save mr-1"></i> Uložiť oprávnenia</button>
      </div>
    </form>

    <?php if ($auditRows): ?>
      <div class="card collapsed-card mt-3">
        <div class="card-header"><h3 class="card-title">História zmien</h3><div class="card-tools"><button class="btn btn-tool" type="button" data-card-widget="collapse"><i class="fas fa-plus"></i></button></div></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm mb-0"><thead><tr><th>Dátum</th><th>Zmenil</th><th>Predchádzajúce</th><th>Nové</th></tr></thead><tbody>
          <?php foreach ($auditRows as $audit): ?><tr><td><?= authAdminH(date('d.m.Y H:i', strtotime((string) $audit['created_at']))) ?></td><td><?= authAdminH(trim((string) $audit['actor_name']) ?: 'Systém') ?></td><td><code><?= authAdminH($audit['previous_json']) ?></code></td><td><code><?= authAdminH($audit['current_json']) ?></code></td></tr><?php endforeach; ?>
          </tbody></table>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($selectedEmployee): ?>
<script>
(function () {
  const emergencyAdministrator = <?= $isEmergencyAdministrator ? 'true' : 'false' ?>;
  const roleCheckboxes = Array.from(document.querySelectorAll('.auth-admin-role-checkbox'));
  const permissionRows = Array.from(document.querySelectorAll('[data-auth-permission-row]'));
  const filterButtons = Array.from(document.querySelectorAll('[data-auth-filter]'));
  let activeFilter = 'all';

  function selectedRoleSources(permissionId) {
    const sources = [];
    roleCheckboxes.forEach(function (checkbox) {
      if (!checkbox.checked) return;
      const permissionIds = String(checkbox.dataset.permissions || '')
        .split(',')
        .filter(Boolean)
        .map(Number);
      if (permissionIds.includes(permissionId)) {
        sources.push(checkbox.dataset.roleLabel || ('Rola #' + checkbox.value));
      }
    });
    return sources;
  }

  function selectedOverride(row) {
    const checked = row.querySelector('.auth-admin-choice input[type="radio"]:checked');
    return checked ? checked.value : 'inherit';
  }

  function renderSources(container, sources) {
    container.textContent = '';
    if (!sources.length) {
      const empty = document.createElement('span');
      empty.className = 'auth-admin-muted';
      empty.textContent = 'Neudeľuje žiadna vybraná rola';
      container.appendChild(empty);
      return;
    }
    const prefix = document.createElement('span');
    prefix.className = 'auth-admin-muted';
    prefix.textContent = 'Zdedené z:';
    container.appendChild(prefix);
    sources.forEach(function (source) {
      const badge = document.createElement('span');
      badge.className = 'badge badge-info auth-admin-role-source';
      badge.textContent = source;
      container.appendChild(badge);
    });
  }

  function updatePermissionRow(row) {
    const permissionId = Number(row.dataset.permissionId || 0);
    const sources = selectedRoleSources(permissionId);
    const override = selectedOverride(row);
    const sourceContainer = row.querySelector('[data-role-sources]');
    const effectiveBadge = row.querySelector('[data-effective-badge]');

    renderSources(sourceContainer, sources);
    row.dataset.inherited = sources.length ? '1' : '0';
    row.dataset.override = override;

    effectiveBadge.classList.remove('badge-success', 'badge-secondary', 'badge-danger', 'badge-info', 'badge-warning');
    if (emergencyAdministrator) {
      effectiveBadge.classList.add('badge-warning');
      effectiveBadge.textContent = 'Núdzový plný prístup';
    } else if (override === 'deny') {
      effectiveBadge.classList.add('badge-danger');
      effectiveBadge.textContent = 'Zakázané výnimkou';
    } else if (override === 'allow') {
      effectiveBadge.classList.add('badge-info');
      effectiveBadge.textContent = 'Povolené individuálne';
    } else if (sources.length) {
      effectiveBadge.classList.add('badge-success');
      effectiveBadge.textContent = 'Prístup cez role';
    } else {
      effectiveBadge.classList.add('badge-secondary');
      effectiveBadge.textContent = 'Bez prístupu';
    }
  }

  function applyFilter() {
    permissionRows.forEach(function (row) {
      const visible = activeFilter === 'all'
        || (activeFilter === 'inherited' && row.dataset.inherited === '1')
        || (activeFilter === 'overrides' && row.dataset.override !== 'inherit');
      row.style.display = visible ? '' : 'none';
    });
    document.querySelectorAll('[data-auth-module]').forEach(function (module) {
      const hasVisibleRow = Array.from(module.querySelectorAll('[data-auth-permission-row]'))
        .some(function (row) { return row.style.display !== 'none'; });
      module.classList.toggle('is-empty', !hasVisibleRow);
    });
  }

  function refreshPermissions() {
    permissionRows.forEach(updatePermissionRow);
    applyFilter();
  }

  roleCheckboxes.forEach(function (checkbox) {
    checkbox.addEventListener('change', refreshPermissions);
  });
  permissionRows.forEach(function (row) {
    row.querySelectorAll('.auth-admin-choice input[type="radio"]').forEach(function (radio) {
      radio.addEventListener('change', refreshPermissions);
    });
  });
  filterButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      activeFilter = button.dataset.authFilter || 'all';
      filterButtons.forEach(function (candidate) {
        candidate.classList.toggle('active', candidate === button);
      });
      applyFilter();
    });
  });

  refreshPermissions();
})();
</script>
<?php endif; ?>
