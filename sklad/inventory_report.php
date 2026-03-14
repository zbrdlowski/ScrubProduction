<?php
include('db.php');

// Query to get current inventory status
$stmt = $pdo->prepare("SELECT items.barcode, shelves.location, stock_levels.quantity FROM stock_levels
                       JOIN items ON stock_levels.item_id = items.id
                       JOIN shelves ON stock_levels.shelf_id = shelves.id");
$stmt->execute();

$inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h1>Inventory Report</h1>";
echo "<table border='1'>";
echo "<tr><th>Item Name</th><th>Shelf Location</th><th>Quantity</th></tr>";
foreach ($inventory as $row) {
    echo "<tr><td>" . $row['barcode'] . "</td><td>" . $row['location'] . "</td><td>" . $row['quantity'] . "</td></tr>";
}
echo "</table>";
?>
