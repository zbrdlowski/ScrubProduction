<?php
include 'dincludes/conn.php';
$id = $_POST['id'];
$time_in = $_POST['time_in'];
$time_out = $_POST['time_out'];

$sql = "UPDATE schedules SET time_in = '$time_in', time_out = '$time_out' WHERE id = $id";
$conn->query($sql);
?>
