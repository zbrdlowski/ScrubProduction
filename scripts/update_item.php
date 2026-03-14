<?php
session_start();
include('../includes/conn.php');

$id = $_POST['id'] ?? null;
if (!$id) {
  $_SESSION['error'] = "Missing item ID.";
  header('location: ../index.php?page=items');
  exit;
}

$fields = [
  'brand','barcode','scrubcode','name','description','color',
  'optimum','moq','main_supplier','baseline',
  'ufo_pn','ufo_barcode','rt_pn','rt_barcode',
  'ps_pn','ps_barcode','ac_pn','ac_barcode',
  'other_pn','other_barcode'
];

$data = [];
$updates = [];
foreach ($fields as $field) {
  $data[$field] = $_POST[$field] ?? null;
  $updates[] = "$field = :$field";
}
$data['id'] = $id;

$sql = "UPDATE items SET ".implode(', ', $updates)." WHERE id = :id";
$stmt = $pdo->prepare($sql);

try {
  $stmt->execute($data);
  $_SESSION['success'] = "Item updated successfully.";
} catch (Exception $e) {
  $_SESSION['error'] = "Update failed: " . $e->getMessage();
}
header('location: ../index.php?page=items');
?>