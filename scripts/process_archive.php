<?php
session_start();
include('../includes/conn.php');

if (empty($_POST['period'])) {
    $_SESSION['error'] = "No archive period selected!";
    header('Location: ../index.php?page=cleanup');
    exit;
}

$archive_period = $_POST['period'];
$timestamp_cutoff = date('Y-m-d H:i:s', strtotime("-$archive_period"));

try {
    $pdo->beginTransaction();

    // Move to archive
    $stmt = $pdo->prepare("INSERT INTO archive_inventory_movements
        (order_id, item_name, shelf_name, quantity, movement_type, operator, timestamp)
        SELECT order_id, item_name, shelf_name, quantity, movement_type, operator, timestamp
        FROM inventory_movements
        WHERE timestamp < :ts
    ");
    $stmt->execute(['ts' => $timestamp_cutoff]);

    // Count how many were archived
    $archived = $stmt->rowCount();

    // Delete from main table
    $stmt = $pdo->prepare("DELETE FROM inventory_movements
        WHERE timestamp < :ts
    ");
    $stmt->execute(['ts' => $timestamp_cutoff]);

    $deleted = $stmt->rowCount();

    $pdo->commit();

    $_SESSION['success'] = "Archiving completed successfully! ($archived moved, $deleted deleted)";
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = "Error during archiving: " . $e->getMessage();
}

header('Location: ../index.php?page=cleanup');
?>