<style>
.datepicker,
.bootstrap-timepicker-widget {
  z-index: 2000 !important;
}
.datepicker-dropdown {
  background: #fff !important;
}
/* DARK MODE ROW COLORING */

.table-dark-row-work {
  background-color: rgba(0, 166, 90, 0.18) !important;   /* greenish */
}

.table-dark-row-warning {
  background-color: rgba(0, 104, 239, 0.18) !important;  /* blueish */
}

.table-dark-row-break {
  background-color: rgba(243, 221, 18, 0.2) !important; /* yellowish */
}

/* subtle hover improvement */
.table tbody tr.table-dark-row-work:hover,
.table tbody tr.table-dark-row-break:hover,
.table tbody tr.table-dark-row-warning:hover {
  background-color: rgba(255,255,255,0.06) !important;
}
</style>
<?php 
include 'sviatky.php'; 
  session_start();
  ?>
<body class="hold-transition skin-blue sidebar-mini">
<div class="wrapper">
<? $today = date('Y-m-d'); ?>
<? $year = $_REQUEST['year']; ?>

  <?php $redirect = $_SERVER['REQUEST_URI']; ?>
  <!-- Content Wrapper. Contains page content -->

    <!-- Content Header (Page header) -->
    <section class="content-header">
      <h1>
        Detaily Dochádzky dňa <?php echo date('d.m.Y', strtotime($_GET['date'])); ?> 
      </h1>     
    </section>
    <!-- Main content -->
    <section class="content">
      <?php
        if(isset($_SESSION['error'])){
          echo "
            <div class='alert alert-danger alert-dismissible'>
              <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
              <h4><i class='icon fa fa-warning'></i> Chyba!</h4>
              ".$_SESSION['error']."
            </div>
          ";
          unset($_SESSION['error']);
        }
        if(isset($_SESSION['success'])){
          echo "
            <div class='alert alert-success alert-dismissible'>
              <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
              <h4><i class='icon fa fa-check'></i> Podarilo sa !</h4>
              ".$_SESSION['success']."
            </div>
          ";
          unset($_SESSION['success']);
        }
      ?>
      <div class="row">
        <div class="col-md-12">
          <div class="box box-primary">
            <div class="box-header with-border">              
              <div class="box-tools pull-right">
                <a href="#addnew" data-toggle="modal" class="btn btn-success btn-sm btn-flat"><i class="fa fa-plus"></i> Pridať</a>
                <a href="#addovolenka" data-toggle="modal" class="btn btn-info btn-sm btn-flat"><i class="fa fa-plus"></i> Dovolenka / Maródka</a>
                <a href="?page=calendar&eno=<?php echo $_GET['eno']; ?>&year=<?php echo $_GET['year']; ?>&month=<?php echo $_GET['month']; ?>&activedisp=<?php echo $_GET['activedisp']; ?>" class="btn btn-warning btn-sm btn-flat"><i class="fa fa-arrow-left"></i> Späť na prehľad</a>
              </div>
            </div>
            <br /><br/>
            <!-- user personal info -->
            <?php include 'includes/userbanner.php'; ?>
            <!--  datatabledirectly under the banner -->
            <div class="box-body">
                <table id="example7" class="table table-bordered table-hover" style="width: 100%; margin-bottom: 0;">
                <thead>
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
                    $sql = "SELECT *, employees.employee_id AS empid, ".$attdn_table.".id AS attid FROM ".$attdn_table." LEFT JOIN employees ON employees.id=".$attdn_table.".employee_id WHERE ".$attdn_table.".employee_id = '".$_GET['eno']."' AND ".$attdn_table.".date = '".$_GET['date']."' ORDER BY ".$attdn_table.".date DESC, ".$attdn_table.".time_in ASC";
                    $query = $conn->query($sql);
                    while($row = $query->fetch_assoc()){ 
                      $rowClass = '';

                      switch($row['movement']){
                          case 1: // Práca
                              $rowClass = 'table-dark-row-work';
                              break;

                          case 3: // Prestávka
                              $rowClass = 'table-dark-row-break';
                              break;

                          case 4: // Obed                         
                              $rowClass = 'table-dark-row-warning';
                              break;
                      }
                                                               
                      //$status = ($row['status'])?'<span class="label label-warning pull-right">ontime</span>':'<span class="label label-danger pull-right">late</span>';
                      echo "<tr class='".$rowClass."'>                                                  
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
                            case 6: echo  "Lekár"; break;
                          }
                          echo "</td>";
                          echo "<td>";
                          $WorkingHours = strtotime($row['time_out'])-strtotime($row['time_in']);  echo date("H:i:s",$WorkingHours - 3600);
                          echo "</td> 
                          <td align='center'>
                            <button class='btn btn-success btn-sm btn-flat edit' data-id='".$row['attid']."'><i class='fa fa-edit'></i> Uprav</button>
                            <button class='btn btn-danger btn-sm btn-flat delete' data-id='".$row['attid']."'><i class='fa fa-trash'></i> Vymaž</button>
                          </td>
                        </tr>";
                    }
                  ?>                  
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>   
   
  <?php include 'includes/attendance_modal.php'; ?>
</div>

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
    url: 'scripts/attendance_row.php',
    data: {id:id,year:<?echo $_REQUEST['year'];?>},
    dataType: 'json',
    success: function(response){
      // Date: prefer tempusdominus if available, fallback to input value
      if (typeof $.fn.datetimepicker !== 'undefined' && $('#datepicker_edit').length) {
        if (response.date) {
          $('#datepicker_edit').datetimepicker('date', moment(response.date, 'YYYY-MM-DD'));
        } else {
          $('#datepicker_edit').datetimepicker('clear');
        }
      } else {
        $('#datepicker_edit input').val(response.date);
      }
      $('#attendance_date').html(response.date);

      // Time in
      if (typeof $.fn.datetimepicker !== 'undefined' && $('#time_in_edit').length) {
        if (response.time_in) {
          $('#time_in_edit').datetimepicker('date', moment(response.time_in, 'HH:mm:ss'));
        } else {
          $('#time_in_edit').datetimepicker('clear');
        }
      } else {
        $('#time_in_edit input').val(response.time_in);
      }

      // Time out
      if (typeof $.fn.datetimepicker !== 'undefined' && $('#time_out_edit').length) {
        if (response.time_out) {
          $('#time_out_edit').datetimepicker('date', moment(response.time_out, 'HH:mm:ss'));
        } else {
          $('#time_out_edit').datetimepicker('clear');
        }
      } else {
        $('#time_out_edit input').val(response.time_out);
      }
      $('#attid').val(response.attid);
      $('#employee_name').html(response.firstname+' '+response.lastname);
      $('#del_attid').val(response.attid);
      $('#edit_movement').val(response.movement);
      $('#del_employee_name').html(response.firstname+' '+response.lastname);
    }
    ,
    error: function(xhr, status, err) {
      console.error('getRow AJAX error:', status, err, xhr.responseText);
      alert('Chyba načítania záznamu. Skontrolujte konzolu.');
    }
  });
}
</script>
</script>
</body>
</html>
