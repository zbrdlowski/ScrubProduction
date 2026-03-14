<?php
include '../includes/conn.php';
session_start();

if (isset($_POST['add'])) {

    $employee  = $_POST['employee'] ?? '';
    $date      = $_POST['date'] ?? '';
    $time_in   = $_POST['time_in'] ?? '';
    $time_out  = $_POST['time_out'] ?? '';
    $movement  = $_POST['movement'] ?? '';
    $redirect  = $_POST['redirect'] ?? '';

    if ($employee === '' || $date === '' || $movement === '') {
        $_SESSION['error'] = 'Missing required fields (employee/date/movement).';
        header('location:' . $redirect);
        exit;
    }

    // Normalize times only if provided
    if ($time_in !== '')  $time_in  = date('H:i:s', strtotime($time_in));
    if ($time_out !== '') $time_out = date('H:i:s', strtotime($time_out));

    // IMPORTANT: employee from modal is employees.id (like in dovolenka_add.php)
    $sql = "SELECT * FROM employees WHERE id = '$employee'";
    $query = $conn->query($sql);

    if ($query->num_rows < 1) {
        $_SESSION['error'] = 'Employee not found';
        header('location:' . $redirect);
        exit;
    }

    $row = $query->fetch_assoc();
    $emp = $row['id'];

    // Optional: prevent duplicate same day (uncomment if you want)
    /*
    $chk = $conn->query("SELECT id FROM $attdn_table WHERE employee_id='$emp' AND date='$date'");
    if ($chk && $chk->num_rows > 0) {
        $_SESSION['error'] = 'Employee attendance for the day exists';
        header('location:' . $redirect);
        exit;
    }
    */

    // schedule status calc (if you want to keep it)
    $logstatus = 1;
    if (!empty($row['schedule_id']) && $time_in !== '') {
        $sched = $row['schedule_id'];
        $squery = $conn->query("SELECT * FROM schedules WHERE id = '$sched'");
        if ($squery && $squery->num_rows > 0) {
            $scherow = $squery->fetch_assoc();
            $logstatus = ($time_in > $scherow['time_in']) ? 0 : 1;
        }
    }

    $sql = "INSERT INTO $attdn_table (employee_id, date, time_in, time_out, status, movement, online_status)
            VALUES ('$emp', '$date', '$time_in', '$time_out', '$logstatus', '$movement', '1')";

    if ($conn->query($sql)) {
        $_SESSION['success'] = 'Attendance added successfully';
        $id = $conn->insert_id;

        // Compute num_hr only if both times exist
        if ($time_in !== '' && $time_out !== '') {
            $sql = "SELECT * FROM employees LEFT JOIN schedules ON schedules.id=employees.schedule_id
                    WHERE employees.id = '$emp'";
            $q2 = $conn->query($sql);
            $srow = $q2 ? $q2->fetch_assoc() : null;

            if ($srow) {
                if (!empty($srow['time_in']) && $srow['time_in'] > $time_in) $time_in = $srow['time_in'];
                if (!empty($srow['time_out']) && $srow['time_out'] < $time_out) $time_out = $srow['time_out'];
            }

            $dt_in  = new DateTime($time_in);
            $dt_out = new DateTime($time_out);
            $interval = $dt_in->diff($dt_out);

            $hrs  = (int)$interval->format('%h');
            $mins = (int)$interval->format('%i');
            $int  = $hrs + ($mins / 60);

            if ($int > 4) $int -= 1; // lunch rule

            $conn->query("UPDATE $attdn_table SET num_hr = '$int' WHERE id = '$id'");
        }

    } else {
        $_SESSION['error'] = $conn->error;
    }

    header('location:' . $redirect);
    exit;
}

$_SESSION['error'] = 'Fill up add form first';
header('location:' . ($_POST['redirect'] ?? '../index.php'));
exit;