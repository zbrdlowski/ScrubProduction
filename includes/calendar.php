<style>
/* Calendar row coloring */
.table > tbody > tr.work-day > td {
    color: #e6ffe6;
}

.table > tbody > tr.day-off > td {
    background-color: #4d4d4d !important;
    color: #ffe8d1;
}

.table-hover > tbody > tr.work-day:hover > td {
    background-color: #275027 !important;
}

.table-hover > tbody > tr.day-off:hover > td {
    background-color: #4a3526 !important;
}

/* Toolbar alignment – AdminLTE + Bootstrap 3 */
.calendar-toolbar .btn,
.calendar-toolbar .form-control {
    height: 30px;              /* match btn-sm */
    padding-top: 4px;
    padding-bottom: 4px;
    vertical-align: middle;
    margin-right: 6px;
}

/* IMPORTANT: do NOT force line-height on buttons */
.calendar-toolbar .btn {
    line-height: normal;       /* let Bootstrap center text */
}

/* Fix select text alignment */
.calendar-toolbar .form-control {
    line-height: 1.42857143;
}

/* Icon alignment */
.calendar-toolbar .btn i {
    vertical-align: middle;
}

.calendar-toolbar .btn-group {
    margin-right: 6px;
}
/* Equal height columns for profile + edit blocks */
.row-eq-height {
  display: flex;
  flex-wrap: wrap;
}

.row-eq-height > [class*="col-"] {
  display: flex;
  flex-direction: column;
}

/* Make cards fill full column height */
.row-eq-height .card,
.row-eq-height .box {
  flex: 1 1 auto;
}
/* Attendance rows that need manual check */
.table > tbody > tr.attendance-error > td {
    background-color: #f02020 !important;
    /*color: #ffd6d6 !important;*/
    color: #0a0a0a !important;
}

.table-hover > tbody > tr.attendance-error:hover > td {
    background-color: #f02020 !important;
}
</style>

<?php include 'sviatky.php'; ?>
<div class="wrapper">
<? $today = date('Y-m-d'); ?>
  <?php $redirect = $_SERVER['REQUEST_URI']; ?>

    <!-- Main content -->
    <section class="content">
     <?
     $url = "$_SERVER[REQUEST_URI]";
     $url = strrchr( $url, '?');
     //$url = AFTER('?',$url);
     $_SESSION['calendar_url'] = $url;
     if(empty($_GET['year'])){$Year = date('Y');}else{$Year = $_GET['year'];}
     if(empty($_GET['month'])){$Month = date('m');}else{$Month = $_GET['month'];}
     $ActiveDisp = $_GET['activedisp'] ?? 'active';
     if (!in_array($ActiveDisp, ['active', 'inactive', 'all'], true)) {
       $ActiveDisp = 'active';
     }

     $attendanceWhere = [
       'active' => "attendance_enabled = 1 AND active = 'Active'",
       'inactive' => "active = 'Inactive'",
       'all' => "attendance_enabled = 1 OR active = 'Inactive'"
     ];
     $sql2 = "SELECT id, firstname, lastname, active
              FROM employees
              WHERE " . $attendanceWhere[$ActiveDisp] . "
              ORDER BY lastname ASC";
     $activegombik = ($ActiveDisp === 'active') ? 'warning' : 'default';
     $inactivegombik = ($ActiveDisp === 'inactive') ? 'warning' : 'default';
     $allgombik = ($ActiveDisp === 'all') ? 'warning' : 'default';


     $VelkaNedela = date("d-m", easter_date($Year));
     $VelkyPiatok = date("d-m", easter_date($Year)-172800);
     $VelkyPondelok = date("d-m", easter_date($Year)+86400);

     $eno = isset($_GET['eno']) ? (int)$_GET['eno'] : 0; // zamestnanec
     $attendanceSelectionError = false;
     if ($eno > 0) {
       $attendanceEmployee = $conn->query(
         "SELECT id FROM employees
          WHERE id = " . $eno . "
            AND (attendance_enabled = 1 OR active = 'Inactive')
          LIMIT 1"
       );
       if (!$attendanceEmployee || $attendanceEmployee->num_rows === 0) {
         $eno = 0;
         unset($_GET['eno']);
         $attendanceSelectionError = true;
       }
     }
     $date = date('Y-m-d'); // aktualny den
     $ThisYear =  date( 'Y', strtotime($date)); // aktualny Rock
     $Thismonth =  date( 'm', strtotime($date)); // Tentok Mesiac - číselne 01,02,03 etc..
     $PreviousMonth = date( 'm', strtotime( $date . '-1 Month' ) ); //predošlý mesiac
     $PreviousMonthName = date( 'F', strtotime( $date . '-1 Month' ) ); // predošlý mesiac názov
     $GetMonthName = date( 'F', strtotime($Month)); // Názov mesiaca načítaného cez $_GET['month']
     $ThisMonthName = date( 'F', strtotime($date)); // Názov aktuáneho mesiaca
     $number = cal_days_in_month(CAL_GREGORIAN, $Month, $Year); // výpočet dní v mesiaci načítanom cez $_GET['month']
     //$DayToCalculate = $ThisYear.'-'.$PreviousMonth; // reťazec na počítanie. Už ani neviem načo som to robil
     $DayToTest = $Year.'-'.$Month; // toto je podobný prípad.


     $menosql = "SELECT firstname,lastname FROM employees WHERE id = '$eno'"; // načítame zamestnanca
     $menoquery = $conn->query($menosql);
     while($menorow = $menoquery->fetch_assoc()){
      $MenoExcel = $menorow['firstname'] .' '. $menorow['lastname'];
     }
