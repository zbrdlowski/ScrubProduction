
<?php
 
  session_start();

  if(!isset($_SESSION['permission'])){	
    header('location:login.php');
  }
  include 'includes/conn.php'; 
  ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
 <title>Scrub Production</title>
  <!-- Google Font: Source Sans Pro -->
  <link rel="stylesheet" href="../js/googleapis.css">
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="../plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="../bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="../font-awesome/css/font-awesome.min.css">
  <style>
  html, body {
  font-size: 88%; /* or 85% for relative scaling */  
}
.dataTables_filter {
  text-align: right !important;
}
</style>
</head>
<body class="hold-transition dark-mode sidebar-mini sidebar-collapse layout-fixed layout-navbar-fixed layout-footer-fixed">
<div class="wrapper">

  <!-- Preloader 
  <div class="preloader flex-column justify-content-center align-items-center">
    <img class="animation__wobble" src="dist/img/loading-logo.png" alt="AdminLTELogo" height="60" width="60">
  </div>-->

  <!-- Navbar -->
  <? include '../includes/navbar.php'; ?>    
  <!-- /.navbar -->

  <!-- Main Sidebar Container -->
  <? include '../includes/sidebar.php'; ?> 

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
                 
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="index.php">Home</a></li>
              <li class="breadcrumb-item active"><? print @$_GET['page']; ?></li>
            </ol>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>

    <!-- Main content -->
    <section class="content">

      <!-- Default box -->
      <div class="card">
        <div class="card-header">  

        </div>
        <div class="card-body">
          <div class="container-fluid">
            <div class="row">
              <div class="col-md-12">
                <div class="card" style="width:100%;">
                  <div class="card-body">  
<!-- ✅ CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/rowreorder/1.4.1/css/rowReorder.dataTables.min.css">

<style>
.reorder-handle {
  cursor: move;
  text-align: center;
}
</style>
</head>
<body class="p-4 bg-dark text-white">

<div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h1>Dissasembled Kits parts (Kit diss)</h1>
              </div>
              <div class="card-body">

  <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addKitModal">+ Add Kit</button>

  <table id="kitsTable" class="table table-bordered table-striped table-hover">
    <thead class="table-dark">
      <tr>
        <th style="display:none;">Position</th>
        <th style="width:30px;"><i class="fas fa-grip-lines"></i></th>
        <th>Date Time</th>
        <th>User</th>
        <th>Diss P/N</th>
        <th>Model</th>
        <th>Part</th>
        <th>Color</th>
        <th>Missing P/N</th>
        <th>For Model</th>
        <th>Missing Part</th>
        <th>Order No.</th>
        <th>Supplier</th>
        <th>Actions</th>
      </tr>
    </thead>
  </table>
</div>

<!-- ✅ Add Modal -->
<div class="modal fade" id="addKitModal" tabindex="-1" aria-labelledby="addKitLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="addKitForm">
        <div class="modal-header">
          <h5 class="modal-title" id="addKitLabel">Add Kit</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="user" value="<?php echo $_SESSION['name']; ?>">
          <div class="mb-3">
            <label>Kit P/N</label>
            <input type="text" name="barcode" class="form-control" required>
          </div>
          <div class="mb-3">
            <label>Missing part P/N</label>
            <input type="text" name="missing_barcode" class="form-control" required>
          </div>
          <div class="mb-3">
            <label>Order Number</label>
            <input type="text" name="order_number" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Add</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ✅ Delete Modal -->
<div class="modal fade" id="confirmDelete" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-body">Are you sure you want to delete this record?</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button id="deleteConfirmBtn" class="btn btn-danger">Delete</button>
      </div>
    </div>
  </div>
</div>
<script src="../plugins/jquery/jquery.min.js"></script>
<!-- ✅ JS -->
<script src="https://kit.fontawesome.com/a2e0e9f6fd.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/rowreorder/1.4.1/js/dataTables.rowReorder.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>


