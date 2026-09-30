<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/staff_attendance_helpers.php';

if (!auth_can('attendance.view_all')) {
    echo '<div class="alert alert-danger">You do not have permission to view staff attendance.</div>';
    return;
}

staffAttendanceRenderStyles();

$today = date('Y-m-d');
$selectedEmployeeId = max(0, (int) ($_GET['eno'] ?? 0));
$selectedEmployee = $selectedEmployeeId > 0 ? staffAttendanceFetchEmployee($conn, $selectedEmployeeId) : null;

$selectedDate = (string) ($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = $today;
}
$year = staffAttendanceNormalizeYear($_GET['year'] ?? substr($selectedDate, 0, 4));
$dateYear = (int) substr($selectedDate, 0, 4);
if ($dateYear >= 2000 && $dateYear <= 2100) {
    $year = $dateYear;
}
$month = staffAttendanceNormalizeMonth($_GET['month'] ?? substr($selectedDate, 5, 2));
$monthPadded = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
$employeeFilter = staffAttendanceNormalizeEmployeeFilter($_GET['view'] ?? 'attendance');
$tableExists = staffAttendanceTableExists($conn, $year);
$rows = ($selectedEmployee && $tableExists) ? staffAttendanceDayRows($conn, $year, $selectedEmployeeId, $selectedDate) : [];

$backUrl = staffAttendanceUrl('staff_attendance', [
    'eno' => $selectedEmployeeId,
    'year' => $year,
    'month' => $monthPadded,
    'view' => $employeeFilter,
]);

$leaveTime = '--';
$remaining = '';
$breakdown = '';
$canComputeLeave = false;
$leaveTimestamp = 0;
$isToday = $selectedDate === $today;
$missingNetWorkSeconds = 0;
$isDone = false;

