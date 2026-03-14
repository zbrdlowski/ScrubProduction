<?php
include('../includes/conn.php');

$selectedIds = $_POST['selected_ids'] ?? [];

if (!empty($selectedIds)) {
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $stmt = $pdo->prepare("UPDATE plastics_orders SET status = 'sent' WHERE id IN ($placeholders)");
    $stmt->execute($selectedIds);
    header("Location: ../index.php?page=plastics_orders_sent&updated=1");
    exit;
} else {
    header("Location: ../index.php?page=plastics_orders_sent&updated=0");
    exit;
}
?>