<?php
session_start();

require_once('../includes/conn.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit;
}

$barcode = isset($_POST['barcode']) ? trim($_POST['barcode']) : '';
$order_id = isset($_POST['order_id']) ? trim($_POST['order_id']) : null;
$shelf_location = isset($_POST['shelf_location']) ? trim($_POST['shelf_location']) : '';
$quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 0;
$timestamp = date('Y-m-d H:i:s');
$operator = isset($_SESSION['name']) ? $_SESSION['name'] : 'unknown';

// Basic validation
if ($barcode === '' || $shelf_location === '' || $quantity <= 0) {
    $_SESSION['error'] = 'Invalid input (barcode, shelf or quantity).';
    header('Location: ../index.php?page=scan_form_out');
    exit;
}

try {
    // Get item details (id and name if available)
    $stmt = $pdo->prepare("SELECT id, name, quantity AS total_quantity FROM items WHERE barcode = :barcode");
    $stmt->execute(['barcode' => $barcode]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        $_SESSION['error'] = "Item not found!";
        header('Location: ../index.php?page=scan_form_out');
        exit;
    }

    // Get shelf ID
    $stmt = $pdo->prepare("SELECT id FROM shelves WHERE location = :location");
    $stmt->execute(['location' => $shelf_location]);
    $shelf = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shelf) {
        $_SESSION['error'] = "Shelf location not found!";
        header('Location: ../index.php?page=scan_form_out');
        exit;
    }

    // Start transaction to make operation atomic
    $pdo->beginTransaction();

    // Attempt to decrement stock_levels only if there's enough on that shelf
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

    // If no rows affected, there wasn't enough stock on that shelf
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        $_SESSION['error'] = "Not enough stock in the specified shelf!";
        header('Location: ../index.php?page=scan_form_out');
        exit;
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

    // Update total quantity in items table (you can also enforce >= :qty here if desired)
    $stmt = $pdo->prepare("UPDATE items SET quantity = quantity - :qty WHERE id = :item_id");
    $stmt->execute([
        'qty' => $quantity,
        'item_id' => $item['id']
    ]);

    $pdo->commit();

    $_SESSION['success'] = 'Položka ' . $barcode . ' úspešne odscanovaná z pozície ' . $shelf_location . ' !';
    $_SESSION['saved_order'] = $order_id;
    header('Location: ../index.php?page=scan_form_out');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log error in real app; don't expose internal error to user
    error_log('Scan OUT error: ' . $e->getMessage());
    $_SESSION['error'] = 'Unexpected error during scanning. Contact administrator.';
    header('Location: ../index.php?page=scan_form_out');
    exit;
}
?>