if ($attendanceSelectionError) {
  echo '<div class="alert alert-warning"><i class="fa fa-info-circle"></i> Tento pracovník nemá zapnutú dochádzku a nie je možné mu spracovať výkaz.</div>';
}

echo '<div class="box box-solid">';
echo '  <div class="box-header with-border">';

echo '    <h3 class="box-title"><i class="fa fa-calendar"></i> Detail dochádzky</h3>';

echo '      <div class="btn-group" style="gap:8px; margin-right:10px; margin-left:10px;">';
echo '        <a href="index.php?page=calendar&year='.$Year.'&month='.$Month.'&activedisp=active" class="btn btn-'.$activegombik.' btn-flat"><i class="fa fa-user"></i> Aktívni</a>';
echo '        <a href="index.php?page=calendar&year='.$Year.'&month='.$Month.'&activedisp=inactive" class="btn btn-'.$inactivegombik.' btn-flat"><i class="fa fa-user-times"></i> Vyradení</a>';
echo '        <a href="index.php?page=calendar&year='.$Year.'&month='.$Month.'&activedisp=all" class="btn btn-'.$allgombik.'  btn-flat"><i class="fa fa-users"></i> Všetci</a>';

echo '      <form class="form-inline" style="display:inline-block; vertical-align:middle; margin:0;">';
// Employee select
echo '        <select class="form-control input-sm" id="eno" name="eno" style="width:210px; display:inline-block;" ';
echo '          onchange="this.value && (window.location=this.value);">';
echo '<option value="">Vyber zamestnanca</option>';
$query2 = $conn->query($sql2);
while($row2 = $query2->fetch_array()){
  $sel = ((string)$row2['id'] === (string)$eno) ? ' selected' : '';
  echo '<option value="index.php?page=calendar&eno='.$row2['id'].'&year='.$Year.'&month='.$Month.'&activedisp='.$ActiveDisp.'"'.$sel.'>'
      .$row2['lastname'].' '.$row2['firstname'].
      '</option>';
}
echo '        </select> ';

