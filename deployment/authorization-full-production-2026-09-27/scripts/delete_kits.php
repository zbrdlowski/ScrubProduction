<?php
include ('../includes/conn.php');
require_once __DIR__ . '/plastics_access.php';
$id = $_POST['id'];
$pdo->prepare("DELETE FROM disassembled_kits WHERE id = ?")->execute([$id]);
?>
