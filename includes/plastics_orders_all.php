<style>
  input[type="checkbox"] {
    transform: scale(2.2);
    margin: 5px;
    cursor: pointer;
  }

  /* Optional: vertically center checkbox in cell */
  td input[type="checkbox"] {
    vertical-align: middle;
  }
</style>
<!-- Required for DataTables buttons -->
<script src="js/jszip.min.js"></script>
<script src="js/pdfmake.min.js"></script>
<script src="js/vfs_fonts.js"></script>
<script src="js/buttons.html5.min.js"></script>
<script src="js/buttons.print.min.js"></script>
<script src="js/jquery.min.js"></script>
<section class="content">
    
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h1>All Unarchived Plastics Orders</h1>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
<?php if (isset($_GET['updated'])): ?>
  <div class="alert alert-<?= $_GET['updated'] ? 'success' : 'warning' ?>">
    <?= $_GET['updated'] ? 'Selected orders marked as sent.' : 'No orders selected.' ?>
  </div>
<?php endif; 


// Get distinct order numbers and suppliers
$orderNumbers = $pdo->query("SELECT DISTINCT order_number FROM plastics_orders ORDER BY order_number")->fetchAll(PDO::FETCH_COLUMN);
$suppliers = $pdo->query("SELECT DISTINCT main_supplier FROM plastics_orders ORDER BY main_supplier")->fetchAll(PDO::FETCH_COLUMN);

// Handle filters
$selectedOrder = $_GET['order_number'] ?? '';
$selectedSupplier = $_GET['main_supplier'] ?? '';
$selectedStatus = $_GET['status'] ?? '';

$query = "SELECT * FROM plastics_orders WHERE 1";
$params = [];

if ($selectedOrder) {
    $query .= " AND order_number = :order_number";
    $params['order_number'] = $selectedOrder;
}
if ($selectedSupplier) {
    $query .= " AND main_supplier = :main_supplier";
    $params['main_supplier'] = $selectedSupplier;
}

if ($selectedStatus) {
    $query .= " AND status = :status";
    $params['status'] = $selectedStatus;
}

$query .= " ORDER BY order_number, main_supplier, name";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<form method="get" class="form-inline" style="margin-bottom: 15px;">
  <input type="hidden" name="page" value="plastics_orders_all">

  <label for="order_number">&nbsp;&nbsp;&nbsp;Order #:&nbsp;&nbsp;&nbsp;</label>
  <select name="order_number" id="order_number" class="form-control input-sm" onchange="this.form.submit()">
    <option value="">-- All --</option>
    <?php foreach ($orderNumbers as $num): ?>
      <option value="<?= htmlspecialchars($num) ?>" <?= $num === $selectedOrder ? 'selected' : '' ?>>
        <?= htmlspecialchars($num) ?>
      </option>
    <?php endforeach; ?>
  </select>

  &nbsp;&nbsp;

  <label for="main_supplier">&nbsp;&nbsp;&nbsp;&nbsp;Supplier:&nbsp;&nbsp;&nbsp;&nbsp;</label>
  <select name="main_supplier" id="main_supplier" class="form-control input-sm" onchange="this.form.submit()">
    <option value="">-- All --</option>
    <?php foreach ($suppliers as $supplier): ?>
      <option value="<?= htmlspecialchars($supplier) ?>" <?= $supplier === $selectedSupplier ? 'selected' : '' ?>>
        <?= htmlspecialchars($supplier) ?>
      </option>
    <?php endforeach; ?>
  </select>

  &nbsp;&nbsp;

  <!-- ✅ NEW STATUS FILTER -->
  <label for="status">&nbsp;&nbsp;&nbsp;&nbsp;Status:&nbsp;&nbsp;&nbsp;&nbsp;</label>
  <select name="status" id="status" class="form-control input-sm" onchange="this.form.submit()">
    <option value="">-- All --</option>
    <option value="created"  <?= ($_GET['status'] ?? '') === 'created'  ? 'selected' : '' ?>>Created</option>
    <option value="sent"     <?= ($_GET['status'] ?? '') === 'sent'     ? 'selected' : '' ?>>Sent</option>
    <option value="received" <?= ($_GET['status'] ?? '') === 'received' ? 'selected' : '' ?>>Received</option>
  </select>

</form>


