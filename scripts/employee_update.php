<?php
session_start();
include '../includes/conn.php';

$return_to = $_POST['return_to'] ?? '';

if ($return_to === '') {
    $return_to = '../index.php?page=employee';
} elseif (
    strpos($return_to, 'http://') !== 0 &&
    strpos($return_to, 'https://') !== 0 &&
    strpos($return_to, '/') !== 0 &&
    strpos($return_to, '../') !== 0
) {
    $return_to = '../' . ltrim($return_to, '/');
}

if (!isset($_SESSION['permission'])) {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . $return_to);
    exit;
}

$editor_permission = (int)$_SESSION['permission'];

if ($editor_permission < 300) {
    $_SESSION['error'] = 'You do not have permission to edit employee';
    header("Location: " . $return_to);
    exit;
}

if (!isset($_POST['empid'])) {
    $_SESSION['error'] = 'Najprv vyber, koho treba upraviť';
    header("Location: " . $return_to);
    exit;
}

$empid = (int)$_POST['empid'];

$stmt = $conn->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $empid);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current) {
    $_SESSION['error'] = 'Employee not found';
    header("Location: " . $return_to);
    exit;
}

$firstname = trim($_POST['firstname'] ?? $current['firstname']);
$lastname = trim($_POST['lastname'] ?? $current['lastname']);
$address = trim($_POST['address'] ?? $current['address']);
$birthdate = trim($_POST['birthdate'] ?? $current['birthdate']);
$contact = trim($_POST['contact_info'] ?? $current['contact_info']);
$gender = trim($_POST['gender'] ?? $current['gender']);
$position = (int)($_POST['position_id'] ?? $current['position_id']);
$schedule = (int)($_POST['schedule_id'] ?? $current['schedule_id']);
$created_on = trim($_POST['created_on'] ?? $current['created_on']);
$personal = trim($_POST['personal'] ?? $current['personal']);
$worker_type = trim($_POST['worker_type'] ?? ($current['worker_type'] ?? 'employee'));
$password = trim($_POST['password'] ?? '');
$permission = (int)($_POST['permission'] ?? $current['permission']);

$active = isset($_POST['active']) && $_POST['active'] === 'Active' ? 'Active' : 'Inactive';
$grid = isset($_POST['grid']) ? 1 : 0;
$attendance_enabled = isset($_POST['attendance_enabled']) ? 1 : 0;
$personal_orders = isset($_POST['personal_orders']) ? 1 : 0;
$chat = isset($_POST['chat']) && $_POST['chat'] === 'yes' ? 'yes' : 'no';

if (!in_array($gender, ['Male', 'Female'], true)) {
    $gender = $current['gender'];
}

if (!in_array($personal, ['X', 'A', 'B', 'C'], true)) {
    $personal = $current['personal'];
}

if (!in_array($worker_type, ['employee', 'contractor'], true)) {
    $worker_type = $current['worker_type'] ?? 'employee';
}

if ($editor_permission == 300 && $permission > 300) {
    $permission = 300;
}
if ($editor_permission == 500 && $permission > 500) {
    $permission = 500;
}
if ($editor_permission >= 900 && $permission > 900) {
    $permission = 900;
}

if ($editor_permission == 300) {
    $firstname = $current['firstname'];
    $lastname = $current['lastname'];
    $address = $current['address'];
    $birthdate = $current['birthdate'];
    $contact = $current['contact_info'];
    $gender = $current['gender'];
    $schedule = (int)$current['schedule_id'];
    $active = $current['active'];
    $worker_type = $current['worker_type'] ?? 'employee';
    $grid = (int)$current['grid'];
    $attendance_enabled = (int)($current['attendance_enabled'] ?? 1);
    $personal_orders = (int)$current['personal_orders'];
    $personal = $current['personal'];
    $created_on = $current['created_on'];
    $chat = $current['chat'];
    $password = '';
}

if ($password !== '' && $editor_permission >= 500) {
    $stmt = $conn->prepare("
        UPDATE employees
        SET firstname = ?,
            lastname = ?,
            address = ?,
            birthdate = ?,
            contact_info = ?,
            gender = ?,
            position_id = ?,
            schedule_id = ?,
            created_on = ?,
            chat = ?,
            active = ?,
            worker_type = ?,
            grid = ?,
            attendance_enabled = ?,
            personal_orders = ?,
            personal = ?,
            permission = ?,
            password = PASSWORD(?)
        WHERE id = ?
    ");

    $stmt->bind_param(
        "ssssssiissssiiisisi",
        $firstname,
        $lastname,
        $address,
        $birthdate,
        $contact,
        $gender,
        $position,
        $schedule,
        $created_on,
        $chat,
        $active,
        $worker_type,
        $grid,
        $attendance_enabled,
        $personal_orders,
        $personal,
        $permission,
        $password,
        $empid
    );
} else {
    $stmt = $conn->prepare("UPDATE employees
        SET firstname = ?,
            lastname = ?,
            address = ?,
            birthdate = ?,
            contact_info = ?,
            gender = ?,
            position_id = ?,
            schedule_id = ?,
            created_on = ?,
            chat = ?,
            active = ?,
            worker_type = ?,
            grid = ?,
            attendance_enabled = ?,
            personal_orders = ?,
            personal = ?,
            permission = ?
        WHERE id = ?
    ");

    $stmt->bind_param(
        "ssssssiissssiiisii",
        $firstname,
        $lastname,
        $address,
        $birthdate,
        $contact,
        $gender,
        $position,
        $schedule,
        $created_on,
        $chat,
        $active,
        $worker_type,
        $grid,
        $attendance_enabled,
        $personal_orders,
        $personal,
        $permission,
        $empid
    );
}

if ($stmt->execute()) {
    $_SESSION['success'] = 'Zmeny úspešne uložené';
} else {
    $_SESSION['error'] = $stmt->error;
}

$stmt->close();

header("Location: " . $return_to);
exit;
?>
