<?php
include('../includes/conn.php');

$updates = json_decode($_POST['updates'], true);

foreach ($updates as $row) {
  $stmt = $pdo->prepare("UPDATE disassembled_kits SET position = ? WHERE id = ?");
  $stmt->execute([$row['position'], $row['id']]);
}
?>