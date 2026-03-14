<?php include 'includes/session.php'; ?>
<?php
  session_start();
  if($_SESSION['permission'] < 300){
    // ak nemá oprávnenie, kopne ho to domov
    header('location:home.php?page=modeldata');
  }
?>
<?php include 'includes/header.php'; ?>
<body class="hold-transition skin-blue sidebar-mini">
<div class="wrapper">

  <?php include 'includes/navbar.php'; ?>
  <?php include 'includes/menubar.php'; ?>

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <h1>
      Zoznam Zamestnancov
      </h1>
      <ol class="breadcrumb">
        <li><a href="index.php"><i class="fa fa-dashboard"></i> Domov</a></li>
        <li>Zamestnanci </li>
        <li class="active">Zoznam Zamestnancov</li>
      </ol>
    </section>
    <!-- Main content -->
    <section class="content">
      <?php
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
      <div class="row">
        <div class="col-xs-12">
          <div class="box">
            <div class="box-header with-border">
               <a href="#addnew" data-toggle="modal" class="btn btn-primary btn-sm btn-flat"><i class="fa fa-plus"></i> Pridaj</a>              
                         
            
<?php
if(empty($_GET['activedisp'])){$ActiveDisp = 'active';}else{$ActiveDisp = $_GET['activedisp'];}

switch($ActiveDisp){
 case 'all': $sql2 = "SELECT * FROM employees"; 
 $allgombik = 'warning'; $activegombik = 'default'; $inactivegombik = 'default'; break;
 case 'inactive': $sql2 = "SELECT * FROM employees WHERE active = 'Inactive' ORDER BY lastname ASC"; $gombik = 'success';
 $allgombik = 'default'; $activegombik = 'default'; $inactivegombik = 'warning';  break;  
 case 'active': $sql2 = "SELECT * FROM employees WHERE active = 'Active' ORDER BY lastname ASC"; $gombik = 'success'; 
 $allgombik = 'default'; $activegombik = 'warning'; $inactivegombik = 'default';  break;    
}
     echo '<a href="employee_print.php?activedisp='.$ActiveDisp.'" class="btn btn-success btn-sm btn-flat"><span class="glyphicon glyphicon-print"></span> Vytlačiť</a>';
                 // selekcia zamestnancov     
     echo '&nbsp;&nbsp;';
     echo '<a href="employee.php?activedisp=active" class="btn btn-'.$activegombik.' btn-sm btn-flat"><span class="glyphicon glyphicon-user"></span>&nbsp;&nbsp;Aktívny zamestnanci</a>';
     echo '&nbsp;&nbsp;';
     echo '<a href="employee.php?activedisp=inactive" class="btn btn-'.$inactivegombik.' btn-sm btn-flat"><span class="glyphicon glyphicon-user"></span>&nbsp;&nbsp;Neaktívny zamestnanci</a>';
     echo '&nbsp;&nbsp;';
     echo '<a href="employee.php?activedisp=all" class="btn btn-'.$allgombik.' btn-sm btn-flat"><span class="glyphicon glyphicon-user"></span>&nbsp;&nbsp;Všetci zamestnanci</a>';
?></div>
            <div class="box-body">
              <table id="example" class="table table-bordered">
                <thead>
                  <th>ID Zamestnanca</th>
                  <th>Foto</th>
                  <th>Meno</th>
                  <th>Zamestnaný od</th>
                  <th>Vek</th>
                  <th>Aktívny Y/N</th>
                  <th>Username</th>
                  <th>Permission</th>
                  <th>Nástroje</th>
                </thead>
                <tbody>
                  <?php

                    $now = date('Y-m-d');

                    //$sql = "SELECT *, employees.id AS empid FROM employees LEFT JOIN position ON position.id=employees.position_id LEFT JOIN schedules ON schedules.id=employees.schedule_id";
                    $query = $conn->query($sql2);


                    while($row = $query->fetch_assoc()){
                      $vek =  $now - $row['birthdate'];                 
                                         
                      SWITCH($row['permission']){
                        case 1 : $userpermitions = 'User';  break;     
                        case 300: $userpermitions = 'Moderator';  break; 
                        case 500: $userpermitions = 'Administrator';  break; 
                                              
                              };
                      
                      ?>
                        <tr>
                          <td><?php echo $row['employee_id']; ?></td>
                          <td><img src="<?php echo (!empty($row['photo']))? '../images/'.$row['photo']:'../images/profile.jpg'; ?>" width="30px" height="30px"> <a href="#edit_photo" data-toggle="modal" class="pull-right photo" data-id="<?php echo $row['empid']; ?>"><span class="fa fa-edit"></span></a></td>
                          <td><?php echo $row['firstname'].' '.$row['lastname']; ?></td>                                         
                          <td><?php echo date('M d, Y', strtotime($row['created_on'])) ?></td>
                          <td align="center"><?php echo $vek; ?></td>
                          <td align="center"><?php echo $row['active']; ?></td>
                          <td align="center"><?php echo $row['username']; ?></td>
                          <td align="center"><?php echo $userpermitions; ?></td>
                          <td align="center">
                            <button class="btn btn-success btn-sm edit btn-flat" data-id="<?php echo $row['id']; ?>"><i class="fa fa-edit"></i> Edit</button>
                            <?
                            if($_SESSION['permission'] > 300){                              
                              echo '<button class="btn btn-danger btn-sm delete btn-flat" data-id="'. $row['id'] .'"><i class="fa fa-trash"></i> Delete</button>';
                            }
                            ?>                            
                          </td>
                        </tr>
                      <?php
                    }
                  ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>   
  </div>
    
  <?php include 'includes/footer.php'; ?>
  <?php include 'includes/employee_modal.php'; ?>
</div>
<?php include 'includes/scripts.php'; ?>
<script>
$(function(){
  $('.edit').click(function(e){
    e.preventDefault();
    $('#edit').modal('show');
    var id = $(this).data('id');       
    getRow(id);    
  });

  $('.delete').click(function(e){
    e.preventDefault();
    $('#delete').modal('show');
    var id = $(this).data('id');
    getRow(id);
  });

  $('.photo').click(function(e){
    e.preventDefault();
    var id = $(this).data('id');
    getRow(id);
  });

});

function getRow(id){
  $.ajax({
    type: 'POST',
    url: 'employee_row.php',
    data: {id:id},
    dataType: 'json',
    success: function(response){
      $('.empid').val(response.id);
      $('.employee_id').html(response.employee_id);
      $('.del_employee_name').html(response.firstname+' '+response.lastname);
      $('#employee_name').html(response.firstname+' '+response.lastname);
      $('#edit_firstname').val(response.firstname);
      $('#edit_lastname').val(response.lastname);
      $('#edit_username').val(response.username);
      $('#edit_address').val(response.address);
      $('#datepicker_edit').val(response.birthdate);
      $('#edit_contact').val(response.contact_info);
      $('#gender_val').val(response.gender).html(response.gender);
      $('#position_val').val(response.position_id).html(response.description);
      $('#active_val').val(response.active).html(response.active);
      $('#personal_val').val(response.personal).html(response.personal);
      $('#schedule_val').val(response.schedule_id).html(response.time_in+' - '+response.time_out);      
    }
  });
}
</script>
<?php include 'includes/datatable_initializer.php'; ?>
</body>
</html>
