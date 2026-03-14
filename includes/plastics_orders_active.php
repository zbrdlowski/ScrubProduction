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
  input[type="checkbox"] {
    transform: scale(1.5);
    margin-right: 8px;
  }

</style>
<!-- Required for DataTables buttons -->
<script src="js/jszip.min.js"></script>
<script src="js/pdfmake.min.js"></script>
<script src="js/vfs_fonts.js"></script>
<script src="js/buttons.html5.min.js"></script>
<script src="js/buttons.print.min.js"></script>

<section class="content">
    
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h1>Active Plastics Orders (Awaiting confirmations)</h1>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
<?php if (isset($_GET['updated'])): ?>
  <div class="alert alert-<?= $_GET['updated'] ? 'success' : 'warning' ?>">
    <?= $_GET['updated'] ? 'Selected orders marked as sent.' : 'No orders selected.' ?>
  </div>
<?php endif; 


// Get distinct order numbers and suppliers
$orderNumbers = $pdo->query("SELECT DISTINCT order_number FROM plastics_orders WHERE status = 'created' ORDER BY order_number")->fetchAll(PDO::FETCH_COLUMN);
$suppliers = $pdo->query("SELECT DISTINCT main_supplier FROM plastics_orders WHERE status = 'created' ORDER BY main_supplier")->fetchAll(PDO::FETCH_COLUMN);

// Handle filters
$selectedOrder = $_GET['order_number'] ?? '';
$selectedSupplier = $_GET['main_supplier'] ?? '';

$query = "SELECT * FROM plastics_orders WHERE status = 'created'";
$params = [];

if ($selectedOrder) {
    $query .= " AND order_number = :order_number";
    $params['order_number'] = $selectedOrder;
}
if ($selectedSupplier) {
    $query .= " AND main_supplier = :main_supplier";
    $params['main_supplier'] = $selectedSupplier;
}

$query .= " ORDER BY order_number, main_supplier, name";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<form method="get" class="form-inline" style="margin-bottom: 15px;">
<input type="hidden" name="page" value="plastics_orders_active">
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
</form>

<form method="post" action="scripts/update_order_status.php" id="orderStatusForm">
  <table id="example9" class="table table-bordered table-striped">
  <thead>
    <tr>
      <th style="width:60px; text-align:center;">
  <input type="checkbox" id="toggleAll">
</th>
      <th>Date</th>
      <th>Order #</th>
      <th>Supplier</th>
      <th>Brand</th>
      <th>Barcode</th>
      <th>Name</th>
      <th>Description</th>
      <th>Color</th>
      <th>Qty to Order</th>
      <th>Note</th>
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
      <th>Delete</th>
    </tr>
  </thead>
  <tbody>
    <?php if (count($orders)): ?>
      <?php foreach ($orders as $item): ?>
        <tr>
          <td align="center">
  <input type="checkbox"
         class="rowCheck"
         name="selected_ids[]"
         value="<?= $item['id'] ?>"
         <?= $item['status'] === 'sent' ? 'disabled title="Already sent"' : 'checked' ?>>
</td>
          <td><?= date( "d.m.Y", strtotime($item['created_at'])) ?></td>
          <td><?= htmlspecialchars($item['order_number']) ?></td>
          <td><?= htmlspecialchars($item['main_supplier']) ?></td>
          <td><?= htmlspecialchars($item['brand']) ?></td>
          <td><?= htmlspecialchars($item['barcode']) ?></td>
          <td><?= htmlspecialchars($item['name']) ?></td>
          <td><?= htmlspecialchars($item['description']) ?></td>
          <td><?= htmlspecialchars($item['color']) ?></td>
          <td class="editable-qty" data-id="<?= $item['id'] ?>">
          <span class="qty-display"><?= $item['quantity_to_order'] ?></span>
            <input type="number" class="qty-input form-control" 
              value="<?= $item['quantity_to_order'] ?>" 
                style="display:none; width:80px;">
          </td>
          <td><?= htmlspecialchars($item['note']) ?></td>
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
          <td>
        <button type="button" class="btn btn-danger btn-sm delete-row" data-id="<?= $item['id'] ?>">
  <i class="fa fa-trash"></i>
</button>
        </td>
        </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="8">No orders found for selected filters.</td></tr>
    <?php endif; ?>
  </tbody>

</table>
  <button type="submit" class="btn btn-primary">Patrik Selected as Sent</button>
</form>
<script>
$(function () {

    const table = $('#example9').DataTable({
        order: [],
        paging: true,
        pageLength: 5000,
        destroy: true // <-- prevents reinit error
    });

    // --- TOGGLE ALL ---
    $('#toggleAll').on('change', function () {
        const state = $(this).is(':checked');

        $('input.rowCheck', table.rows({ search: 'applied' }).nodes())
            .not(':disabled')
            .prop('checked', state);

        highlightRows();
    });

    // --- SINGLE CHECK ---
    $('#example9').on('change', 'input.rowCheck', function () {
        updateToggleAllState();
        highlightRows();
    });

    // --- HELPERS ---
    function updateToggleAllState() {
        const rows = $('input.rowCheck', table.rows({ search: 'applied' }).nodes()).not(':disabled');
        $('#toggleAll').prop('checked', rows.length === rows.filter(':checked').length);
    }

    function highlightRows() {
        $('tr', table.rows({ search: 'applied' }).nodes()).each(function () {
            $(this).toggleClass('info', $(this).find('input.rowCheck').prop('checked'));
        });
    }

    updateToggleAllState();
    highlightRows();
});
</script>

        <!-- DataTables core -->
            <link rel="stylesheet" href="js/dataTables.bootstrap.min.css">
<link rel="stylesheet" href="js/buttons.bootstrap.min.css">
<script src="js/jszip.min.js"></script>
<script src="js/pdfmake.min.js"></script>
<script src="js/vfs_fonts.js"></script>
<script src="js/buttons.html5.min.js"></script>
<script src="js/buttons.print.min.js"></script>  
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
  <script>
$(document).ready(function() {

    // ----------------------------
    // Inline quantity editing
    // ----------------------------
    $('#example9').on('click', '.editable-qty', function() {
        let span = $(this).find('.qty-display');
        let input = $(this).find('.qty-input');

        span.hide();
        input.show().focus();
    });

    $('#example9').on('blur', '.qty-input', function() {
        let input = $(this);
        let newQty = input.val();
        let rowID = input.closest('td').data('id');
        let span = input.closest('td').find('.qty-display');

        // Save via AJAX
        $.post('scripts/update_quantity.php', { id: rowID, qty: newQty }, function(res) {
            if (res.trim() === "OK") {
                span.text(newQty).show();
                input.hide();
            } else {
                alert("Error saving quantity!");
            }
        });
    });

    // Press Enter to save
    $('#example9').on('keypress', '.qty-input', function(e) {
        if (e.which === 13) {
            $(this).blur();
        }
    });


    // ----------------------------
    // Delete row handler
    // ----------------------------
$('#example9').on('click', '.delete-row', function(e) {
    e.preventDefault();
    e.stopPropagation();

    let rowID = $(this).data('id');
    let row = $(this).closest('tr');

    if (!confirm("Delete this row?")) return;

    $.post('scripts/delete_order_row.php', { id: rowID }, function(res) {

        res = (res || "").trim();

        if (res === "OK") {
            row.fadeOut(300, function() { row.remove(); });
        } else {
            alert("Error deleting row!");
            console.error("Delete response:", res);
        }

    }).fail(function(xhr) {
        alert("Error deleting row!");
        console.error(xhr);
    });

});   // <-- REQUIRED CLOSING BRACKET

});
</script>
