<?php
include ('../includes/conn.php');
$id = $_POST['id'];
$pdo->prepare("DELETE FROM disassembled_kits WHERE id = ?")->execute([$id]);
?>