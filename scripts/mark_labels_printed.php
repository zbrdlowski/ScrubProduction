<?php
require_once '../includes/conn.php';

$ids = $_POST['ids'] ?? [];

if (!$ids) exit;

$placeholders = implode(',', array_fill(0, count($ids), '?'));

// Delete selected records
$stmt = $pdo->prepare("DELETE FROM intake_label_queue
  WHERE id IN ($placeholders)
");

$stmt->execute($ids);
?>