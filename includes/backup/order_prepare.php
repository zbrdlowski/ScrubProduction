
<div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h1>Items Needs to be ordered (Order Queue)</h1>
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
$selectedSupplier = $_GET['supplier'] ?? '';
?>
<h3> select Supplier </h3>
<div class="row">
  <div class="col-xs-12 text-right" style="margin-bottom: 10px;">
    <form method="get" class="form-inline">
    <input type="hidden" name="page" value="order_prepare">
      
      <select name="supplier" id="supplier" class="form-control input-sm" onchange="this.form.submit()">
        <option value="">-- All Suppliers --</option>
        <?php foreach ($suppliers as $supplier): ?>
          <option value="<?= htmlspecialchars($supplier) ?>" <?= $supplier === $selectedSupplier ? 'selected' : '' ?>>
            <?= htmlspecialchars($supplier) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>
<?
if (isset($_GET['supplier'])){  
$query = "SELECT 
    i.id, i.barcode, i.name, i.description, i.color,
    i.quantity, i.optimum, i.moq, i.main_supplier,
    po.order_number, po.status, po.quantity_to_order
FROM items i
LEFT JOIN (
    SELECT barcode,
           SUM(quantity_to_order) AS quantity_to_order,
           MAX(order_number) AS order_number,
           MAX(status) AS status
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

$itemsWithoutOrder = [];
if (is_array($lowStockItems)) {
    $itemsWithoutOrder = array_filter($lowStockItems, function($item) {
        return empty($item['order_number']);
    });
}

if (!empty($itemsWithoutOrder)): 
  print '<form method="POST" action="index.php?page=order_prepare_form">';
   print ' <input type="hidden" name="supplier" value="'. htmlspecialchars($selectedSupplier) .'">';
     foreach ($itemsWithoutOrder as $item): 
      print '<input type="hidden" name="items[]" value="'. htmlspecialchars($item['id']) .'">';
      echo "<!-- Sending item ID: {$item['id']} -->";
    endforeach; 
    print '<button type="submit" class="btn btn-warning">Prepare Order</button>';
  print '</form>';
  print '<br />';
 endif;
  
}
?>
<table id="example1" class="table table-bordered table-striped">
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
      <th>Main Supplier</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!empty($lowStockItems)): ?>
      <?php foreach ($lowStockItems as $item): ?>
        <tr>
          <td><?= htmlspecialchars($item['barcode']) ?></td>
          <td><?= htmlspecialchars($item['name']) ?></td>
          <td><?= htmlspecialchars($item['description']) ?></td>
          <td><?= htmlspecialchars($item['color']) ?></td>
          <td align="center"><?= $item['quantity'] ?></td> 
          <td align="center"><?= $item['quantity_to_order'] ?></td>        
          <td align="center"><?= $item['optimum'] ?></td>
          <td align="center"><?= $item['moq'] ?></td>           
          <td align="center"><?= $item['moq'] - ($item['quantity'] + $item['quantity_to_order']) ?></td>          
          <td align="center"><?= htmlspecialchars($item['main_supplier']); 
          if (!empty($item['order_number'])) {
          // Set color based on status
          $color = ($item['status'] === 'created') ? '#00b360' : '#d9534f';
          // Set link based on status
          $page = ($item['status'] === 'created') 
            ? 'plastics_orders_active' 
            : 'plastics_orders_sent';
          echo '<a href="index.php?page=' . $page . '&order_number=' . htmlspecialchars($item['order_number']) . '&main_supplier=' . htmlspecialchars($item['main_supplier']) . '">
            <font color="' . $color . '"><strong>' . htmlspecialchars($item['order_number']) . '</strong></font>
          </a>';
         }?>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="6">No items below optimum stock.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
                </div>
              <!-- /.card-body -->
            </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
        </div>