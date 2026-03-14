<?php
// items.php - Clean refactor for AdminLTE2 (Bootstrap3)
// Assumes: session started and $conn (mysqli) already created in index.php
// (If you're running this standalone, uncomment session_start() and include conn.php)

// session_start();
// require_once('../includes/conn.php');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// List of filterable fields (database column names)
$filterFields = [
  'barcode','brand','name','description','color','main_supplier',
  'ufo_pn','ufo_barcode','rt_pn','rt_barcode','ps_pn','ps_barcode',
  'ac_pn','ac_barcode','other_pn','other_barcode'
];

$hasFilter = false;
$values = [];
$placeholders = [];

// Build WHERE (only when at least one filter provided)
foreach ($filterFields as $f) {
    if (!empty($_GET[$f])) {
        $hasFilter = true;
        $values[] = '%' . $_GET[$f] . '%';
        $placeholders[] = "items.`$f` LIKE ?";
    }
}
?>
<section class="content">
  <div class="row">
    <div class="col-md-12">

      <div class="box box-primary">
        <div class="box-header with-border">
          <h3 class="box-title">Scrub Stock Item List</h3>
        </div>

        <div class="box-body">

          <!-- Compact helper CSS to keep the table inside the canvas -->
          <style>
            .filter-row .form-control { font-size:13px; padding:6px; }
            .compact-table { font-size:12px; }
            .compact-table th, .compact-table td { vertical-align: middle; }
            /* Allow cells to wrap instead of creating huge width */
            .compact-table td { white-space: normal !important; word-wrap: break-word; }
            /* Make table cells smaller and force table to respect container */
            .table-responsive { overflow-x:auto; }
            /* optional: reduce modal font sizes */
            #addToOrderModal .modal-body { font-size: 13px; }
            /* tiny buttons */
            .btn-sm { padding: 4px 8px; font-size: 12px; }
          </style>

          <!-- Filters -->
          <form method="get" action="index.php" class="form-horizontal mb-3">
            <input type="hidden" name="page" value="new_items">

            <div class="row filter-row">
              <div class="col-md-2"><input type="text" name="barcode" class="form-control" placeholder="Scrub P/N" value="<?= h($_GET['barcode'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="brand" class="form-control" placeholder="Brand" value="<?= h($_GET['brand'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="name" class="form-control" placeholder="Model" value="<?= h($_GET['name'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="description" class="form-control" placeholder="Part" value="<?= h($_GET['description'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="color" class="form-control" placeholder="Color" value="<?= h($_GET['color'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="main_supplier" class="form-control" placeholder="Supplier" value="<?= h($_GET['main_supplier'] ?? '') ?>"></div>
            </div>

            <div class="row filter-row" style="margin-top:10px;">
              <div class="col-md-2"><input type="text" name="ufo_pn" class="form-control" placeholder="Ufo P/N" value="<?= h($_GET['ufo_pn'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="ac_pn" class="form-control" placeholder="Acerbis P/N" value="<?= h($_GET['ac_pn'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="rt_pn" class="form-control" placeholder="R-tech P/N" value="<?= h($_GET['rt_pn'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="ps_pn" class="form-control" placeholder="Polisport P/N" value="<?= h($_GET['ps_pn'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="other_pn" class="form-control" placeholder="Other P/N" value="<?= h($_GET['other_pn'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="other_barcode" class="form-control" placeholder="Other Barcode" value="<?= h($_GET['other_barcode'] ?? '') ?>"></div>
            </div>

            <div class="row filter-row" style="margin-top:10px;">
              <div class="col-md-2"><input type="text" name="ufo_barcode" class="form-control" placeholder="Ufo Barcode" value="<?= h($_GET['ufo_barcode'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="ac_barcode" class="form-control" placeholder="Acerbis Barcode" value="<?= h($_GET['ac_barcode'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="rt_barcode" class="form-control" placeholder="R-Tech Barcode" value="<?= h($_GET['rt_barcode'] ?? '') ?>"></div>
              <div class="col-md-2"><input type="text" name="ps_barcode" class="form-control" placeholder="Polisport Barcode" value="<?= h($_GET['ps_barcode'] ?? '') ?>"></div>
              <div class="col-md-2"><button type="submit" class="btn btn-primary btn-block">Filter</button></div>
              <div class="col-md-2"><a href="index.php?page=new_items" class="btn btn-default btn-block">Reset</a></div>
            </div>
          </form>

<?php
if ($hasFilter) {
    // Build the SQL with placeholders
    // join subquery for quantity_to_order and order_number from plastics_orders (status=sent)
    $whereSql = implode(' AND ', $placeholders);
    $sql = "SELECT items.*, COALESCE(po.quantity_to_order, 0) AS quantity_sent, po.order_number
            FROM items
            LEFT JOIN (
                SELECT barcode, quantity_to_order, order_number
                FROM plastics_orders
                WHERE status = 'sent'
            ) AS po ON items.barcode = po.barcode
            WHERE $whereSql
            LIMIT 1000"; // safety limit

    // prepare & bind
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo '<div class="alert alert-danger">Query prepare failed.</div>';
    } else {
        // build types string (all strings)
        $types = str_repeat('s', count($values));
        // mysqli bind_param requires references
        $bind_names[] = $types;
        for ($i=0; $i<count($values); $i++) {
            $bind_name = 'bind' . $i;
            $$bind_name = $values[$i];
            $bind_names[] = &$$bind_name;
        }
        // call_user_func_array expects array of references
        call_user_func_array([$stmt, 'bind_param'], $bind_names);
        $stmt->execute();
        $result = $stmt->get_result();
        ?>

        <div class="table-responsive">
          <table id="example6" class="table table-bordered table-striped compact-table">
            <thead>
              <tr style="background-color:#333940; color:white;">
                <th>ACTION</th><th>ADD</th><th>PART NUMBER</th><th>Brand</th><th>SCRUBCODE</th><th>MODEL</th><th>PART</th><th>COLOR</th>
                <th>QUANTITY</th><th>ORDERED</th><th>OPT</th><th>MOQ</th><th>SUPPLIER</th>
                <th>UFO P/N</th><th>UFO CODE</th><th>RT P/N</th><th>RT CODE</th><th>PS P/N</th><th>PS CODE</th>
                <th>AC P/N</th><th>AC CODE</th><th>OTHER P/N</th><th>OTHER CODE</th>
              </tr>
            </thead>
            <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
              <tr>
                <td style="white-space:nowrap;">
                  <form method="get" action="index.php" style="display:inline-block; margin:0;">
                    <input type="hidden" name="page" value="edit_item">
                    <input type="hidden" name="id" value="<?= h($row['id']); ?>">
                    <button type="submit" class="btn btn-sm btn-warning">Edit</button>
                  </form>
                  <!-- view / print buttons -->
                  <a class="btn btn-sm btn-info" href="index.php?page=view_item&id=<?= h($row['id']); ?>" style="margin-left:4px;">View</a>
                  <a class="btn btn-sm btn-default" href="print_item.php?id=<?= h($row['id']); ?>" target="_blank" style="margin-left:4px;">Print</a>
                </td>

                <td style="white-space:nowrap;">
                  <button class="btn btn-sm btn-primary addToOrderBtn"
                      data-barcode="<?= h($row['barcode']) ?>"
                      data-name="<?= h($row['name']) ?>"
                      data-color="<?= h($row['color']) ?>"
                      data-brand="<?= h($row['brand']) ?>"
                      data-description="<?= h($row['description']) ?>">
                    Add To Order
                  </button>
                </td>

                <td><?= h($row['barcode']) ?></td>
                <td><?= h($row['brand']) ?></td>
                <td><?= h($row['scrubcode']) ?></td>
                <td><?= h($row['name']) ?></td>
                <td><?= h($row['description']) ?></td>
                <td><?= h($row['color']) ?></td>
                <td align="center"><?= h($row['quantity']) ?></td>
                <td align="center" title="Order No: <?= h($row['order_number']) ?>"><?= h($row['quantity_sent']) ?></td>
                <td align="center"><?= h($row['optimum']) ?></td>
                <td align="center"><?= h($row['moq']) ?></td>
                <td><?= h($row['main_supplier']) ?></td>
                <td><?= h($row['ufo_pn']) ?></td>
                <td><?= h($row['ufo_barcode']) ?></td>
                <td><?= h($row['rt_pn']) ?></td>
                <td><?= h($row['rt_barcode']) ?></td>
                <td><?= h($row['ps_pn']) ?></td>
                <td><?= h($row['ps_barcode']) ?></td>
                <td><?= h($row['ac_pn']) ?></td>
                <td><?= h($row['ac_barcode']) ?></td>
                <td><?= h($row['other_pn']) ?></td>
                <td><?= h($row['other_barcode']) ?></td>
              </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div> <!-- /.table-responsive -->

    <?php
        $stmt->close();
    }
} else {
    echo '<div class="alert alert-info">Please enter at least one filter to display results.</div>';
}
?>

          <!-- DataTables assets (assumes CSS included globally) -->
<script>
$(function(){

    // Prevent double initialisation
    if ($.fn.DataTable.isDataTable("#example6")) {
        $("#example6").DataTable().clear().destroy();
    }

    $("#example6").DataTable({
        responsive: true,
        autoWidth: false,
        pageLength: 25,
        order: [],
        columnDefs: [
            { orderable: false, targets: [0,1] }
        ],
        language: {
            search: "Filter:",
            lengthMenu: "Show _MENU_"
        }
    });

});
</script>

        </div><!-- /.box-body -->
      </div><!-- /.box -->
    </div><!-- /.col -->
  </div><!-- /.row -->
</section>

<!-- Add to Order Modal -->
<div class="modal fade" id="addToOrderModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="scripts/save_order_item.php">
        <input type="hidden" name="return_url" value="<?= h($_SERVER['REQUEST_URI']) ?>">
        <div class="modal-header" style="background:#3c8dbc; color:white;">
          <h4 class="modal-title">Add Item to Order</h4>
        </div>
        <div class="modal-body">
          <input type="hidden" name="barcode" id="order_barcode">
          <p><h4 id="order_item_barcode"></h4></p>
          <p><h5 id="order_item_name"></h5></p>

          <div class="form-group">
            <label>Quantity to Order</label>
            <input type="number" class="form-control" name="quantity_to_order" min="1" required>
          </div>

          <div class="form-group">
            <label>Select Existing Order (status = created)</label>
            <select class="form-control" name="existing_order">
              <option value="">— no existing order —</option>
              <?php
                $ordRes = $conn->query("SELECT DISTINCT order_number FROM plastics_orders WHERE status='created'");
                while ($o = $ordRes->fetch_assoc()) {
                    echo '<option value="'.h($o['order_number']).'">'.h($o['order_number']).'</option>';
                }
              ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-default" data-dismiss="modal">Cancel</button>
          <button class="btn btn-success" type="submit"><i class="glyphicon glyphicon-ok"></i> Add</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add to Order script -->
<script>
$(document).on("click", ".addToOrderBtn", function() {
    var $btn = $(this);
    var barcode = $btn.data("barcode");
    var name = $btn.data("name") || '';
    var color = $btn.data("color") || '';
    var brand = $btn.data("brand") || '';
    var desc = $btn.data("description") || '';

    $("#order_barcode").val(barcode);
    $("#order_item_barcode").text(barcode);

    $("#order_item_name").html("<b>"+brand+"</b> — "+name+"<br/>"+desc+" - <small>("+color+")</small>");
    $("#addToOrderModal").modal("show");
});
</script>
