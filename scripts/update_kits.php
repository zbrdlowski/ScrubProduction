<?php
include ('../includes/conn.php');

$id = $_POST['id'];
$field = $_POST['field'];
$value = $_POST['value'];

$allowed = ['barcode', 'missing_barcode', 'order_number']; // ← added here

if (in_array($field, $allowed)) {
  $stmt = $pdo->prepare("UPDATE disassembled_kits SET $field = ? WHERE id = ?");
  $stmt->execute([$value, $id]);
}
?>