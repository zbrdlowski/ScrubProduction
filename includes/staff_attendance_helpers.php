<?php
declare(strict_types=1);

function staffAttendanceH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function staffAttendanceNormalizeYear($value): int
{
    $year = (int) preg_replace('/\D/', '', (string) $value);
    if ($year < 2000 || $year > 2100) {
        $year = (int) date('Y');
    }
    return $year;
}

function staffAttendanceNormalizeMonth($value): int
{
    $monthText = trim((string) $value);
    $monthText = preg_replace('/:.*/', '', $monthText) ?? '';
    if ($monthText === '') {
        return (int) date('n');
    }

    if (ctype_digit($monthText)) {
        $month = (int) $monthText;
        return ($month >= 1 && $month <= 12) ? $month : (int) date('n');
    }

    $months = [
        'january' => 1, 'jan' => 1,
        'february' => 2, 'feb' => 2,
        'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'may' => 5,
        'june' => 6, 'jun' => 6,
        'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9,
        'october' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12,
    ];

    $key = strtolower($monthText);
    return $months[$key] ?? (int) date('n');
}

function staffAttendanceMonthOptions(): array
{
    return [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ];
}

function staffAttendanceAvailableYears(mysqli $conn): array
{
    $years = [];
    $query = $conn->query('SHOW TABLES');
    if ($query) {
        while ($row = $query->fetch_array()) {
            $table = (string) ($row[0] ?? '');
            if (preg_match('/^attdn_(\d{4})$/', $table, $matches)) {
                $years[] = (int) $matches[1];
            }
        }
    }

    $years = array_values(array_unique($years));
    rsort($years, SORT_NUMERIC);
    return $years ?: [(int) date('Y')];
}

function staffAttendanceTableName(int $year): string
{
    return 'attdn_' . $year;
}

function staffAttendanceQuotedTableName(int $year): string
{
    return '`' . staffAttendanceTableName($year) . '`';
}

function staffAttendanceTableExists(mysqli $conn, int $year): bool
{
    static $cache = [];
    $cacheKey = spl_object_id($conn) . ':' . $year;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $table = $conn->real_escape_string(staffAttendanceTableName($year));
    $query = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $cache[$cacheKey] = (bool) ($query && $query->num_rows > 0);
}

function staffAttendanceEmployeeFilterOptions(): array
{
    return [
        'attendance' => 'Attendance enabled',
        'active' => 'Active employees',
        'inactive' => 'Inactive employees',
        'all' => 'All employees',
    ];
}

function staffAttendanceNormalizeEmployeeFilter($value): string
{
    $value = (string) $value;
    return array_key_exists($value, staffAttendanceEmployeeFilterOptions()) ? $value : 'attendance';
}

