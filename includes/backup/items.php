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
    <div class="col-md-2"><input type="text" name="brand" class="form-control" placeholder="Brand" value="<?= htmlspecialchars($_GET['brand'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="name" class="form-control" placeholder="Model" value="<?= htmlspecialchars($_GET['name'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="description" class="form-control" placeholder="Part" value="<?= htmlspecialchars($_GET['description'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="color" class="form-control" placeholder="Color" value="<?= htmlspecialchars($_GET['color'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="main_supplier" class="form-control" placeholder="Supplier" value="<?= htmlspecialchars($_GET['main_supplier'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="ufo_pn" class="form-control" placeholder="Ufo PN" value="<?= htmlspecialchars($_GET['ufo_pn'] ?? '') ?>"></div>
  </div>

  <div class="row" style="margin-top:10px;">
    <div class="col-md-2"><input type="text" name="ufo_barcode" class="form-control" placeholder="Ufo Barcode" value="<?= htmlspecialchars($_GET['ufo_barcode'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="rt_pn" class="form-control" placeholder="R-tech PN" value="<?= htmlspecialchars($_GET['rt_pn'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="rt_barcode" class="form-control" placeholder="R-Tech Barcode" value="<?= htmlspecialchars($_GET['rt_barcode'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="ps_pn" class="form-control" placeholder="Polisport PN" value="<?= htmlspecialchars($_GET['ps_pn'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="ps_barcode" class="form-control" placeholder="Polisport Barcode" value="<?= htmlspecialchars($_GET['ps_barcode'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="ac_pn" class="form-control" placeholder="Acerbis PN" value="<?= htmlspecialchars($_GET['ac_pn'] ?? '') ?>"></div>
  </div>

  <div class="row" style="margin-top:10px;">
    <div class="col-md-2"><input type="text" name="ac_barcode" class="form-control" placeholder="Acerbis Barcode" value="<?= htmlspecialchars($_GET['ac_barcode'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="other_pn" class="form-control" placeholder="Other PN" value="<?= htmlspecialchars($_GET['other_pn'] ?? '') ?>"></div>
    <div class="col-md-2"><input type="text" name="other_barcode" class="form-control" placeholder="Other Barcode" value="<?= htmlspecialchars($_GET['other_barcode'] ?? '') ?>"></div>
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

$filterFields = ['brand', 'name', 'description', 'color', 'main_supplier', 'ufo_pn', 'ufo_barcode', 'rt_pn', 'rt_barcode', 'ps_pn', 'ps_barcode', 'ac_pn', 'ac_barcode', 'other_pn', 'other_barcode'];
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
    echo '<table id="example6" class="table table-bordered table-striped">';
    echo '<thead><tr style="background-color:#333940;">';
    echo '<th>ACTION</th><th>PART NUMBER</th><th>Brand</th><th>SCRUBCODE</th><th>MODEL</th><th>PART</th><th>COLOR</th><th>QUANTITY</th><th>ORDERED</th><th>OPT</th><th>MOQ</th><th>SUPPLIER</th><th>UFO P/N</th><th>UFO CODE</th><th>RT P/N</th><th>RT CODE</th><th>PS P/N</th><th>PS CODE</th><th>AC P/N</th><th>AC CODE</th><th>OTHER P/N</th><th>OTHER CODE</th>';
    echo '</tr></thead><tbody>';

    while ($row = $query->fetch_array()) {
        echo '<tr>';
        echo '<td><form method="get" action="index.php" style="margin:0;"><input type="hidden" name="page" value="edit_item"><input type="hidden" name="id" value="'.$row['id'].'"><button type="submit" class="btn btn-sm btn-warning">Edit</button></form></td>';
        echo '<td>'.$row['barcode'].'</td>';
        echo '<td>'.$row['brand'].'</td>';
        echo '<td>'.$row['scrubcode'].'</td>';
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
} else {
    // ❌ No filters applied
    echo '<div class="alert alert-info">Please enter at least one filter to display results.</div>';
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
