<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/staff_attendance_helpers.php';

if (!auth_can('attendance.view_all')) {
    echo '<div class="alert alert-danger">You do not have permission to view staff attendance.</div>';
    return;
}

include __DIR__ . '/../sviatky.php';

staffAttendanceRenderStyles();

$employeeFilter = staffAttendanceNormalizeEmployeeFilter($_GET['view'] ?? 'attendance');
$employees = staffAttendanceEmployeeList($conn, $employeeFilter);
$selectedEmployeeId = max(0, (int) ($_GET['eno'] ?? 0));
$selectedEmployee = $selectedEmployeeId > 0 ? staffAttendanceFetchEmployee($conn, $selectedEmployeeId) : null;

$year = staffAttendanceNormalizeYear($_GET['year'] ?? date('Y'));
$month = staffAttendanceNormalizeMonth($_GET['month'] ?? date('n'));
$monthPadded = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
$monthOptions = staffAttendanceMonthOptions();
$availableYears = staffAttendanceAvailableYears($conn);
if (!in_array($year, $availableYears, true)) {
    $availableYears[] = $year;
    rsort($availableYears, SORT_NUMERIC);
}

$currentMonth = DateTime::createFromFormat('Y-m-d', $year . '-' . $monthPadded . '-01') ?: new DateTime('first day of this month');
$previousMonth = (clone $currentMonth)->modify('-1 month');
$nextMonth = (clone $currentMonth)->modify('+1 month');
$tableExists = staffAttendanceTableExists($conn, $year);
?>

