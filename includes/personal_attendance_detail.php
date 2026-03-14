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
/* Go-home banner variants (match userbanner full width) */
.go-home-banner { width: 100%; margin: 0 0 15px 0; }

/* Default (blue like userbanner) */
.go-home-banner.is-blue{
  background: linear-gradient(135deg, #3c8dbc, #367fa9);
}

/* Missing time (red) */
.go-home-banner.is-red{
  background: linear-gradient(135deg, #dd4b39, #b93b2f);
}

/* Completed (green) */
.go-home-banner.is-green{
  background: linear-gradient(135deg, #00a65a, #008d4c);
}

/* slightly stronger dark overlay for readability */
.go-home-banner .emp-infobox{
  background: rgba(0,0,0,.22);
}

/* small mono-like digits for time */
.go-home-time{
  font-size:22px;
  font-weight:800;
  letter-spacing:.3px;
}
.go-home-sub{
  font-size:12px;
  opacity:.95;
  margin-top:4px;
}
/* Align go-home right box exactly with userbanner boxes */
.go-home-banner .emp-infobox-wrap {
  width: auto;              /* remove 95% */
  margin-left: 16px;        /* same gap as userbanner left padding */
  flex: 1 1 auto;
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
      <div class="row">
        <div class="col-md-12">
          <div class="box box-primary">
            <div class="box-header with-border">              
              <div class="box-tools pull-right">              
                <a href="#"
                    onclick="event.preventDefault(); window.history.back();"
                    class="btn btn-warning btn-sm btn-flat">
                    <i class="fa fa-arrow-left"></i> Späť na prehľad
                    </a>
                                </div>
            </div>
            <br /><br/>
            <!-- user personal info -->
<?php include 'includes/userbanner.php'; ?>

<?php
// ================================
// GO-HOME TIME WIDGET (for the selected day)
// Uses schedules + attendance movements
// movements: 1=work, 3=break, 4=lunch
// ================================
date_default_timezone_set('Europe/Bratislava');

$eno  = mysqli_real_escape_string($conn, $_GET['eno'] ?? '');
$dayYmd = mysqli_real_escape_string($conn, $_GET['date'] ?? '');

$goHomeTimeStr = '—';
$remainStr     = '';
$breakdownStr  = '';
$canCompute    = false;

if ($eno !== '' && $dayYmd !== '') {

  // 1) Get employee schedule (same join idea as banner)
  $schedIn = '';
  $schedOut = '';
  $qSched = $conn->query("
    SELECT s.time_in AS sched_in, s.time_out AS sched_out
    FROM employees e
    LEFT JOIN schedules s ON s.id = e.schedule_id
    WHERE e.id = '{$eno}'
    LIMIT 1
  ");
  if ($qSched && $qSched->num_rows) {
    $r = $qSched->fetch_assoc();
    $schedIn  = $r['sched_in'] ?? '';
    $schedOut = $r['sched_out'] ?? '';
  }

  // schedule span in seconds
  $scheduleSpan = 0;
  if ($schedIn !== '' && $schedOut !== '') {
    $scheduleSpan = strtotime($dayYmd.' '.$schedOut) - strtotime($dayYmd.' '.$schedIn);
    if ($scheduleSpan < 0) $scheduleSpan = 0;
  }

  // mandatory lunch if schedule exceeds 6 hours
  $lunchMandatory = ($scheduleSpan > 21600); // 6h * 3600
  $mandatoryLunchSec = $lunchMandatory ? 1800 : 0;

  // net required work (what counts as "work done") = schedule span - mandatory lunch
  $requiredNetWork = max(0, $scheduleSpan - $mandatoryLunchSec);

  // 2) Helper: sum seconds for a movement for this day
  $attdn_table = 'attdn_' . (int)($_REQUEST['year'] ?? date('Y')); // your pattern
  $sumMovement = function(int $movement) use ($conn, $attdn_table, $eno, $dayYmd): int {
    $sql = "SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS sec
      FROM {$attdn_table}
      WHERE employee_id = '{$eno}'
        AND date = '{$dayYmd}'
        AND movement = {$movement}
        AND time_in IS NOT NULL
        AND time_out IS NOT NULL
        AND time_out <> '23:59:59'
    ";
    $q = $conn->query($sql);
    if ($q && $q->num_rows) {
      $r = $q->fetch_assoc();
      return (int)($r['sec'] ?? 0);
    }
    return 0;
  };

  // Optional: running open segment (if someone is still clocked in and time_out is 00:00:00)
$runningMovement = function(int $movement) use ($conn, $attdn_table, $eno, $dayYmd): int {
  // running makes sense only for today
  if ($dayYmd !== date('Y-m-d')) return 0;

  $sql = "SELECT time_in
    FROM {$attdn_table}
    WHERE employee_id = '{$eno}'
      AND date = '{$dayYmd}'
      AND movement = {$movement}
      AND time_in IS NOT NULL
      AND time_out = '23:59:59'
    ORDER BY id DESC
    LIMIT 1
  ";
  $q = $conn->query($sql);
  if ($q && $q->num_rows) {
    $r = $q->fetch_assoc();
    $tin = $r['time_in'] ?? '';
    if ($tin !== '') {
      $start = strtotime($dayYmd.' '.$tin);
      $now = time();
      if ($start && $now > $start) return (int)($now - $start);
    }
  }
  return 0;
};

  // 3) First WORK in time (movement=1)
  $firstWorkIn = '';
  $qFirst = $conn->query("SELECT MIN(time_in) AS first_in
    FROM {$attdn_table}
    WHERE employee_id = '{$eno}'
      AND date = '{$dayYmd}'
      AND movement = 1
      AND time_in IS NOT NULL
  ");
  if ($qFirst && $qFirst->num_rows) {
    $r = $qFirst->fetch_assoc();
    $firstWorkIn = $r['first_in'] ?? '';
  }

  if ($firstWorkIn !== '' && $requiredNetWork > 0) {
    $canCompute = true;

    // Collect day totals (+ running if open)
    $workSec  = $sumMovement(1) + $runningMovement(1);
    $breakSec = $sumMovement(3) + $runningMovement(3);
    $lunchSec = $sumMovement(4) + $runningMovement(4);

    // Effective lunch to add into "presence required"
    // - if mandatory and lunch < 30m => treat as 30m
    // - if mandatory and lunch 0 => treat as 30m
    // - if not mandatory => use real lunch logged
    $effectiveLunch = 0;
    if ($lunchMandatory) {
      if ($lunchSec <= 0) $effectiveLunch = 1800;
      else $effectiveLunch = ($lunchSec < 1800) ? 1800 : $lunchSec;
    } else {
      $effectiveLunch = max(0, $lunchSec);
    }

    // Presence needed from first clock-in:
    // required net work + breaks + effective lunch
    $startTs = strtotime($dayYmd.' '.$firstWorkIn);
    $leaveTs = $startTs + $requiredNetWork + $breakSec + $effectiveLunch;

    $leaveTsEpoch = (int)$leaveTs;
$isToday = ($dayYmd === date('Y-m-d'));

// Missing net work (work only counts!)
$missingNetWorkSec = max(0, $requiredNetWork - $workSec);

// For TODAY: consider “done” if current time is past leaveTs even if the last segment is still open etc.
if ($isToday) {
  $isDone = (time() >= $leaveTsEpoch);
} else {
  // For past/future dates: done if missing is 0
  $isDone = ($missingNetWorkSec === 0);
}

// Initial banner color (JS will keep it updated for today)
$bannerClass = $isDone ? 'is-green' : 'is-red';

// Remaining string (you already compute it) — keep as-is, but we’ll also feed JS exact seconds
$remainingSec = max(0, $leaveTsEpoch - time());

    $goHomeTimeStr = date('H:i', $leaveTs);

    // Remaining (only meaningful for today)
    if ($dayYmd === date('Y-m-d')) {
      $rem = $leaveTs - time();
      if ($rem > 0) {
        $h = floor($rem / 3600);
        $m = floor(($rem % 3600) / 60);
        $remainStr = sprintf('%02d:%02d', $h, $m);
      } else {
        $remainStr = '00:00';
      }
    }

    $breakdownStr =
      'Práca: '.gmdate('H:i', $workSec).
      ' • Obed: '.gmdate('H:i', $effectiveLunch).
      ' • Prestávky: '.gmdate('H:i', $breakSec).
      ' • Čistý fond: '.gmdate('H:i', $requiredNetWork);
  }
}
?>

<?php if ($canCompute): ?>
  <div
    id="goHomeBanner"
    class="emp-banner go-home-banner <?php echo $bannerClass; ?>"
    data-leave-ts="<?php echo (int)$leaveTsEpoch; ?>"
    data-is-today="<?php echo $isToday ? '1' : '0'; ?>"
  >
    <div class="emp-left">
      <div class="emp-main">
        <p class="emp-name" style="margin:0;">
          <i class="fa fa-sign-out"></i>&nbsp;Korektný čas odchodu:
          <span class="go-home-time" id="goHomeTime">
            <?php echo htmlspecialchars($goHomeTimeStr, ENT_QUOTES, 'UTF-8'); ?>
          </span>
        </p>

        <p class="emp-sub" style="margin:6px 0 0 0;">
          <span id="goHomeRemainWrap" style="<?php echo $isToday ? '' : 'display:none;'; ?>">
            Zostáva: <b id="goHomeRemain"><?php echo htmlspecialchars($remainStr, ENT_QUOTES, 'UTF-8'); ?></b>
          </span>
          <span id="goHomeDoneWrap" style="<?php echo (!$isToday && $isDone) ? '' : 'display:none;'; ?>">
            Splnené ✅
          </span>
          <span id="goHomeMissingWrap" style="<?php echo (!$isToday && !$isDone) ? '' : 'display:none;'; ?>">
            Chýba: <b><?php echo gmdate('H:i', $missingNetWorkSec); ?></b>
          </span>
        </p>

        <?php if ($breakdownStr !== ''): ?>
          <div class="go-home-sub">
            <?php echo htmlspecialchars($breakdownStr, ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="emp-right">
  <div class="emp-infobox-wrap" style="width:95%;">
      <div class="emp-infobox-wrap">
        <div class="emp-infobox" style="min-height:70px;">
          <div class="emp-icon">
            <div class="icon-bubble bubble-date">
              <i class="fa fa-clock-o"></i>
            </div>
          </div>
          <div class="emp-content">
            <div class="emp-title">Dátum</div>
            <div class="emp-value">
              <?php echo htmlspecialchars(date('d.m.Y', strtotime($dayYmd)), ENT_QUOTES, 'UTF-8'); ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

            <!--  datatabledirectly under the banner -->
            <div class="box-body">
                <table id="example7" class="table table-bordered table-hover" style="width: 100%; margin-bottom: 0;">
                <thead>
                  <th>Dátum</th>                  
                  <th><center>Príchod</center></th>
                  <th><center>Odchod</center></th>
                  <th><center>Činnosť</center></th>
                  <th><center>Trvanie</center></th>                  
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
                          <td align='center'>".date('H:i:s', strtotime($row['time_in'])).$status."</td>
                          <td align='center'>".date('H:i:s', strtotime($row['time_out']))."</td>
                          <td align='center'>";
                          SWITCH($row['movement']){
                            case 1: echo "Práca"; break;
                            case 2: echo  "Doma"; break;
                            case 3: echo  "Prestávka"; break;
                            case 4: echo  "Obed"; break;
                            case 5: echo  "Dovolenka"; break;
                            case 6: echo  "Lekár"; break;
                          }
                          echo "</td>";
                          echo "<td align='center'>";
                          $WorkingHours = strtotime($row['time_out'])-strtotime($row['time_in']);  echo date("H:i:s",$WorkingHours - 3600);
                          echo "</td>                           
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
<script>
(function(){
  var el = document.getElementById('goHomeBanner');
  if (!el) return;

  var leaveTs = parseInt(el.getAttribute('data-leave-ts') || '0', 10);
  var isToday = (el.getAttribute('data-is-today') === '1');

  var remainEl = document.getElementById('goHomeRemain');
  var remainWrap = document.getElementById('goHomeRemainWrap');
  var doneWrap = document.getElementById('goHomeDoneWrap');

  function pad(n){ return (n < 10 ? '0' : '') + n; }

  function setBannerState(done){
    el.classList.remove('is-red','is-green','is-blue');
    el.classList.add(done ? 'is-green' : 'is-red');
  }

  function tick(){
    if (!isToday || !leaveTs) return;

    var now = Math.floor(Date.now() / 1000);
    var diff = leaveTs - now;

    if (diff <= 0) {
      // done
      if (remainEl) remainEl.textContent = '00:00';
      if (remainWrap) remainWrap.style.display = 'none';
      if (doneWrap) doneWrap.style.display = '';
      setBannerState(true);
      return;
    }

    // still missing
    var h = Math.floor(diff / 3600);
    var m = Math.floor((diff % 3600) / 60);

    if (remainEl) remainEl.textContent = pad(h) + ':' + pad(m);
    if (remainWrap) remainWrap.style.display = '';
    if (doneWrap) doneWrap.style.display = 'none';
    setBannerState(false);
  }

  // initial + every minute
  tick();
  setInterval(tick, 60000);
})();
</script>
</body>
</html>
