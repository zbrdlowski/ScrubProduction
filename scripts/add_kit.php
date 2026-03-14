<?php
include('../includes/conn.php');

$user = $_POST['user'] ?? 'admin';
$barcode = $_POST['barcode'] ?? '';
$missing = $_POST['missing_barcode'] ?? '';
$qty = (int)($_POST['quantity'] ?? 1);
$order = $_POST['order_number'] ?? '';

$stmt = $pdo->prepare("INSERT INTO disassembled_kits (user, barcode, missing_barcode, quantity, order_number)
                       VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$user, $barcode, $missing, $qty, $order]);
/*
if ($barcode && $missing && $qty > 0) {
  $stmt = $pdo->prepare("INSERT INTO disassembled_kits (user, barcode, missing_barcode, quantity) VALUES (?, ?, ?, ?)");
  $stmt->execute([$user, $barcode, $missing, $qty]);
}
*/
?>