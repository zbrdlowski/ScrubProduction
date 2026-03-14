<style>
  /* Prevent long tables from breaking card/container */
  #example6 {
      width: 100% !important;
      table-layout: auto !important;      
  }
  #viewDetailsModal .modal-content {
      position: relative;
  }
   /* keep scrubcode column tight */
  #example6 td:nth-child(5), #example6 th:nth-child(5) {
    white-space: nowrap;
    max-width: 90px;
  }
</style>
<section class="content">
    <?
    if(isset($_SESSION['error'])){
          echo "
            <div class='alert alert-danger alert-dismissible'>
              <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
              <h4><i class='icon fa fa-warning'></i> Nie je to dobré!</h4>
              ".$_SESSION['error']."
            </div>
          ";
          unset($_SESSION['error']);
        }
        if(isset($_SESSION['success'])){
          echo "
            <div class='alert alert-success alert-dismissible'>
              <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
              <h4><i class='icon fa fa-check'></i> Podarilo sa!</h4>
              ".$_SESSION['success']."
            </div>
          ";
          unset($_SESSION['success']);
        }
    ?>
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">Scrub Stock Item List</h3>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
              <form method="get" action="index.php" class="form-horizontal mb-3">
  <input type="hidden" name="page" value="items">

  <div class="row">
    <div class="col-md-2"><input type="text" name="barcode" class="form-control" placeholder="Srub P/N" value="<?= htmlspecialchars($_GET['barcode'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="brand" class="form-control" placeholder="Brand" value="<?= htmlspecialchars($_GET['brand'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="scrubcode" class="form-control" placeholder="Modelcode" value="<?= htmlspecialchars($_GET['scrubcode'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="name" class="form-control" placeholder="Model" value="<?= htmlspecialchars($_GET['name'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="description" class="form-control" placeholder="Part" value="<?= htmlspecialchars($_GET['description'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="color" class="form-control" placeholder="Color" value="<?= htmlspecialchars($_GET['color'] ?? '') ?>"></div>
    
    
  </div>

  <div class="row" style="margin-top:10px;">
    <div class="col-md-2"><input type="text" name="main_supplier" class="form-control" placeholder="Main Supplier" value="<?= htmlspecialchars($_GET['main_supplier'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="ufo_pn" class="form-control" placeholder="Ufo PN" value="<?= htmlspecialchars($_GET['ufo_pn'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="ac_pn" class="form-control" placeholder="Acerbis PN" value="<?= htmlspecialchars($_GET['ac_pn'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="rt_pn" class="form-control" placeholder="R-tech PN" value="<?= htmlspecialchars($_GET['rt_pn'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="ps_pn" class="form-control" placeholder="Polisport PN" value="<?= htmlspecialchars($_GET['ps_pn'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="other_pn" class="form-control" placeholder="Other PN" value="<?= htmlspecialchars($_GET['other_pn'] ?? '') ?>"></div>
    
    
  </div>

  <div class="row" style="margin-top:10px;">
    <div class="col-md-2"><input type="text" name="ufo_barcode" class="form-control" placeholder="Ufo Barcode" value="<?= htmlspecialchars($_GET['ufo_barcode'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="ac_barcode" class="form-control" placeholder="Acerbis Barcode" value="<?= htmlspecialchars($_GET['ac_barcode'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="rt_barcode" class="form-control" placeholder="R-Tech Barcode" value="<?= htmlspecialchars($_GET['rt_barcode'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="ps_barcode" class="form-control" placeholder="Polisport Barcode" value="<?= htmlspecialchars($_GET['ps_barcode'] ?? '') ?>"></div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-primary btn-block">Filter</button>
    </div>
    <div class="col-md-2">
      <a href="index.php?page=items" class="btn btn-secondary btn-block">Reset</a>
    </div>
  </div>
</form>

 
             <?
           
            $filter_sql = "";
$types = "";
$values = [];

$filterFields = ['barcode','brand', 'scrubcode', 'name', 'description', 'color', 'main_supplier', 'ufo_pn', 'ufo_barcode', 'rt_pn', 'rt_barcode', 'ps_pn', 'ps_barcode', 'ac_pn', 'ac_barcode', 'other_pn', 'other_barcode'];
$hasFilter = false;

// Build filter SQL and check if any filter is active
foreach ($filterFields as $field) {
    if (!empty($_GET[$field])) {
        $hasFilter = true;
        $filter_sql .= " AND items.$field LIKE ?";
        $types .= "s";
        $values[] = '%' . $_GET[$field] . '%';
    }
}

if ($hasFilter) {
    // ✅ Only run this if filters are applied
    $sql = "SELECT items.*, COALESCE(po.quantity_to_order, 0) AS quantity_sent, po.order_number
            FROM items
            LEFT JOIN (
                SELECT barcode, quantity_to_order, order_number
                FROM plastics_orders
                WHERE status = 'sent'
            ) AS po ON items.barcode = po.barcode
            WHERE 1=1 $filter_sql";

    $stmt = $conn->prepare($sql);
    if ($values) {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $query = $stmt->get_result();

    // ✅ Render the table
    echo '<div class="table-responsive" style="overflow-x:auto;">';
    echo '<table id="example6" class="table table-bordered table-striped" style="width:100%;">';
    echo '<thead><tr style="background-color:#333940;">';
    echo '<th>ACTION</th><th>ADD</th><th>PART NUMBER</th><th>Brand</th><th>MODELCODE</th><th>MODEL</th><th>PART</th><th>COLOR</th><th><center>QUANTITY</center></th><th><center>ORDERED</center></th><th><center>MIN</center></th><th><center>MAX</center></th><th>SUPPLIER</th><th>UFO P/N</th><th>UFO CODE</th><th>RT P/N</th><th>RT CODE</th><th>PS P/N</th><th>PS CODE</th><th>AC P/N</th><th>AC CODE</th><th>OTHER P/N</th><th>OTHER CODE</th>';
    echo '</tr></thead><tbody>';

    function renderScrubcodeCell(string $full, string $search = ''): array {
    $full = trim($full);
    if ($full === '') return ['&nbsp;', ''];

    // split by any whitespace
    $tokens = preg_split('/\s+/', $full, -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) return ['&nbsp;', ''];

    $search = trim($search);

    // default visible = first token
    $visible = $tokens[0];
    $others  = array_slice($tokens, 1);

    // if user searched scrubcode, show the matching token (if exists)
    if ($search !== '') {
        $matchIndex = null;
        foreach ($tokens as $i => $t) {
            if (strcasecmp($t, $search) === 0) { // exact token match, case-insensitive
                $matchIndex = $i;
                break;
            }
        }

        if ($matchIndex !== null) {
            $visible = $tokens[$matchIndex];
            $others = $tokens;
            unset($others[$matchIndex]);
            $others = array_values($others);
        } else {
            // if they searched something that isn't an exact token (but LIKE matched),
            // keep first token visible, but tooltip still shows all tokens
            $others = array_slice($tokens, 1);
        }
    }

    $title = htmlspecialchars(implode(' ', $others), ENT_QUOTES, 'UTF-8');
    $visibleEsc = htmlspecialchars($visible, ENT_QUOTES, 'UTF-8');

    return [$visibleEsc, $title];
}

    while ($row = $query->fetch_array()) {

    // Calculate total ordered quantity and get order numbers for this item
    $orders_result = $conn->query("SELECT order_number, quantity_to_order 
                                   FROM plastics_orders 
                                   WHERE barcode='" . $conn->real_escape_string($row['barcode']) . "' AND status='sent'");

    $total_ordered = 0;
    $order_numbers = [];
    while ($o = $orders_result->fetch_assoc()) {
        $total_ordered += $o['quantity_to_order'];
        $order_numbers[] = $o['order_number'];
    }

    $order_numbers_str = implode('&nbsp;&nbsp;', $order_numbers); // separated by space

    echo '<tr>';
    echo '<td>
      <div style="display:flex; gap:5px; align-items:center;">
        <form method="get" action="index.php" style="margin:0; display:inline-block;">
          <input type="hidden" name="page" value="edit_item">
          <input type="hidden" name="id" value="'.$row['id'].'">
          <button type="submit" class="btn btn-sm btn-warning">Edit</button>
        </form>

        <button class="btn btn-sm btn-info viewDetailsBtn"
            data-barcode="'.htmlspecialchars($row['barcode']).'"
            data-brand="'.htmlspecialchars($row['brand']).'"
            data-scrubcode="'.htmlspecialchars($row['scrubcode']).'"
            data-name="'.htmlspecialchars($row['name']).'"
            data-description="'.htmlspecialchars($row['description']).'"
            data-color="'.htmlspecialchars($row['color']).'"
            data-total_ordered="'.htmlspecialchars($total_ordered).'"
            data-order_numbers="'.htmlspecialchars($order_numbers_str).'"
            data-quantity="'.htmlspecialchars($row['quantity']).'"
            data-optimum="'.htmlspecialchars($row['optimum']).'"
            data-moq="'.htmlspecialchars($row['moq']).'"
            data-main_supplier="'.htmlspecialchars($row['main_supplier']).'"
            data-ufo_pn="'.htmlspecialchars($row['ufo_pn']).'"
            data-ufo_barcode="'.htmlspecialchars($row['ufo_barcode']).'"
            data-rt_pn="'.htmlspecialchars($row['rt_pn']).'"
            data-rt_barcode="'.htmlspecialchars($row['rt_barcode']).'"
            data-ps_pn="'.htmlspecialchars($row['ps_pn']).'"
            data-ps_barcode="'.htmlspecialchars($row['ps_barcode']).'"
            data-ac_pn="'.htmlspecialchars($row['ac_pn']).'"
            data-ac_barcode="'.htmlspecialchars($row['ac_barcode']).'"
            data-other_pn="'.htmlspecialchars($row['other_pn']).'"
            data-other_barcode="'.htmlspecialchars($row['other_barcode']).'"
        >View Details</button>
      </div>
    </td>';

    // Add To Order button and other columns remain unchanged
    echo '<td>
            <button class="btn btn-sm btn-primary addToOrderBtn"
                data-barcode="'.$row['barcode'].'"
                data-name="'.htmlspecialchars($row['name']).'"
                data-color="'.htmlspecialchars($row['color']).'"
                data-brand="'.htmlspecialchars($row['brand']).'"
                data-description="'.htmlspecialchars($row['description']).'">
                Add To Order
            </button>
          </td>';
    echo '<td>'.$row['barcode'].'</td>';
    echo '<td>'.$row['brand'].'</td>';
   $fullScrub = trim($row['scrubcode'] ?? '');
// hide empty scrubcode as &nbsp; to keep table structure, but allow searching for empty scrubcode with empty search
$fullScrubEsc = htmlspecialchars($fullScrub, ENT_QUOTES, 'UTF-8');
$searchScrub = $_GET['scrubcode'] ?? '';
[$scrubVisible, $scrubTooltip] = renderScrubcodeCell($fullScrub, $searchScrub);
$attrTitle = ($scrubTooltip !== '') 
  ? ' data-toggle="tooltip" data-placement="top" title="'.$scrubTooltip.'"' 
  : '';
echo '<td align="center" style="white-space:nowrap;"'.$attrTitle.'>
        <span class="scrubcode-cell" data-export="'.$fullScrubEsc.'">'.$scrubVisible.'</span>
      </td>';

    echo '<td>'.$row['name'].'</td>';
    echo '<td>'.$row['description'].'</td>';
    echo '<td>'.$row['color'].'</td>';
    echo '<td align="center">'.$row['quantity'].'</td>';
    echo '<td align="center" title="Order No: '.$row['order_number'].'">'.$row['quantity_sent'].'</td>';
    echo '<td align="center">'.$row['optimum'].'</td>';
    echo '<td align="center">'.$row['moq'].'</td>';
    echo '<td>'.$row['main_supplier'].'</td>';
    echo '<td>'.$row['ufo_pn'].'</td>';
    echo '<td>'.$row['ufo_barcode'].'</td>';
    echo '<td>'.$row['rt_pn'].'</td>';
    echo '<td>'.$row['rt_barcode'].'</td>';
    echo '<td>'.$row['ps_pn'].'</td>';
    echo '<td>'.$row['ps_barcode'].'</td>';
    echo '<td>'.$row['ac_pn'].'</td>';
    echo '<td>'.$row['ac_barcode'].'</td>';
    echo '<td>'.$row['other_pn'].'</td>';
    echo '<td>'.$row['other_barcode'].'</td>';
    echo '</tr>';
}
    echo '</tbody></table>';
    echo '</div>';
    
            } else {
                // ❌ No filters applied
                echo '<a href="index.php?page=pato_items" title="Klikni sem, nech sa ti zobrazia všetky položky"><div class="alert alert-info">Please enter at least one filter to display results.</div></a>';
            }                             
              ?>                          
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
<div class="modal fade" id="addToOrderModal" tabindex="-1">
  <div class="modal-dialog modal-md">
    <div class="modal-content">

      <form method="post" action="scripts/save_order_item.php" id="addToOrderForm">
        <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
        <input type="hidden" name="barcode" id="order_barcode">
        <input type="hidden" name="color" id="order_color">

        <!-- HEADER -->
        <div class="modal-header bg-primary text-white">
          <h4 class="modal-title">Add Item to Order</h4>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <!-- BODY -->
        <div class="modal-body">

          <!-- ITEM INFO -->
          <div class="mb-3 p-2 border rounded bg-light text-center">
            <h5 class="mb-1" id="order_item_barcode"></h5>
            <div class="text-muted" id="order_item_name"></div>
          </div>

          <!-- QUANTITY -->
          <div class="form-group mb-3">
            <label class="fw-bold">Quantity to Order</label>
            <input type="number"
                   class="form-control"
                   name="quantity_to_order"
                   min="1"
                   required>
          </div>

          <!-- ORDER SELECTION -->
          <div class="form-group mb-3">
            <label class="fw-bold">Order</label>
            <select class="form-control" name="existing_order" id="existing_order">
              <option value="">— Select existing order —</option>
              <option value="__new__">➕ Create New Order</option>
              <?php
                $orders = $conn->query("
                  SELECT DISTINCT order_number
                  FROM plastics_orders
                  WHERE status = 'created'
                  ORDER BY order_number ASC
                ");
                while ($o = $orders->fetch_assoc()) {
                  echo '<option value="'.$o['order_number'].'">'.$o['order_number'].'</option>';
                }
              ?>
            </select>
          </div>

          <!-- SUPPLIER (ALWAYS VISIBLE) -->
          <div class="form-group mb-3">
            <label class="fw-bold">Choose Supplier</label>
            <select class="form-control" name="new_order_supplier" id="new_order_supplier">
              <option value="">— Select Supplier —</option>
              <?php
                $suppliers = $conn->query("
                  SELECT DISTINCT main_supplier
                  FROM items
                  WHERE main_supplier IS NOT NULL
                    AND main_supplier <> ''
                  ORDER BY main_supplier ASC
                ");
                while ($s = $suppliers->fetch_assoc()) {
                  echo '<option value="'.htmlspecialchars($s['main_supplier']).'">'.$s['main_supplier'].'</option>';
                }
              ?>
            </select>
            <small class="text-muted">
              Required only when creating a new order
            </small>
          </div>

          <!-- NEW ORDER NUMBER (CONDITIONAL) -->
          <div class="form-group mb-3" id="new_order_number_group" style="display:none;">
            <label class="fw-bold">New Order Number</label>
            <input type="text"
                   class="form-control"
                   name="new_order_number"
                   id="new_order_number"
                   placeholder="e.g. 2025-00123">
          </div>

          <!-- NOTE -->
          <div class="form-group">
            <label class="fw-bold">Note (optional)</label>
            <textarea class="form-control"
                      name="order_note"
                      rows="2"
                      placeholder="Additional info..."></textarea>
          </div>

        </div>

        <!-- FOOTER -->
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            Cancel
          </button>
          <button type="submit" class="btn btn-success">
            <i class="glyphicon glyphicon-ok"></i> Add to Order
          </button>
        </div>

      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="viewDetailsModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header bg-info text-white">
        <h4 class="modal-title">Item Details</h4>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <!-- Top Section -->
        <div class="mb-3 p-3 border bg-light">
          <h5>General Info</h5>
          <table class="table table-sm table-borderless mb-0">
              <tbody>
                <tr><th>Brand</th><td id="detail_brand"></td></tr>
                <tr><th>Barcode</th><td id="detail_barcode"></td></tr>
                <tr><th>Modelcode</th><td id="detail_scrubcode"></td></tr>
                <tr><th>Name</th><td id="detail_name"></td></tr>
                <tr><th>Description</th><td id="detail_description"></td></tr>
                <tr><th>Color</th><td id="detail_color"></td></tr>
                <tr><th>Quantity</th><td id="detail_quantity"></td></tr>
                <tr><th>Optimum</th><td id="detail_optimum"></td></tr>
                <tr><th>Max</th><td id="detail_moq"></td></tr>
                <tr><th>Main Supplier</th><td id="detail_main_supplier"></td></tr>
                <tr><th>Total Ordered Quantity</th><td id="detail_total_ordered"></td></tr>
                <tr><th>Orders</th><td id="detail_orders"></td></tr>
              </tbody>
            </table>
        </div>

        <!-- Bottom Section -->
        <div class="mb-3 p-3 border bg-light">
          <h5>Supplier Codes</h5>
          <table class="table table-sm table-borderless mb-0">
            <tbody>
              <tr><th>RT P/N</th><td id="detail_rt_pn"></th></tr>
              <tr><th>UFO P/N</th><td id="detail_ufo_pn"></th></tr> 
              <tr><th>PS P/N</th><td id="detail_ps_pn"></th></tr>              
              <tr><th>AC P/N</th><td id="detail_ac_pn"></th></tr>
              <tr><th>Other P/N</th><td id="detail_other_pn"></th></tr> 
                <tr><th>&nbsp;</th></tr>
              <tr><th>RT Barcode</th><td id="detail_rt_barcode"></th></tr>
              <tr><th>UFO Barcode</th><td id="detail_ufo_barcode"></th></tr>
              <tr><th>PS Barcode</th><td id="detail_ps_barcode"></th></tr>
               <tr><th>AC Barcode</th><td id="detail_ac_barcode"></th></tr>
               <tr><th>Other Barcode</th><td id="detail_other_barcode"></th></tr>
            </tbody>
          </table>
        </div>
        <!-- QR Code (bottom-right corner) -->
<div id="detail_qr_wrapper"
     style="position:absolute;
        bottom:40px;   /* 15 + 10 */
        right:25px;    /* 15 + 25 */
        text-align:center;
        background:white;
        padding:6px;
        border-radius:6px;
        box-shadow:0 2px 6px rgba(0,0,0,0.2);
     ">
    <img id="detail_qr"
         src=""
         alt="QR Code"
         style="width:90px;height:90px;">
</div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>
<script>
$(document).on('click', '.viewDetailsBtn', function() {

    const barcode = $(this).data('barcode');

    $('#detail_brand').text($(this).data('brand'));
    $('#detail_barcode').text(barcode);
    $('#detail_scrubcode').text($(this).data('scrubcode'));
    $('#detail_name').text($(this).data('name'));
    $('#detail_description').text($(this).data('description'));
    $('#detail_color').text($(this).data('color'));
    $('#detail_quantity').text($(this).data('quantity'));
    $('#detail_optimum').text($(this).data('optimum'));
    $('#detail_moq').text($(this).data('moq'));
    $('#detail_main_supplier').text($(this).data('main_supplier'));

    $('#detail_ufo_pn').text($(this).data('ufo_pn'));    
    $('#detail_rt_pn').text($(this).data('rt_pn'));   
    $('#detail_ps_pn').text($(this).data('ps_pn'));    
    $('#detail_ac_pn').text($(this).data('ac_pn'));    
    $('#detail_other_pn').text($(this).data('other_pn'));    
    $('#detail_total_ordered').html($(this).data('total_ordered'));
    $('#detail_orders').html($(this).data('order_numbers')); 
    $('#detail_other_barcode').text($(this).data('other_barcode'));
    $('#detail_ac_barcode').text($(this).data('ac_barcode'));
    $('#detail_ps_barcode').text($(this).data('ps_barcode'));
    $('#detail_rt_barcode').text($(this).data('rt_barcode'));
    $('#detail_ufo_barcode').text($(this).data('ufo_barcode'));

    // ✅ QR CODE GENERATION
    const qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data="
                  + encodeURIComponent(barcode);

    $('#detail_qr').attr('src', qrUrl);

    $('#viewDetailsModal').modal('show');
});


</script>
<script>
document.getElementById('existing_order').addEventListener('change', function () {
    const newOrderGroup = document.getElementById('new_order_number_group');
    const newOrderInput = document.getElementById('new_order_number');
    const supplier = document.getElementById('new_order_supplier');

    if (this.value === '__new__') {
        newOrderGroup.style.display = 'block';
        newOrderInput.required = true;
        supplier.required = true;
    } else {
        newOrderGroup.style.display = 'none';
        newOrderInput.required = false;
        supplier.required = false;
        newOrderInput.value = '';
    }
});

// Replace "__new__" with real order number before submit
document.getElementById('addToOrderForm').addEventListener('submit', function () {
    const existingOrder = document.getElementById('existing_order');
    if (existingOrder.value === '__new__') {
        existingOrder.value = document.getElementById('new_order_number').value;
    }
});
</script>

<script>
document.querySelector('#addToOrderModal form').addEventListener('submit', function(e) {
    if (document.getElementById('existing_order').value === '__new__') {
        // replace dropdown value before submit
        document.getElementById('existing_order').value =
            document.getElementById('new_order_number').value;
    }
});
</script>

<script>
$(document).on('click', '.addToOrderBtn', function() {

    // Fill fields
    $('#order_barcode').val($(this).data('barcode'));
    $('#order_color').val($(this).data('color')); // ✅ ADD THIS
    $('#order_item_barcode').text($(this).data('barcode'));
    $('#order_item_name').text(
        $(this).data('brand') + " " +
        $(this).data('name') + " - " +
        $(this).data('color')
    );

    // Show modal
    $('#addToOrderModal').modal('show');
});
</script>

