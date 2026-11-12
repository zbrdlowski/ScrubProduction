<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['permission'])) {
    http_response_code(403);
    exit('Login required.');
}

// TABLES TO SEARCH
$archiveTables = [
    "archive_inventory_movements_2020",
    "archive_inventory_movements_2021",
    "archive_inventory_movements_2022",
    "archive_inventory_movements_2023",
    "archive_inventory_movements_2024",
    "archive_inventory_movements_2025",
    "archive_inventory_movements",
    "inventory_movements"
];

// Main Supplier is the current value on the item card, including for archived movements.
$selectedSupplier = is_string($_GET['main_supplier'] ?? null) ? $_GET['main_supplier'] : '';
$suppliers = $pdo->query("SELECT DISTINCT main_supplier FROM items
    WHERE main_supplier IS NOT NULL AND TRIM(main_supplier) <> ''
    ORDER BY main_supplier")->fetchAll(PDO::FETCH_COLUMN);
// Preserve bookmarked selections even if the supplier was removed from the item cards.
if ($selectedSupplier !== '' && !in_array($selectedSupplier, $suppliers, true)) {
    $suppliers[] = $selectedSupplier;
}
$exportCsv = ($_GET['export'] ?? '') === 'csv';

// Build filters
$filters = [];
$params = [];

if ($selectedSupplier !== '') {
    $filters[] = "i.main_supplier = :main_supplier";
    $params['main_supplier'] = $selectedSupplier;
}

// DATE FROM
if (!empty($_GET['from'])) {
    $filters[] = "a.timestamp >= :from";
    $params['from'] = $_GET['from'] . " 00:00:00";
}

// DATE TO
if (!empty($_GET['to'])) {
    $filters[] = "a.timestamp <= :to";
    $params['to'] = $_GET['to'] . " 23:59:59";
}

// ORDER
if (!empty($_GET['order_id'])) {
    $filters[] = "a.order_id LIKE :order_id";
    $params['order_id'] = "%" . trim($_GET['order_id']) . "%";
}

// ITEM NAME / BARCODE
if (!empty($_GET['item_name'])) {
    $filters[] = "a.item_name LIKE :item_name";
    $params['item_name'] = "%" . trim($_GET['item_name']) . "%";
}

// SHELF NAME
if (!empty($_GET['shelf_name'])) {
    $filters[] = "a.shelf_name LIKE :shelf_name";
    $params['shelf_name'] = "%" . trim($_GET['shelf_name']) . "%";
}

// --- OPERATOR ---
if (!empty($_GET['operator'])) {
    $filters[] = "a.operator LIKE :operator";
    $params['operator'] = "%" . trim($_GET['operator']) . "%";
}
// MOVEMENTS
if (!empty($_GET['movement_type'])) {
    $filters[] = "a.movement_type LIKE :movement_type";
    $params['movement_type'] = "%" . trim($_GET['movement_type']) . "%";
}

// Build WHERE string
$whereSQL = "";
if (!empty($filters)) {
    $whereSQL = " WHERE " . implode(" AND ", $filters);
}

$hasFilters = $selectedSupplier !== '' ||
    !empty($_GET['from']) ||
    !empty($_GET['to']) ||
    !empty($_GET['order_id']) ||
    !empty($_GET['item_name']) ||
    !empty($_GET['shelf_name']) ||
    !empty($_GET['operator']) ||
    !empty($_GET['movement_type']);

if ($hasFilters) {

    // DISTINCT prevents identical duplicate item cards from repeating the same movement.
    // The source table and movement id keep genuinely separate movements distinct.
    $unionParts = [];
    foreach ($archiveTables as $tbl) {
        $unionParts[] = "SELECT DISTINCT
                '$tbl' AS source_table,
                a.id, 
                a.operator, 
                a.order_id, 
                a.item_name, 
                a.shelf_name, 
                a.quantity, 
                a.movement_type, 
                a.timestamp,
                i.name AS model, 
                i.description, 
                i.color,
                i.main_supplier
            FROM $tbl a
            LEFT JOIN items i ON i.barcode = a.item_name
            $whereSQL";
    }

    // Keep the browser preview bounded; the full CSV uses the same filters without a limit.
    $sql = "SELECT *
        FROM (
            " . implode(" UNION ALL ", $unionParts) . "
        ) AS merged
        ORDER BY timestamp DESC, source_table, id DESC";
    if (!$exportCsv) {
        $sql .= " LIMIT 5001";
    }

    if ($exportCsv && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($exportCsv) {
        // index.php buffers the page shell before including this file.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="historical-movements-' . date('Y-m-d-His') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date', 'Operator', 'Order', 'Barcode', 'Item Name', 'Description',
            'Color', 'Main Supplier', 'Location', 'Qty', 'Type', 'Archive Table'], ';', '"', '');
        $csvText = static function ($value): string {
            $text = (string) ($value ?? '');
            // Treat spreadsheet formula prefixes in stored text as literal text.
            return preg_match('/^[\s]*[=+@-]|^[\t\r\n]/', $text) ? "'" . $text : $text;
        };
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $r['timestamp'], $csvText($r['operator']), $csvText($r['order_id']),
                $csvText($r['item_name']), $csvText($r['model']), $csvText($r['description']),
                $csvText($r['color']), $csvText($r['main_supplier']), $csvText($r['shelf_name']),
                $r['quantity'], $csvText($r['movement_type']), $r['source_table']
            ], ';', '"', '');
        }
        $stmt->closeCursor();
        fclose($out);
        exit;
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $previewTruncated = count($rows) > 5000;
    if ($previewTruncated) {
        array_pop($rows);
    }
}

