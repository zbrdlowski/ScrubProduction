<style>
td.editable-qty {
    cursor: pointer;
}
td.editable-qty input {
    min-width: 70px;
}
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

     <div class="card-body">

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-<?= $_GET['updated'] ? 'success' : 'warning' ?>">
        <?= $_GET['updated'] ? 'Selected orders marked as sent.' : 'No orders selected.' ?>
    </div>
<?php endif; ?>

<?php
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

<div style="margin-bottom: 15px;">
    <button type="button" id="bulk-edit-btn" class="btn btn-primary">
        <i class="fa fa-edit"></i> Edit selected
    </button>
</div>

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
                <tr data-id="<?= (int)$item['id'] ?>">
                    <td align="center">
                        <input type="checkbox"
                               class="rowCheck"
                               name="selected_ids[]"
                               value="<?= (int)$item['id'] ?>"
                               <?= $item['status'] === 'sent' ? 'disabled title="Already sent"' : 'checked' ?>>
                    </td>

                    <td><?= date("d.m.Y", strtotime($item['created_at'])) ?></td>
                    <td class="order-number-cell"><?= htmlspecialchars($item['order_number']) ?></td>
                    <td><?= htmlspecialchars($item['main_supplier']) ?></td>
                    <td><?= htmlspecialchars($item['brand']) ?></td>
                    <td><?= htmlspecialchars($item['barcode']) ?></td>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td><?= htmlspecialchars($item['description']) ?></td>
                    <td><?= htmlspecialchars($item['color']) ?></td>

                    <td class="editable-qty" data-id="<?= (int)$item['id'] ?>">
                        <span class="qty-display"><?= $item['quantity_to_order'] ?></span>
                        <input type="number"
                               class="qty-input form-control"
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
                        <button type="button" class="btn btn-danger btn-sm delete-row" data-id="<?= (int)$item['id'] ?>">
                            <i class="fa fa-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="23">No orders found for selected filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <button type="submit" class="btn btn-primary">Mark Selected as Sent</button>
</form>

<!-- Bulk edit modal -->
<div class="modal fade" id="bulkEditModal" tabindex="-1" role="dialog" aria-labelledby="bulkEditModalLabel" aria-hidden="true">
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
                <p id="bulk-edit-info" style="margin-bottom: 0;"></p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" id="save-bulk-edit-btn" class="btn btn-primary">Save changes</button>
            </div>
        </div>
    </div>
</div>

<!-- DataTables core -->
<link rel="stylesheet" href="js/dataTables.bootstrap.min.css">
<link rel="stylesheet" href="js/buttons.bootstrap.min.css">

<script src="js/jquery.dataTables.min.js"></script>
<script src="js/dataTables.bootstrap.min.js"></script>
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

<script>
$(function () {
    const table = $('#example9').DataTable({
        order: [],
        paging: true,
        pageLength: 5000,
        destroy: true
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
        $('#toggleAll').prop('checked', rows.length > 0 && rows.length === rows.filter(':checked').length);
    }

    function highlightRows() {
        $('tr', table.rows({ search: 'applied' }).nodes()).each(function () {
            $(this).toggleClass('info', $(this).find('input.rowCheck').prop('checked'));
        });
    }

    updateToggleAllState();
    highlightRows();
});

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

        $.post('scripts/update_quantity.php', { id: rowID, qty: newQty }, function(res) {
            if ((res || '').trim() === "OK") {
                span.text(newQty).show();
                input.hide();
            } else {
                alert("Error saving quantity!");
            }
        }).fail(function() {
            alert("Error saving quantity!");
        });
    });

    // Press Enter to save
    $('#example9').on('keypress', '.qty-input', function(e) {
        if (e.which === 13) {
            $(this).blur();
        }
    });

    // ----------------------------
    // Bulk edit order number
    // ----------------------------
    $('#bulk-edit-btn').on('click', function() {
        let ids = $('.rowCheck:checked').map(function() {
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

    $('#save-bulk-edit-btn').on('click', function() {
        let ids = $('.rowCheck:checked').map(function() {
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

        if (!confirm('Change order number for selected rows (' + ids.length + ')?')) return;

        $.post('scripts/update_order_number.php', {
            ids: ids,
            order_number: newOrderNumber
        }, function(res) {
            res = (res || "").trim();

            if (res === "OK") {
                $('.rowCheck:checked').each(function() {
                    let row = $(this).closest('tr');
                    row.find('.order-number-cell').text(newOrderNumber);
                });

                $('#bulkEditModal').modal('hide');
            } else {
                alert("Error updating order number!");
                console.error("Bulk edit response:", res);
            }
        }).fail(function(xhr) {
            alert("Error updating order number!");
            console.error(xhr);
        });
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
    });
});
</script>