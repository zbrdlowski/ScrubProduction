<?php
session_start();

require_once('../includes/conn.php');

$shelf = $_POST['shelf'] ?? '';
$items = $_POST['items'] ?? [];
$timestamp = date('Y-m-d H:i:s');
 $operator = $_SESSION['name'];

if (!$shelf || empty($items)) {
    $_SESSION['error'] = "Shelf or items missing.";
    header('Location: ../index.php?page=reset_location');
    exit;
}

// Get shelf ID
$stmt = $pdo->prepare("SELECT id FROM shelves WHERE location = :location");
$stmt->execute(['location' => $shelf]);
$shelfData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shelfData) {
    $_SESSION['error'] = "Shelf not found.";
    header('Location: ../index.php?page=reset_location');
    exit;
}

$shelf_id = $shelfData['id'];

foreach ($items as $item_id => $qty) {
    $qty = (int)$qty;
    if ($qty <= 0) continue;

    // Get barcode
    $stmt = $pdo->prepare("SELECT barcode FROM items WHERE id = ?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    $barcode = $item['barcode'] ?? 'UNKNOWN';

    // Record movement
    $stmt = $pdo->prepare("INSERT INTO inventory_movements (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, operator, timestamp)
        VALUES ('RESET', :item_id, :item_name, :shelf_id, :shelf_name, 'OUT', :quantity, :operator, :timestamp)");
    $stmt->execute([
        'item_id' => $item_id,
        'item_name' => $barcode,
        'shelf_id' => $shelf_id,
        'shelf_name' => $shelf,
        'quantity' => $qty,
        'operator' => $operator,
        'timestamp' => $timestamp
    ]);

    // vymazat celú lokáciu
    $stmt = $pdo->prepare("DELETE from stock_levels WHERE shelf_id = :shelf_id");
    $stmt->execute(['shelf_id' => $shelf_id]);

       /* ---------------- Updatnutie celkovej kvantity v KP_GEN ---------------- */

    $stmt = $pdo->prepare("UPDATE items
        SET quantity = (
            SELECT COALESCE(SUM(quantity), 0)
            FROM stock_levels
            WHERE item_id = :item_id
        )
        WHERE id = :item_id
    ");
    $stmt->execute(['item_id' => $item_id]);
}

$_SESSION['success'] = "Shelf '$shelf' successfully reset.";
header('Location: ../index.php?page=reset_location');
exit;