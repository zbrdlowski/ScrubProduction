<?php
require 'db.php'; // or however you connect
require_once __DIR__ . '/plastics_access.php';

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
