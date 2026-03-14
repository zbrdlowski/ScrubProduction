<?php
session_start();
require_once('../includes/conn.php');

$data = json_decode(file_get_contents("php://input"), true);
$order_id = $_POST['order_number'] ?? $data['order_number'] ?? null;
$shelf_location = $data['shelf_location'] ?? $_POST['shelf_location'] ?? null;
$items = $data['items'] ?? $_POST['items'] ?? [];

$operator = $_SESSION['name'] ?? 'unknown';
$timestamp = date('Y-m-d H:i:s');

if (!$order_id) {
    die("Error: order_number is missing.");
}

$order_ref = $order_id;
$intake_ref = $order_ref . ' Intake';

foreach ($items as $entry) {
  $barcode = $entry['barcode'];
  $quantity = (int)$entry['quantity'];

  if ($quantity <= 0) continue;

  /* 🔍 Get item details */
  $stmt = $pdo->prepare("SELECT id, name FROM items WHERE barcode = :barcode");
  $stmt->execute(['barcode' => $barcode]);
  $item = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$item) continue;

  /* 📍 Get shelf */
  $stmt = $pdo->prepare("SELECT id FROM shelves WHERE location = :location");
  $stmt->execute(['location' => $shelf_location]);
  $shelf = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$shelf) continue;

  /* 🏭 Get supplier from plastics_orders */
  $stmt = $pdo->prepare("SELECT main_supplier 
    FROM plastics_orders 
    WHERE order_number = :order_id AND barcode = :barcode
    LIMIT 1
  ");
  $stmt->execute([
    'order_id' => $order_id,
    'barcode' => $barcode
  ]);
  $supplierRow = $stmt->fetch(PDO::FETCH_ASSOC);
  $supplier = $supplierRow ? $supplierRow['main_supplier'] : null;

  /* 📦 INVENTORY MOVEMENT */
  $stmt = $pdo->prepare("INSERT INTO inventory_movements
    (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, operator, timestamp)
    VALUES
    (:order_id, :item_id, :item_name, :shelf_id, :shelf_name, 'IN', :quantity, :operator, :timestamp)
  ");
  $stmt->execute([
    'order_id' => $intake_ref, // Keep original order number
    'item_id' => $item['id'],
    'item_name' => $barcode,
    'shelf_id' => $shelf['id'],
    'shelf_name' => $shelf_location,
    'quantity' => $quantity,
    'operator' => $operator,
    'timestamp' => $timestamp
  ]);

  /* 📊 STOCK LEVELS */
  $stmt = $pdo->prepare("INSERT INTO stock_levels
    (item_id, item_code, shelf_id, shelf_name, quantity)
    VALUES
    (:item_id, :item_code, :shelf_id, :shelf_name, :quantity)
    ON DUPLICATE KEY UPDATE quantity = quantity + :quantity
  ");
  $stmt->execute([
    'item_id' => $item['id'],
    'item_code' => $barcode,
    'shelf_id' => $shelf['id'],
    'shelf_name' => $shelf_location,
    'quantity' => $quantity
  ]);

  /*
  //  ITEMS TOTAL 
  $stmt = $pdo->prepare("UPDATE items SET quantity = quantity + :quantity WHERE barcode = :barcode
  ");
  $stmt->execute([
    'quantity' => $quantity,
    'barcode' => $barcode
  ]);
 
  */

  #  ITEMS TOTAL (recalculate from stock_levels using barcode) 
  $stmt = $pdo->prepare("UPDATE items i SET i.quantity = (SELECT COALESCE(SUM(sl.quantity), 0) FROM stock_levels sl WHERE sl.item_code = i.barcode)
  WHERE i.barcode = :barcode");
  $stmt->execute(['barcode' => $barcode]);
   

  /* 🏷️ LABEL QUEUE (NEW) */
  $stmt = $pdo->prepare("INSERT INTO intake_label_queue
    (intake_ref, barcode, item_name, quantity, shelf_location, supplier)
    VALUES
    (:intake_ref, :barcode, :item_name, :quantity, :shelf_location, :supplier)
  ");
  $stmt->execute([
    'intake_ref' => $intake_ref, // This is fine to include "Intake"
    'barcode' => $barcode,
    'item_name' => $barcode,
    'quantity' => $quantity,
    'shelf_location' => $shelf_location,
    'supplier' => $supplier
  ]);

  /* 🧾 UPDATE ORDER STATUS */
  $stmt = $pdo->prepare("SELECT quantity_to_order FROM plastics_orders
    WHERE order_number = :order_id AND barcode = :barcode
  ");
  $stmt->execute([
    'order_id' => $order_id,
    'barcode' => $barcode
  ]);
  $order = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($order) {
    $orderedQty = (int)$order['quantity_to_order'];

    if ($quantity >= $orderedQty) {
      $stmt = $pdo->prepare("UPDATE plastics_orders
        SET status = 'received'
        WHERE order_number = :order_id AND barcode = :barcode
      ");
    } else {
      $stmt = $pdo->prepare("UPDATE plastics_orders
        SET quantity_to_order = :remaining, status = 'sent'
        WHERE order_number = :order_id AND barcode = :barcode
      ");
      $stmt->execute([
        'remaining' => $orderedQty - $quantity,
        'order_id' => $order_id,
        'barcode' => $barcode
      ]);
      continue;
    }

    $stmt->execute([
      'order_id' => $order_id,
      'barcode' => $barcode
    ]);
  }
}

echo "Received " . count($items) . " items and queued labels.";
?>