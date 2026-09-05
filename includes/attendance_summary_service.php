<?php

if (!function_exists('attendanceSummaryFormatSeconds')) {
    function attendanceSummaryFormatSeconds($seconds)
    {
        $seconds = (int)round($seconds);
        $sign = $seconds < 0 ? '-' : '';
        $seconds = abs($seconds);

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return $sign . $hours . ':' . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT)
            . ':' . str_pad((string)$remainingSeconds, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('attendanceSummaryAvailableYears')) {
    function attendanceSummaryAvailableYears(mysqli $conn)
    {
        $years = [];
        $result = $conn->query('SHOW TABLES');

        if ($result) {
            while ($row = $result->fetch_row()) {
                if (preg_match('/^attdn_(\d{4})$/', (string)$row[0], $match)) {
                    $years[] = (int)$match[1];
                }
            }
        }

        rsort($years, SORT_NUMERIC);
        return array_values(array_unique($years));
    }
}

if (!function_exists('attendanceSummaryCalculate')) {
    function attendanceSummaryCalculate(mysqli $conn, $employeeId, $year, $month)
    {
        $employeeId = (int)$employeeId;
        $year = (int)$year;
        $month = (int)$month;

        if ($employeeId <= 0 || $year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('Invalid attendance period.');
        }

        $scheduleSeconds = 0;
        $scheduleStmt = $conn->prepare(
            'SELECT TIME_TO_SEC(TIMEDIFF(s.time_out, s.time_in)) AS gross_seconds
             FROM employees e
             LEFT JOIN schedules s ON s.id = e.schedule_id
             WHERE e.id = ?
             LIMIT 1'
        );
        $scheduleStmt->bind_param('i', $employeeId);
        $scheduleStmt->execute();
        $scheduleRow = $scheduleStmt->get_result()->fetch_assoc();
        $scheduleStmt->close();

        if (!$scheduleRow) {
            throw new RuntimeException('Employee not found.');
        }

        if ($scheduleRow['gross_seconds'] !== null) {
            $scheduleSeconds = max(0, (int)$scheduleRow['gross_seconds'] - 1800);
        }

        $table = 'attdn_' . $year;
        $tableExists = false;
        $tables = $conn->query('SHOW TABLES');
        if ($tables) {
            while ($tableRow = $tables->fetch_row()) {
                if ((string)$tableRow[0] === $table) {
                    $tableExists = true;
                    break;
                }
            }
        }

        $dailyMovements = [];
        $recordCount = 0;
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        if ($tableExists) {
            $movementSql = "SELECT date, movement,
                                   SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS seconds_total,
                                   COUNT(1) AS row_count
                            FROM `{$table}`
                            WHERE employee_id = ?
                              AND date BETWEEN ? AND ?
                              AND movement IN (1, 4, 5, 6)
                            GROUP BY date, movement";
            $movementStmt = $conn->prepare($movementSql);
            $movementStmt->bind_param('iss', $employeeId, $startDate, $endDate);
            $movementStmt->execute();
            $movementResult = $movementStmt->get_result();

            while ($movementRow = $movementResult->fetch_assoc()) {
                $dateKey = (string)$movementRow['date'];
                $movement = (int)$movementRow['movement'];
                $dailyMovements[$dateKey][$movement] = max(0, (int)$movementRow['seconds_total']);
                $recordCount += (int)$movementRow['row_count'];
            }
            $movementStmt->close();
        }

        $fixedHolidays = ['01-01', '06-01', '01-05', '05-07', '29-08', '15-09', '01-11', '24-12', '25-12', '26-12'];
        $easterSunday = easter_date($year);
        $goodFriday = date('d-m', $easterSunday - 172800);
        $easterMonday = date('d-m', $easterSunday + 86400);

        $workedSeconds = 0;
        $vacationSeconds = 0;
        $doctorSeconds = 0;
        $prescribedSeconds = 0;
        $weekendSeconds = 0;
        $holidaySeconds = 0;
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $dayMonth = sprintf('%02d-%02d', $day, $month);
            $isWeekend = (int)date('N', strtotime($date)) > 5;
            $isFixedHoliday = in_array($dayMonth, $fixedHolidays, true);
            $isEasterHoliday = ($dayMonth === $goodFriday || $dayMonth === $easterMonday);

            $work = (int)($dailyMovements[$date][1] ?? 0);
            $lunch = (int)($dailyMovements[$date][4] ?? 0);
            $vacation = (int)($dailyMovements[$date][5] ?? 0);
            $doctor = (int)($dailyMovements[$date][6] ?? 0);

            if ($lunch < 1800 && $work > 21600) {
                $work -= (1800 - $lunch);
            }

            if ($scheduleSeconds > 0) {
                $vacation = min($vacation, $scheduleSeconds);
                $doctor = min($doctor, $scheduleSeconds);
            }

            $workedSeconds += max(0, $work);
            $vacationSeconds += max(0, $vacation);
            $doctorSeconds += max(0, $doctor);

            if ($isWeekend) {
                $weekendSeconds += max(0, $work);
            }
            if ($isFixedHoliday) {
                $holidaySeconds += max(0, $work);
            }
            if (!$isWeekend && !$isFixedHoliday && !$isEasterHoliday) {
                $prescribedSeconds += $scheduleSeconds;
            }
        }

        $overtimeSeconds = ($workedSeconds + $vacationSeconds + $doctorSeconds) - $prescribedSeconds;
        $vacationDays = $vacationSeconds / 28800;
        $vacationDaysLabel = (floor($vacationDays) == $vacationDays)
            ? (string)(int)$vacationDays
            : rtrim(rtrim(number_format($vacationDays, 2, '.', ''), '0'), '.');

        return [
            'employee_id' => $employeeId,
            'year' => $year,
            'month' => $month,
            'table_exists' => $tableExists,
            'has_data' => $recordCount > 0,
            'record_count' => $recordCount,
            'overtime_seconds' => $overtimeSeconds,
            'doctor_seconds' => $doctorSeconds,
            'holiday_seconds' => $holidaySeconds,
            'vacation_seconds' => $vacationSeconds,
            'vacation_days' => $vacationDaysLabel,
            'weekend_seconds' => $weekendSeconds,
            'overtime' => attendanceSummaryFormatSeconds($overtimeSeconds),
            'doctor' => attendanceSummaryFormatSeconds($doctorSeconds),
            'holidays' => attendanceSummaryFormatSeconds($holidaySeconds),
            'vacation' => attendanceSummaryFormatSeconds($vacationSeconds),
            'weekends' => attendanceSummaryFormatSeconds($weekendSeconds)
        ];
    }
}

