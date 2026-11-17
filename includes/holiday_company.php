<?php

function holidayCompanyWorkingDates(string $start, string $end, array $publicHolidays): array
{
  foreach ([$start, $end] as $value) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
      throw new InvalidArgumentException('Enter valid holiday dates.');
    }
  }
  if ($start > $end) {
    throw new InvalidArgumentException('The end date must not be before the start date.');
  }

  $dates = [];
  $last = new DateTimeImmutable($end);
  for ($day = new DateTimeImmutable($start); $day <= $last; $day = $day->modify('+1 day')) {
    if ((int) $day->format('N') < 6 && !isset($publicHolidays[$day->format('d-m')])) {
      $dates[] = $day->format('Y-m-d');
    }
  }
  if (!$dates) {
    throw new InvalidArgumentException('The selected period does not contain any working days.');
  }
  return $dates;
}

function holidayCreateCompanyLeave(
  mysqli $conn,
  bool $canManage,
  int $adminId,
  string $start,
  string $end,
  string $note,
  array $publicHolidays
): array {
  if (!$canManage || $adminId <= 0) {
    throw new InvalidArgumentException('You do not have permission to perform this action.');
  }
  $dates = holidayCompanyWorkingDates($start, $end, $publicHolidays);
  $note = trim($note);
  if (strlen($note) > 60000) {
    throw new InvalidArgumentException('The note is too long.');
  }
  $adminNote = 'Company-wide holiday';
  $result = ['employees_total' => 0, 'employees_added' => 0, 'days_added' => 0, 'days_skipped' => 0, 'skipped' => []];
  $overlapStmt = null;
  $insertStmt = null;

  if (!$conn->begin_transaction()) {
    throw new RuntimeException('Could not start company leave transaction.');
  }
  try {
    // Lock employees in a stable order so concurrent bulk submissions cannot create duplicates.
    $employeesResult = $conn->query("SELECT id, firstname, lastname FROM employees
        WHERE active='Active' ORDER BY id FOR UPDATE");
    if (!$employeesResult) {
      throw new RuntimeException('Could not load employees.');
    }
    $employees = $employeesResult->fetch_all(MYSQLI_ASSOC);
    $employeesResult->free();
    $result['employees_total'] = count($employees);
    if (!$employees) {
      throw new InvalidArgumentException('No active employees were found.');
    }

    $overlapStmt = $conn->prepare("SELECT start_date, end_date FROM holiday_requests
        WHERE employee_id=? AND status IN ('pending','approved')
          AND start_date <= ? AND end_date >= ? FOR UPDATE");
    $insertStmt = $conn->prepare("INSERT INTO holiday_requests
        (employee_id, request_type, status, start_date, end_date, note, admin_note,
         requested_by, reviewed_by, reviewed_at, employee_seen_at)
        VALUES (?, 'holiday', 'approved', ?, ?, ?, ?, ?, ?, NOW(), NULL)");
    if (!$overlapStmt || !$insertStmt) {
      throw new RuntimeException('Could not prepare company leave statements.');
    }

    foreach ($employees as $employee) {
      $employeeId = (int) $employee['id'];
      $overlapStmt->bind_param('iss', $employeeId, $end, $start);
      if (!$overlapStmt->execute()) {
        throw new RuntimeException('Could not check existing requests.');
      }
      $overlapResult = $overlapStmt->get_result();
      $existing = $overlapResult->fetch_all(MYSQLI_ASSOC);
      $overlapResult->free();
      $ranges = [];
      $skippedDates = [];
      $range = null;
      foreach ($dates as $date) {
        $occupied = false;
        foreach ($existing as $request) {
          if ($request['start_date'] <= $date && $request['end_date'] >= $date) {
            $occupied = true;
            break;
          }
        }
        if ($occupied) {
          $skippedDates[] = $date;
          if ($range !== null) {
            $ranges[] = $range;
            $range = null;
          }
        } else {
          if ($range === null) {
            $range = [$date, $date];
          }
          $range[1] = $date;
          $result['days_added']++;
        }
      }
      if ($range !== null) {
        $ranges[] = $range;
      }
      if ($skippedDates) {
        $result['days_skipped'] += count($skippedDates);
        $result['skipped'][] = [
          'employee' => trim($employee['lastname'] . ' ' . $employee['firstname']),
          'dates' => $skippedDates,
        ];
      }
      foreach ($ranges as [$rangeStart, $rangeEnd]) {
        $insertStmt->bind_param('issssii', $employeeId, $rangeStart, $rangeEnd, $note, $adminNote, $adminId, $adminId);
        if (!$insertStmt->execute() || $insertStmt->affected_rows !== 1) {
          throw new RuntimeException('Could not insert company leave.');
        }
      }
      if ($ranges) {
        $result['employees_added']++;
      }
    }
    if (!$conn->commit()) {
      throw new RuntimeException('Could not commit company leave.');
    }
    return $result;
  } catch (Throwable $error) {
    $conn->rollback();
    throw $error;
  } finally {
    if ($overlapStmt) {
      $overlapStmt->close();
    }
    if ($insertStmt) {
      $insertStmt->close();
    }
  }
}
