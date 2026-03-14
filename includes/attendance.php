<?php include 'includes/session.php'; ?>
<?php include 'includes/header.php'; ?>
<body class="hold-transition skin-blue sidebar-mini">
<div class="wrapper">
<? $today = date('Y-m-d'); ?>
  <?php include 'includes/navbar.php'; ?>
  <?php include 'includes/menubar.php'; ?>
  <?php $redirect = $_SERVER['REQUEST_URI']; ?>
  
  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <h1>
        Dochádzka 
      </h1>
      <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Domov</a></li>
        <li class="active">Dochádzka</li>
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
      <div class="row">
        <div class="col-xs-12">
          <div class="box">
            <div class="box-header with-border">
              <a href="#addnew" data-toggle="modal" class="btn btn-primary btn-sm btn-flat"><i class="fa fa-plus"></i> Pridať</a>            
              <a href="#addovolenka" data-toggle="modal" class="btn btn-primary btn-sm btn-flat"><i class="fa fa-plus"></i> Pridať Dovolenku / Maródku</a>
            </div>
            <div class="box-body">
              <table id="example" class="table table-bordered">
                <thead>
                  <th class="hidden"></th>
                  <th>Foto</th>
                  <th>Dátum</th>
                  <th>ID Zamestnanca</th>                  
                  <th>Meno</th>
                  <th>Príchod</th>
                  <th>Odchod</th>
                  <th>Činnosť</th>
                  <th>Trvanie</th>
                  <th>Nástroje</th>
                </thead>
                <tbody>
                  <?php
                    // vypluje celú databázu 
                    //$sql = "SELECT *, employees.employee_id AS empid, ".$attdn_table.".id AS attid FROM ".$attdn_table." LEFT JOIN employees ON employees.id=".$attdn_table.".employee_id";

                    // vráti len 1000 vysledkov
                    $sql = "SELECT *, employees.employee_id AS empid, ".$attdn_table.".id AS attid FROM ".$attdn_table." LEFT JOIN employees ON employees.id=".$attdn_table.".employee_id ORDER BY ".$attdn_table.".date DESC, ".$attdn_table.".time_in DESC LIMIT 1000";
                    $query = $conn->query($sql);
                    while($row = $query->fetch_assoc()){ 
                      if($row['time_out'] == '23:59:59' AND $row['date'] != $today){
                        $status = '<span class="label label-danger pull-right">Problém</span>';
                      }else{
                        $status = '<span class="label label-warning pull-right">V Poriadku</span>';
                      }                                          
                      //$status = ($row['status'])?'<span class="label label-warning pull-right">ontime</span>':'<span class="label label-danger pull-right">late</span>';
                      echo "
                        <tr>
                          <td><img src='../images/".$row['photo']."' width='30px' height='30px'></td>
                          <td class='hidden'></td>
                          <td>".date('M d, Y', strtotime($row['date']))."</td>
                          <td>".$row['empid']."</td>                          
                          <td>".$row['firstname'].' '.$row['lastname']."</td>
                          <td>".date('H:i:s', strtotime($row['time_in'])).$status."</td>
                          <td>".date('H:i:s', strtotime($row['time_out']))."</td>
                          <td>";
                          SWITCH($row['movement']){
                            case 1: echo "Práca"; break;
                            case 2: echo  "Doma"; break;
                            case 3: echo  "Prestávka"; break;
                            case 4: echo  "Obed"; break;
                            case 5: echo  "Dovolenka"; break;
                            case 6: echo  "Maródka"; break;
                          }
                          echo "</td>";
                          echo "<td>";
                          $WorkingHours = strtotime($row['time_out'])-strtotime($row['time_in']);  echo date("H:i:s",$WorkingHours - 3600);
                          echo "</td> 
                          <td>
                            <button class='btn btn-success btn-sm btn-flat edit' data-id='".$row['attid']."'><i class='fa fa-edit'></i> Uprav</button>
                            <button class='btn btn-danger btn-sm btn-flat delete' data-id='".$row['attid']."'><i class='fa fa-trash'></i> Vymaž</button>
                          </td>
                        </tr>
                      ";
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
  <?php include 'includes/attendance_modal.php'; ?>
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
});

function getRow(id){
  $.ajax({
    type: 'POST',
    url: 'attendance_row.php',
    data: {id:id},
    dataType: 'json',
    success: function(response){
      $('#datepicker_edit').val(response.date);
      $('#attendance_date').html(response.date);
      $('#edit_time_in').val(response.time_in);
      $('#edit_time_out').val(response.time_out);
      $('#attid').val(response.attid);
      $('#employee_name').html(response.firstname+' '+response.lastname);
      $('#del_attid').val(response.attid);
      $('#edit_movement').val(response.movement);
      $('#del_employee_name').html(response.firstname+' '+response.lastname);
    }
  });
}
</script>
<?php include 'includes/datatable_initializer.php'; ?>
</body>
</html>
