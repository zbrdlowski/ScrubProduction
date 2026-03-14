<?php
include 'includes/conn.php';
$id = $_POST['id'];
$description = $conn->real_escape_string($_POST['description']);
$sql = "UPDATE position SET description = '$description' WHERE id = $id";
$conn->query($sql);
?>
