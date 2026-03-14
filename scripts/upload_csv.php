<?php
session_start();
include('db.php'); //  db connection
$operator = $_SESSION['name'] ?? 'system';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "File upload failed.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    $filePath = $_FILES['csv_file']['tmp_name'];
    $csvFile = fopen($filePath, 'r');
    if (!$csvFile) {
        $_SESSION['error'] = "Unable to open CSV file.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    $timestamp = date('Y-m-d H:i:s');
    $rowCount = 0;
    $errorCount = 0;

    fgetcsv($csvFile); // Skip header
    $skippedRows = [];
while (($row = fgetcsv($csvFile)) !== false) {
    list($order_id, $barcode, $shelf_location, $quantity, $movement_type) = $row;

    $movement_type = strtoupper(trim($movement_type));
    $quantity = (int)$quantity;

    if (!in_array($movement_type, ['IN', 'OUT'])) {
        $errorCount++;
        $skippedRows[] = [
            'row' => $row,
            'reason' => "Invalid movement type: $movement_type"
        ];
        continue;
    }

    // Get item
    $stmt = $pdo->prepare("SELECT id FROM items WHERE barcode = :barcode");
    $stmt->execute(['barcode' => $barcode]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        $errorCount++;
        $skippedRows[] = [
            'row' => $row,
            'reason' => "Neplatný kód produktu: $barcode"
        ];
        continue;
    }

    // Get shelf
    $stmt = $pdo->prepare("SELECT id FROM shelves WHERE location = :location");
    $stmt->execute(['location' => $shelf_location]);
    $shelf = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shelf) {
        $errorCount++;
        $skippedRows[] = [
            'row' => $row,
            'reason' => "Neexistujúci regál: $shelf_location"
        ];
        continue;
    }


    // Adjust quantity sign for OUT
    $adjustedQty = ($movement_type === 'OUT') ? -$quantity : $quantity;

    // Record movement
    $stmt = $pdo->prepare("INSERT INTO inventory_movements (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, operator, quantity, timestamp) 
        VALUES (:order_id, :item_id, :item_name, :shelf_id, :shelf_name, :movement_type, :operator, :quantity, :timestamp)");
    $stmt->execute([
        'order_id' => $order_id,
        'item_id' => $item['id'],
        'item_name' => $barcode,
        'shelf_id' => $shelf['id'],
        'shelf_name' => $shelf_location,
        'movement_type' => $movement_type,
        'operator' => $operator,
        'quantity' => $quantity,
        'timestamp' => $timestamp
    ]);

    // Update shelf stock
    $stmt = $pdo->prepare("INSERT INTO stock_levels (item_id, item_code, shelf_id, shelf_name, quantity) 
        VALUES (:item_id, :item_name, :shelf_id, :shelf_name, :quantity) 
        ON DUPLICATE KEY UPDATE quantity = quantity + :quantity");
    $stmt->execute([
        'item_id' => $item['id'],
        'item_name' => $barcode,
        'shelf_id' => $shelf['id'],
        'shelf_name' => $shelf_location,
        'quantity' => $adjustedQty
    ]);

    // Update item quantity
    $stmt = $pdo->prepare("UPDATE items SET quantity = quantity + :quantity WHERE barcode = :barcode");
    $stmt->execute([
        'quantity' => $adjustedQty,
        'barcode' => $barcode
    ]);

    $rowCount++;
}

    fclose($csvFile);
    $_SESSION['success'] = "Importovaných $rowCount riadkov. Preskočených $errorCount (Kvôli neexistujúcim kódom, regálom, alebo nedostatku zásob).";

if (!empty($skippedRows)) {
    $_SESSION['skipped_details'] = $skippedRows;
    $logFile = fopen('upload_errors.log', 'a');
    foreach ($skippedRows as $error) {
        $rowData = implode(',', $error['row']);
        fwrite($logFile, "[$timestamp] $rowData | Reason: {$error['reason']}\n");
    }
    fclose($logFile);
}


    header("Location: ../index.php?page=upload_csv");
    exit;
}
?>
