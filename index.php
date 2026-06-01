<?php

// pred session_start()
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 7); // 7 dní
//session_save_path('volume1/homes/admin/sessions'); // ← pridaj toto
session_set_cookie_params([
  'lifetime' => 60 * 60 * 24 * 7, // 7 dní
  'path' => '/',
  'httponly' => true,
  'samesite' => 'Lax',
]);

session_start();

if (!isset($_SESSION['permission'])) {
  header('location:login.php');
}
include 'includes/conn.php';

// Page title mapping - user-friendly names for breadcrumb
$pageLabels = [
  'plastics_dashboard' => 'Dashboard',
  'orders' => 'Orders',
  'order_prepare' => 'Order Queue',
  'scan_form' => 'Scan Items',
  'stock_levels' => 'Stock Levels',
  'items' => 'KP Gen',
  'pato_items' => 'KP Gen Komplet',
  'receiving' => 'Receiving',
  'suppliers' => 'Suppliers',
  'reports' => 'Reports',
  'settings' => 'Settings',
  'users' => 'Users',
  'profile' => 'My Profile',
  'kit_diss' => 'Kit Disassembly',
  'add_item' => 'Add New Item',
  'upload_items' => 'ADD / Upload Items',
  'shelves' => 'Shelves List',
  'display_stock' => 'Shelves / PN Stock Overview',
  'search_item' => 'Quick Search',
  'scan_form_out' => 'Scan Out',
  'bulk_scan_in' => 'Scan In',
  'reset_location' => 'Reset Location',
  'relocate_item' => 'Relocate Item',
  'plastics_orders_active' => 'Waiting Plastic Orders',
  'employee' => 'Employee Management',
  'controlls' => 'System Controls',
  'bulk_scan_in_2' => 'A010 Scan IN Form',
  'upload_csv' => 'Upload inventory CSV',
  'inventory_movements' => 'Inventory Movements',
  'plastics_orders_sent' => 'Sent Plastic Orders',
  'plastics_orders_all' => 'All Plastic Orders',
  'historical_movements' => 'Archived Movements',
  'order_prepare_form_out' => 'Prepare Outgoing Order',
  'intake_print_labels' => 'Print Intake Labels',
  'logs' => 'System Logs',
  'calendar' => 'Dochádzka',
  'orders_dashboard' => 'Orders Dashboard',
  'cleanup' => 'Cleanup',
  'holidays' => 'Dovolenky',
  'chat' => 'Interný chat',
  'shoptet_order_download' => 'Shoptet Order Download'

  // Add more as needed
];
?>


<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>
    <?php
    $page = @$_GET['page'];
    $pageTitle = isset($pageLabels[$page]) ? $pageLabels[$page] : ucfirst(str_replace('_', ' ', $page));
    echo $pageTitle ? $pageTitle . ' - Scrub Production' : 'Scrub Production';
    ?>
  </title>
  <!-- Google Font: Source Sans Pro -->
  <link rel="stylesheet" href="js/googleapis.css">
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="font-awesome/css/font-awesome.min.css">
  <script src="js/jquery-3.6.0.min.js"></script>
  <!-- Then Bootstrap 3 JS -->
  <script src="js/bootstrap.min.js"></script>
  <style>
    html,
    body {
      font-size: 88%;
      /* or 85% for relative scaling */
    }

    .dataTables_filter {
      text-align: right !important;
    }
  </style>
</head>
<script>
  $(document).ready(function () {

    // Highlight active link
    const active = $('.nav-link.active');

    if (active.length) {

      // Find parent menus and open them
      active
        .closest('.nav-item.has-treeview')
        .addClass('menu-open')
        .children('.nav-link')
        .addClass('active');
    }

  });
</script>

<body
  class="hold-transition dark-mode sidebar-mini sidebar-collapse layout-fixed layout-navbar-fixed layout-footer-fixed">
  <div class="wrapper">

    <!-- Preloader 
    <div class="preloader flex-column justify-content-center align-items-center">
      <img class="animation__wobble" src="dist/img/loading-logo.png" alt="Scrubdesignz Logo" height="60" width="60">
    </div>
