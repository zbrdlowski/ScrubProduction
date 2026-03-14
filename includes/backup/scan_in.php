<?php
session_start();
require_once('../includes/conn.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $barcode = trim($_POST['barcode'] ?? '');
    $order_id = trim($_POST['order_id'] ?? '');
    $shelf_location = trim($_POST['shelf_location'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 1);
    $timestamp = date('Y-m-d H:i:s');

    if (!$barcode || !$order_id || !$shelf_location) {
        $_SESSION['error'] = "Missing required fields.";
        header("Location: ../index.php?page=scan_form");
        exit;
    }

    // Find item by barcode
    $stmt = $pdo->prepare("SELECT id, quantity FROM items WHERE barcode = :barcode");
    $stmt->execute(['barcode' => $barcode]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        $_SESSION['error'] = "Item not found: $barcode";
        header("Location: ../index.php?page=scan_form");
        exit;
    }

    // Find shelf
    $stmt = $pdo->prepare("SELECT id FROM shelves WHERE location = :loc");
    $stmt->execute(['loc' => $shelf_location]);
    $shelf = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shelf) {
        $_SESSION['error'] = "Shelf not found: $shelf_location";
        header("Location: ../index.php?page=scan_form");
        exit;
    }

    // INSERT movement
    $stmt = $pdo->prepare("
        INSERT INTO inventory_movements
        (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, timestamp)
        VALUES
        (:order_id, :item_id, :item_name, :shelf_id, :shelf_name, 'IN', :qty, :ts)
    ");

    $stmt->execute([
        'order_id' => $order_id,
        'item_id' => $item['id'],
        'item_name' => $barcode,
        'shelf_id' => $shelf['id'],
        'shelf_name' => $shelf_location,
        'qty' => $quantity,
        'ts' => $timestamp
    ]);

    // Update shelf quantity
    $stmt = $pdo->prepare("
        INSERT INTO stock_levels (item_id, item_code, shelf_id, shelf_name, quantity)
        VALUES (:item_id, :code, :shelf_id, :shelf_name, :qty)
        ON DUPLICATE KEY UPDATE quantity = quantity + :qty
    ");

    $stmt->execute([
        'item_id' => $item['id'],
        'code' => $barcode,
        'shelf_id' => $shelf['id'],
        'shelf_name' => $shelf_location,
        'qty' => $quantity
    ]);

    // Update total QTY in items table
    $stmt = $pdo->prepare("UPDATE items SET quantity = quantity + :qty WHERE id = :id");
    $stmt->execute([
        'qty' => $quantity,
        'id' => $item['id']
    ]);

    $_SESSION['success'] = "Stock updated OK.";
    header("Location: ../index.php?page=scan_form");
    exit;
}

header("Location: ../index.php?page=scan_form");
exit;

?>