function staffAttendanceEmployeeList(mysqli $conn, string $filter): array
{
    $whereMap = [
        'attendance' => 'attendance_enabled = 1',
        'active' => "active = 'Active'",
        'inactive' => "active = 'Inactive'",
        'all' => '1 = 1',
    ];
    $where = $whereMap[$filter] ?? $whereMap['attendance'];
    $employees = [];

    $query = $conn->query("
        SELECT id, firstname, lastname, employee_id, active
        FROM employees
        WHERE {$where}
        ORDER BY lastname ASC, firstname ASC
    ");
    if ($query) {
        while ($row = $query->fetch_assoc()) {
            $employees[] = $row;
        }
    }

    return $employees;
}

function staffAttendanceFetchEmployee(mysqli $conn, int $employeeId): ?array
{
    if ($employeeId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            e.*,
            e.id AS emp_pk,
            e.employee_id AS emp_code,
            p.description AS position_name,
            s.time_in AS sched_in,
            s.time_out AS sched_out
        FROM employees e
        LEFT JOIN position p ON p.id = e.position_id
        LEFT JOIN schedules s ON s.id = e.schedule_id
        WHERE e.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $employee ?: null;
}

function staffAttendanceEmployeeName(?array $employee, bool $lastFirst = false): string
{
    if (!$employee) {
        return '';
    }
    $first = trim((string) ($employee['firstname'] ?? ''));
    $last = trim((string) ($employee['lastname'] ?? ''));
    $name = $lastFirst ? trim($last . ' ' . $first) : trim($first . ' ' . $last);
    return $name !== '' ? $name : 'Unknown employee';
}

function staffAttendanceScheduleSeconds(?array $employee, string $dateYmd): ?int
{
    if (!$employee) {
        return null;
    }

    $timeIn = (string) ($employee['sched_in'] ?? '');
    $timeOut = (string) ($employee['sched_out'] ?? '');
    if ($timeIn === '' || $timeOut === '') {
        return null;
    }

    $start = strtotime($dateYmd . ' ' . $timeIn);
    $end = strtotime($dateYmd . ' ' . $timeOut);
    if (!$start || !$end || $end <= $start) {
        return null;
    }

    return max(0, (int) ($end - $start) - 1800);
}

function staffAttendanceMovementLabel($movement): string
{
    switch ((int) $movement) {
        case 1:
            return 'Work';
        case 2:
            return 'Home';
        case 3:
            return 'Break';
        case 4:
            return 'Lunch';
        case 5:
            return 'Holiday';
        case 6:
            return 'Medical';
        default:
            return 'Other';
    }
}

function staffAttendanceMovementClass($movement): string
{
    switch ((int) $movement) {
        case 1:
            return 'staff-attendance-row-work';
        case 3:
            return 'staff-attendance-row-break';
        case 4:
            return 'staff-attendance-row-lunch';
        case 5:
            return 'staff-attendance-row-holiday';
        case 6:
            return 'staff-attendance-row-medical';
        default:
            return '';
    }
}

function staffAttendanceFormatSeconds(?int $seconds, bool $withSign = false): string
{
    if ($seconds === null) {
        return '--';
    }

    $sign = '';
    if ($seconds < 0) {
        $sign = '- ';
        $seconds = abs($seconds);
    } elseif ($withSign && $seconds > 0) {
        $sign = '+ ';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remainingSeconds = $seconds % 60;
    return $sign . sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
}

function staffAttendanceFormatTime($value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '--';
    }
    return date('H:i:s', strtotime($value));
}

function staffAttendanceDayName(string $dateYmd): string
{
    $timestamp = strtotime($dateYmd);
    return $timestamp ? date('l', $timestamp) : '';
}

function staffAttendanceIsDayOff(string $dateYmd, array $holidays, int $year): bool
{
    $dayMonth = date('d-m', strtotime($dateYmd));
    if (in_array($dayMonth, $holidays, true)) {
        return true;
    }

    $easter = easter_date($year);
    $goodFriday = date('d-m', $easter - 172800);
    $easterMonday = date('d-m', $easter + 86400);
    if ($dayMonth === $goodFriday || $dayMonth === $easterMonday) {
        return true;
    }

    return (int) date('N', strtotime($dateYmd)) > 5;
}

function staffAttendanceMovementSeconds(mysqli $conn, int $year, int $employeeId, string $dateYmd, int $movement, bool $includeRunning = true): int
{
    if ($employeeId <= 0 || !staffAttendanceTableExists($conn, $year)) {
        return 0;
    }

    $table = staffAttendanceQuotedTableName($year);
    $stmt = $conn->prepare("
        SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS seconds_total
        FROM {$table}
        WHERE employee_id = ?
          AND date = ?
          AND movement = ?
          AND time_in IS NOT NULL
          AND time_out IS NOT NULL
          AND time_out <> '23:59:59'
    ");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('isi', $employeeId, $dateYmd, $movement);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $seconds = (int) ($row['seconds_total'] ?? 0);

    if ($includeRunning && $dateYmd === date('Y-m-d')) {
        $stmt = $conn->prepare("
            SELECT time_in
            FROM {$table}
            WHERE employee_id = ?
              AND date = ?
              AND movement = ?
              AND time_in IS NOT NULL
              AND time_out = '23:59:59'
        ");
        if ($stmt) {
            $stmt->bind_param('isi', $employeeId, $dateYmd, $movement);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $start = strtotime($dateYmd . ' ' . (string) ($row['time_in'] ?? ''));
                if ($start && time() > $start) {
                    $seconds += (int) (time() - $start);
                }
            }
            $stmt->close();
        }
    }

    return max(0, $seconds);
}

function staffAttendanceDayHasRows(mysqli $conn, int $year, int $employeeId, string $dateYmd): bool
{
    if ($employeeId <= 0 || !staffAttendanceTableExists($conn, $year)) {
        return false;
    }

    $table = staffAttendanceQuotedTableName($year);
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS row_count
        FROM {$table}
        WHERE employee_id = ?
          AND date = ?
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('is', $employeeId, $dateYmd);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return (int) ($row['row_count'] ?? 0) > 0;
}

function staffAttendanceDayHasOpenPastRows(mysqli $conn, int $year, int $employeeId, string $dateYmd): bool
{
    if ($employeeId <= 0 || $dateYmd >= date('Y-m-d') || !staffAttendanceTableExists($conn, $year)) {
        return false;
    }

    $table = staffAttendanceQuotedTableName($year);
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS row_count
        FROM {$table}
        WHERE employee_id = ?
          AND date = ?
          AND (TIME(time_in) = '00:00:00' OR TIME(time_out) = '23:59:59')
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('is', $employeeId, $dateYmd);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return (int) ($row['row_count'] ?? 0) > 0;
}

function staffAttendanceDayRows(mysqli $conn, int $year, int $employeeId, string $dateYmd): array
{
    if ($employeeId <= 0 || !staffAttendanceTableExists($conn, $year)) {
        return [];
    }

    $table = staffAttendanceQuotedTableName($year);
    $stmt = $conn->prepare("
        SELECT
            a.*,
            a.id AS attid,
            e.employee_id AS employee_code,
            e.firstname,
            e.lastname
        FROM {$table} a
        LEFT JOIN employees e ON e.id = a.employee_id
        WHERE a.employee_id = ?
          AND a.date = ?
        ORDER BY a.date DESC, a.time_in ASC, a.id ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('is', $employeeId, $dateYmd);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function staffAttendanceRenderStyles(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;
    ?>
<style>
  .staff-attendance-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 12px;
  }
  .staff-attendance-toolbar .form-control,
  .staff-attendance-toolbar .btn {
    min-height: 31px;
  }
  .staff-attendance-card {
    border-top: 3px solid #20c997;
  }
  .staff-attendance-employee {
    display: flex;
    gap: 16px;
    align-items: stretch;
    padding: 14px 16px;
    margin: 0 0 15px 0;
    background: linear-gradient(135deg, #3c8dbc, #367fa9);
    color: #fff;
    border-radius: 6px;
  }
  .staff-attendance-employee-left {
    display: flex;
    gap: 12px;
    align-items: center;
    min-width: 260px;
  }
  .staff-attendance-avatar {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,255,255,.6);
    box-shadow: 0 4px 10px rgba(0,0,0,.35);
  }
  .staff-attendance-name {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .staff-attendance-sub {
    font-size: 13px;
    opacity: .95;
    margin: 3px 0 0 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .staff-attendance-employee-right {
    flex: 1 1 auto;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
  }
  .staff-attendance-info {
    flex: 1 1 180px;
    min-width: 180px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-radius: 8px;
    background: rgba(0,0,0,.28);
    border: 1px solid rgba(255,255,255,.18);
    padding: 10px 12px;
  }
  .staff-attendance-info i {
    font-size: 18px;
    opacity: .95;
  }
  .staff-attendance-info-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    opacity: .82;
  }
  .staff-attendance-info-value {
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .staff-attendance-row-work > td {
    background-color: rgba(0, 166, 90, 0.14) !important;
  }
  .staff-attendance-row-lunch > td {
    background-color: rgba(0, 104, 239, 0.14) !important;
  }
  .staff-attendance-row-break > td {
    background-color: rgba(243, 221, 18, 0.16) !important;
  }
  .staff-attendance-row-holiday > td,
  .staff-attendance-row-medical > td {
    background-color: rgba(32, 201, 151, 0.12) !important;
  }
  .staff-attendance-day-off > td {
    background-color: #4d4d4d !important;
    color: #ffe8d1;
  }
  .staff-attendance-work-day > td {
    color: #e6ffe6;
  }
  .staff-attendance-attention > td {
    background-color: #f02020 !important;
    color: #0a0a0a !important;
  }
  .staff-attendance-balance-positive {
    color: #00ff66;
    font-weight: 700;
  }
  .staff-attendance-balance-negative {
    color: #ff5f57;
    font-weight: 700;
  }
  .staff-attendance-balance-neutral {
    color: #f8f9fa;
    font-weight: 700;
  }
  .staff-attendance-readonly-note {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 31px;
    padding: 4px 10px;
    border-radius: 4px;
    background: rgba(32, 201, 151, .15);
    border: 1px solid rgba(32, 201, 151, .45);
    color: #d7fff3;
    font-weight: 700;
  }
  @media (max-width: 768px) {
    .staff-attendance-employee {
      flex-direction: column;
    }
    .staff-attendance-employee-left,
    .staff-attendance-info {
      min-width: 100%;
    }
  }
</style>
    <?php
}

function staffAttendanceRenderEmployeeBanner(?array $employee): void
{
    if (!$employee) {
        ?>
        <div class="alert alert-info">Select an employee to view attendance.</div>
        <?php
        return;
    }

    $photo = trim((string) ($employee['photo'] ?? ''));
    $gender = strtolower(trim((string) ($employee['gender'] ?? '')));
    $defaultPhoto = $gender === 'female' ? 'images/female.png' : 'images/male.png';
    $photoPath = $defaultPhoto;
    if ($photo !== '') {
        $candidate = 'images/' . $photo;
        if (file_exists(__DIR__ . '/../' . $candidate)) {
            $photoPath = $candidate;
        }
    }

    $name = staffAttendanceEmployeeName($employee);
    $code = trim((string) ($employee['emp_code'] ?? ''));
    $position = trim((string) ($employee['position_name'] ?? ''));
    $username = trim((string) ($employee['username'] ?? ''));
    $phone = trim((string) ($employee['contact_info'] ?? ''));
    $address = trim((string) ($employee['address'] ?? ''));
    $createdOn = trim((string) ($employee['created_on'] ?? ''));
    $active = strcasecmp(trim((string) ($employee['active'] ?? '')), 'Active') === 0;
    $schedule = trim((string) ($employee['sched_in'] ?? '')) && trim((string) ($employee['sched_out'] ?? ''))
        ? (string) $employee['sched_in'] . ' - ' . (string) $employee['sched_out']
        : '--';

    $sub = [];
    if ($position !== '') {
        $sub[] = $position;
    }
    if ($username !== '') {
        $sub[] = '@' . $username;
    }
    ?>
    <div class="staff-attendance-employee">
      <div class="staff-attendance-employee-left">
        <img class="staff-attendance-avatar" src="<?= staffAttendanceH($photoPath) ?>" alt="Employee photo">
        <div style="min-width:0;">
          <p class="staff-attendance-name">
            <?= staffAttendanceH($name) ?>
            <?php if ($code !== ''): ?>
              <span style="margin-left:10px; font-size:12px; opacity:.95;"><i class="fa fa-id-badge"></i> <?= staffAttendanceH($code) ?></span>
            <?php endif; ?>
          </p>
          <p class="staff-attendance-sub"><?= staffAttendanceH(implode(' - ', $sub)) ?></p>
        </div>
      </div>
      <div class="staff-attendance-employee-right">
        <div class="staff-attendance-info">
          <i class="fa fa-user"></i>
          <div>
            <div class="staff-attendance-info-label">Status</div>
            <div class="staff-attendance-info-value"><?= $active ? 'Active' : 'Inactive' ?></div>
          </div>
        </div>
        <div class="staff-attendance-info">
          <i class="fa fa-business-time"></i>
          <div>
            <div class="staff-attendance-info-label">Working Hours</div>
            <div class="staff-attendance-info-value"><?= staffAttendanceH($schedule) ?></div>
          </div>
        </div>
        <div class="staff-attendance-info">
          <i class="fa fa-phone"></i>
          <div>
            <div class="staff-attendance-info-label">Phone</div>
            <div class="staff-attendance-info-value"><?= staffAttendanceH($phone !== '' ? $phone : '--') ?></div>
          </div>
        </div>
        <div class="staff-attendance-info">
          <i class="fa fa-map-marker"></i>
          <div>
            <div class="staff-attendance-info-label">Address</div>
            <div class="staff-attendance-info-value"><?= staffAttendanceH($address !== '' ? $address : '--') ?></div>
          </div>
        </div>
        <div class="staff-attendance-info">
          <i class="fa fa-calendar"></i>
          <div>
            <div class="staff-attendance-info-label">Employed Since</div>
            <div class="staff-attendance-info-value">
              <?= $createdOn !== '' ? staffAttendanceH(date('M d, Y', strtotime($createdOn))) : '--' ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
}

function staffAttendanceUrl(string $page, array $params = []): string
{
    return 'index.php?' . http_build_query(array_merge(['page' => $page], $params));
}
