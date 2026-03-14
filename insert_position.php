<?php
include 'includes/conn.php';
$description = $conn->real_escape_string($_POST['description']);
$sql = "INSERT INTO position (description) VALUES ('$description')";
$conn->query($sql);
echo json_encode(['id' => $conn->insert_id]);
?>
