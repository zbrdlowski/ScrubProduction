<?php

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

// Build filters
$filters = [];
$params = [];

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

$hasFilters = !empty($_GET['from']) ||
    !empty($_GET['to']) ||
    !empty($_GET['order_id']) ||
    !empty($_GET['item_name']) ||
    !empty($_GET['shelf_name']) ||
    !empty($_GET['operator']) ||
    !empty($_GET['movement_type']);

if ($hasFilters) {

    // Build union SQL for all archive tables with LEFT JOIN to items on barcode
    $unionParts = [];
    foreach ($archiveTables as $tbl) {
        $unionParts[] = "SELECT 
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
                i.color
            FROM $tbl a
            LEFT JOIN items i ON i.barcode = a.item_name
            $whereSQL";
    }

    // Wrap UNION and apply ORDER + LIMIT to final merged result
    $sql = "SELECT *
        FROM (
            " . implode(" UNION ALL ", $unionParts) . "
        ) AS merged
        ORDER BY timestamp DESC
        LIMIT 5000";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
                <input type="text" name="movement_type" class="form-control" placeholder="Movement"
                    value="<?= htmlspecialchars($_GET['movement_type'] ?? '') ?>">
            </div>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Search</button>
            <a href="index.php?page=historical_movements" class="btn btn-warning"><i class="fa fa-refresh"></i>
                Reset</a>
        </form>
    </div>
</div>
<br /><br />

<!-- RESULTS -->
<?php if ($hasFilters): ?>

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
                <th>Location</th>
                <th class="text-center">Qty</th>
                <th class="text-center">Type</th>
                <th>Archive Table</th>
            </tr>
        </thead>

        <tbody>
            <?php while ($r = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                <tr class="<?= $r['movement_type'] === 'IN' ? 'success' : 'danger' ?>">
                    <td data-order="<?= date('Y-m-d', strtotime($r['timestamp'])) ?>">
                        <?= date("d.m.Y H:i", strtotime($r['timestamp'])) ?></td>
                    <td><?= htmlspecialchars($r['operator']) ?></td>
                    <td><?= htmlspecialchars($r['order_id']) ?></td>
                    <td><?= htmlspecialchars($r['item_name']) ?></td>
                    <td><?= htmlspecialchars($r['model']) ?></td>
                    <td><?= htmlspecialchars($r['description']) ?></td>
                    <td><?= htmlspecialchars($r['color']) ?></td>
                    <td><?= htmlspecialchars($r['shelf_name']) ?></td>
                    <td class="text-center"><?= $r['quantity'] ?></td>
                    <td class="text-center">
                        <span class="label label-<?= $r['movement_type'] === 'IN' ? 'success' : 'danger' ?>">
                            <?= $r['movement_type'] ?>
                        </span>
                    </td>
                    <td><code><?= htmlspecialchars($r['source_table']) ?></code></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

<?php else: ?>

    <div class="alert alert-warning" style="margin-top:20px;">
        <strong>Please enter at least one filter to view results.</strong>
    </div>

<?php endif; ?>