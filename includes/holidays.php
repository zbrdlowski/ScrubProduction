<?php
// includes/holidays.php
// Six-month holiday / time off planning grid.

$holidayIsAdmin = isset($_SESSION['permission']) && intval($_SESSION['permission']) >= 400;
$holidayEmpId = intval($_SESSION['user_id'] ?? 0);

function holidayTableExists(mysqli $conn, string $table): bool
{
  $safe = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
  return $res && $res->num_rows > 0;
}

function holidayColumnExists(mysqli $conn, string $table, string $column): bool
{
  $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res && $res->num_rows > 0;
}

function holidayRedirect(string $url): void
{
  if (!headers_sent()) {
    header('Location: ' . $url, true, 303);
    exit;
  }

  $jsonUrl = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $htmlUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
  echo "<script>window.location.replace({$jsonUrl});</script>";
  echo "<noscript><meta http-equiv=\"refresh\" content=\"0;url={$htmlUrl}\"></noscript>";
  exit;
}

function holidayTypeLabel(string $type): string
{
  $labels = [
    'holiday' => 'Holiday',
    'toil' => 'Nahradne volno',
    'doctor' => 'Doctor',
    'sick' => 'Sick',
    'other' => 'Other',
  ];
  return $labels[$type] ?? ucfirst($type);
}

function holidayStatusBadge(string $status): string
{
  $map = [
    'pending' => ['warning', 'Pending'],
    'approved' => ['success', 'Approved'],
    'rejected' => ['danger', 'Rejected'],
    'cancelled' => ['secondary', 'Cancelled'],
  ];
  [$class, $label] = $map[$status] ?? ['secondary', ucfirst($status)];
  return '<span class="badge badge-' . $class . '">' . htmlspecialchars($label) . '</span>';
}

function holidayCellCode(string $type, string $status): string
{
  if ($type === 'holiday') {
    return $status === 'approved' ? 'Ah' : 'Rh';
  }
  if ($type === 'toil') {
    return $status === 'approved' ? 'At' : 'Rt';
  }
  if ($type === 'doctor') {
    return 'D';
  }
  if ($type === 'sick') {
    return 'S';
  }
  return $status === 'approved' ? 'A' : 'R';
}

function holidayRangeDates(string $start, string $end): array
{
  $dates = [];
  $cursor = new DateTime($start);
  $last = new DateTime($end);
  while ($cursor <= $last) {
    $dates[] = $cursor->format('Y-m-d');
    $cursor->modify('+1 day');
  }
  return $dates;
}

function holidayValidDate(string $date): bool
{
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    return false;
  }
  [$y, $m, $d] = array_map('intval', explode('-', $date));
  return checkdate($m, $d, $y);
}

function holidayAvatar(?string $photo, string $name = ''): string
{
  $photo = trim((string) $photo);
  $src = $photo !== '' ? 'images/' . ltrim($photo, '/') : 'images/profile.jpg';
  $src = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
  $alt = htmlspecialchars($name !== '' ? $name : 'User', ENT_QUOTES, 'UTF-8');
  return '<img src="' . $src . '" alt="' . $alt . '" title="' . $alt . '" class="holiday-avatar" onerror="this.src=\'images/profile.jpg\';">';
}

$holidayPublicHolidays = [];
$holidayPublicHolidayFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sviatky.php';
if (is_file($holidayPublicHolidayFile)) {
  $sviatky = [];
  ob_start();
  include $holidayPublicHolidayFile;
  ob_end_clean();

  if (isset($sviatky) && is_array($sviatky)) {
    foreach ($sviatky as $holidayDate) {
      $holidayDate = trim((string) $holidayDate);
      if (preg_match('/^\d{2}-\d{2}$/', $holidayDate)) {
        $holidayPublicHolidays[$holidayDate] = true;
      }
    }
  }
}

