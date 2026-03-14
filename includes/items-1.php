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
               <form method="get" action="index.php" class="form-inline mb-3">
                <input type="hidden" name="page" value="items-1">

                <input type="text" name="brand" class="form-control mr-2" placeholder="Brand" value="<?= htmlspecialchars($_GET['brand'] ?? '') ?>">                
                <input type="text" name="name" class="form-control mr-2" placeholder="Model" value="<?= htmlspecialchars($_GET['name'] ?? '') ?>">
                <input type="text" name="description" class="form-control mr-2" placeholder="Part" value="<?= htmlspecialchars($_GET['description'] ?? '') ?>">
                <input type="text" name="color" class="form-control mr-2" placeholder="Color" value="<?= htmlspecialchars($_GET['color'] ?? '') ?>">                
                <input type="text" name="main_supplier" class="form-control mr-2" placeholder="Supplier" value="<?= htmlspecialchars($_GET['main_supplier'] ?? '') ?>">

                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="index.php?page=items-1" class="btn btn-secondary ml-2">Reset</a>
              </form>
 
             <?
           
            $filter_sql = "";
$types = "";
$values = [];

if (!empty($_GET['brand'])) {
    $filter_sql .= " AND brand LIKE ?";
    $types .= "s";
    $values[] = '%' . $_GET['brand'] . '%';
}
if (!empty($_GET['name'])) {
    $filter_sql .= " AND name LIKE ?";
    $types .= "s";
    $values[] = '%' . $_GET['name'] . '%';
}
if (!empty($_GET['description'])) {
    $filter_sql .= " AND description LIKE ?";
    $types .= "s";
    $values[] = '%' . $_GET['description'] . '%';
}
if (!empty($_GET['color'])) {
    $filter_sql .= " AND color LIKE ?";
    $types .= "s";
    $values[] = '%' . $_GET['color'] . '%';
}
if (!empty($_GET['main_supplier'])) {
    $filter_sql .= " AND main_supplier LIKE ?";
    $types .= "s";
    $values[] = '%' . $_GET['main_supplier'] . '%';
}

$sql = "SELECT * FROM items WHERE 1=1 $filter_sql";
$stmt = $conn->prepare($sql);

if ($values) {
    $stmt->bind_param($types, ...$values);
}

$stmt->execute();
$query = $stmt->get_result(); // ✅ Now $query is defined



            print '<table id="example6" class="table table-bordered table-striped">';
            print '<thead>';
                print'<tr style="background-color:#333940;">';
                print'<th>ACTION</th>';
            print'<th>CODE</th>';
            print'<th>Brand</th>';
            print'<th>SCRUBCODE</th>';
            print'<th>MODEL</th>';
            print'<th>PART</th>';
            print'<th>COLOR</th>';
            print'<th>QUANTITY</th>';
            print'<th>OPT</th>';
            print'<th>MOQ</th>';
            print'<th>SUPPLIER</th>';
            print'<th>UFO P/N</th>';
            print'<th>UFO CODE</th>';
            print'<th>RT P/N</th>';
            print'<th>RT CODE</th>';
            print'<th>PS P/N</th>';
            print'<th>PS CODE</th>';
            print'<th>AC P/N</th>';
            print'<th>AC CODE</th>';
            print'<th>OTHER P/N</th>';
            print'<th>OTHER CODE</th>';            
            print '</tr>';
                print '</thead>';
                print '<tbody>';
            while($row = $query->fetch_array()){
                $scrubcode = $row['scrubcode'];
                print '<tr>';
                print '<td>
                    <form method="get" action="index.php" style="margin:0;">
                    <input type="hidden" name="page" value="edit_item">
                      <input type="hidden" name="id" value="'.$row['id'].'">
                      <button type="submit" class="btn btn-sm btn-warning">Edit</button>
                    </form>
                  </td>';
                print '<td> '. $row['barcode'] .' </td>';
                print '<td> '. $row['brand'].' </td>'; 
                print '<td> '. $scrubcode.' </td>';             
                print '<td> '. $row['name'].' </td>';                
                print '<td> '. $row['description'].' </td>';
                print '<td> '. $row['color'].' </td>';
                print '<td align="center"> '. $row['quantity'].'</td>';
                print '<td align="center"> '. $row['optimum'].'</td>';
                print '<td align="center"> '. $row['moq'].'</td>';                
                print '<td> '. $row['main_supplier'].'</td>';
                print '<td> '. $row['ufo_pn'].'</td>'; 
                print '<td> '. $row['ufo_barcode'].'</td>';
                print '<td> '. $row['rt_pn'].'</td>'; 
                print '<td> '. $row['rt_barcode'].'</td>';
                print '<td> '. $row['ps_pn'].'</td>'; 
                print '<td> '. $row['ps_barcode'].'</td>';
                print '<td> '. $row['ac_pn'].'</td>'; 
                print '<td> '. $row['ac_barcode'].'</td>';
                print '<td> '. $row['other_pn'].'</td>'; 
                print '<td> '. $row['other_barcode'].'</td>';
                print '</tr>';                
            }
                print '</tbody>';
                print '<tfoot>';
                print'<tr style="background-color:#333940;">';
                print'<th>ACTION</th>';
            print'<th>CODE</th>';
            print'<th>Brand</th>';
            print'<th>SCRUBCODE</th>';
            print'<th>MODEL</th>';
            print'<th>PART</th>';
            print'<th>COLOR</th>';
            print'<th>QUANTITY</th>';
            print'<th>OPT</th>';
            print'<th>MOQ</th>';
            print'<th>SUPPLIER</th>';
            print'<th>UFO P/N</th>';
            print'<th>UFO CODE</th>';
            print'<th>RT P/N</th>';
            print'<th>RT CODE</th>';
            print'<th>PS P/N</th>';
            print'<th>PS CODE</th>';
            print'<th>AC P/N</th>';
            print'<th>AC CODE</th>';
            print'<th>OTHER P/N</th>';
            print'<th>OTHER CODE</th>';            
            print '</tr>';
                print '</tfoot>';
                
                print '</table>';
                                
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
