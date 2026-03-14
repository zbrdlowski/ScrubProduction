<?php
include('db.php');

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
            // Check if there's any stock in the shelf
            $stmt = $pdo->prepare("SELECT quantity FROM stock_levels WHERE item_id = :item_id AND shelf_id = :shelf_id");
            $stmt->execute(['item_id' => $item['id'], 'shelf_id' => $shelf['id']]);
            $stock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stock && $stock['quantity'] > 0) {
                // Record the movement (OUT)
                $stmt = $pdo->prepare("INSERT INTO inventory_movements (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, timestamp) VALUES (:order_id, :item_id, :item_name, :shelf_id, :shelf_name, 'OUT', $quantity, :timestamp)");
            $stmt->execute([
                'order_id' => $order_id,
                'item_id' => $item['id'],
                'item_name' => $barcode,
                'shelf_id' => $shelf['id'],
                'shelf_name' => $shelf_location,
                'timestamp' => $timestamp
            ]);
                
                // Update the quantity on the shelf
                $stmt = $pdo->prepare("UPDATE stock_levels SET quantity = quantity - $quantity WHERE item_id = :item_id AND shelf_id = :shelf_id");
                $stmt->execute([
                    'item_id' => $item['id'],
                    'shelf_id' => $shelf['id']
                ]);

                // Update the quantity on the shelf
                $stmt = $pdo->prepare("UPDATE items SET quantity = quantity - $quantity WHERE barcode = :barcode");
                $stmt->execute([
                    'barcode' => $barcode
                ]);
                
                echo "Item scanned out successfully!";
            } else {
                echo "Not enough stock in the specified shelf!";
            }
        } else {
            echo "Shelf location not found!";
        }
    } else {
        echo "Item not found!";
    }
}
?>
