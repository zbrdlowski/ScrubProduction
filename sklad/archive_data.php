<?php
include('db.php');

// Set the time period you want to archive (e.g., data older than 1 month)
$archive_period = '1 MONTH';
$timestamp_cutoff = date('Y-m-d H:i:s', strtotime("-$archive_period"));

// Start a transaction for data integrity
$pdo->beginTransaction();

// Step 1: Move data to archive
$stmt = $pdo->prepare("INSERT INTO archive_inventory_movements (item_id, order_id, item_name, shelf_id, shelf_name, quantity, movement_type, timestamp)
                       SELECT item_id, order_id, item_name, shelf_id, shelf_name, movement_type, quantity, timestamp
                       FROM inventory_movements
                       WHERE timestamp < :timestamp_cutoff");
$stmt->execute(['timestamp_cutoff' => $timestamp_cutoff]);

// Step 2: Delete archived data from the main table
$stmt = $pdo->prepare("DELETE FROM inventory_movements WHERE timestamp < :timestamp_cutoff");
$stmt->execute(['timestamp_cutoff' => $timestamp_cutoff]);

// Step 3: Optionally, clear stock levels for archived items (if you don't need historical stock tracking)
$stmt = $pdo->prepare("DELETE FROM stock_levels WHERE item_id IN (SELECT item_id FROM archive_inventory_movements WHERE timestamp < :timestamp_cutoff)");
$stmt->execute(['timestamp_cutoff' => $timestamp_cutoff]);

// Commit the transaction
$pdo->commit();

echo "Archiving completed successfully!";
?>
