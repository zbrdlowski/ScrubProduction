<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/holiday_company.php';

function companyAssert(bool $condition, string $message): void
{
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function companyExpectInvalid(callable $operation): void
{
  try {
    $operation();
  } catch (InvalidArgumentException $error) {
    return;
  }
  throw new RuntimeException('Expected validation to reject the operation.');
}

$publicHolidays = ['24-12' => true, '25-12' => true, '01-01' => true];
companyAssert(holidayCompanyWorkingDates('2026-12-23', '2026-12-28', $publicHolidays)
  === ['2026-12-23', '2026-12-28'], 'Weekends and public holidays must be excluded.');
companyAssert(holidayCompanyWorkingDates('2026-12-31', '2027-01-04', $publicHolidays)
  === ['2026-12-31', '2027-01-04'], 'Year boundaries must work.');
companyAssert(holidayCompanyWorkingDates('2028-02-29', '2028-02-29', [])
  === ['2028-02-29'], 'Leap day must be accepted.');
foreach ([['2026-02-29', '2026-03-02'], ['2026-12-08', '2026-12-07'],
  ['', '2026-12-07'], ['2026-12-05', '2026-12-06'], ['2026-12-24', '2026-12-25']] as [$start, $end]) {
  companyExpectInvalid(static function () use ($start, $end, $publicHolidays): void {
    holidayCompanyWorkingDates($start, $end, $publicHolidays);
  });
}

require_once dirname(__DIR__) . '/includes/conn.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Connection-local temporary tables shadow production tables. All writes stay in these fixtures.
$conn->query("CREATE TEMPORARY TABLE employees (
  id INT PRIMARY KEY, firstname VARCHAR(60), lastname VARCHAR(60), active VARCHAR(20),
  holiday_planner_enabled TINYINT DEFAULT 1, photo VARCHAR(255) DEFAULT '', position_id INT DEFAULT 1
) ENGINE=InnoDB");
$conn->query("CREATE TEMPORARY TABLE holiday_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL,
  request_type ENUM('holiday','toil','doctor','sick','other') NOT NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL,
  start_date DATE NOT NULL, end_date DATE NOT NULL, note TEXT, admin_note TEXT,
  requested_by INT, reviewed_by INT, reviewed_at DATETIME, employee_seen_at DATETIME,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (employee_id), KEY (start_date,end_date)
) ENGINE=InnoDB");
$conn->query("CREATE TEMPORARY TABLE position (id INT PRIMARY KEY, description VARCHAR(60)) ENGINE=InnoDB");

try {
  $conn->query("INSERT INTO employees (id,firstname,lastname,active,holiday_planner_enabled) VALUES
    (1,'First','Employee','Active',1), (2,'Second','Employee','Active',1),
    (3,'Hidden','Employee','Active',0), (4,'Former','Employee','Inactive',1)");
  $conn->query("INSERT INTO position VALUES (1,'Test department')");
  $conn->query("INSERT INTO holiday_requests
    (employee_id,request_type,status,start_date,end_date,note) VALUES
    (1,'doctor','approved','2026-12-09','2026-12-09','Keep doctor'),
    (1,'toil','pending','2026-12-11','2026-12-11','Keep pending'),
    (1,'holiday','rejected','2026-12-07','2026-12-08','Keep rejected'),
    (1,'holiday','cancelled','2026-12-14','2026-12-14','Keep cancelled'),
    (2,'sick','approved','2026-12-07','2026-12-14','Keep sick'),
    (2,'other','pending','2026-12-08','2026-12-10','Overlapping record')");

  $mode = $argv[1] ?? '';
  if (in_array($mode, ['--render-admin', '--render-user', '--render-result', '--invalid-csrf', '--unauthorized'], true)) {
    $_SESSION = ['user_id' => 1, 'permission' => in_array($mode, ['--render-admin', '--render-result', '--invalid-csrf'], true) ? 900 : 100,
      'dpt' => 1, 'name' => 'Test Employee', 'holiday_company_csrf' => str_repeat('a', 64)];
    $editableDate = date('Y-m-d', strtotime('+1 year'));
    $editableStmt = $conn->prepare("INSERT INTO holiday_requests (employee_id,request_type,status,start_date,end_date,note)
      VALUES (1,'holiday','approved',?,?,'Editable note')");
    $editableStmt->bind_param('ss', $editableDate, $editableDate);
    $editableStmt->execute();
    $editableStmt->close();
    if ($mode === '--render-result') {
      $_SESSION['holiday_company_result'] = holidayCreateCompanyLeave($conn, true, 1, '2026-12-07', '2026-12-14', 'Shared note', []);
    }
    $renderRowCount = (int) $conn->query('SELECT COUNT(*) AS total FROM holiday_requests')->fetch_assoc()['total'];
    $_GET = ['start' => '2026-12-01'];
    $_POST = [];
    $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    if (in_array($mode, ['--invalid-csrf', '--unauthorized'], true)) {
      $_SERVER['REQUEST_METHOD'] = 'POST';
      $_POST = ['action' => 'create_company_holiday', 'csrf_token' => 'bad-token',
        'start_date' => '2026-12-07', 'end_date' => '2026-12-14', 'note' => 'Must not write'];
    }
    ob_start();
    include dirname(__DIR__) . '/includes/holidays.php';
    $html = ob_get_clean();
    companyAssert((int) $conn->query('SELECT COUNT(*) AS total FROM holiday_requests')->fetch_assoc()['total'] === $renderRowCount,
      'Rendering or unauthorized submission must not create requests.');
    echo $html;
  } else {
    $original = $conn->query('SELECT * FROM holiday_requests ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    companyExpectInvalid(static function () use ($conn): void {
      holidayCreateCompanyLeave($conn, false, 1, '2026-12-07', '2026-12-14', '', []);
    });
    companyExpectInvalid(static function () use ($conn): void {
      holidayCreateCompanyLeave($conn, true, 0, '2026-12-07', '2026-12-14', '', []);
    });
    $result = holidayCreateCompanyLeave($conn, true, 99, '2026-12-07', '2026-12-14', "Shared 'note' <test>", []);
    companyAssert($result['employees_total'] === 3 && $result['employees_added'] === 2, 'Include all active employees, including hidden ones.');
    companyAssert($result['days_added'] === 10 && $result['days_skipped'] === 8, 'Count employee-days once even with overlapping records.');
    companyAssert($result['skipped'][0]['dates'] === ['2026-12-09', '2026-12-11'], 'Report exact occupied dates.');
    $after = $conn->query('SELECT * FROM holiday_requests WHERE id <= 6 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    companyAssert($after === $original, 'Existing request states and notes must not change.');
    $added = $conn->query('SELECT * FROM holiday_requests WHERE id > 6 ORDER BY employee_id,start_date')->fetch_all(MYSQLI_ASSOC);
    companyAssert(count($added) === 4, 'Only contiguous unoccupied working days may share a range.');
    companyAssert(array_map(static function (array $row): array {
      return [(int) $row['employee_id'], $row['start_date'], $row['end_date']];
    }, $added) === [[1,'2026-12-07','2026-12-08'], [1,'2026-12-10','2026-12-10'],
      [1,'2026-12-14','2026-12-14'], [3,'2026-12-07','2026-12-14']], 'Split around conflicts without filling their days.');
    foreach ($added as $row) {
      companyAssert($row['status'] === 'approved' && $row['request_type'] === 'holiday', 'New records must be approved holidays.');
      companyAssert((int) $row['requested_by'] === 99 && (int) $row['reviewed_by'] === 99
        && $row['reviewed_at'] !== null && $row['employee_seen_at'] === null, 'Record the approver and keep employee notification unread.');
      companyAssert($row['note'] === "Shared 'note' <test>" && $row['admin_note'] !== '', 'Preserve the supplied note and company label.');
    }
    $again = holidayCreateCompanyLeave($conn, true, 99, '2026-12-07', '2026-12-14', 'Repeat', []);
    companyAssert($again['days_added'] === 0 && $again['days_skipped'] === 18, 'Repeat submission must not duplicate days.');

    $beforeFailure = $conn->query('SELECT * FROM holiday_requests ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $conn->query("ALTER TABLE holiday_requests ADD CONSTRAINT test_second_employee_failure
      CHECK (employee_id <> 2 OR start_date <> '2027-01-04')");
    foreach ([MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, MYSQLI_REPORT_OFF] as $reportMode) {
      mysqli_report($reportMode);
      $failed = false;
      try {
        holidayCreateCompanyLeave($conn, true, 99, '2027-01-04', '2027-01-04', 'Must roll back', []);
      } catch (Throwable $error) {
        $failed = true;
      }
      companyAssert($failed, 'A failed employee insert must propagate an error.');
      companyAssert($conn->query('SELECT * FROM holiday_requests ORDER BY id')->fetch_all(MYSQLI_ASSOC) === $beforeFailure,
        'A failed batch must roll back earlier employee inserts.');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->query("UPDATE employees SET active='Inactive'");
    companyExpectInvalid(static function () use ($conn): void {
      holidayCreateCompanyLeave($conn, true, 99, '2027-01-05', '2027-01-05', '', []);
    });
    echo "holiday_company: OK (dates, permissions, conflicts, active scope, approvals, idempotency, rollback)\n";
  }
} finally {
  $conn->query('DROP TEMPORARY TABLE IF EXISTS holiday_requests');
  $conn->query('DROP TEMPORARY TABLE IF EXISTS employees');
  $conn->query('DROP TEMPORARY TABLE IF EXISTS position');
}
