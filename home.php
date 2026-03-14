<?php include 'includes/session.php'; ?>
<script src="./js/jquery-3.6.0.min.js"></script>
    <script src="./js/bootstrap.min.js"></script>
    <script src="./js/script.js"></script>

<?php 
  $today = date('Y-m-d');
  $year = date('Y'); 
  if(isset($_GET['year'])){
    $year = $_GET['year'];
  } 
  if(isset($_GET['page'])){
    $page = $_GET['page'];
  } else {
    $page = 'real_data';
  }
?>
<?php include 'includes/header.php'; ?>
<body class="hold-transition skin-blue sidebar-mini">
<div class="wrapper">

  	<?php include 'includes/navbar.php'; ?>
  	<?php include 'includes/menubar.php'; ?>

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <div class="preloader">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <h1>
        Dashboard
      </h1>
      <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
        <li class="active">Dashboard</li>
      </ol>
    </section>

    <!-- Main content -->
    <section class="content">
      <?php
        if(isset($_SESSION['error'])){
          echo "
            <div class='alert alert-danger alert-dismissible'>
              <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
              <h4><i class='icon fa fa-warning'></i> Error!</h4>
              ".$_SESSION['error']."
            </div>
          ";
          unset($_SESSION['error']);
        }
        if(isset($_SESSION['success'])){
          echo "
            <div class='alert alert-success alert-dismissible'>
              <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
              <h4><i class='icon fa fa-check'></i> Success!</h4>
              ".$_SESSION['success']."
            </div>
          ";
          unset($_SESSION['success']);
        }
      ?>
      <?
      // switch na vyber spravnych dlaždíc hore na vrchu.
      SWITCH ($page){
        case 'modeldata' : include ('includes/compat_tiles.php');
        break;
        default : include ('includes/tiles.php');
      }
      
      ?>
      
      <!-- /.row -->
      <div class="row">
        <div class="col-xs-12">
          <div class="box">            
            <div class="box-body">
              <!-- create table to show data -->
    <?

    // sem includovať $page
    include(''.$page.'.php');

    ?>
          </div>
        </div>
      </div>

      </section>
      <!-- right col -->
    </div>
  	<?php include 'includes/footer.php'; ?>
</div>
<!-- ./wrapper -->
</div>
<!-- ./preloader -->
<!-- Chart Data -->

<?php include 'includes/scripts.php'; ?>

</body>
</html>