<form method="post" action="scripts/update_order_status.php" id="orderStatusForm">
  <table id="example8" class="table table-bordered table-striped">
  <thead>
    <tr>
      <!-- <th align="center">Select</th> -->
      <th>Date</th>
      <th>Order #</th>
      <th>Supplier</th>
      <th>Brand</th>
      <th>Barcode</th>
      <th>Name</th>
      <th>Description</th>
      <th>Color</th>
      <th>Qty to Order</th>
      <th>UFO PN</th>
      <th>UFO BC</th>
      <th>R-Tech PN</th>
      <th>R-Tech BC</th>
      <th>Polisport PN</th>
      <th>Polisport BC</th>
      <th>Acerbis PN</th>
      <th>Acerbis BC</th>
      <th>Other PN</th>
      <th>Other BC</th>
      <th>Order Status</th>
    </tr>
  </thead>
  <tbody>
    <?php if (count($orders)): ?>
      <?php foreach ($orders as $item): ?>
        <?php
    $bg = '';
    switch ($item['status']) {
    case 'received':
        $bg = 'background-color: rgba(0, 130, 0, 0.15);';   // subtle dark green
        break;
    case 'created':
        $bg = 'background-color: rgba(150, 0, 0, 0.15);';   // subtle dark red
        break;
    case 'sent':        
        $bg = 'background-color: rgba(180, 140, 0, 0.15);'; // subtle warm yellow
        break;
        }
        ?>
        <tr style="<?= $bg ?>">
          <!--
          <td align="center"><input type="checkbox" name="selected_ids[]" value="<?= $item['id'] ?>"
          <input type="checkbox" name="selected_ids[]" value="<?= $item['id'] ?>"
          <?= $item['status'] === 'sent' ? 'disabled title="Already sent"' : 'checked' ?>>
      -->
          </td>
          <td><?= date( "d.m.Y", strtotime($item['created_at'])) ?></td>
          <td><?= htmlspecialchars($item['order_number']) ?></td>
          <td><?= htmlspecialchars($item['main_supplier']) ?></td>
          <td><?= htmlspecialchars($item['brand']) ?></td>
          <td><?= htmlspecialchars($item['barcode']) ?></td>
          <td><?= htmlspecialchars($item['name']) ?></td>
          <td><?= htmlspecialchars($item['description']) ?></td>
          <td><?= htmlspecialchars($item['color']) ?></td>
          <td><?= $item['quantity_to_order'] ?></td>
          <td><?= htmlspecialchars($item['ufo_pn']) ?></td>
          <td><?= htmlspecialchars($item['ufo_barcode']) ?></td>
          <td><?= htmlspecialchars($item['rt_pn']) ?></td>
          <td><?= htmlspecialchars($item['rt_barcode']) ?></td>
          <td><?= htmlspecialchars($item['ps_pn']) ?></td>
          <td><?= htmlspecialchars($item['ps_barcode']) ?></td>
          <td><?= htmlspecialchars($item['ac_pn']) ?></td>
          <td><?= htmlspecialchars($item['ac_barcode']) ?></td>
          <td><?= htmlspecialchars($item['other_pn']) ?></td>
          <td><?= htmlspecialchars($item['other_barcode']) ?></td>
          <td><?= htmlspecialchars($item['status']) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="8">No orders found for selected filters.</td></tr>
    <?php endif; ?>
  </tbody>

</table>
<!--
  <button type="submit" class="btn btn-primary">Mark Selected as Sent</button>
    -->
</form>
<script>
  $(document).ready(function() {
   

    // Toggle all checkboxes
    $('#toggleAll').on('click', function() {
      var isChecked = $(this).prop('checked');
      $('input[name="selected_ids[]"]', table.rows().nodes()).prop('checked', isChecked);
      $(table.rows().nodes()).toggleClass('info', isChecked);
    });

    // Row highlight on individual checkbox change
    $('#example7').on('change', 'input[name="selected_ids[]"]', function() {
      $(this).closest('tr').toggleClass('info', $(this).prop('checked'));
    });
  });
</script>


                        <!-- DataTables core -->
            <link rel="stylesheet" href="js/dataTables.bootstrap.min.css">
            <script src="js/jquery.dataTables.min.js"></script>
            <script src="js/dataTables.bootstrap.min.js"></script>

            <!-- Buttons extension -->
            <link rel="stylesheet" href="js/buttons.bootstrap.min.css">
            <script src="js/dataTables.buttons.min.js"></script>
            <script src="js/buttons.bootstrap.min.js"></script>
            <script src="js/buttons.colVis.min.js"></script>          
               </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
        </div>
        <!-- /.row -->
      </div>
      <!-- /.container-fluid -->
    </section>
    <!-- /.content -->
  </div>