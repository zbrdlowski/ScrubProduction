<?php
include 'includes/conn.php';
$time_in = $conn->real_escape_string($_POST['time_in']);
$time_out = $conn->real_escape_string($_POST['time_out']);
$sql = "INSERT INTO schedules (time_in, time_out) VALUES ('$time_in', '$time_out')";
$conn->query($sql);
echo json_encode(['id' => $conn->insert_id]);
?>
