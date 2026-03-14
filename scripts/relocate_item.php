<?php
session_start();
require_once('../includes/conn.php');

$item_id = $_POST['item_id'] ?? null;
$barcode = $_POST['barcode'] ?? '';
$new_location = $_POST['new_location'] ?? '';
$selected = $_POST['selected'] ?? [];
$qty = $_POST['qty'] ?? [];
$operator = $_SESSION['name'];
$timestamp = date('Y-m-d H:i:s');

if (!$item_id || !$new_location || empty($selected)) {
    $_SESSION['error'] = "Missing required data.";
    header('Location: ../index.php?page=relocate_item');
    exit;
}

// Get new shelf ID or create it
$stmt = $pdo->prepare("SELECT id FROM shelves WHERE location = :location");
$stmt->execute(['location' => $new_location]);
$shelf = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shelf) {
    // Create new shelf
    $stmt = $pdo->prepare("INSERT INTO shelves (location) VALUES (:location)");
    $stmt->execute(['location' => $new_location]);
    $shelf_id = $pdo->lastInsertId();
} else {
    $shelf_id = $shelf['id'];
}

// Process each selected stock record
foreach ($selected as $stock_id) {
    $move_qty = (int)($qty[$stock_id] ?? 0);
    if ($move_qty <= 0) continue;

    // Get current stock record
    $stmt = $pdo->prepare("SELECT shelf_id, shelf_name, quantity FROM stock_levels WHERE id = ?");
    $stmt->execute([$stock_id]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stock || $move_qty > $stock['quantity']) continue;

  // 1) Log OUT movement
$stmt = $pdo->prepare("INSERT INTO inventory_movements 
    (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, operator, timestamp)
    VALUES 
    ('RELOCATE', :item_id, :item_name, :from_id, :from_name, 'OUT', :qty, :operator, :ts)
");
$stmt->execute([
    'item_id' => $item_id,
    'item_name' => $barcode,
    'from_id' => $stock['shelf_id'],
    'from_name' => $stock['shelf_name'],
    'qty' => $move_qty,
    'operator' => $operator,
    'ts' => $timestamp
]);

// 2) Log IN movement
$stmt = $pdo->prepare("INSERT INTO inventory_movements 
    (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, operator, timestamp)
    VALUES 
    ('RELOCATE', :item_id, :item_name, :to_id, :to_name, 'IN', :qty, :operator, :ts)
");
$stmt->execute([
    'item_id' => $item_id,
    'item_name' => $barcode,
    'to_id' => $shelf_id,
    'to_name' => $new_location,
    'qty' => $move_qty,
    'operator' => $operator,
    'ts' => $timestamp
]);


    // Deduct from old shelf
    if ($move_qty == $stock['quantity']) {
        $stmt = $pdo->prepare("DELETE FROM stock_levels WHERE id = ?");
        $stmt->execute([$stock_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE stock_levels SET quantity = quantity - :qty WHERE id = :id");
        $stmt->execute(['qty' => $move_qty, 'id' => $stock_id]);
    }

    // Add to new shelf
    $stmt = $pdo->prepare("INSERT INTO stock_levels (item_id, item_code, shelf_id, shelf_name, quantity)
        VALUES (:item_id, :item_code, :shelf_id, :shelf_name, :qty)
        ON DUPLICATE KEY UPDATE quantity = quantity + :qty");
    $stmt->execute([
        'item_id' => $item_id,
        'item_code' => $barcode,
        'shelf_id' => $shelf_id,
        'shelf_name' => $new_location,
        'qty' => $move_qty
    ]);
}

$_SESSION['success'] = "Relocation completed for barcode $barcode.";
header('Location: ../index.php?page=relocate_item');
exit;