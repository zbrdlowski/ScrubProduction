<?php
       if(isset($_SESSION['error'])){
          echo "
            <div class='alert alert-danger alert-dismissible'>
              <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
              <h4><i class='icon fa fa-warning'></i> Nie je to dobré!</h4>
              ".$_SESSION['error']."
            </div>
          ";
          unset($_SESSION['error']);
        }
        if(isset($_SESSION['success'])){
          echo "
            <div class='alert alert-success alert-dismissible'>
              <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
              <h4><i class='icon fa fa-check'></i> Podarilo sa!</h4>
              ".$_SESSION['success']."
            </div>
          ";
          unset($_SESSION['success']);
        }

// Query to get the current stock and shelf locations
$stmt = $pdo->prepare("
    SELECT 
    items.name AS item_name,
    items.barcode AS barcode,
    items.color AS color,
    items.description AS part,
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


<h2>Warehouse Shelves / PN Stock Overview</h2>

<table width="100%" id="example1" class="table table-bordered table-striped">
    <thead>
        <tr>
            <th>Shelf Location</th>
            <th>Item Code</th>
            <th>Item Name</th>
            <th>Part</th>
            <th>Color</th>              
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
                    <td>{$row['part']}</td>
                    <td>{$row['color']}</td>                    
                    <td align='center'>{$row['quantity']}</td>
                    <td align='center'>{$row['total_quantity']}</td>
                </tr>";
            }
        } else {
            echo "<tr><td colspan='3'>No data available</td></tr>";
        }
        ?>
    </tbody>
</table>