$holidayHasTable = holidayTableExists($conn, 'holiday_requests');
$holidayHasEmployeeFlag = holidayColumnExists($conn, 'employees', 'holiday_planner_enabled');
$holidayMessage = '';
$holidayError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $holidayHasTable) {
  $action = $_POST['action'] ?? '';
  $returnStart = preg_replace('/[^0-9-]/', '', $_POST['return_start'] ?? ($_GET['start'] ?? ''));
  $returnUrl = $_SERVER['PHP_SELF'] . '?page=holidays' . ($returnStart !== '' ? '&start=' . urlencode($returnStart) : '');

  if ($action === 'create_holiday_request') {
    $employeeId = $holidayIsAdmin ? (intval($_POST['employee_id'] ?? 0) ?: $holidayEmpId) : $holidayEmpId;
    $type = $_POST['request_type'] ?? 'holiday';
    $allowedTypes = ['holiday', 'toil', 'doctor', 'sick', 'other'];
    if (!in_array($type, $allowedTypes, true)) {
      $type = 'holiday';
    }
    $start = trim($_POST['start_date'] ?? '');
    $end = trim($_POST['end_date'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($employeeId > 0 && holidayValidDate($start) && holidayValidDate($end)) {
      if (strtotime($end) < strtotime($start)) {
        [$start, $end] = [$end, $start];
      }
      $stmt = $conn->prepare("INSERT INTO holiday_requests
          (employee_id, request_type, status, start_date, end_date, note, requested_by)
          VALUES (?, ?, 'pending', ?, ?, ?, ?)");
      $stmt->bind_param('issssi', $employeeId, $type, $start, $end, $note, $holidayEmpId);
      $stmt->execute();
      $stmt->close();
    }
    holidayRedirect($returnUrl);
  }

  if ($action === 'review_holiday_request' && $holidayIsAdmin) {
    $requestId = intval($_POST['request_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    $adminNote = trim($_POST['admin_note'] ?? '');
    if ($requestId > 0 && in_array($newStatus, ['approved', 'rejected'], true)) {
      $stmt = $conn->prepare("UPDATE holiday_requests
          SET status=?, admin_note=?, reviewed_by=?, reviewed_at=NOW(), employee_seen_at=NULL
          WHERE id=? AND status='pending'");
      $stmt->bind_param('ssii', $newStatus, $adminNote, $holidayEmpId, $requestId);
      $stmt->execute();
      $stmt->close();
    }
    holidayRedirect($returnUrl);
  }

  if ($action === 'cancel_holiday_request') {
    $requestId = intval($_POST['request_id'] ?? 0);
    if ($requestId > 0) {
      if ($holidayIsAdmin) {
        $stmt = $conn->prepare("UPDATE holiday_requests SET status='cancelled' WHERE id=? AND status='pending'");
        $stmt->bind_param('i', $requestId);
      } else {
        $stmt = $conn->prepare("UPDATE holiday_requests SET status='cancelled' WHERE id=? AND employee_id=? AND status='pending'");
        $stmt->bind_param('ii', $requestId, $holidayEmpId);
      }
      $stmt->execute();
      $stmt->close();
    }
    holidayRedirect($returnUrl);
  }
}

$startParam = $_GET['start'] ?? '';
if (!holidayValidDate($startParam)) {
  $startParam = date('Y-m-01');
}
$windowStart = new DateTime(date('Y-m-01', strtotime($startParam)));
$windowEnd = (clone $windowStart)->modify('+5 months')->modify('last day of this month');
$prevStart = (clone $windowStart)->modify('-6 months')->format('Y-m-01');
$nextStart = (clone $windowStart)->modify('+6 months')->format('Y-m-01');
$currentStart = date('Y-m-01');

if ($holidayHasTable && $holidayEmpId > 0) {
  $seenStmt = $conn->prepare("UPDATE holiday_requests
      SET employee_seen_at=NOW()
      WHERE employee_id=? AND status IN ('approved','rejected') AND employee_seen_at IS NULL");
  if ($seenStmt) {
    $seenStmt->bind_param('i', $holidayEmpId);
    $seenStmt->execute();
    $seenStmt->close();
  }
}

$employeeWhere = "e.active = 'Active'";
if ($holidayHasEmployeeFlag) {
  $employeeWhere .= " AND e.holiday_planner_enabled = 1";
}
$employees = [];
$employeeSql = "
    SELECT e.id, e.firstname, e.lastname, e.photo, e.active, e.position_id,
           COALESCE(p.description, 'No department') AS department_name
    FROM employees e
    LEFT JOIN position p ON p.id = e.position_id
    WHERE {$employeeWhere}
    ORDER BY department_name ASC, e.lastname ASC, e.firstname ASC";
$employeeRes = $conn->query($employeeSql);
if ($employeeRes) {
  while ($row = $employeeRes->fetch_assoc()) {
    $row['name'] = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
    if ($row['name'] === '') {
      $row['name'] = 'Employee #' . intval($row['id']);
    }
    $employees[] = $row;
  }
}

$requestsByEmployeeDate = [];
$pendingRequests = [];
$myRequests = [];
if ($holidayHasTable) {
  $rangeStart = $windowStart->format('Y-m-d');
  $rangeEnd = $windowEnd->format('Y-m-d');
  $stmt = $conn->prepare("SELECT hr.*, CONCAT_WS(' ', e.firstname, e.lastname) AS employee_name,
             e.photo, COALESCE(p.description, 'No department') AS department_name,
             CONCAT_WS(' ', rb.firstname, rb.lastname) AS reviewed_name
      FROM holiday_requests hr
      LEFT JOIN employees e ON e.id = hr.employee_id
      LEFT JOIN position p ON p.id = e.position_id
      LEFT JOIN employees rb ON rb.id = hr.reviewed_by
      WHERE hr.start_date <= ? AND hr.end_date >= ? AND hr.status IN ('pending','approved')
      ORDER BY hr.start_date ASC, hr.end_date ASC");
  $stmt->bind_param('ss', $rangeEnd, $rangeStart);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    foreach (holidayRangeDates($row['start_date'], $row['end_date']) as $date) {
      if ($date >= $rangeStart && $date <= $rangeEnd) {
        $requestsByEmployeeDate[intval($row['employee_id'])][$date][] = $row;
      }
    }
  }
  $stmt->close();

  if ($holidayIsAdmin) {
    $res = $conn->query("SELECT hr.*, CONCAT_WS(' ', e.firstname, e.lastname) AS employee_name
        FROM holiday_requests hr
        LEFT JOIN employees e ON e.id = hr.employee_id
        WHERE hr.status='pending'
        ORDER BY hr.created_at ASC
        LIMIT 50");
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $pendingRequests[] = $row;
      }
    }
  }

  if ($holidayEmpId > 0) {
    $stmt = $conn->prepare("SELECT hr.*, CONCAT_WS(' ', rb.firstname, rb.lastname) AS reviewed_name
        FROM holiday_requests hr
        LEFT JOIN employees rb ON rb.id = hr.reviewed_by
        WHERE hr.employee_id=?
        ORDER BY hr.created_at DESC
        LIMIT 30");
    $stmt->bind_param('i', $holidayEmpId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $myRequests[] = $row;
    }
    $stmt->close();
  }
}

$employeesByDepartment = [];
foreach ($employees as $employee) {
  $dept = $employee['department_name'] ?: 'No department';
  $employeesByDepartment[$dept][] = $employee;
}

$months = [];
for ($i = 0; $i < 6; $i++) {
  $monthStart = (clone $windowStart)->modify("+{$i} months");
  $monthEnd = (clone $monthStart)->modify('last day of this month');
  $months[] = [$monthStart, $monthEnd];
}
?>

<style>
  .holiday-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 12px;
  }

  .holiday-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 12px;
  }

  .holiday-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 6px;
    border: 1px solid rgba(255, 255, 255, .22);
  }

  .holiday-month-card .card-header {
    position: sticky;
    top: 0;
    z-index: 4;
    background: #2f4858;
    border-bottom: 1px solid rgba(23, 162, 184, .55);
    color: #f8f9fa;
  }

  .holiday-month-card .card-title {
    color: #f8f9fa;
    font-weight: 700;
  }

  .holiday-table-wrap {
    overflow-x: auto;
    max-width: 100%;
  }

  .holiday-grid {
    table-layout: fixed;
    min-width: 1180px;
    margin-bottom: 0;
  }

  .holiday-grid th,
  .holiday-grid td {
    text-align: center;
    vertical-align: middle !important;
    padding: 4px 3px !important;
    height: 34px;
  }

  .holiday-grid .holiday-employee-col {
    position: sticky;
    left: 0;
    z-index: 3;
    min-width: 210px;
    width: 210px;
    text-align: left;
    background: #343a40;
  }

  .holiday-grid thead .holiday-employee-col {
    z-index: 5;
    background: #2f353b;
  }

  .holiday-dept-row td {
    background: #2f353b !important;
    color: #f8f9fa;
    font-weight: 700;
    text-align: left;
  }

  .holiday-day-weekend {
    background: rgba(220, 53, 69, .12) !important;
  }

  .holiday-day-public {
    background: rgba(220, 53, 69, .21) !important;
    color: #ffd9de;
  }

  .holiday-day-today {
    box-shadow: inset 0 0 0 2px rgba(23, 162, 184, .75);
  }

  .holiday-cell-own {
    cursor: pointer;
  }

  .holiday-cell-own:hover {
    background: rgba(23, 162, 184, .18);
  }

  .holiday-pill {
    display: inline-flex;
    min-width: 24px;
    height: 22px;
    border-radius: 4px;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid rgba(255, 255, 255, .24);
  }

  .holiday-pill-pending {
    background: rgba(255, 193, 7, .28);
    color: #ffe8a1;
  }

  .holiday-pill-approved {
    background: rgba(40, 167, 69, .34);
    color: #dff7e6;
  }

  .holiday-pill-doctor {
    background: rgba(23, 162, 184, .34);
    color: #e1f8ff;
  }

  .holiday-pill-sick {
    background: rgba(220, 53, 69, .34);
    color: #ffe1e5;
  }

  .holiday-request-table td {
    vertical-align: middle !important;
  }
</style>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0" style="color:#e4e6eb;"><i class="far fa-calendar-check mr-2"></i>Holiday planner</h4>
    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#holidayRequestModal" <?= $holidayHasTable ? '' : 'disabled' ?>>
      <i class="fas fa-plus mr-1"></i> New request
    </button>
  </div>

  <?php if (!$holidayHasTable || !$holidayHasEmployeeFlag): ?>
    <div class="alert alert-warning">
      Holiday planner database schema is not fully installed yet.
      Run <strong>db/holiday_planner.sql</strong> first. The page can render a preview, but requests need the database table.
    </div>
  <?php endif; ?>

  <div class="holiday-toolbar">
    <a class="btn btn-sm btn-outline-secondary" href="?page=holidays&start=<?= htmlspecialchars($prevStart) ?>">
      <i class="fas fa-chevron-left"></i> Previous 6 months
    </a>
    <a class="btn btn-sm btn-outline-info" href="?page=holidays&start=<?= htmlspecialchars($currentStart) ?>">Current</a>
    <a class="btn btn-sm btn-outline-secondary" href="?page=holidays&start=<?= htmlspecialchars($nextStart) ?>">
      Next 6 months <i class="fas fa-chevron-right"></i>
    </a>
    <span class="text-muted ml-2">
      <?= htmlspecialchars($windowStart->format('M Y')) ?> - <?= htmlspecialchars($windowEnd->format('M Y')) ?>
    </span>
  </div>

  <div class="holiday-legend">
    <span class="holiday-pill holiday-pill-pending">Rh</span><span class="text-muted mr-2">Holiday requested</span>
    <span class="holiday-pill holiday-pill-approved">Ah</span><span class="text-muted mr-2">Approved holiday</span>
    <span class="holiday-pill holiday-pill-pending">Rt</span><span class="text-muted mr-2">TOIL requested</span>
    <span class="holiday-pill holiday-pill-approved">At</span><span class="text-muted mr-2">Approved TOIL</span>
    <span class="holiday-pill holiday-pill-doctor">D</span><span class="text-muted mr-2">Doctor</span>
    <span class="holiday-pill holiday-pill-sick">S</span><span class="text-muted mr-2">Sick</span>
  </div>

  <?php if ($holidayIsAdmin && !empty($pendingRequests)): ?>
    <div class="card card-warning card-outline">
      <div class="card-header">
        <h5 class="card-title mb-0"><i class="fas fa-bell mr-1"></i> Pending approval</h5>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0 holiday-request-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>Date</th>
                <th>Note</th>
                <th style="width:260px;">Decision</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pendingRequests as $request): ?>
                <tr>
                  <td><?= htmlspecialchars($request['employee_name'] ?? '') ?></td>
                  <td><?= htmlspecialchars(holidayTypeLabel($request['request_type'])) ?></td>
                  <td><?= date('d.m.Y', strtotime($request['start_date'])) ?> - <?= date('d.m.Y', strtotime($request['end_date'])) ?></td>
                  <td><?= htmlspecialchars($request['note'] ?? '') ?></td>
                  <td>
                    <form method="POST" class="d-flex" style="gap:4px;">
                      <input type="hidden" name="action" value="review_holiday_request">
                      <input type="hidden" name="request_id" value="<?= intval($request['id']) ?>">
                      <input type="hidden" name="return_start" value="<?= htmlspecialchars($windowStart->format('Y-m-01')) ?>">
                      <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="Admin note">
                      <button type="submit" name="status" value="approved" class="btn btn-sm btn-success">Approve</button>
                      <button type="submit" name="status" value="rejected" class="btn btn-sm btn-danger">Reject</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ($months as [$monthStart, $monthEnd]): ?>
    <?php
    $days = [];
    $cursor = clone $monthStart;
    while ($cursor <= $monthEnd) {
      $days[] = clone $cursor;
      $cursor->modify('+1 day');
    }
    ?>
    <div class="card card-outline card-secondary holiday-month-card">
      <div class="card-header py-2">
        <h5 class="card-title mb-0"><?= htmlspecialchars($monthStart->format('F Y')) ?></h5>
      </div>
      <div class="card-body p-0 holiday-table-wrap">
        <table class="table table-sm table-bordered table-hover holiday-grid">
          <thead>
            <tr>
              <th class="holiday-employee-col">Employee</th>
              <?php foreach ($days as $day): ?>
                <?php
                $headerClasses = [];
                if (intval($day->format('N')) >= 6) {
                  $headerClasses[] = 'holiday-day-weekend';
                }
                if (isset($holidayPublicHolidays[$day->format('d-m')])) {
                  $headerClasses[] = 'holiday-day-public';
                }
                ?>
                <th class="<?= htmlspecialchars(implode(' ', $headerClasses)) ?>" <?= isset($holidayPublicHolidays[$day->format('d-m')]) ? 'title="Public holiday" data-toggle="tooltip"' : '' ?>>
                  <div><?= htmlspecialchars($day->format('D')) ?></div>
                  <strong><?= intval($day->format('j')) ?></strong>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($employeesByDepartment as $department => $deptEmployees): ?>
              <tr class="holiday-dept-row">
                <td colspan="<?= count($days) + 1 ?>"><?= htmlspecialchars($department) ?></td>
              </tr>
              <?php foreach ($deptEmployees as $employee): ?>
                <tr>
                  <td class="holiday-employee-col">
                    <?= holidayAvatar($employee['photo'] ?? '', $employee['name']) ?>
                    <?= htmlspecialchars($employee['lastname'] . ' ' . $employee['firstname']) ?>
                  </td>
                  <?php foreach ($days as $day): ?>
                    <?php
                    $date = $day->format('Y-m-d');
                    $isWeekend = intval($day->format('N')) >= 6;
                    $isPublicHoliday = isset($holidayPublicHolidays[$day->format('d-m')]);
                    $isToday = $date === date('Y-m-d');
                    $isOwn = intval($employee['id']) === $holidayEmpId;
                    $cellRequests = $requestsByEmployeeDate[intval($employee['id'])][$date] ?? [];
                    $cellClasses = [];
                    if ($isWeekend) {
                      $cellClasses[] = 'holiday-day-weekend';
                    }
                    if ($isPublicHoliday) {
                      $cellClasses[] = 'holiday-day-public';
                    }
                    if ($isToday) {
                      $cellClasses[] = 'holiday-day-today';
                    }
                    if ($isOwn && empty($cellRequests) && $holidayHasTable) {
                      $cellClasses[] = 'holiday-cell-own';
                    }
                    ?>
                    <td class="<?= htmlspecialchars(implode(' ', $cellClasses)) ?>" data-date="<?= htmlspecialchars($date) ?>">
                      <?php foreach ($cellRequests as $request): ?>
                        <?php
                        $pillClass = $request['status'] === 'pending' ? 'holiday-pill-pending' : 'holiday-pill-approved';
                        if ($request['request_type'] === 'doctor') {
                          $pillClass = 'holiday-pill-doctor';
                        } elseif ($request['request_type'] === 'sick') {
                          $pillClass = 'holiday-pill-sick';
                        }
                        $tooltipParts = [
                          holidayTypeLabel($request['request_type']) . ' - ' . $request['status'],
                        ];
                        if (!empty($request['note'])) {
                          $tooltipParts[] = 'Note: ' . $request['note'];
                        }
                        if (!empty($request['reviewed_name'])) {
                          $tooltipParts[] = ($request['status'] === 'approved' ? 'Approved by: ' : 'Reviewed by: ') . $request['reviewed_name'];
                        }
                        $title = implode(' | ', $tooltipParts);
                        ?>
                        <span class="holiday-pill <?= $pillClass ?>" title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>" data-toggle="tooltip">
                          <?= htmlspecialchars(holidayCellCode($request['request_type'], $request['status'])) ?>
                        </span>
                      <?php endforeach; ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="card card-outline card-info">
    <div class="card-header">
      <h5 class="card-title mb-0"><i class="fas fa-list mr-1"></i> My requests</h5>
    </div>
    <div class="card-body p-0">
      <?php if (empty($myRequests)): ?>
        <p class="text-muted text-center py-3 mb-0">No requests yet.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0 holiday-request-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Date</th>
                <th>Status</th>
                <th>Note</th>
                <th>Admin note</th>
                <th style="width:90px;"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($myRequests as $request): ?>
                <tr>
                  <td><?= htmlspecialchars(holidayTypeLabel($request['request_type'])) ?></td>
                  <td><?= date('d.m.Y', strtotime($request['start_date'])) ?> - <?= date('d.m.Y', strtotime($request['end_date'])) ?></td>
                  <td><?= holidayStatusBadge($request['status']) ?></td>
                  <td><?= htmlspecialchars($request['note'] ?? '') ?></td>
                  <td><?= htmlspecialchars($request['admin_note'] ?? '') ?></td>
                  <td>
                    <?php if ($request['status'] === 'pending'): ?>
                      <form method="POST" onsubmit="return confirm('Cancel this request?');">
                        <input type="hidden" name="action" value="cancel_holiday_request">
                        <input type="hidden" name="request_id" value="<?= intval($request['id']) ?>">
                        <input type="hidden" name="return_start" value="<?= htmlspecialchars($windowStart->format('Y-m-01')) ?>">
                        <button type="submit" class="btn btn-xs btn-outline-danger">Cancel</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal fade" id="holidayRequestModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="create_holiday_request">
        <input type="hidden" name="return_start" value="<?= htmlspecialchars($windowStart->format('Y-m-01')) ?>">
        <div class="modal-header bg-primary">
          <h5 class="modal-title text-white">New time off request</h5>
          <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <?php if ($holidayIsAdmin): ?>
            <div class="form-group">
              <label>Employee</label>
              <select name="employee_id" id="holidayEmployeeId" class="form-control">
                <?php foreach ($employees as $employee): ?>
                  <option value="<?= intval($employee['id']) ?>" <?= intval($employee['id']) === $holidayEmpId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($employee['lastname'] . ' ' . $employee['firstname']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label>From</label>
              <input type="date" name="start_date" id="holidayStartDate" class="form-control" required>
            </div>
            <div class="form-group col-md-6">
              <label>To</label>
              <input type="date" name="end_date" id="holidayEndDate" class="form-control" required>
            </div>
          </div>
          <div class="form-group">
            <label>Type</label>
            <select name="request_type" class="form-control">
              <option value="holiday">Holiday</option>
              <option value="toil">Nahradne volno</option>
              <option value="doctor">Doctor</option>
              <option value="sick">Sick</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group">
            <label>Note</label>
            <textarea name="note" class="form-control" rows="3" placeholder="Optional note, time, context..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Submit request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  $(function () {
    $('[data-toggle="tooltip"]').tooltip({ container: 'body' });

    $('.holiday-cell-own').on('click', function () {
      var date = $(this).data('date');
      if (!date) {
        return;
      }
      $('#holidayStartDate').val(date);
      $('#holidayEndDate').val(date);
      $('#holidayRequestModal').modal('show');
    });
  });
</script>
