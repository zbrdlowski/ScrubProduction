<style>
  td {
  vertical-align: middle !important;
  text-align: center;
}
</style>
<section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">DataTable with minimal features & hover style</h3>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
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

            <div class="box-header with-border">
               <a href="#addnew" data-toggle="modal" class="btn btn-primary btn-sm btn-flat"><i class="fa fa-plus"></i> Pridaj</a>              
                         
            
<?php
if(empty($_GET['activedisp'])){$ActiveDisp = 'active';}else{$ActiveDisp = $_GET['activedisp'];}

switch($ActiveDisp){
 case 'all': $sql2 = "SELECT *, employees.id AS empid FROM employees"; 
 $allgombik = 'warning'; $activegombik = 'default'; $inactivegombik = 'default'; break;
 case 'inactive': $sql2 = "SELECT *, employees.id AS empid FROM employees WHERE active = 'Inactive' ORDER BY lastname ASC"; $gombik = 'success';
 $allgombik = 'default'; $activegombik = 'default'; $inactivegombik = 'warning';  break;  
 case 'active': $sql2 = "SELECT *, employees.id AS empid FROM employees WHERE active = 'Active' ORDER BY lastname ASC"; $gombik = 'success'; 
 $allgombik = 'default'; $activegombik = 'warning'; $inactivegombik = 'default';  break;    
}
     //echo '<a href="employee_print.php?activedisp='.$ActiveDisp.'" class="btn btn-success btn-sm btn-flat"><span class="glyphicon glyphicon-print"></span> Vytlačiť</a>';
     // selekcia zamestnancov 
     echo '&nbsp;&nbsp;';
     echo '<a href="index.php?page=employee&activedisp=active" class="btn btn-'.$activegombik.' btn-sm btn-flat"><span class="glyphicon glyphicon-user"></span>&nbsp;&nbsp;Aktívny zamestnanci</a>';
     echo '&nbsp;&nbsp;';
     echo '<a href="index.php?page=employee&activedisp=inactive" class="btn btn-'.$inactivegombik.' btn-sm btn-flat"><span class="glyphicon glyphicon-user"></span>&nbsp;&nbsp;Neaktívny zamestnanci</a>';
     echo '&nbsp;&nbsp;';
     echo '<a href="index.php?page=employee&activedisp=all" class="btn btn-'.$allgombik.' btn-sm btn-flat"><span class="glyphicon glyphicon-user"></span>&nbsp;&nbsp;Všetci zamestnanci</a>';
?></div><br />
<div class="table-wrapper">
            <table width="100%" id="example1" class="table table-bordered table-striped">
                <thead>
                  <th>Foto</th>
                  <th>ID Zamestnanca</th>                 
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
                      @$vek =  $now - $row['birthdate'];                 
                                         
                      SWITCH($row['permission']){
                        case 1 : $userpermitions = 'User';  break;     
                        case 300: $userpermitions = 'Moderator';  break; 
                        case 500: $userpermitions = 'Administrator';  break; 
                                              
                              };
                      
                      ?>
                        <tr>                          
                          <td align="center">                            
                               <img src="<?php echo (!empty(@$row['photo']))? 'images/'.@$row['photo']:'images/profile.jpg'; ?>" width="50px" height="50px">                                                       
                          </td>
                          <td><?php echo $row['employee_id']; ?></td>
                          <td><?php echo $row['firstname'].' '.$row['lastname']; ?></td>                                         
                          <td><?php echo date('M d, Y', strtotime($row['created_on'])) ?></td>
                          <td align="center"><?php echo $vek; ?></td>
                          <td align="center"><?php echo $row['active']; ?></td>
                          <td align="center"><?php echo $row['username']; ?></td>
                          <td align="center"><?php echo $userpermitions; ?></td>
                          <td align="center">
                            <?
                          // echo '<button class="btn btn-primary btn-sm edit btn-flat" data-id="'. $row['empid'].'"><i class="fa fa-edit"></i> Uprav</button>';
                           echo '&nbsp;&nbsp;';
                           echo '<a href="?page=employee_edit&user-id='.$row['empid'].'"><button type="button" class="btn bg-gradient-primary btn-sm edit-btn"><i class="fa fa-edit"></i> Uprav </button></a>';
                           echo '&nbsp;&nbsp;';
                            if($_SESSION['permission'] > 300){                              
                            //echo '<button class="btn btn-danger btn-sm delete btn-flat" data-id="'.$row['empid'].'"><i class="fa fa-trash"></i> Vymaž</button>';
                            }
                            ?>                            
                          </td>
                        </tr>
                      <?php
                    }
                  ?>               
                </table>
                </div>
               </tbody>
               </div>
<!-- /.box-body -->
            </div>
        </div>
<!-- /.card-body -->
        </div>
    </div>
</section>
<?php include 'includes/employee_modal.php'; ?>
<script>
$(function(){
  $(document).on('click', '.edit', function () {
  var id = $(this).data('id');
  getRow(id);
  $('#edit').modal('show'); // Optional if you're not using data-toggle
});

  $(document).on('click', '.delete', function () {
  var id = $(this).data('id');
  getRow(id);
  $('#delete').modal('show'); // Optional if you're not using data-toggle
});

 $(document).on('click', '.photo', function () {
  var id = $(this).data('id');
  $('.empid').val(id); // Set the hidden input value
  getRow(id);          // Load additional data if needed
  $('#edit_photo .empid').val(''); // Clear any old value
  $('#edit_photo .empid').val(id); // Set new value
  $('#edit_photo .employee_id').val(id); 
  $('#edit_photo').modal('show');
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
