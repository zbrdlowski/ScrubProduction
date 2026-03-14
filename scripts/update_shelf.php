<?php
require 'db.php'; // or however you connect

$id = $_POST['id'];
$location = $_POST['location'];
$capacity = $_POST['capacity'];
$category = $_POST['category'];
$description = $_POST['description'];

$stmt = $pdo->prepare("UPDATE shelves SET capacity = ?, category = ?, description = ? WHERE id = ?");
$stmt->execute([$capacity, $category, $description, $id]);

echo "success";
header('location: ../index.php?page=shelves');
?>
