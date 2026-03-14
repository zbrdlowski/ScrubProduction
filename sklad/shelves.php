<?php
include('db.php');

// Query to get the current stock and shelf locations
$stmt = $pdo->prepare("
    SELECT * FROM shelves;
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
            <th>id</th>
            <th>Location</th>
            <th>Description</th> 
        </tr>
    </thead>
    <tbody>
        <?php
        if (count($stock_data) > 0) {
            foreach ($stock_data as $row) {
                echo "<tr>
                    <td>{$row['id']}</td>
                    <td>{$row['location']}</td>
                    <td>{$row['description']}</td>
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
