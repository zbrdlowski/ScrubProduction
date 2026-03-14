<?php
 session_start();

require_once('../includes/conn.php');
/*
echo "<h2>🛠️ Debug: POST Data Received in " . basename(__FILE__) . "</h2>";
echo "<pre>";
print_r($_POST);
echo "</pre>";
exit;
*/
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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $barcode = $_POST['barcode'];
    $order_id = $_POST['order_id'];
    $shelf_location = $_POST['shelf_location'];
    $quantity = $_POST['quantity'];
    $timestamp = date('Y-m-d H:i:s');
    
    // Get item details
    $stmt = $pdo->prepare("SELECT id, quantity FROM items WHERE barcode = :barcode");
    $stmt->execute(['barcode' => $barcode]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($item) {
        // Get shelf ID
        $stmt = $pdo->prepare("SELECT id FROM shelves WHERE location = :location");
        $stmt->execute(['location' => $shelf_location]);
        $shelf = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($shelf) {
            // Record the movement (IN)
            $stmt = $pdo->prepare("INSERT INTO inventory_movements (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, timestamp) VALUES (:order_id, :item_id, :item_name, :shelf_id, :shelf_name, 'IN', $quantity, :timestamp)");
            $stmt->execute([
                'order_id' => $order_id,
                'item_id' => $item['id'],
                'item_name' => $barcode,
                'shelf_id' => $shelf['id'],
                'shelf_name' => $shelf_location,
                'timestamp' => $timestamp
            ]);
            
            // Update the quantity on the shelf
            $stmt = $pdo->prepare("INSERT INTO stock_levels (item_id, item_code, shelf_id, shelf_name, quantity) VALUES (:item_id, :item_name, :shelf_id, :shelf_name, $quantity) ON DUPLICATE KEY UPDATE quantity = quantity + $quantity");
            $stmt->execute([
                'item_id' => $item['id'],
                'item_name' => $barcode,
                'shelf_id' => $shelf['id'],
                'shelf_name' => $shelf_location
            ]);

            // Update the quantity in items
            $stmt = $pdo->prepare("UPDATE items SET quantity = quantity + $quantity WHERE barcode = :barcode");
            $stmt->execute(['barcode' => $barcode]);

            $_SESSION['success'] = "Stock Updated successfully!";
            //echo "Stock Updated successfully!";
            header('location: ../index.php?page=scan_form');
        } else {
            $_SESSION['error'] = "Shelf location not found!";
            //echo "Shelf location not found!";
        }   header('location: ../index.php?page=scan_form');
    } else {
        $_SESSION['error'] = "Item not found!";
        //echo "Item not found!";
        header('location: ../index.php?page=scan_form');
    }
}
?>
