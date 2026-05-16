<?php
ob_start();
session_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function json_exit($payload, $code = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');

        json_exit([
            'status' => 'error',
            'message' => 'PHP fatal error on server. Check Synology PHP error log.',
            'detail' => $error['message']
        ]);
    }
});

if (empty($_SESSION['name'])) {
    http_response_code(401);
    json_exit([
    'status' => 'session_expired',
    'message' => 'Session expired. Please log in again.'
], 401);
    exit;
}

require_once('../includes/conn.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_exit([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ], 405);
    exit;
}


$barcode = isset($_POST['barcode']) ? trim($_POST['barcode']) : '';
$order_id = isset($_POST['order_id']) ? trim($_POST['order_id']) : '';
$shelf_location = isset($_POST['shelf_location']) ? trim($_POST['shelf_location']) : '';
$quantity = isset($_POST['quantity']) ? max(1, intval($_POST['quantity'])) : 1;
$scan_type = isset($_POST['scan_type']) ? $_POST['scan_type'] : 'standard';

$timestamp = date('Y-m-d H:i:s');
$operator = $_SESSION['name'];
$receivingShelf = 'A010';

/* ---------------- VALIDACIA ---------------- */

if (!$barcode || !$order_id || !$shelf_location) {
    http_response_code(400);
    json_exit([
        'status' => 'error',
        'message' => 'Missing required fields.'
    ], 400);
    exit;
}

/* ---------------- DB TRANSAKCIA ---------------- */

$pdo->beginTransaction();

try {

    /* -------- ITEM -------- */
    $stmt = $pdo->prepare("SELECT id FROM items WHERE barcode = :barcode FOR UPDATE");
    $stmt->execute(['barcode' => $barcode]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) { // Ak nenašlo item
        throw new Exception("Item not found: $barcode");
    }

    /* -------- REGAL -------- */
    $stmt = $pdo->prepare("SELECT id FROM shelves WHERE location = :loc");
    $stmt->execute(['loc' => $shelf_location]);
    $shelf = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shelf) { // Ak nenašlo regál
        throw new Exception("Shelf not found: $shelf_location");
    }

    /* ---------------- PRAVIDLÁ PRE A010 (Príjmová plocha) ----------------
   - standard: správa sa ako normálny regál (ADD je povolené)
   - from_receiving: A010 sa chová ako zdroj (Len odčítavanie povolené)
    -------------------------------------------------------------------- */

    // ❌ Štandartné IN Nesmie ani len čuchnúť k A010
    /*
    if ($scan_type === 'standard' && $shelf_location === $receivingShelf) {
        throw new Exception("Standard IN is not allowed on receiving shelf (A010).");
    }
    */
    // 🔄 Ak je IN z prijatej objednávky - je to natvrdo vo formularoch
    if ($scan_type === 'from_receiving') {

        // ❌ Nesmie odrátavať z A010
        if ($shelf_location === $receivingShelf) {
            throw new Exception("Cannot move items INTO receiving shelf (A010).");
        }

        // 🔍 Zámok & kontrola stoku na A010
        $stmt = $pdo->prepare("SELECT quantity
            FROM stock_levels
            WHERE item_id = :item_id
              AND shelf_name = :shelf
            FOR UPDATE
        ");
        $stmt->execute([
            'item_id' => $item['id'],
            'shelf' => $receivingShelf
        ]);

        $availableQty = (int) $stmt->fetchColumn();

        if ($availableQty < $quantity) {
            throw new Exception(
                "Not enough stock on receiving shelf (A010). Available: {$availableQty}, requested: {$quantity}."
            );
        }

        // ✅ Odrátanie z A010
        $stmt = $pdo->prepare("UPDATE stock_levels
            SET quantity = quantity - :qty
            WHERE item_id = :item_id
              AND shelf_name = :shelf
        ");
        $stmt->execute([
            'qty' => $quantity,
            'item_id' => $item['id'],
            'shelf' => $receivingShelf
        ]);
    }

    /* ---------------- Pridanie na cielový regál ---------------- */

    $stmt = $pdo->prepare("INSERT INTO stock_levels (item_id, item_code, shelf_id, shelf_name, quantity)
        VALUES (:item_id, :code, :shelf_id, :shelf_name, :qty)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
    ");
    $stmt->execute([
        'item_id' => $item['id'],
        'code' => $barcode,
        'shelf_id' => $shelf['id'],
        'shelf_name' => $shelf_location,
        'qty' => $quantity
    ]);

    /* ---------------- Zápis pohybu ---------------- */

    $stmt = $pdo->prepare("INSERT INTO inventory_movements
        (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, operator, timestamp)
        VALUES
        (:order_id, :item_id, :item_name, :shelf_id, :shelf_name, 'IN', :qty, :operator, :ts)
    ");
    $stmt->execute([
        'order_id' => $order_id,
        'item_id' => $item['id'],
        'item_name' => $barcode,
        'shelf_id' => $shelf['id'],
        'shelf_name' => $shelf_location,
        'qty' => $quantity,
        'operator' => $operator,
        'ts' => $timestamp
    ]);

    /* ---------------- Updatnutie celkovej kvantity v KP_GEN ---------------- */

    $stmt = $pdo->prepare("UPDATE items
        SET quantity = (
            SELECT COALESCE(SUM(quantity), 0)
            FROM stock_levels
            WHERE item_id = :item_id
        )
        WHERE id = :item_id
    ");
    $stmt->execute(['item_id' => $item['id']]);

    /* ---------------- COMMIT ---------------- */

    $pdo->commit();

    json_exit([
        'status' => 'ok',
        'message' => "Item {$barcode} successfully moved to {$shelf_location}."
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    json_exit([
        'status' => 'error',
        'message' => $e->getMessage()
    ], 400);
    exit;
}

?>