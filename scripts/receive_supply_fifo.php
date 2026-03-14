<?php
// receive_supply_fifo.php

/* IMMEDIATE DEBUG - write proof of execution */
$debugFile = __DIR__ . '/fifo_debug.txt';
file_put_contents($debugFile, "[" . date('Y-m-d H:i:s') . "] Script STARTED\n", FILE_APPEND);

session_start();
file_put_contents($debugFile, "[" . date('Y-m-d H:i:s') . "] Session started\n", FILE_APPEND);

require_once('../includes/conn.php');
file_put_contents($debugFile, "[" . date('Y-m-d H:i:s') . "] DB connected\n", FILE_APPEND);

/* ================= LOGGING ================= */

$logDir = __DIR__ . '/../logs/receiving/fifo';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}

/* Fallback if directory not writable (archive attribute issue on Windows) */
if (!is_writable($logDir)) {
    $logDir = sys_get_temp_dir() . '/darkscrub_logs';
    @mkdir($logDir, 0777, true);
}

file_put_contents($debugFile, "[" . date('Y-m-d H:i:s') . "] Log dir: {$logDir}, writable: " . (is_writable($logDir) ? 'YES' : 'NO') . "\n", FILE_APPEND);

$logFile = $logDir . '/fifo_' . date('Ymd_His') . '.log';

function logMsg(string $level, string $msg): void {
    global $logFile;
    if (!$logFile) return;
    error_log("[" . date('Y-m-d H:i:s') . "] [$level] $msg\n", 3, $logFile);
}

/* Write initial log entry */
logMsg('START', 'FIFO Script initialized');
file_put_contents($debugFile, "[" . date('Y-m-d H:i:s') . "] Log file: {$logFile}\n", FILE_APPEND);

/* Register error handler AFTER logMsg is defined */
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        logMsg('FATAL', $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});

/* Initialize log file */
@file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [START] FIFO Receiving Script Started\n", FILE_APPEND | LOCK_EX);

/* ================= INPUT ================= */

logMsg('INFO', "=== FIFO RECEIVING START " . date('Y-m-d H:i:s') . " ===");
logMsg('INFO', "POST data received: supplier=" . ($_POST['supplier'] ?? 'MISSING'));

$operator = $_SESSION['name'] ?? 'system';

if (empty($_POST['supplier'])) {
    logMsg('ERROR', 'Missing supplier');
    http_response_code(400);
    echo "Missing supplier.";
    exit;
}

$supplier = trim($_POST['supplier']);
$shelf_location = preg_replace('/[^A-Za-z0-9_\- ]/', '', $_POST['shelf_location'] ?? 'A010');
$timestamp = date('Y-m-d H:i:s');

logMsg('INFO', "Operator: {$operator}");
logMsg('INFO', "Supplier: {$supplier}");
logMsg('INFO', "Shelf: {$shelf_location}");

/* ================= FILE PARSING ================= */

logMsg('INFO', 'Parsing uploaded file: ' . ($_FILES['file']['name'] ?? 'unknown'));

require_once 'parse_uploaded_file.php';

if (!isset($rows)) {
    logMsg('ERROR', 'parse_uploaded_file.php did not set $rows');
}

logMsg('INFO', 'File parsed, rows=' . (is_array($rows) ? count($rows) : 0));

if (empty($rows)) {
    logMsg('ERROR', 'No valid rows found');
    http_response_code(400);
    echo "No valid rows found.";
    exit;
}

logMsg('INFO', 'Parsed rows count: ' . count($rows));

/* ================= FIFO PROCESS ================= */

