<?php
require 'db.php'; // adjust path if needed

$location = $_POST['location'];
$capacity = $_POST['capacity'] ?: null;
$category = $_POST['category'];
$description = $_POST['description'];

$stmt = $pdo->prepare("INSERT INTO shelves (location, capacity, category, description) VALUES (?, ?, ?, ?)");
$stmt->execute([$location, $capacity, $category, $description]);

echo "success";
header('location: ../index.php?page=shelves');
?>