-->
    <!-- Navbar -->
    <? include 'includes/navbar.php'; ?>
    <!-- /.navbar -->

    <!-- Main Sidebar Container -->
    <? include 'includes/sidebar.php'; ?>

    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
      <!-- Content Header (Page header) -->

      <!-- Main content -->
      <section class="content">

        <!-- Default box -->
        <div class="card">
          <div class="card-header">

          </div>
          <div class="card-body">
            <?
            if (!empty($_GET['page'])) {
              include 'includes/' . $_GET['page'] . '.php';
            } else {
              ?>
              <div class="container-fluid">
                <div class="row">
                  <div class="col-md-12">
                    <div class="card" style="width:100%;">
                      <div class="card-header">
                        <h2>Welcome</h2>
                      </div>
                      <div class="card-body">
                        New Content Here
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <?
            }
            ?>
          </div>
          <!-- /.card-body -->
          <!-- ukončenie hlavného tabu -->

          <!-- <div class="card-footer">
          Footerr
        </div> -->

          <!-- /.card-footer-->
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
      <strong>Copyright &copy; 2014-2026 <a href="https://scrubdesignz.com">SCRUBDESIGNZ</a>.</strong> All rights
      reserved.
    </footer>

    <!-- Control Sidebar -->
    <aside class="control-sidebar control-sidebar-dark menu-open">
      <!-- Control sidebar content goes here -->
    </aside>
    <!-- /.control-sidebar -->
  </div>
  <!-- ./wrapper -->

  <!-- jQuery -->
  <?
  if (isset($_GET['page']) && $_GET['page'] !== 'kit_diss') {
    echo '<script src="plugins/jquery/jquery.min.js"></script>';
  }
  ?>
  <!-- jQuery UI 1.11.4 -->
  <script src="plugins/jquery-ui/jquery-ui.min.js"></script>
  <!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
  <script>  $.widget.bridge('uibutton', $.ui.button)</script>
  <!-- Bootstrap 4 -->
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <!-- ChartJS -->
  <script src="plugins/chart.js/Chart.min.js"></script>
  <!-- Sparkline -->
  <script src="plugins/sparklines/sparkline.js"></script>
  <!-- JQVMap -->
  <script src="plugins/jqvmap/jquery.vmap.min.js"></script>
  <script src="plugins/jqvmap/maps/jquery.vmap.usa.js"></script>
  <!-- jQuery Knob Chart -->
  <script src="plugins/jquery-knob/jquery.knob.min.js"></script>
  <!-- daterangepicker -->
  <script src="plugins/moment/moment.min.js"></script>
  <script src="plugins/daterangepicker/daterangepicker.js"></script>
  <!-- Tempusdominus Bootstrap 4 -->
  <script src="plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
  <!-- Summernote -->
  <script src="plugins/summernote/summernote-bs4.min.js"></script>
  <!-- overlayScrollbars -->
  <script src="plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
  <!-- AdminLTE App -->
  <script src="dist/js/adminlte.js"></script>
  <!-- AdminLTE for demo purposes -->
  <script src="dist/js/demo.js"></script>
  <!-- AdminLTE dashboard demo (This is only for demo purposes) -->
  <script src="dist/js/pages/dashboard.js"></script>


  <!-- DataTables  & Plugins -->
  <script src="plugins/datatables/jquery.dataTables.min.js"></script>
  <script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
  <script src="plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
  <script src="plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
  <script src="plugins/datatables-buttons/js/dataTables.buttons.min.js"></script>
  <script src="plugins/datatables-buttons/js/buttons.bootstrap4.min.js"></script>
  <script src="plugins/jszip/jszip.min.js"></script>
  <script src="plugins/pdfmake/pdfmake.min.js"></script>
  <script src="plugins/pdfmake/vfs_fonts.js"></script>
  <script src="plugins/datatables-buttons/js/buttons.html5.min.js"></script>
  <script src="plugins/datatables-buttons/js/buttons.print.min.js"></script>
  <script src="plugins/datatables-buttons/js/buttons.colVis.min.js"></script>

  <!-- Page specific script -->
  <?
  include('js/datatable.js')
    ?>
  <style>
    /* ✅ Tempus Dominus timepicker: center 09 : 09 : 32 under arrows */
    .bootstrap-datetimepicker-widget .timepicker-picker table td,
    .bootstrap-datetimepicker-widget .timepicker-picker table th {
      text-align: center !important;
      vertical-align: middle !important;
      padding-left: 0 !important;
      padding-right: 0 !important;
    }

    /* KEY FIX: spans must not be inline, or width won't apply */
    .bootstrap-datetimepicker-widget .timepicker-hour,
    .bootstrap-datetimepicker-widget .timepicker-minute,
    .bootstrap-datetimepicker-widget .timepicker-second {
      display: inline-block !important;
      /* <-- THIS fixes your screenshot */
      width: 54px !important;
      /* keep official width */
      text-align: center !important;
      margin: 0 auto !important;
    }

    /* Make ":" column not push layout oddly */
    .bootstrap-datetimepicker-widget td.separator {
      width: 16px !important;
      padding: 0 !important;
      text-align: center !important;
    }

    /* Center the arrow buttons too */
    .bootstrap-datetimepicker-widget .timepicker-picker a.btn {
      display: inline-flex !important;
      justify-content: center !important;
      align-items: center !important;
      width: 54px !important;
      /* match number width */
      color: #e4e6eb !important;
    }
  </style>
</body>
<script>
  $(document).ready(function () {

    // Mark active links
    const activeLink = $('.nav-link.active');

    if (activeLink.length) {
      // Open all parent treeviews
      activeLink.parents('li.has-treeview').addClass('menu-open');

      // Highlight parent headers
      activeLink.parents('li.has-treeview').children('.nav-link').addClass('active');
    }

  });
</script>

</html>