try {
    $pdo->beginTransaction();
    logMsg('INFO', 'DB transaction BEGIN');

    // Resolve shelf
    $stmt = $pdo->prepare("SELECT id FROM shelves WHERE location = :loc");
    $stmt->execute(['loc' => $shelf_location]);
    $shelf = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shelf) {
        throw new Exception("Shelf not found: {$shelf_location}");
    }

    foreach ($rows as $idx => $row) {

        logMsg('INFO', 'Row #' . ($idx + 1) . ': ' . json_encode($row));

        $barcode = trim($row['barcode'] ?? '');
        $remainingQty = (int)($row['quantity'] ?? 0);

        if ($barcode === '' || $remainingQty <= 0) {
            logMsg('WARN', 'Skipped invalid row');
            continue;
        }

        // Resolve item
        $stmt = $pdo->prepare("SELECT id FROM items WHERE barcode = :b");
        $stmt->execute(['b' => $barcode]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            logMsg('ERROR', "Item not found for barcode {$barcode}");
            continue;
        }

        // FIFO orders
        $stmt = $pdo->prepare("SELECT 
                po.id,
                po.order_number,
                po.quantity_to_order,
                po.created_at
            FROM plastics_orders po
            WHERE
                po.main_supplier = :supplier
                AND po.barcode = :barcode
                AND po.status = 'sent'
                AND po.quantity_to_order > 0
            ORDER BY po.created_at ASC
            FOR UPDATE
        ");

        $stmt->execute([
            'supplier' => $supplier,
            'barcode'  => $barcode
        ]);

        $foundOrder = false;
        $allResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        /* DEBUG: Log what we found */
        logMsg('DEBUG', "Query: supplier={$supplier}, barcode={$barcode}");
        logMsg('DEBUG', "Found " . count($allResults) . " orders");
        
        if (count($allResults) === 0) {
            /* Check what exists in database for this combination */
            $debugStmt = $pdo->prepare("SELECT id, order_number, barcode, main_supplier, status, quantity_to_order 
                FROM plastics_orders 
                WHERE main_supplier = :supplier 
                AND barcode = :barcode
                LIMIT 10
            ");
            $debugStmt->execute(['supplier' => $supplier, 'barcode' => $barcode]);
            $debugResults = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
            logMsg('DEBUG', "All orders in DB for this supplier+barcode: " . json_encode($debugResults));
        }

        /* Process results */
        foreach ($allResults as $orderRow) {

            $foundOrder = true;
            if ($remainingQty <= 0) break;

            /* Use quantity_to_order directly - it's already updated after each allocation */
            $openQty  = (int)$orderRow['quantity_to_order'];
            $allocQty = min($openQty, $remainingQty);

            logMsg(
                'INFO',
                "Alloc {$allocQty} pcs | {$barcode} → Order {$orderRow['order_number']}"
            );

            // inventory movement
            $stmtIns = $pdo->prepare("INSERT INTO inventory_movements
                (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, operator, timestamp)
                VALUES
                (:order_id, :item_id, :item_name, :shelf_id, :shelf_name, 'IN', :qty, :operator, :ts)
            ");

            try {
                $stmtIns->execute([
                    'order_id'   => 'FIFO Intake ' . $orderRow['order_number'],
                    'item_id'    => $item['id'],
                    'item_name'  => $barcode,
                    'shelf_id'   => $shelf['id'],
                    'shelf_name' => $shelf_location,
                    'qty'        => $allocQty,
                    'operator'   => $operator,
                    'ts'         => $timestamp
                ]);
                logMsg('INFO', "✓ Inventory movement recorded for {$barcode}");
            } catch (Throwable $e) {
                logMsg('ERROR', "✗ Inventory movement insert failed: " . $e->getMessage());
                throw $e;
            }

            // stock levels
            $stmtSL = $pdo->prepare("INSERT INTO stock_levels
                (item_id, item_code, shelf_id, shelf_name, quantity)
                VALUES
                (:item_id, :code, :shelf_id, :shelf_name, :qty)
                ON DUPLICATE KEY UPDATE quantity = quantity + :qty
            ");

            try {
                $stmtSL->execute([
                    'item_id'    => $item['id'],
                    'code'       => $barcode,
                    'shelf_id'   => $shelf['id'],
                    'shelf_name' => $shelf_location,
                    'qty'        => $allocQty
                ]);
                logMsg('INFO', "✓ Stock levels updated for {$barcode}");
            } catch (Throwable $e) {
                logMsg('ERROR', "✗ Stock levels insert failed: " . $e->getMessage());
                throw $e;
            }

            // recalc item total
            $stmt = $pdo->prepare("UPDATE items i
                SET i.quantity = (
                    SELECT COALESCE(SUM(sl.quantity), 0)
                    FROM stock_levels sl
                    WHERE sl.item_code = i.barcode
                )
                WHERE i.barcode = :barcode
            ");
            
            try {
                $stmt->execute(['barcode' => $barcode]);
                logMsg('INFO', "✓ Item quantity recalculated for {$barcode}");
            } catch (Throwable $e) {
                logMsg('ERROR', "✗ Item quantity update failed: " . $e->getMessage());
                throw $e;
            }

            // update order status
            $newRemainingQty = $orderRow['quantity_to_order'] - $allocQty;
            $newStatus = ($newRemainingQty <= 0) ? 'received' : 'sent';
            
            $stmt = $pdo->prepare("UPDATE plastics_orders SET status = :st, quantity_to_order = :qty WHERE id = :id");
            
            try {
                $stmt->execute([
                    'qty' => $newRemainingQty,
                    'st' => $newStatus,
                    'id' => $orderRow['id']
                ]);
                logMsg('INFO', "✓ Order status updated");
            } catch (Throwable $e) {
                logMsg('ERROR', "✗ Order status update failed: " . $e->getMessage());
                throw $e;
            }

              /* 🏷️ LABEL QUEUE (NEW) */
                $stlb = $pdo->prepare("INSERT INTO intake_label_queue
                    (intake_ref, barcode, item_name, quantity, shelf_location, supplier)
                    VALUES
                    (:intake_ref, :barcode, :item_name, :quantity, :shelf_location, :supplier)
                ");
                $stlb->execute([
                    'intake_ref' => 'FIFO Intake ' . $orderRow['order_number'], // This is fine to include "Intake"
                    'barcode' => $barcode,
                    'item_name' => $barcode,
                    'quantity' => $allocQty,
                    'shelf_location' => $shelf_location,
                    'supplier' => $supplier
                ]);
        
            logMsg(
                'INFO',
                "Order {$orderRow['order_number']} allocated {$allocQty} | remaining_qty: {$newRemainingQty} → {$newStatus}"
            );
   
            $remainingQty -= $allocQty;
        }

        if (!$foundOrder) {
            logMsg('WARN', "No open FIFO orders for barcode {$barcode}");
        }

        if ($remainingQty > 0) {
            logMsg('WARN', "Overdelivery {$remainingQty} pcs for barcode {$barcode}");
            
            /* Add overdelivery to stock_levels and inventory_movements */
            $stmtOD = $pdo->prepare("INSERT INTO stock_levels
                (item_id, item_code, shelf_id, shelf_name, quantity)
                VALUES
                (:item_id, :code, :shelf_id, :shelf_name, :qty)
                ON DUPLICATE KEY UPDATE quantity = quantity + :qty
            ");

            try {
                $stmtOD->execute([
                    'item_id'    => $item['id'],
                    'code'       => $barcode,
                    'shelf_id'   => $shelf['id'],
                    'shelf_name' => $shelf_location,
                    'qty'        => $remainingQty
                ]);
                logMsg('INFO', "✓ Overdelivery {$remainingQty} pcs added to stock_levels");
            } catch (Throwable $e) {
                logMsg('ERROR', "✗ Overdelivery stock_levels insert failed: " . $e->getMessage());
                throw $e;
            }

            /* Record overdelivery in inventory_movements */
            $stmtODIns = $pdo->prepare("INSERT INTO inventory_movements
                (order_id, item_id, item_name, shelf_id, shelf_name, movement_type, quantity, operator, timestamp)
                VALUES
                (:order_id, :item_id, :item_name, :shelf_id, :shelf_name, 'IN', :qty, :operator, :ts)
            ");

            try {
                $stmtODIns->execute([
                    'order_id'   => 'FIFO Intake UNALLOCATED',
                    'item_id'    => $item['id'],
                    'item_name'  => $barcode,
                    'shelf_id'   => $shelf['id'],
                    'shelf_name' => $shelf_location,
                    'qty'        => $remainingQty,
                    'operator'   => $operator,
                    'ts'         => $timestamp
                ]);
                logMsg('INFO', "✓ Overdelivery {$remainingQty} pcs recorded in inventory_movements");
            } catch (Throwable $e) {
                logMsg('ERROR', "✗ Overdelivery inventory_movements insert failed: " . $e->getMessage());
                throw $e;
            }

            /* Recalc item total with overdelivery included */
            $stmt = $pdo->prepare("UPDATE items i
                SET i.quantity = (
                    SELECT COALESCE(SUM(sl.quantity), 0)
                    FROM stock_levels sl
                    WHERE sl.item_code = i.barcode
                )
                WHERE i.barcode = :barcode
            ");
            
            try {
                $stmt->execute(['barcode' => $barcode]);
                logMsg('INFO', "✓ Item quantity recalculated (including overdelivery)");
            } catch (Throwable $e) {
                logMsg('ERROR', "✗ Item quantity update failed: " . $e->getMessage());
                throw $e;
            }
        }
    }

    $pdo->commit();
    logMsg('INFO', 'DB transaction COMMIT');
    logMsg('INFO', 'FIFO RECEIVING FINISHED OK');

    echo "FIFO receiving completed successfully.";

} catch (Throwable $e) {

    $pdo->rollBack();

    logMsg('ERROR', 'DB transaction ROLLBACK');
    logMsg('ERROR', $e->getMessage());

    http_response_code(500);
    echo "FIFO receiving failed. Check log file.";
}
?>
