<?php
 session_start();
include('../includes/conn.php');

/* Set the time period you want to archive (e.g., data older than 1 month)
Valid formats to use

'1 DAY'
'3 DAYS'
'1 WEEK'
'4 WEEKS'
'2 MONTHS'
'6 MONTHS'
'1 YEAR'
'3 YEARS'

Exact lengths
'24 HOURS'
'48 HOURS'
'72 HOURS'
'7 DAYS'

With fractional values
'1.5 WEEKS'
'2.25 MONTHS'

Combined values
'1 MONTH 10 DAYS'
'2 WEEKS 3 DAYS'
'1 YEAR 6 MONTHS 2 WEEKS'

Specific calendar
'first day of last month'
'last day of previous year'
'Monday last week'
'last Sunday'

*/
$archive_period = '2 WEEKS';
$timestamp_cutoff = date('Y-m-d H:i:s', strtotime("-$archive_period"));

// Start a transaction for data integrity
$pdo->beginTransaction();

// Step 1: Move data to archive
$stmt = $pdo->prepare("INSERT INTO archive_inventory_movements (item_id, order_id, item_name, shelf_id, shelf_name, quantity, movement_type, operator, timestamp)
                       SELECT item_id, order_id, item_name, shelf_id, shelf_name, quantity, movement_type, operator, timestamp
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

$_SESSION['success'] = 'Archiving completed successfully!';
header('location: ../index.php?page=display_stock');
?>