?>

<!-- FILTER FORM -->
<div class="panel panel-info">
    <div class="panel-heading">
        <h3 class="panel-title"><i class="fa fa-filter"></i> Filter Historical Inventory Movements</h3>
    </div>
    <div class="panel-body">
        <form method="GET" action="index.php" class="form-inline">
            <input type="hidden" name="page" value="historical_movements">

            <div class="form-group">
                <label for="from">From:</label> &nbsp;&nbsp;
                <input type="date" name="from" class="form-control"
                    value="<?= htmlspecialchars($_GET['from'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="to">&nbsp;&nbsp;To:</label> &nbsp;&nbsp;
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>">
            </div>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <div class="form-group">
                <input type="text" name="order_id" class="form-control" placeholder="Order No."
                    value="<?= htmlspecialchars($_GET['order_id'] ?? '') ?>">
            </div>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <div class="form-group">
                <input type="text" name="item_name" class="form-control" placeholder="Item Barcode"
                    value="<?= htmlspecialchars($_GET['item_name'] ?? '') ?>">
            </div>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <div class="form-group">
                <input type="text" name="shelf_name" class="form-control" placeholder="Shelf"
                    value="<?= htmlspecialchars($_GET['shelf_name'] ?? '') ?>">
            </div>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <div class="form-group">
                <input type="text" name="operator" class="form-control" placeholder="Operator"
                    value="<?= htmlspecialchars($_GET['operator'] ?? '') ?>">
            </div>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <div class="form-group">
                <label for="main_supplier">Main Supplier:</label> &nbsp;&nbsp;
                <select name="main_supplier" id="main_supplier" class="form-control" onchange="this.form.submit()">
                    <option value="" <?= $selectedSupplier === '' ? 'selected' : '' ?>>All</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= htmlspecialchars($supplier, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $selectedSupplier === $supplier ? 'selected' : '' ?>><?= htmlspecialchars($supplier, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <div class="form-group">
                <input type="text" name="movement_type" class="form-control" placeholder="Movement (IN / OUT / MOVE)"
                    value="<?= htmlspecialchars($_GET['movement_type'] ?? '') ?>">
            </div>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Search</button>
            <button type="submit" name="export" value="csv" class="btn btn-success"><i class="fa fa-download"></i> Download all CSV</button>
            <a href="index.php?page=historical_movements" class="btn btn-warning"><i class="fa fa-refresh"></i>
                Reset</a>
        </form>
        <p class="help-block" style="margin-top:10px; margin-bottom:0;">
            Main Supplier uses the current item card. IN / OUT are stock receipts / issues and may also include transfers or corrections.
            Download all CSV includes every movement matching the filters above, including archives.
        </p>
    </div>
</div>
<br /><br />

<!-- RESULTS -->
<?php if ($hasFilters): ?>

    <?php if ($previewTruncated): ?>
        <div class="alert alert-warning">
            Showing the latest 5,000 matching movements. Table export buttons include only this preview.
            Use <strong>Download all CSV</strong> above to download every matching movement.
        </div>
    <?php endif; ?>

    <table width="100%" id="example1" class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>Date</th>
                <th>Operator</th>
                <th>Order</th>
                <th>Barcode</th>
                <th>Item Name</th>
                <th>Description</th>
                <th>Color</th>
                <th>Main Supplier</th>
                <th>Location</th>
                <th class="text-center">Qty</th>
                <th class="text-center">Type</th>
                <th>Archive Table</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= $r['movement_type'] === 'IN' ? 'success' : 'danger' ?>">
                    <td data-order="<?= date('Y-m-d', strtotime($r['timestamp'])) ?>">
                        <?= date("d.m.Y H:i", strtotime($r['timestamp'])) ?></td>
                    <td><?= htmlspecialchars($r['operator']) ?></td>
                    <td><?= htmlspecialchars($r['order_id']) ?></td>
                    <td><?= htmlspecialchars($r['item_name']) ?></td>
                    <td><?= htmlspecialchars($r['model'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['color'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['main_supplier'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['shelf_name']) ?></td>
                    <td class="text-center"><?= $r['quantity'] ?></td>
                    <td class="text-center">
                        <span class="label label-<?= $r['movement_type'] === 'IN' ? 'success' : 'danger' ?>">
                            <?= $r['movement_type'] ?>
                        </span>
                    </td>
                    <td><code><?= htmlspecialchars($r['source_table']) ?></code></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php else: ?>

    <div class="alert alert-warning" style="margin-top:20px;">
        <strong>Please enter at least one filter to view results.</strong>
    </div>

<?php endif; ?>