<script>
$(document).ready(function () {
  let deleteId = null;

  const table = $('#kitsTable').DataTable({
    ajax: '../scripts/fetch_kits.php',
    rowReorder: {
      selector: '.reorder-handle',
      dataSrc: 'position'
    },
    columns: [
      { data: 'position', visible: false },
      { data: null, className: 'reorder-handle text-center', orderable: false, defaultContent: '<i class="fas fa-grip-lines"></i>' },
      { data: 'timestamp' },
      { data: 'user' },
      { data: 'barcode' },
      { data: 'name' },
      { data: 'description' },
      { data: 'color' },
      { data: 'missing_barcode' },
      { data: 'missing_name' },
      { data: 'missing_description' },
      { data: 'order_number' },
      { data: 'main_supplier' },
      {
        data: 'id',
        render: data => `<button class="btn btn-sm btn-danger delete-btn" data-id="${data}">Delete</button>`
      }
    ]
  });

  // ✅ Handle drag reorder
  table.on('row-reorder', function (e, diff) {
    if (!diff.length) return;
    const updates = diff.map(d => ({
      id: table.row(d.node).data().id,
      position: d.newData
    }));
    console.log('Reorder:', updates);
    $.post('../scripts/update_kit_order.php', { updates: JSON.stringify(updates) });
  });

  // ✅ Add kit (demo only reloads)
  $('#addKitForm').submit(function (e) {
    e.preventDefault();
    $('#addKitModal').modal('hide');
    table.ajax.reload();
  });

  // ✅ Delete confirmation (demo only)
  $('#kitsTable').on('click', '.delete-btn', function () {
    deleteId = $(this).data('id');
    new bootstrap.Modal('#confirmDelete').show();
  });

 $('#deleteConfirmBtn').click(function () {
        $.post('../scripts/delete_kits.php', { id: deleteId }, () => {
          $('#confirmDelete').modal('hide');
          table.ajax.reload();
        });
      });
    });
</script>
            </div>
                </div>
              </div>
            </div>
          </div>  
        </div>
              </div>
      <!-- /.card -->

    </section>
    <!-- /.content -->
  </div>
  <!-- /.content-wrapper -->

  <footer class="main-footer">
    <div class="float-right d-none d-sm-block">
      <b>Version</b> 3.2.0
    </div>
    <strong>Copyright &copy; 2014-2026 <a href="https://scrubdesignz.com">SCRUBDESIGNZ</a>.</strong> All rights reserved.
  </footer>
<!-- Control Sidebar -->
  <aside class="control-sidebar control-sidebar-dark menu-open">
    <!-- Control sidebar content goes here -->
  </aside>
  <!-- /.control-sidebar -->
</div>
<!-- ./wrapper -->

<!-- jQuery -->


<!-- jQuery UI 1.11.4 -->
<script src="../plugins/jquery-ui/jquery-ui.min.js"></script>
<!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
<script>  $.widget.bridge('uibutton', $.ui.button)</script>
<!-- Bootstrap 4 -->
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- ChartJS -->
<script src="../plugins/chart.js/Chart.min.js"></script>
<!-- Sparkline -->
<script src="../plugins/sparklines/sparkline.js"></script>
<!-- JQVMap -->
<script src="../plugins/jqvmap/jquery.vmap.min.js"></script>
<script src="../plugins/jqvmap/maps/jquery.vmap.usa.js"></script>
<!-- jQuery Knob Chart -->
<script src="../plugins/jquery-knob/jquery.knob.min.js"></script>
<!-- daterangepicker -->
<script src="../plugins/moment/moment.min.js"></script>
<script src="../plugins/daterangepicker/daterangepicker.js"></script>
<!-- Tempusdominus Bootstrap 4 -->
<script src="../plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
<!-- Summernote -->
<script src="../plugins/summernote/summernote-bs4.min.js"></script>
<!-- overlayScrollbars -->
<script src="../plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
<!-- AdminLTE App -->
<script src="../dist/js/adminlte.js"></script>
<!-- AdminLTE for demo purposes -->
<script src="../dist/js/demo.js"></script>
<!-- AdminLTE dashboard demo (This is only for demo purposes) -->
<script src="../dist/js/pages/dashboard.js"></script>
<!-- DataTables  & Plugins -->
<script src="../plugins/datatables/jquery.dataTables.min.js"></script>
<script src="../plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="../plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
<script src="../plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
<script src="../plugins/datatables-buttons/js/dataTables.buttons.min.js"></script>
<script src="../plugins/datatables-buttons/js/buttons.bootstrap4.min.js"></script>
<script src="../plugins/jszip/jszip.min.js"></script>
<script src="../plugins/pdfmake/pdfmake.min.js"></script>
<script src="../plugins/pdfmake/vfs_fonts.js"></script>
<script src=../plugins/datatables-buttons/js/buttons.html5.min.js"></script>
<script src="../plugins/datatables-buttons/js/buttons.print.min.js"></script>
<script src="../plugins/datatables-buttons/js/buttons.colVis.min.js"></script>
<!-- Page specific script -->
 <?
include ('../js/datatable.js')
?>
</div>
</div>
</div>
</div>