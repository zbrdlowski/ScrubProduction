<?php
include('db.php');

// Query to get the current stock and shelf locations
$stmt = $pdo->prepare("
    SELECT 
    items.name AS item_name,
    items.barcode AS barcode,
    shelves.location AS shelf_location,
    stock_levels.quantity,
    SUM(stock_levels.quantity) OVER (PARTITION BY items.id) AS total_quantity
FROM stock_levels
JOIN items ON stock_levels.item_id = items.id
JOIN shelves ON stock_levels.shelf_id = shelves.id
ORDER BY shelves.location, items.name;

");
$stmt->execute();

$stock_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Stock Overview</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        table, th, td {
            border: 1px solid #ddd;
        }
        th, td {
            padding: 10px;
            text-align: left;
        }
        th {
            background-color: #f4f4f4;
        }
    </style>
</head>
<body>

<h2>Warehouse Stock Overview</h2>

<table>
    <thead>
        <tr>
            <th>Shelf Location</th>
            <th>Item Code</th>
            <th>Item Name</th>            
            <th>Shelf Quantity</th>
            <th>Stock Quantity</th>
        </tr>
    </thead>
    <tbody>
        <?php
        if (count($stock_data) > 0) {
            foreach ($stock_data as $row) {
                echo "<tr>
                    <td>{$row['shelf_location']}</td>
                    <td>{$row['barcode']}</td>
                    <td>{$row['item_name']}</td>                    
                    <td>{$row['quantity']}</td>
                    <td>{$row['total_quantity']}</td>
                </tr>";
            }
        } else {
            echo "<tr><td colspan='3'>No data available</td></tr>";
        }
        ?>
    </tbody>
</table>

</body>
</html>
