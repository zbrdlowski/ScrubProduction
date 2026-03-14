<?php
 session_start();
include('db.php');

// First, get a list of all current stock (based on the remaining movements)
$stmt = $pdo->prepare("SELECT item_id, shelf_id, SUM(CASE WHEN movement_type = 'IN' THEN 1 ELSE -1 END) AS stock_level
                       FROM inventory_movements
                       GROUP BY item_id, shelf_id");
$stmt->execute();

$stock_levels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Step 2: Update stock_levels table
foreach ($stock_levels as $row) {
    $stmt = $pdo->prepare("INSERT INTO stock_levels (item_id, shelf_id, quantity)
                           VALUES (:item_id, :shelf_id, :quantity)
                           ON DUPLICATE KEY UPDATE quantity = :quantity");
    $stmt->execute([
        'item_id' => $row['item_id'],
        'shelf_id' => $row['shelf_id'],
        'quantity' => $row['stock_level']
    ]);
}

$_SESSION['success'] = 'Employee added successfully';
header('location: ../index.php?page=display_stock');
?>