if ($selectedEmployee) {
    $schedIn = trim((string) ($selectedEmployee['sched_in'] ?? ''));
    $schedOut = trim((string) ($selectedEmployee['sched_out'] ?? ''));
    $scheduleSpan = 0;
    if ($schedIn !== '' && $schedOut !== '') {
        $scheduleStart = strtotime($selectedDate . ' ' . $schedIn);
        $scheduleEnd = strtotime($selectedDate . ' ' . $schedOut);
        if ($scheduleStart && $scheduleEnd && $scheduleEnd > $scheduleStart) {
            $scheduleSpan = (int) ($scheduleEnd - $scheduleStart);
        }
    }

    $mandatoryLunchSeconds = $scheduleSpan > 21600 ? 1800 : 0;
    $requiredNetWorkSeconds = max(0, $scheduleSpan - $mandatoryLunchSeconds);

    $firstWorkIn = '';
    if ($tableExists) {
        $table = staffAttendanceQuotedTableName($year);
        $stmt = $conn->prepare("
            SELECT MIN(time_in) AS first_in
            FROM {$table}
            WHERE employee_id = ?
              AND date = ?
              AND movement IN (1, 6)
              AND time_in IS NOT NULL
        ");
        if ($stmt) {
            $stmt->bind_param('is', $selectedEmployeeId, $selectedDate);
            $stmt->execute();
            $result = $stmt->get_result();
            $firstRow = $result ? $result->fetch_assoc() : null;
            $firstWorkIn = (string) ($firstRow['first_in'] ?? '');
            $stmt->close();
        }
    }

    if ($firstWorkIn !== '' && $requiredNetWorkSeconds > 0) {
        $workSeconds = staffAttendanceMovementSeconds($conn, $year, $selectedEmployeeId, $selectedDate, 1);
        $medicalSeconds = staffAttendanceMovementSeconds($conn, $year, $selectedEmployeeId, $selectedDate, 6);
        $breakSeconds = staffAttendanceMovementSeconds($conn, $year, $selectedEmployeeId, $selectedDate, 3);
        $lunchSeconds = staffAttendanceMovementSeconds($conn, $year, $selectedEmployeeId, $selectedDate, 4);
        $effectiveLunch = $mandatoryLunchSeconds > 0 ? max(1800, $lunchSeconds) : $lunchSeconds;

        $startTimestamp = strtotime($selectedDate . ' ' . $firstWorkIn);
        if ($startTimestamp) {
            $canComputeLeave = true;
            $leaveTimestamp = $startTimestamp + $requiredNetWorkSeconds + $breakSeconds + $effectiveLunch;
            $leaveTime = date('H:i', $leaveTimestamp);
            $missingNetWorkSeconds = max(0, $requiredNetWorkSeconds - $workSeconds - $medicalSeconds);
            $isDone = $isToday ? time() >= $leaveTimestamp : $missingNetWorkSeconds === 0;

            if ($isToday) {
                $secondsRemaining = max(0, $leaveTimestamp - time());
                $remaining = staffAttendanceFormatSeconds($secondsRemaining);
            }

            $breakdown = 'Work: ' . staffAttendanceFormatSeconds($workSeconds)
                . ($medicalSeconds > 0 ? ' - Medical: ' . staffAttendanceFormatSeconds($medicalSeconds) : '')
                . ' - Lunch: ' . staffAttendanceFormatSeconds($effectiveLunch)
                . ' - Breaks: ' . staffAttendanceFormatSeconds($breakSeconds)
                . ' - Net target: ' . staffAttendanceFormatSeconds($requiredNetWorkSeconds);
        }
    }
}
?>

<div class="container-fluid">
  <div class="card staff-attendance-card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
      <h3 class="card-title mb-0">
        <i class="fa fa-clock mr-1"></i>
        Attendance Details for <?= staffAttendanceH(date('M d, Y', strtotime($selectedDate))) ?>
      </h3>
      <div>
        <span class="staff-attendance-readonly-note mr-2"><i class="fa fa-lock"></i> Read-only</span>
        <a class="btn btn-warning btn-sm" href="<?= staffAttendanceH($backUrl) ?>">
          <i class="fa fa-arrow-left"></i> Back to overview
        </a>
      </div>
    </div>
    <div class="card-body">
      <?php if ($selectedEmployeeId > 0 && !$selectedEmployee): ?>
        <div class="alert alert-warning">Selected employee was not found.</div>
      <?php endif; ?>

      <?php staffAttendanceRenderEmployeeBanner($selectedEmployee); ?>

      <?php if ($selectedEmployee && !$tableExists): ?>
        <div class="alert alert-warning">Attendance table <code><?= staffAttendanceH(staffAttendanceTableName($year)) ?></code> does not exist.</div>
      <?php endif; ?>

      <?php if ($selectedEmployee && $canComputeLeave): ?>
        <div
          id="staffGoHomeBanner"
          class="staff-attendance-employee"
          style="background: linear-gradient(135deg, <?= $isDone ? '#00a65a, #008d4c' : '#dd4b39, #b93b2f' ?>);"
          data-leave-ts="<?= (int) $leaveTimestamp ?>"
          data-is-today="<?= $isToday ? '1' : '0' ?>"
        >
          <div class="staff-attendance-employee-left">
            <i class="fa fa-sign-out" style="font-size:32px;"></i>
            <div>
              <p class="staff-attendance-name">Expected leave time: <span id="staffGoHomeTime"><?= staffAttendanceH($leaveTime) ?></span></p>
              <p class="staff-attendance-sub">
                <?php if ($isToday): ?>
                  <span id="staffGoHomeRemainWrap">Remaining: <strong id="staffGoHomeRemain"><?= staffAttendanceH($remaining) ?></strong></span>
                  <span id="staffGoHomeDoneWrap" style="<?= $isDone ? '' : 'display:none;' ?>">Completed</span>
                <?php elseif ($isDone): ?>
                  Completed
                <?php else: ?>
                  Missing: <strong><?= staffAttendanceH(staffAttendanceFormatSeconds($missingNetWorkSeconds)) ?></strong>
                <?php endif; ?>
              </p>
              <div class="staff-attendance-sub"><?= staffAttendanceH($breakdown) ?></div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($selectedEmployee && $tableExists): ?>
        <div class="table-responsive">
          <table id="staffAttendanceDetailTable" class="table table-bordered table-hover" style="width:100%;">
            <thead>
              <tr>
                <th>Date</th>
                <th>Employee ID</th>
                <th>Name</th>
                <th class="text-center">Clock In</th>
                <th class="text-center">Clock Out</th>
                <th class="text-center">Activity</th>
                <th class="text-center">Duration</th>
                <th class="text-center">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <?php
                  $timeIn = (string) ($row['time_in'] ?? '');
                  $timeOut = (string) ($row['time_out'] ?? '');
                  $isOpen = $timeOut === '23:59:59';
                  $isBadClock = $timeIn === '00:00:00' || ($isOpen && $selectedDate !== $today);
                  $duration = '--';
                  $start = strtotime($selectedDate . ' ' . $timeIn);
                  $end = $isOpen ? ($selectedDate === $today ? time() : null) : strtotime($selectedDate . ' ' . $timeOut);
                  if ($start && $end && $end >= $start) {
                      $duration = staffAttendanceFormatSeconds((int) ($end - $start));
                  }
                  $statusBadge = '<span class="badge badge-success">Closed</span>';
                  if ($isBadClock) {
                      $statusBadge = '<span class="badge badge-danger">Needs review</span>';
                  } elseif ($isOpen) {
                      $statusBadge = '<span class="badge badge-warning">Open</span>';
                  }
                ?>
                <tr class="<?= staffAttendanceH(staffAttendanceMovementClass($row['movement'] ?? 0)) ?>">
                  <td><?= staffAttendanceH(date('M d, Y', strtotime((string) $row['date']))) ?></td>
                  <td><?= staffAttendanceH($row['employee_code'] ?? '') ?></td>
                  <td><?= staffAttendanceH(trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? ''))) ?></td>
                  <td class="text-center"><?= staffAttendanceH(staffAttendanceFormatTime($timeIn)) ?></td>
                  <td class="text-center"><?= $isOpen ? 'Open' : staffAttendanceH(staffAttendanceFormatTime($timeOut)) ?></td>
                  <td class="text-center"><?= staffAttendanceH(staffAttendanceMovementLabel($row['movement'] ?? 0)) ?></td>
                  <td class="text-center"><?= staffAttendanceH($duration) ?></td>
                  <td class="text-center"><?= $statusBadge ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if (!$rows): ?>
          <div class="alert alert-info mt-3">No attendance records for this day.</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  function pad(number) {
    return (number < 10 ? '0' : '') + number;
  }

  function formatSeconds(seconds) {
    var hours = Math.floor(seconds / 3600);
    var minutes = Math.floor((seconds % 3600) / 60);
    var remainingSeconds = seconds % 60;
    return pad(hours) + ':' + pad(minutes) + ':' + pad(remainingSeconds);
  }

  $(function () {
    if ($.fn.DataTable && $('#staffAttendanceDetailTable').length) {
      $('#staffAttendanceDetailTable').DataTable({
        responsive: true,
        info: true,
        lengthChange: false,
        autoWidth: false,
        pageLength: 100,
        dom: 'Bfrtip',
        buttons: ['copy', 'csv', 'excel', 'pdf', 'print']
      }).buttons().container().appendTo('#staffAttendanceDetailTable_wrapper .col-md-6:eq(0)');
    }

    var banner = document.getElementById('staffGoHomeBanner');
    if (!banner) return;

    var leaveTs = parseInt(banner.getAttribute('data-leave-ts') || '0', 10);
    var isToday = banner.getAttribute('data-is-today') === '1';
    var remain = document.getElementById('staffGoHomeRemain');
    var remainWrap = document.getElementById('staffGoHomeRemainWrap');
    var doneWrap = document.getElementById('staffGoHomeDoneWrap');

    function tick() {
      if (!isToday || !leaveTs) return;

      var diff = leaveTs - Math.floor(Date.now() / 1000);
      if (diff <= 0) {
        banner.style.background = 'linear-gradient(135deg, #00a65a, #008d4c)';
        if (remain) remain.textContent = '00:00:00';
        if (remainWrap) remainWrap.style.display = 'none';
        if (doneWrap) doneWrap.style.display = '';
        return;
      }

      banner.style.background = 'linear-gradient(135deg, #dd4b39, #b93b2f)';
      if (remain) remain.textContent = formatSeconds(diff);
      if (remainWrap) remainWrap.style.display = '';
      if (doneWrap) doneWrap.style.display = 'none';
    }

    tick();
    window.setInterval(tick, 60000);
  });
})();
</script>
