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
      <h1>Sent Plastics Orders</h1>
     </div>

     <div class="card-body">
<?php
// Get distinct order numbers and suppliers
$orderNumbers = $pdo->query("SELECT DISTINCT order_number FROM plastics_orders WHERE status = 'sent' ORDER BY order_number")->fetchAll(PDO::FETCH_COLUMN);
$suppliers = $pdo->query("SELECT DISTINCT main_supplier FROM plastics_orders WHERE status = 'sent' ORDER BY main_supplier")->fetchAll(PDO::FETCH_COLUMN);

// Handle filters
$selectedOrder = $_GET['order_number'] ?? '';
$selectedSupplier = $_GET['main_supplier'] ?? '';

$query = "SELECT * FROM plastics_orders WHERE status = 'sent'";
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
    <input type="hidden" name="page" value="plastics_orders_sent">

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

<div style="margin-bottom: 15px;">
    <button type="button" id="bulk-edit-btn" class="btn btn-primary">
        <i class="fa fa-edit"></i> Edit selected
    </button>

    <button type="button" id="bulk-delete-btn" class="btn btn-danger">
        <i class="fa fa-trash"></i> Delete selected
    </button>
</div>

<table id="example7" class="table table-bordered table-striped">
    <thead>
        <tr>
            <th style="width:40px; text-align:center;">
                <input type="checkbox" id="toggle-all">
            </th>
            <th>Date</th>
            <th>Order #</th>
            <th>Supplier</th>
            <th>Brand</th>
            <th>Barcode</th>
            <th>Name</th>
            <th>Description</th>
            <th>Color</th>
            <th>Qty</th>
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
            <tr data-id="<?= (int)$item['id'] ?>">
                <td align="center">
                    <input type="checkbox" class="row-checkbox" value="<?= (int)$item['id'] ?>">
                </td>
                <td><?= date("d.m.Y", strtotime($item['created_at'])) ?></td>
                <td><?= htmlspecialchars($item['order_number']) ?></td>
                <td><?= htmlspecialchars($item['main_supplier']) ?></td>
                <td><?= htmlspecialchars($item['brand']) ?></td>
                <td><?= htmlspecialchars($item['barcode']) ?></td>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td><?= htmlspecialchars($item['description']) ?></td>
                <td><?= htmlspecialchars($item['color']) ?></td>
                <td align="center"><?= $item['quantity_to_order'] ?></td>
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
                <td align="center">
                    <button type="button" class="btn btn-danger delete-row" data-id="<?= (int)$item['id'] ?>">
                        <i class="fa fa-trash"></i>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="23">No orders found for selected filters.</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<link rel="stylesheet" href="js/dataTables.bootstrap.min.css">
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/dataTables.bootstrap.min.js"></script>

<link rel="stylesheet" href="js/buttons.bootstrap.min.css">
<script src="js/dataTables.buttons.min.js"></script>
<script src="js/buttons.bootstrap.min.js"></script>
<script src="js/buttons.colVis.min.js"></script>

     </div>
    </div>
   </div>
  </div>
 </div>
</section>

</div>
<!-- Bulk edit modal -->
<div class="modal fade" id="bulkEditModal" tabindex="-1" role="dialog" aria-labelledby="bulkEditModalLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title" id="bulkEditModalLabel">Edit order number</h4>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <div class="form-group">
          <label for="new_order_number">New order number</label>
          <input type="text" id="new_order_number" class="form-control" placeholder="Enter new order number">
        </div>
        <p id="bulk-edit-info" style="margin-bottom:0;"></p>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
        <button type="button" id="save-bulk-edit-btn" class="btn btn-primary">Save changes</button>
      </div>
    </div>
  </div>
</div>
<script>
$(document).ready(function() {

    // Toggle all checkboxes
    $('#toggle-all').on('change', function() {
        $('.row-checkbox').prop('checked', $(this).is(':checked'));
    });

    // If one row is unchecked manually, uncheck toggle-all
    $('#example7').on('change', '.row-checkbox', function() {
        let total = $('.row-checkbox').length;
        let checked = $('.row-checkbox:checked').length;
        $('#toggle-all').prop('checked', total > 0 && total === checked);
    });

    // Single delete
    $('#example7').on('click', '.delete-row', function(e) {
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
    });

    // Bulk delete
    $('#bulk-delete-btn').on('click', function() {
        let ids = $('.row-checkbox:checked').map(function() {
            return $(this).val();
        }).get();

        if (!ids.length) {
            alert('Please select at least one row.');
            return;
        }

        if (!confirm('Delete selected rows (' + ids.length + ')?')) return;

        $.post('scripts/delete_order_row.php', { ids: ids }, function(res) {
            res = (res || "").trim();

            if (res === "OK") {
                $('.row-checkbox:checked').each(function() {
                    $(this).closest('tr').fadeOut(300, function() {
                        $(this).remove();
                    });
                });
                $('#toggle-all').prop('checked', false);
            } else {
                alert("Error deleting selected rows!");
                console.error("Bulk delete response:", res);
            }
        }).fail(function(xhr) {
            alert("Error deleting selected rows!");
            console.error(xhr);
        });
    });
        // Open bulk edit modal
    $('#bulk-edit-btn').on('click', function() {
        let ids = $('.row-checkbox:checked').map(function() {
            return $(this).val();
        }).get();

        if (!ids.length) {
            alert('Please select at least one row.');
            return;
        }

        $('#new_order_number').val('');
        $('#bulk-edit-info').text('Selected rows: ' + ids.length);
        $('#bulkEditModal').modal('show');
    });

    // Save bulk edit
    $('#save-bulk-edit-btn').on('click', function() {
        let ids = $('.row-checkbox:checked').map(function() {
            return $(this).val();
        }).get();

        let newOrderNumber = $('#new_order_number').val().trim();

        if (!ids.length) {
            alert('Please select at least one row.');
            return;
        }

        if (!newOrderNumber) {
            alert('Please enter a new order number.');
            return;
        }

        $.post('scripts/update_order_number.php', {
            ids: ids,
            order_number: newOrderNumber
        }, function(res) {
            res = (res || "").trim();

            if (res === "OK") {
                $('.row-checkbox:checked').each(function() {
                    let row = $(this).closest('tr');
                    row.find('td').eq(2).text(newOrderNumber); // stĺpec Order #
                });

                $('#bulkEditModal').modal('hide');
                $('#toggle-all').prop('checked', false);
                $('.row-checkbox').prop('checked', false);

            } else {
                alert("Error updating order number!");
                console.error("Bulk edit response:", res);
            }
        }).fail(function(xhr) {
            alert("Error updating order number!");
            console.error(xhr);
        });
    });

});
</script>