<div class="container-fluid">
  <div class="card staff-attendance-card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
      <h3 class="card-title mb-0"><i class="fa fa-calendar-check mr-1"></i> Staff Attendance</h3>
      <span class="staff-attendance-readonly-note"><i class="fa fa-lock"></i> Read-only</span>
    </div>
    <div class="card-body">
      <form method="get" class="staff-attendance-toolbar">
        <input type="hidden" name="page" value="staff_attendance">

        <a class="btn btn-primary btn-sm" href="<?= staffAttendanceH(staffAttendanceUrl('staff_attendance', [
            'eno' => $selectedEmployeeId,
            'year' => $previousMonth->format('Y'),
            'month' => $previousMonth->format('m'),
            'view' => $employeeFilter,
        ])) ?>">
          <i class="fa fa-chevron-left"></i> Previous
        </a>
        <a class="btn btn-primary btn-sm" href="<?= staffAttendanceH(staffAttendanceUrl('staff_attendance', [
            'eno' => $selectedEmployeeId,
            'year' => $nextMonth->format('Y'),
            'month' => $nextMonth->format('m'),
            'view' => $employeeFilter,
        ])) ?>">
          Next <i class="fa fa-chevron-right"></i>
        </a>

        <select class="form-control input-sm" name="view" style="width:180px;" onchange="this.form.submit()">
          <?php foreach (staffAttendanceEmployeeFilterOptions() as $key => $label): ?>
            <option value="<?= staffAttendanceH($key) ?>" <?= $employeeFilter === $key ? 'selected' : '' ?>>
              <?= staffAttendanceH($label) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select class="form-control input-sm" name="eno" style="width:260px;" onchange="this.form.submit()">
          <option value="">Select employee</option>
          <?php foreach ($employees as $employee): ?>
            <?php
              $employeeId = (int) $employee['id'];
              $employeeLabel = staffAttendanceEmployeeName($employee, true);
              $employeeCode = trim((string) ($employee['employee_id'] ?? ''));
              if ($employeeCode !== '') {
                  $employeeLabel .= ' (' . $employeeCode . ')';
              }
              if (($employee['active'] ?? '') !== 'Active') {
                  $employeeLabel .= ' - inactive';
              }
            ?>
            <option value="<?= $employeeId ?>" <?= $employeeId === $selectedEmployeeId ? 'selected' : '' ?>>
              <?= staffAttendanceH($employeeLabel) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select class="form-control input-sm" name="month" style="width:145px;" onchange="this.form.submit()">
          <?php foreach ($monthOptions as $monthNumber => $monthName): ?>
            <option value="<?= (int) $monthNumber ?>" <?= (int) $monthNumber === $month ? 'selected' : '' ?>>
              <?= staffAttendanceH($monthName) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select class="form-control input-sm" name="year" style="width:100px;" onchange="this.form.submit()">
          <?php foreach ($availableYears as $availableYear): ?>
            <option value="<?= (int) $availableYear ?>" <?= (int) $availableYear === $year ? 'selected' : '' ?>>
              <?= (int) $availableYear ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>

      <?php if ($selectedEmployeeId > 0 && !$selectedEmployee): ?>
        <div class="alert alert-warning">Selected employee was not found.</div>
      <?php endif; ?>

      <?php staffAttendanceRenderEmployeeBanner($selectedEmployee); ?>

      <?php if ($selectedEmployee && !$tableExists): ?>
        <div class="alert alert-warning">Attendance table <code><?= staffAttendanceH(staffAttendanceTableName($year)) ?></code> does not exist.</div>
      <?php endif; ?>

      <?php if ($selectedEmployee && $tableExists): ?>
        <?php
          $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
          $scheduleSeconds = staffAttendanceScheduleSeconds($selectedEmployee, $year . '-' . $monthPadded . '-01');
        ?>
        <div class="table-responsive">
          <table id="staffAttendanceMonthTable" class="table table-bordered table-hover">
            <thead>
              <tr>
                <th style="width:70px;">Day</th>
                <th style="width:150px;">Day Type</th>
                <th>Work</th>
                <th>Lunch</th>
                <th>Breaks</th>
                <th>Holiday</th>
                <th>Sick / Medical</th>
                <th style="width:130px;">Balance</th>
                <th style="width:115px;">Details</th>
              </tr>
            </thead>
            <tbody>
              <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                <?php
                  $dayPadded = str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                  $dateYmd = $year . '-' . $monthPadded . '-' . $dayPadded;
                  $dayOff = staffAttendanceIsDayOff($dateYmd, $sviatky ?? [], $year);
                  $hasRows = staffAttendanceDayHasRows($conn, $year, $selectedEmployeeId, $dateYmd);
                  $hasOpenPastRows = staffAttendanceDayHasOpenPastRows($conn, $year, $selectedEmployeeId, $dateYmd);
                  $rowClass = $dayOff ? 'staff-attendance-day-off' : 'staff-attendance-work-day';
                  if ($hasOpenPastRows) {
                      $rowClass .= ' staff-attendance-attention';
                  }

                  $daySchedule = $scheduleSeconds;
                  $workedTotal = 0;
                  $isHolidayOrSick = false;

                  $workSeconds = staffAttendanceMovementSeconds($conn, $year, $selectedEmployeeId, $dateYmd, 1);
                  if ($workSeconds > 0) {
                      $workedTotal += $workSeconds;
                  }

                  $lunchSeconds = staffAttendanceMovementSeconds($conn, $year, $selectedEmployeeId, $dateYmd, 4);
                  $breakSeconds = staffAttendanceMovementSeconds($conn, $year, $selectedEmployeeId, $dateYmd, 3);

                  $holidaySeconds = staffAttendanceMovementSeconds($conn, $year, $selectedEmployeeId, $dateYmd, 5);
                  if ($holidaySeconds > 0) {
                      $isHolidayOrSick = true;
                      $daySchedule = 28800;
                      $holidaySeconds = min($holidaySeconds, $daySchedule);
                      $workedTotal += $holidaySeconds;
                  }

                  $medicalSeconds = staffAttendanceMovementSeconds($conn, $year, $selectedEmployeeId, $dateYmd, 6);
                  if ($medicalSeconds > 0) {
                      $isHolidayOrSick = true;
                      $daySchedule = 28800;
                      if ($medicalSeconds === 30600) {
                          $medicalSeconds = 28800;
                      }
                      $medicalSeconds = min($medicalSeconds, $daySchedule);
                      $workedTotal += $medicalSeconds;
                  }

                  $balanceHtml = '';
                  if ($hasRows && $daySchedule !== null) {
                      if ($isHolidayOrSick) {
                          $balanceHtml = '<span class="staff-attendance-balance-neutral">00:00:00</span>';
                      } else {
                          $balance = $dayOff ? $workedTotal : ($workedTotal - $daySchedule);
                          $balanceClass = $balance > 0
                              ? 'staff-attendance-balance-positive'
                              : ($balance < 0 ? 'staff-attendance-balance-negative' : 'staff-attendance-balance-neutral');
                          $balanceHtml = '<span class="' . $balanceClass . '">' . staffAttendanceH(staffAttendanceFormatSeconds($balance, true)) . '</span>';
                      }
                  }

                  $detailUrl = staffAttendanceUrl('staff_attendance_detail', [
                      'eno' => $selectedEmployeeId,
                      'date' => $dateYmd,
                      'year' => $year,
                      'month' => $monthPadded,
                      'view' => $employeeFilter,
                  ]);
                ?>
                <tr class="<?= staffAttendanceH($rowClass) ?>">
                  <td><?= staffAttendanceH($dayPadded) ?></td>
                  <td><?= staffAttendanceH(staffAttendanceDayName($dateYmd)) ?></td>
                  <td class="text-center"><?= $workSeconds > 0 ? staffAttendanceH(staffAttendanceFormatSeconds($workSeconds)) : '--' ?></td>
                  <td class="text-center"><?= $lunchSeconds > 0 ? staffAttendanceH(staffAttendanceFormatSeconds($lunchSeconds)) : '--' ?></td>
                  <td class="text-center"><?= $breakSeconds > 0 ? staffAttendanceH(staffAttendanceFormatSeconds($breakSeconds)) : '--' ?></td>
                  <td class="text-center"><?= $holidaySeconds > 0 ? staffAttendanceH(staffAttendanceFormatSeconds($holidaySeconds)) : '--' ?></td>
                  <td class="text-center"><?= $medicalSeconds > 0 ? staffAttendanceH(staffAttendanceFormatSeconds($medicalSeconds)) : '--' ?></td>
                  <td class="text-center"><?= $balanceHtml ?></td>
                  <td class="text-center">
                    <?php if ($hasRows): ?>
                      <a class="btn btn-primary btn-sm" href="<?= staffAttendanceH($detailUrl) ?>">
                        <i class="fa fa-search"></i> Details
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
