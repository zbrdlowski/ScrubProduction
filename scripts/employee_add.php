<?php
include '../includes/conn.php';
session_start();

if (isset($_POST['add'])) {

    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $position = (int)($_POST['position'] ?? 0);
    $schedule = (int)($_POST['schedule'] ?? 0);

    $active = isset($_POST['active']) ? 'Active' : 'Inactive';
    $worker_type = trim($_POST['worker_type'] ?? 'employee');
    if (!in_array($worker_type, ['employee', 'contractor'], true)) {
        $worker_type = 'employee';
    }

    if ($worker_type === 'contractor' && $schedule <= 0) {
        $scheduleResult = $conn->query("SELECT id FROM schedules ORDER BY id ASC LIMIT 1");
        if ($scheduleResult && ($scheduleRow = $scheduleResult->fetch_assoc())) {
            $schedule = (int)$scheduleRow['id'];
        }
    }
    $grid = isset($_POST['grid']) ? 1 : 0;
    $attendance_enabled = isset($_POST['attendance_enabled']) ? 1 : 0;
    $personal_orders = isset($_POST['personal_orders']) ? 1 : 0;
    $chat = isset($_POST['chat']) ? 'yes' : 'no';

    $personal = trim($_POST['personal'] ?? 'X');
    if (!in_array($personal, ['X', 'A', 'B', 'C'], true)) {
        $personal = 'X';
    }

    $filename = $_FILES['photo']['name'] ?? '';
    $passw = '123Admin456';

    if (!empty($filename)) {
        move_uploaded_file($_FILES['photo']['tmp_name'], '../images/' . $filename);
    }

    if ($firstname === '' || $lastname === '' || $position <= 0 || $schedule <= 0) {
        $_SESSION['error'] = 'Please fill required fields.';
        header('location: ../?page=employee');
        exit;
    }

    $i = 1;
    while ($i == 1) {
        $employee_id = date('Y') . '-' . mt_rand(1, 9999);

        $chk = $conn->query("SELECT id FROM employees WHERE employee_id = '" . $conn->real_escape_string($employee_id) . "'")->num_rows;

        if ($chk <= 0) {
            $i = 0;
        }
    }

    $username = 'user_' . $employee_id;
    $permission = 1;

    $stmt = $conn->prepare("
        INSERT INTO employees (
            employee_id,
            firstname,
            lastname,
            address,
            birthdate,
            contact_info,
            gender,
            position_id,
            schedule_id,
            photo,
            created_on,
            online_status,
            chat,
            active,
            worker_type,
            grid,
            attendance_enabled,
            personal_orders,
            personal,
            username,
            permission,
            password
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, PASSWORD(?)
        )
    ");

    if (!$stmt) {
        $_SESSION['error'] = $conn->error;
        header('location: ../?page=employee');
        exit;
    }

    $stmt->bind_param(
        "sssssssiissssiiissss",
        $employee_id,
        $firstname,
        $lastname,
        $address,
        $birthdate,
        $contact,
        $gender,
        $position,
        $schedule,
        $filename,
        $chat,
        $active,
        $worker_type,
        $grid,
        $attendance_enabled,
        $personal_orders,
        $personal,
        $username,
        $permission,
        $passw
    );

    if ($stmt->execute()) {
        $_SESSION['success'] = 'Employee added successfully';
    } else {
        $_SESSION['error'] = $stmt->error;
    }

    $stmt->close();

} else {
    $_SESSION['error'] = 'Fill up add form first';
}

header('location: ../?page=employee');
exit;
?>
