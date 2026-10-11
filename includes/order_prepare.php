
<link rel="stylesheet" href="assets/css/plastics-workflow.css">
<div class="container-fluid plastics-workflow-page">
        <div class="plastics-workflow-hero">
          <div>
            <h1>Order Queue</h1>
            <p>Review low-stock items and prepare a focused purchase order for the selected supplier.</p>
          </div>
        </div>
        <div class="row">
          <div class="col-12">
            <div class="card plastics-workflow-card">
              <div class="card-header">
                <i class="fas fa-clipboard-list" aria-hidden="true"></i>
                <span>Items that need to be ordered</span>
              </div>
              <div class="card-body">
<?php
if (isset($_SESSION['success'])) {
    echo "<div class='alert alert-success alert-dismissible'>
            <button type='button' class='close' data-dismiss='alert'>&times;</button>
            <strong>Success:</strong> {$_SESSION['success']}
          </div>";
    unset($_SESSION['success']);
}
// Get distinct suppliers
$supplierStmt = $pdo->query("SELECT DISTINCT main_supplier FROM items WHERE main_supplier IS NOT NULL ORDER BY main_supplier");
$suppliers = $supplierStmt->fetchAll(PDO::FETCH_COLUMN);

// Handle selected supplier
$selectedSupplier = isset($_GET['supplier']) && is_string($_GET['supplier']) ? trim($_GET['supplier']) : '';
$itemsToOrder = [];
?>
<div class="plastics-workflow-filter-card">
  <form method="get" class="plastics-workflow-filter-grid">
    <input type="hidden" name="page" value="order_prepare">

    <div class="plastics-workflow-filter-field">
      <label for="supplier">Supplier</label>
      <select name="supplier" id="supplier" class="form-control input-sm" onchange="this.form.submit()">
        <option value="">-- All Suppliers --</option>
        <?php foreach ($suppliers as $supplier): ?>
          <option value="<?= htmlspecialchars($supplier) ?>" <?= $supplier === $selectedSupplier ? 'selected' : '' ?>>
            <?= htmlspecialchars($supplier) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="plastics-workflow-note"><i class="fas fa-info-circle mr-1"></i>Selecting a supplier refreshes the order queue.</div>
  </form>
</div>
<?
if (isset($_GET['supplier'])){  
$query = "SELECT 
    i.id, i.barcode, i.name, i.description, i.color,
    i.quantity, i.optimum, i.moq, i.main_supplier,
    po.order_numbers, po.statuses, po.quantity_to_order
FROM items i
LEFT JOIN (
    SELECT barcode,
           SUM(quantity_to_order) AS quantity_to_order,
           GROUP_CONCAT(order_number) AS order_numbers,
           GROUP_CONCAT(status) AS statuses
    FROM plastics_orders
    WHERE status IN ('created', 'sent')
    GROUP BY barcode
) AS po ON i.barcode = po.barcode
WHERE i.quantity < i.optimum";

$params = [];

if ($selectedSupplier) {
    $query .= " AND i.main_supplier = :supplier";
    $params['supplier'] = $selectedSupplier;
}

$query .= " ORDER BY i.main_supplier";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itemsToOrder = [];
if (is_array($lowStockItems)) {
    $itemsToOrder = array_filter($lowStockItems, function($item) {
        $toOrder = $item['moq'] - ($item['quantity'] + $item['quantity_to_order']);
        return $toOrder > 0; // Only items with >0 to order
    });
}

if (!empty($itemsToOrder)): 
    echo '<form method="POST" action="index.php?page=order_prepare_form">';
    echo '<input type="hidden" name="supplier" value="'. htmlspecialchars($selectedSupplier) .'">';
    foreach ($itemsToOrder as $item): 
        echo '<input type="hidden" name="items[]" value="'. htmlspecialchars($item['id']) .'">';
        echo "<!-- Sending item ID: {$item['id']} -->";
    endforeach; 
    echo '<div class="plastics-workflow-actions"><button type="submit" class="btn btn-warning"><i class="fas fa-file-alt mr-1"></i>Prepare Order</button></div>';
    echo '</form><br />';
endif;
  
}
?>
<div class="plastics-workflow-table-wrap">
<table id="orderPrepareTable" class="table table-bordered table-striped table-hover">
  <thead>
    <tr>
      <th>Barcode</th>
      <th>Model</th>
      <th>Part</th>
      <th>Color</th>
      <th>Stock</th>
      <th>Ordered</th>
      <th>Min</th>
      <th>Max</th>
      <th>To Order</th>
      <th>Actual Order</th>
    </tr>
  </thead>
  <tbody>
<?php if (!empty($itemsToOrder)): ?>
    <?php foreach ($itemsToOrder as $item): 
        $toOrder = $item['moq'] - ($item['quantity'] + $item['quantity_to_order']);
    ?>
        <tr>
            <td><?= htmlspecialchars($item['barcode']) ?></td>
            <td><?= htmlspecialchars($item['name']) ?></td>
            <td><?= htmlspecialchars($item['description']) ?></td>
            <td><?= htmlspecialchars($item['color']) ?></td>
            <td align="center"><?= $item['quantity'] ?></td> 
            <td align="center"><?= $item['quantity_to_order'] ?></td>        
            <td align="center"><?= $item['optimum'] ?></td>
            <td align="center"><?= $item['moq'] ?></td>           
            <td align="center"><?= $toOrder ?></td>          
            <td align="center"><?php
if (!empty($item['order_numbers'])) {
    $orders = explode(',', $item['order_numbers']);
    $statuses = explode(',', $item['statuses']);

    foreach ($orders as $index => $orderNum) {
        $status = $statuses[$index];

        $color = ($status === 'created') ? '#00b360' : '#d9534f';
        $page  = ($status === 'created') ? 'plastics_orders_active' : 'plastics_orders_sent';

        echo '<a href="index.php?page=' . $page .
             '&order_number=' . htmlspecialchars($orderNum) .
             '&main_supplier=' . htmlspecialchars($item['main_supplier']) . '">
             <font color="' . $color . '"><strong>' . htmlspecialchars($orderNum) . '</strong></font>
             </a>&nbsp;&nbsp;';
    }
}
?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>

</table>
</div>
                </div>
              <!-- /.card-body -->
            </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
        </div>