// Month select
echo '        <select class="form-control input-sm" id="month" name="month" style="width:140px; display:inline-block;" ';
echo '          onchange="this.value && (window.location=this.value);">';
foreach ($mesiace as $key => $value) {
  $sel = ((string)$key === (string)$Month) ? ' selected' : '';
  echo '<option value="index.php?page=calendar&eno='.$eno.'&year='.$Year.'&month='.$key.'&activedisp='.$ActiveDisp.'"'.$sel.'>'.$value.'</option>';
}
echo '        </select> ';

// Year select
echo '        <select class="form-control input-sm" id="year" name="year" style="width:95px; display:inline-block;" ';
echo '          onchange="this.value && (window.location=this.value);">';
$yearsql = "SHOW TABLES FROM scrubproduction";  // <-- your DB name
$yearquery = $conn->query($yearsql);
while($yearow = $yearquery->fetch_array()){
  if (substr($yearow[0],0,6) === 'attdn_') {
    $yearloop = substr($yearow[0], -4);
    $sel = ((string)$Year === (string)$yearloop) ? ' selected' : '';
    echo '<option value="index.php?page=calendar&eno='.$eno.'&year='.$yearloop.'&month='.$Month.'&activedisp='.$ActiveDisp.'"'.$sel.'>'.$yearloop.'</option>';
  }
}
echo '        </select>';
echo '      </form>';

if (isset($_GET['eno'])){
// LEFT: action buttons
echo '      <a href="#addnew" data-toggle="modal" class="btn btn-primary btn-flat"><i class="fa fa-plus"></i> Pridať</a> ';
echo '      <a href="#addovolenka" data-toggle="modal" class="btn btn-primary btn-flat"><i class="fa fa-plus"></i> Dovolenka / Maródka</a> ';
echo '      <a href="scripts/attendance_print.php?eno='.$eno.'&year='.$Year.'&month='.$Month.'" target="top" class="btn btn-success btn-flat"><i class="fa fa-print"></i> Vytlačiť</a> ';
echo '      <a href="scripts/excel.php?eno='.$eno.'&year='.$Year.'&month='.$Month.'&menoexcel='.$MenoExcel.'" target="top" class="btn btn-success btn-flat"><i class="fa fa-file-excel-o"></i> Excel Raw</a> ';
echo '      <a href="scripts/excel_detail.php?eno='.$eno.'&year='.$Year.'&month='.$Month.'&menoexcel='.$MenoExcel.'" target="top" class="btn btn-success btn-flat"><i class="fa fa-file-excel-o"></i> Excel Detail</a> ';

 }
 
echo '      </div>';
echo '<br /><br />';
echo '    </div>'; // pull-right
echo '    <div class="clearfix"></div>';
echo '  </div>'; // box-header
echo '  <div class="box-body">'; 
// user banner 
include 'includes/userbanner.php';

     if(!empty($_GET['eno'])){
    
     $sql = "SELECT * FROM employees WHERE id = '$eno'"; // načítame zamestnanca
     $query = $conn->query($sql);
     while($row = $query->fetch_assoc()){
      if($row['active'] == 'Inactive'){$SelectBg = "silver";}
      if($row['active'] == 'Active'){$SelectBg = "green";}
      
      // nacitame schedule pre konkretneho zamestnanca
      $ScheduleSQL = "SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS schedulediff, time_in, time_out FROM schedules WHERE id = ".$row['schedule_id']."";      
      $ScheduleQuery = $conn->query($ScheduleSQL);
      while($Schedulerow = $ScheduleQuery->fetch_assoc()){
        // $ScheduleDiff -> pracovny cas zo schedules v sekundach
        $ScheduleDiff = $Schedulerow['schedulediff']-1800; // minus obed
        $PracovnaDoba = $Schedulerow['time_in'].' - '.$Schedulerow['time_out']; // kvoli výpisu v tabulke s userom
        $StartPraca = $Schedulerow['time_in'];
        $EndPraca = $Schedulerow['time_out'];
      }    

        SWITCH($row['gender']){ 
            case 'Male': $pohlavie = 'Narodený'; break;
            case 'Female': $pohlavie = 'Narodená'; break;
          }


        
       // print '<tr><td>'.$row['gender'].'</td></tr>';
      }
    }
     //------------------------------

     echo '<hr />';
   echo '<div class="table-responsive">';
