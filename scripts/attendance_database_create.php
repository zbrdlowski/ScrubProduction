<?php
session_start();
include '../includes/conn.php';

function attendanceDatabaseCreateRedirect($redirect)
{
  $fallback = '../index.php?page=attendance_databases';
  $target = trim((string)$redirect);

  if ($target === '') {
    $target = $fallback;
  }

  if (preg_match('/[\r\n]/', $target)) {
    $target = $fallback;
  }

  header('Location: ' . $target);
  exit;
}

$redirect = $_POST['redirect'] ?? '../index.php?page=attendance_databases';

if (intval($_SESSION['permission'] ?? 0) <= 300) {
  $_SESSION['error'] = 'Nemáš oprávnenie na vytvorenie databázy dochádzky.';
  attendanceDatabaseCreateRedirect($redirect);
}

if (!isset($_POST['add'])) {
  $_SESSION['error'] = 'Najprv vyplň formulár na vytvorenie databázy.';
  attendanceDatabaseCreateRedirect($redirect);
}

$year = filter_var($_POST['attdn_year'] ?? null, FILTER_VALIDATE_INT, [
  'options' => [
    'min_range' => 2000,
    'max_range' => 2100
  ]
]);

if ($year === false || $year === null) {
  $_SESSION['error'] = 'Rok databázy musí byť číslo v rozsahu 2000 - 2100.';
  attendanceDatabaseCreateRedirect($redirect);
}

$tableName = 'attdn_' . $year;
$safeTableName = $conn->real_escape_string($tableName);
$existingResult = $conn->query("SHOW TABLES LIKE '{$safeTableName}'");

if ($existingResult && $existingResult->num_rows > 0) {
  $_SESSION['error'] = 'Databáza dochádzky pre rok ' . $year . ' už existuje.';
  attendanceDatabaseCreateRedirect($redirect);
}

$sql = "
  CREATE TABLE `{$tableName}` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `employee_id` varchar(11) NOT NULL,
    `date` date NOT NULL,
    `time_in` time NOT NULL,
    `status` int(1) NOT NULL DEFAULT 1,
    `time_out` time NOT NULL,
    `num_hr` double DEFAULT 0,
    `movement` tinyint(1) NOT NULL DEFAULT 2,
    `online_status` tinyint(4) DEFAULT 0,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
";

if ($conn->query($sql)) {
  $_SESSION['success'] = 'Databáza dochádzky pre rok ' . $year . ' bola úspešne vytvorená.';
} else {
  $_SESSION['error'] = 'Databázu dochádzky sa nepodarilo vytvoriť: ' . $conn->error;
}

attendanceDatabaseCreateRedirect($redirect);
?>
