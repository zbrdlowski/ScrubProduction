<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || empty($_SESSION['name'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'session_expired',
        'message' => 'Session expired. Please log in again.'
    ]);
    exit;
}

require_once('../includes/conn.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
    exit;
}

$barcode = trim($_POST['barcode'] ?? '');
$order_id = trim($_POST['order_id'] ?? '');
$shelf_location = trim($_POST['shelf_location'] ?? '');
$quantity = max(1, intval($_POST['quantity'] ?? 1));
$timestamp = date('Y-m-d H:i:s');
$operator = $_SESSION['name'];

if ($barcode === '' || $order_id === '' || $shelf_location === '' || $quantity <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid input. Order, barcode, shelf and quantity are required.'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Lock item row
    $stmt = $pdo->prepare("SELECT id, name, quantity AS total_quantity FROM items WHERE barcode = :barcode FOR UPDATE");
    $stmt->execute(['barcode' => $barcode]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new Exception("Item not found: {$barcode}");
    }

    // Get shelf
    $stmt = $pdo->prepare("SELECT id FROM shelves WHERE location = :location");
    $stmt->execute(['location' => $shelf_location]);
    $shelf = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shelf) {
        throw new Exception("Shelf location not found: {$shelf_location}");
    }

    // Decrement stock_levels only when enough stock exists on the selected shelf
    $stmt = $pdo->prepare("UPDATE stock_levels
        SET quantity = quantity - :qty
        WHERE item_id = :item_id
          AND shelf_id = :shelf_id
          AND quantity >= :qty
    ");
    $stmt->execute([
        'qty' => $quantity,
        'item_id' => $item['id'],
        'shelf_id' => $shelf['id']
    ]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Not enough stock in the specified shelf.");
    }

    // Insert movement record
    $stmt = $pdo->prepare("INSERT INTO inventory_movements
        (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, operator, timestamp)
        VALUES
        (:order_id, :item_id, :item_name, :shelf_id, :shelf_name, 'OUT', :quantity, :operator, :timestamp)
    ");
    $stmt->execute([
        'order_id' => $order_id,
        'item_id' => $item['id'],
        'item_name' => $barcode,
        'shelf_id' => $shelf['id'],
        'shelf_name' => $shelf_location,
        'operator' => $operator,
        'quantity' => $quantity,
        'timestamp' => $timestamp
    ]);

    // Recalculate total quantity from stock_levels to avoid drift
    $stmt = $pdo->prepare("UPDATE items
        SET quantity = (
            SELECT COALESCE(SUM(quantity), 0)
            FROM stock_levels
            WHERE item_id = :item_id
        )
        WHERE id = :item_id
    ");
    $stmt->execute(['item_id' => $item['id']]);

    $pdo->commit();

    echo json_encode([
        'status' => 'ok',
        'message' => "Položka {$barcode} úspešne odscanovaná z pozície {$shelf_location}."
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
    exit;
}
?>