echo '<table id="calendar_table" class="table table-bordered table-hover">';

echo '<thead>';
echo '<tr>';
echo '<th>Deň</th>';
echo '<th>Čo za deň</th>';
echo '<th>Práca</th>';
echo '<th>Obed</th>';
echo '<th>Prestávky</th>';
echo '<th>Dovolenky</th>';
echo '<th>Práceneschopnosť</th>';
echo '<th>Saldokonto</th>';
echo '<th>Editácia</th>';
echo '</tr>';
echo '</thead>';

echo '<tbody>';

     for($i=01; $i <= $number; $i++){ // slovenske názvy dní
      SWITCH(date('l', strtotime($DayToTest.'-'.$i))){
        case 'Monday' : $SlovakDay = 'Pondelok'; break;
        case 'Tuesday' : $SlovakDay = 'Utorok'; break;
        case 'Wednesday' : $SlovakDay = 'Streda'; break;
        case 'Thursday' : $SlovakDay = 'Štvrtok'; break;
        case 'Friday' : $SlovakDay = 'Piatok'; break;
        case 'Saturday' : $SlovakDay = 'Sobota'; break;
        case 'Sunday' : $SlovakDay = 'Nedeľa'; break;
    }
   
    if(strlen($i) == 1){ //
        $i_display = '0'.$i;
    }else{
        $i_display = $i;
    }
    $lumpsum = 0;
    $CasovyFond = 0;
    $edit = FALSE;
    $volnyDen = FALSE;
    $TrebaObed = FALSE;
    if(in_array($i_display."-".$Month, $sviatky)) {
      $volnyDen = TRUE;
    }
    // skusam velku noc
    if ($i_display."-".$Month == $VelkyPiatok || $i_display."-".$Month == $VelkyPondelok){
      $volnyDen = TRUE;
    }
    if(date('N', strtotime($DayToTest.'-'.$i)) > '5'){
      $volnyDen = TRUE;  
    }

$rowClass = $volnyDen ? 'day-off' : 'work-day';
$currentDate = $Year . '-' . $Month . '-' . $i_display;
$isToday = ($currentDate === $today);
$hasAttendanceError = false;

if (!$isToday && !empty($eno)) {
    $ErrorSQL = "SELECT COUNT(*) AS error_count
        FROM `" . $attdn_table . "`
        WHERE employee_id = '$eno'
          AND date = '" . $currentDate . "'
          AND (
                TIME(time_in) = '00:00:00'
                OR TIME(time_out) = '23:59:59'
          )
    ";
    $ErrorQuery = $conn->query($ErrorSQL);
    if ($ErrorQuery && $ErrorRow = $ErrorQuery->fetch_assoc()) {
        $hasAttendanceError = ((int)$ErrorRow['error_count'] > 0);
    }
}

$rowClass = $volnyDen ? 'day-off' : 'work-day';
if ($hasAttendanceError) {
    $rowClass .= ' attendance-error';
}

echo '<tr class="'.$rowClass.'">';     
    echo '<td width="1%">' . $i_display . '</td>';
    echo '<td width="14%">' . $SlovakDay. '</td>';
        echo '<td width="14%" align="center">';
        // odpracovaný čas
        $ZratajCas =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i_display."' AND movement = '1'") or die(mysqli_error($conn));                       
        if($ZratajCas->num_rows > 0){
            while($row = $ZratajCas->fetch_array()){
                $edit = TRUE;
                if(date('H:i:s', $row['sucet']-3600) != '00:00:00') {
                    $lumpsum = $lumpsum + $row['sucet'];                    
                    $CasovyFond = $CasovyFond + $row['sucet'];                    
                    if($CasovyFond > 21600){$TrebaObed = TRUE;}
                    if($CasovyFond > 43200){echo '<strong>' . gmdate('H:i:s', $CasovyFond) . '</strong>';}
                    else
                    {echo gmdate('H:i:s', $CasovyFond);}                 
                echo '</td>';                
                } else{
                    echo '--<br />';
                    $edit = FALSE;                    
                    echo '</td>';
                }                            
            }
        }
        echo '<td width="14%" align="center">';
        // čas strávený na obede
        $ZratajObed =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i_display."' AND movement = '4'") or die(mysqli_error($conn));                       
        if($ZratajObed->num_rows > 0){
            while($ObedRow = $ZratajObed->fetch_array()){
                $edit = TRUE;
                if(date('H:i:s', $ObedRow['sucet']-3600) != '00:00:00') {
                    $lumpsum = $lumpsum - $ObedRow['sucet'];
                echo gmdate('H:i:s', $ObedRow['sucet']); 
                echo '</td>';
                } else{
                    echo '--';
                    $edit = FALSE;
                    echo '</td>';
                }                            
            }
        }
        echo '<td width="14%" align="center">';
        // čas strávený na prestávke
        $ZratajCiga =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i_display."' AND movement = '3'") or die(mysqli_error($conn));                       
        if($ZratajCiga->num_rows > 0){
            while($CigaRow = $ZratajCiga->fetch_array()){
                $edit = TRUE;
                if(date('H:i:s', $CigaRow['sucet']-3600) != '00:00:00') {
                    $lumpsum = $lumpsum - $CigaRow['sucet'];
                echo gmdate('H:i:s', $CigaRow['sucet']); 
                echo '</td>';
                } else{
                    echo '--';
                    $edit = FALSE;
                    echo '</td>';
                }                            
            }
        }
        echo '<td width="14%" align="center">';
        // dovolenky
        $ZratajDovca =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i_display."' AND movement = '5'") or die(mysqli_error($conn));                       
        if($ZratajDovca->num_rows > 0){
            while($DovcaRow = $ZratajDovca->fetch_array()){
                $edit = TRUE;
                if(date('H:i:s', $DovcaRow['sucet']-3600) != '00:00:00') {
                  // cap vacation to scheduled working time (PracovnaDoba)
                  $DovcaDisplay = $DovcaRow['sucet'];
                  if (isset($ScheduleDiff) && $DovcaDisplay > $ScheduleDiff) { $DovcaDisplay = $ScheduleDiff; }
                  $lumpsum = $lumpsum + $DovcaDisplay; 
                  if($CasovyFond > 21600){$TrebaObed = TRUE;}
                  $CasovyFond = $CasovyFond + $DovcaDisplay;
                //treba odrátať obed, ináč to hádže + pol hodiny
                if($TrebaObed == TRUE){
                  $CasovyFond = $CasovyFond - 1800;
                  $TrebaObed = FALSE;
                }
              echo gmdate('H:i:s', $DovcaDisplay);  
                echo '</td>';
                } else{
                    echo '--';
                    $edit = FALSE;
                    echo '</td>';
                }                            
            }
        }
        echo '<td width="14%" align="center">';
        // Maródky
        $ZratajMarod =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i_display."' AND movement = '6'") or die(mysqli_error($conn));                       
        if($ZratajMarod->num_rows > 0){
            while($MarodRow = $ZratajMarod->fetch_array()){
                $edit = TRUE;
                if(date('H:i:s', $MarodRow['sucet']-3600) != '00:00:00') {
                  $lumpsum = $lumpsum + $MarodRow['sucet'];
                  $CasovyFond = $CasovyFond + $MarodRow['sucet'];
                  if($CasovyFond > 21600){$TrebaObed = TRUE;
                  $MarodDisplay = $MarodRow['sucet'];
                If($MarodDisplay == 30600){
                  $MarodDisplay = 28800;
                }}
                  //treba odrátať obed, ináč to hádže + pol hodiny
                  
                  if($TrebaObed == TRUE){
                    $CasovyFond = $CasovyFond - 1800;
                    $TrebaObed = FALSE;
                  }
                    
                echo gmdate('H:i:s', $MarodDisplay); 
                echo '</td>';
                } else{
                    echo '--';
                  $edit = FALSE;
                    echo '</td>';
                }                            
            }
        }
        /*
        echo'<td align="center">';
        if(date('H:i:s', $lumpsum-3600) != '00:00:00'){
            echo date('H:i:s', $lumpsum);
        }else{
            echo '--';
        }

        */
        echo '</td>';       
        echo'<td width="14%" align="center">';
        // Ak máme dáta
        if(!empty($CasovyFond)){
          // editácia true, aby nám tam hodilo tlačítko "Skontroluj"
            $edit = TRUE;
        // Ak je odpracovaný čas väčší ako predpis
        if($CasovyFond > $ScheduleDiff){
            echo '<font color="#00ff00">'.gmdate('\+ H:i:s', (($CasovyFond - $ScheduleDiff))).'</font>';
        }else{
          // Ak je volný deň alebo weekend
          if($volnyDen == TRUE){
            echo '<font color="#00ff00">'.gmdate('\+ H:i:s', (($CasovyFond))).'</font>';
          }else{
            // Ak je odpracovaný čas presne ako predpis - bude to čierne a bez znamienka + alebo -
            if(($CasovyFond - $ScheduleDiff) == 0){
              echo '<font color="white">'.gmdate('\ H:i:s', (($CasovyFond - $ScheduleDiff))).'</font>';
            }else{
              // Ak je odpracovaný menší ako predpis - bude to červené a so znamienkom -
              // Je to na mínus prvú, ináč mi to zobrazí zvyšok do 24
              echo '<font color="red">'.gmdate('\- H:i:s', (($CasovyFond - $ScheduleDiff)*-1)).'</font>';
            }

            
           // echo '-'.$lumpsum;
              }
            }
            // reset $CasovyFond
            $CasovyFond = 0;
        }else{
            echo '';
            // reset edit
            $edit = FALSE;
        }
        echo "</td>";
        echo"<td width='14%' align='center'>
        <a href='?page=attendance_detail&eno=".$eno."&date=".$Year."-".$Month."-".$i_display."&year=".$Year."&activedisp=".$ActiveDisp."'>";
        if($edit == TRUE){
        echo "<button class='btn-small btn-success'><i class='fa fa-edit'></i></button>";
        $edit = FALSE;
        }else{
            echo "";
            $edit = FALSE;
        }
        echo "</a>
        </td>";
        echo '</tr>';
     }
     echo '</tbody>';
    echo '</table>';
    echo '</div>'; // table-responsive
    echo '  </div>'; // box-body
    echo '</div>'; // box
     ?>
    </section>   
  </div>
    

  <?php include 'includes/attendance_modal.php'; ?>


<script>
$(function(){
  // Initialize pickers when edit modal is shown
  $('#edit').on('show.bs.modal', function() {
    if(typeof initAttendancePickers === 'function') {
      initAttendancePickers();
    }
  });

  // Keep default table controls but prevent automatic DataTable sorting for this calendar table
  // (Table id changed to `calendar_table` so global initialiser on `#example1` won't affect it)
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
      // Set values using datetimepicker API instead of raw .val()
      $('#datepicker_edit').data("DateTimePicker").date(response.date);
      $('#edit_time_in').data("DateTimePicker").date(moment(response.edit_time_in, 'HH:mm:ss'));
      $('#edit_time_out').data("DateTimePicker").date(moment(response.edit_time_out, 'HH:mm:ss'));
      
      $('#attendance_date').html(response.date);
      $('#attid').val(response.attid);
      $('#employee_name').html(response.firstname+' '+response.lastname);
      $('#del_attid').val(response.attid);
      $('#del_employee_name').html(response.firstname+' '+response.lastname);
      $('#edit_movement').val(response.movement);
    }
  });
}
</script>
