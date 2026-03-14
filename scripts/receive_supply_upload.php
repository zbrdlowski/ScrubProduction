<?php
/**
 * receive_supply_upload.php
 * - Accepts CSV / XLSX upload
 * - Preview mode or full processing
 * - Receives supplier orders
 * - Creates inventory movements (INTAKE)
 * - Updates stock + plastics_orders
 * - Queues intake labels for printing
 */

session_start();
require_once('../includes/conn.php');

$operator = $_SESSION['name'] ?? 'system';

/* ===================== Helpers ===================== */

function json_err($msg) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

function sanitize_shelf($s) {
    return preg_replace('/[^A-Za-z0-9_\- ]/', '', trim($s));
}

/* ===================== XLSX support ===================== */

$hasPhpSpreadsheet = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $hasPhpSpreadsheet = class_exists('\PhpOffice\PhpSpreadsheet\IOFactory');
}

/* ===================== Mode ===================== */

$previewOnly = isset($_POST['preview']) && $_POST['preview'] == '1';

/* ===================== File upload ===================== */

if (!isset($_FILES['file'])) {
    $previewOnly ? json_err('No file uploaded') : exit('No file uploaded');
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    $previewOnly ? json_err('Upload error') : exit('Upload error');
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$tmpPath = $file['tmp_name'];

/* ===================== Parse file ===================== */

$rows = [];

if ($ext === 'csv') {
    if (($h = fopen($tmpPath, 'r')) !== false) {
        $r = 0;
        while (($data = fgetcsv($h)) !== false && $r < 1000) {
            $r++;
            if (count($data) < 2) continue;

            $barcode = trim($data[0]);
            $qtyRaw  = trim($data[1]);

            if ($r === 1 && !is_numeric(str_replace(',', '.', $qtyRaw))) continue;

            $qty = (int)round(floatval(str_replace(',', '.', $qtyRaw)));
            if ($barcode && $qty > 0) {
                $rows[] = ['barcode' => $barcode, 'quantity' => $qty];
            }
        }
        fclose($h);
    }
} elseif (in_array($ext, ['xls','xlsx'])) {
    if (!$hasPhpSpreadsheet) {
        $previewOnly ? json_err('XLSX support missing') : exit('XLSX support missing');
    }

    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpPath);
    $reader->setReadDataOnly(true);
    $sheet = $reader->load($tmpPath)->getActiveSheet();

    $max = min(1000, $sheet->getHighestDataRow());
    for ($r = 1; $r <= $max; $r++) {
        $barcode = trim((string)$sheet->getCellByColumnAndRow(1, $r)->getValue());
        $qtyRaw  = (string)$sheet->getCellByColumnAndRow(2, $r)->getValue();

        if ($r === 1 && !is_numeric(str_replace(',', '.', $qtyRaw))) continue;

        $qty = (int)round(floatval(str_replace(',', '.', $qtyRaw)));
        if ($barcode && $qty > 0) {
            $rows[] = ['barcode' => $barcode, 'quantity' => $qty];
        }
    }
} else {
    $previewOnly ? json_err('Unsupported file type') : exit('Unsupported file type');
}

/* ===================== Preview ===================== */

if ($previewOnly) {
    header('Content-Type: application/json');
    echo json_encode(['rows' => array_slice($rows, 0, 200)]);
    exit;
}

/* ===================== Required POST ===================== */

$order_number = trim($_POST['order_number'] ?? '');
if ($order_number === '') exit('Missing order number');

$shelf_location = sanitize_shelf($_POST['shelf_location'] ?? 'A010');

$intake_ref = $order_number . ' Intake';
$timestamp  = date('Y-m-d H:i:s');

/* ===================== Processing ===================== */

try {
    $pdo->beginTransaction();

    $processed = 0;

    foreach ($rows as $entry) {

        $barcode  = $entry['barcode'];
        $quantity = (int)$entry['quantity'];
        if ($quantity <= 0) continue;

        /* Item */
        $stmt = $pdo->prepare("SELECT id, name FROM items WHERE barcode = :b");
        $stmt->execute(['b' => $barcode]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) continue;

        /* Shelf */
        $stmt = $pdo->prepare("SELECT id FROM shelves WHERE location = :l");
        $stmt->execute(['l' => $shelf_location]);
        $shelf = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$shelf) continue;

        /* Supplier */
        $stmt = $pdo->prepare("SELECT main_supplier, quantity_to_order
            FROM plastics_orders
            WHERE order_number = :o AND barcode = :b
            LIMIT 1
        ");
        $stmt->execute([
            'o' => $order_number,
            'b' => $barcode
        ]);
        $orderRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $supplier = $orderRow['main_supplier'] ?? null;
        $ordered  = (int)($orderRow['quantity_to_order'] ?? 0);

        /* Inventory movement (INTAKE) */
        $stmt = $pdo->prepare("INSERT INTO inventory_movements
            (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, operator, timestamp)
            VALUES
            (:o, :i, :n, :s, :sl, 'IN', :q, :op, :t)
        ");
        $stmt->execute([
            'o'  => $intake_ref,
            'i'  => $item['id'],
            'n'  => $barcode,
            's'  => $shelf['id'],
            'sl' => $shelf_location,
            'q'  => $quantity,
            'op' => $operator,
            't'  => $timestamp
        ]);

        /* Stock levels */
        $stmt = $pdo->prepare("INSERT INTO stock_levels
            (item_id, item_code, shelf_id, shelf_name, quantity)
            VALUES (:i,:c,:s,:n,:q)
            ON DUPLICATE KEY UPDATE quantity = quantity + :q
        ");
        $stmt->execute([
            'i' => $item['id'],
            'c' => $barcode,
            's' => $shelf['id'],
            'n' => $shelf_location,
            'q' => $quantity
        ]);
        /*

        // Item total 
        $stmt = $pdo->prepare("UPDATE items SET quantity = quantity + :q WHERE barcode = :b");
        $stmt->execute(['q' => $quantity, 'b' => $barcode]);
        */
          #  ITEMS TOTAL (recalculate from stock_levels using barcode) 
        $stmt = $pdo->prepare("UPDATE items i SET i.quantity = (SELECT COALESCE(SUM(sl.quantity), 0) FROM stock_levels sl WHERE sl.item_code = i.barcode)
        WHERE i.barcode = :barcode");
        $stmt->execute(['barcode' => $barcode]);

        /* Update plastics_orders */
        if ($orderRow) {
            if ($quantity >= $ordered) {
                $stmt = $pdo->prepare("UPDATE plastics_orders
                    SET status = 'received'
                    WHERE order_number = :o AND barcode = :b
                ");
                $stmt->execute(['o' => $order_number, 'b' => $barcode]);
            } else {
                $stmt = $pdo->prepare("UPDATE plastics_orders
                    SET quantity_to_order = :r, status = 'sent'
                    WHERE order_number = :o AND barcode = :b
                ");
                $stmt->execute([
                    'r' => $ordered - $quantity,
                    'o' => $order_number,
                    'b' => $barcode
                ]);
            }
        }

        /* Queue label */
        $stmt = $pdo->prepare("INSERT INTO intake_label_queue
            (intake_ref, barcode, item_name, quantity, shelf_location, supplier)
            VALUES (:i,:b,:n,:q,:s,:p)
        ");
        $stmt->execute([
            'i' => $intake_ref,
            'b' => $barcode,
            'n' => $item['name'],
            'q' => $quantity,
            's' => $shelf_location,
            'p' => $supplier
        ]);

        $processed++;
    }

    $pdo->commit();
    echo "Processed {$processed} items successfully.";

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "Processing failed: " . $e->getMessage();
}
?>