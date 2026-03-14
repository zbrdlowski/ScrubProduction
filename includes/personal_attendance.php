<?php
// Personal attendance table (profile tab)
// Requires: $conn, $eno, and 'sviatky.php' for holidays

if (!isset($eno) || $eno === '') {
  echo '<div class="alert alert-warning">No employee selected.</div>';
  return;
}

// Month/year - use variables from profile.php if available, otherwise parse from URL
if (!isset($Year) || !isset($Month)) {
  $Year  = !empty($_GET['year'])  ? preg_replace('/\D/', '', $_GET['year'])  : date('Y');
  $monthInput = !empty($_GET['month']) ? (string)$_GET['month'] : date('m');
  
  // Strip any Tempusdominus time suffix
  $monthInput = preg_replace('/:.*/i', '', $monthInput);
  
  // Handle both numeric and text month names
  if (!ctype_digit($monthInput)) {
    $months = ['januar'=>1,'februar'=>2,'marec'=>3,'april'=>4,'maj'=>5,'jun'=>6,'jul'=>7,'august'=>8,'september'=>9,'oktober'=>10,'november'=>11,'december'=>12];
    $Month = (int)($months[strtolower($monthInput)] ?? (int)date('n'));
  } else {
    $Month = (int)$monthInput;
  }
  $Month = str_pad((string)$Month, 2, '0', STR_PAD_LEFT);
}

// Define attendance table for the year (adjust if your naming differs)
$attdn_table = 'attdn_' . $Year;

include __DIR__ . '/../sviatky.php';

// ===== Styles (kept from your script, but only what affects table coloring) =====
?>
<style>
  .table > tbody > tr.work-day > td { color: #e6ffe6; }
  .table > tbody > tr.day-off > td  { background-color: #4d4d4d !important; color: #ffe8d1; }
  .table-hover > tbody > tr.work-day:hover > td { background-color: #275027 !important; }
  .table-hover > tbody > tr.day-off:hover > td  { background-color: #4a3526 !important; }

  /* Make button look consistent in AdminLTE/BS3 */
  .btn-details { padding: 3px 10px; font-size: 12px; }
</style>

<?php
// ===== Schedule diff for this employee (needed for saldo logic) =====
$ScheduleDiff = null;

$empSql = "SELECT schedule_id FROM employees WHERE id = '".mysqli_real_escape_string($conn, $eno)."' LIMIT 1";
$empQ = $conn->query($empSql);
if ($empQ && $empQ->num_rows) {
  $emp = $empQ->fetch_assoc();
  $scheduleId = (int)($emp['schedule_id'] ?? 0);

  if ($scheduleId > 0) {
    $ScheduleSQL = "SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS schedulediff, time_in, time_out
                   FROM schedules WHERE id = ".$scheduleId." LIMIT 1";
    $ScheduleQuery = $conn->query($ScheduleSQL);
    if ($ScheduleQuery && $ScheduleQuery->num_rows) {
      $Schedulerow = $ScheduleQuery->fetch_assoc();
      // minus 30 min lunch like original
      $ScheduleDiff = (int)($Schedulerow['schedulediff'] ?? 0) - 1800;
      if ($ScheduleDiff < 0) $ScheduleDiff = 0;
    }
  }
}

// ===== Easter-related days (same as your old logic) =====
$VelkaNedela    = date("d-m", easter_date((int)$Year));
$VelkyPiatok    = date("d-m", easter_date((int)$Year) - 172800);
$VelkyPondelok  = date("d-m", easter_date((int)$Year) + 86400);

// ===== Month calculations =====
$number   = cal_days_in_month(CAL_GREGORIAN, (int)$Month, (int)$Year);
$DayToTest = $Year.'-'.$Month;

// ===== Table output =====
echo '<div class="table-responsive">';
echo '<table id="calendar_table" class="table table-bordered table-hover">';
echo '<thead><tr>
        <th style="width:70px;">Deň</th>
        <th style="width:150px;">Čo za deň</th>
        <th>Práca</th>
        <th>Obed</th>
        <th>Prestávky</th>
        <th>Dovolenky</th>
        <th>Práceneschopnosť</th>
        <th style="width:120px;">Saldokonto</th>
        <th style="width:110px;">Detail</th>
      </tr></thead>';
echo '<tbody>';

for ($i = 1; $i <= $number; $i++) {

  // Slovak day name
  switch (date('l', strtotime($DayToTest.'-'.$i))) {
    case 'Monday':    $SlovakDay = 'Pondelok'; break;
    case 'Tuesday':   $SlovakDay = 'Utorok'; break;
    case 'Wednesday': $SlovakDay = 'Streda'; break;
    case 'Thursday':  $SlovakDay = 'Štvrtok'; break;
    case 'Friday':    $SlovakDay = 'Piatok'; break;
    case 'Saturday':  $SlovakDay = 'Sobota'; break;
    case 'Sunday':    $SlovakDay = 'Nedeľa'; break;
    default:          $SlovakDay = ''; break;
  }

  $i_display = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
  $dateYmd   = $Year.'-'.$Month.'-'.$i_display;

  // Determine day off (holiday/weekend/easter)
  $volnyDen = false;

  if (isset($sviatky) && is_array($sviatky) && in_array($i_display."-".$Month, $sviatky, true)) {
    $volnyDen = true;
  }
  if ($i_display."-".$Month === $VelkyPiatok || $i_display."-".$Month === $VelkyPondelok) {
    $volnyDen = true;
  }
  if ((int)date('N', strtotime($dateYmd)) > 5) {
    $volnyDen = true;
  }

  $rowClass = $volnyDen ? 'day-off' : 'work-day';

  // Reset per-day
  $edit = false;
  $CasovyFond = 0;
  $TrebaObed = false;
  // per-day effective schedule (defaults to employee schedule)
  $DaySchedule = $ScheduleDiff;
  // flag: admin-entered full-day holiday or sick -> treat as full-time day
  $isHolidayOrSick = false;

  echo '<tr class="'.$rowClass.'">';
  echo '<td>'.$i_display.'</td>';
  echo '<td>'.$SlovakDay.'</td>';

  // Helper to run a sum query (seconds) for movement type
  $safeEno = mysqli_real_escape_string($conn, $eno);
  $safeDate = mysqli_real_escape_string($conn, $dateYmd);

  $sumSeconds = function($movement) use ($conn, $attdn_table, $safeEno, $safeDate) {
    $movement = (int)$movement;
    $sql = "SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS sucet
            FROM `".$attdn_table."`
            WHERE employee_id = '".$safeEno."'
              AND date = '".$safeDate."'
              AND movement = '".$movement."'";
    $q = $conn->query($sql);
    if ($q && $q->num_rows) {
      $r = $q->fetch_assoc();
      return (int)($r['sucet'] ?? 0);
    }
    return 0;
  };

  // ===== Work (movement 1) =====
  echo '<td align="center">';
  $workSec = $sumSeconds(1);

  // Original script uses this weird check: date('H:i:s', sec-3600) != '00:00:00'
  // Equivalent: treat < 3600 as "no data"
  if ($workSec > 3600) {
    $edit = true;
    $CasovyFond += $workSec;
    if ($CasovyFond > 21600) { $TrebaObed = true; }
    echo gmdate('H:i:s', $workSec);
  } else {
    echo '--';
  }
  echo '</td>';

  // ===== Lunch (movement 4) =====
  echo '<td align="center">';
  $lunchSec = $sumSeconds(4);
  if ($lunchSec > 0) {
    $edit = true;
    // lunch does not add to CasovyFond, it subtracts from lumpsum in old script
    echo gmdate('H:i:s', $lunchSec);
  } else {
    echo '--';
  }
  echo '</td>';

  // ===== Breaks (movement 3) =====
  echo '<td align="center">';
  $breakSec = $sumSeconds(3);
  if ($breakSec > 3600) {
    $edit = true;
    echo gmdate('H:i:s', $breakSec);
  } else {
    echo '--';
  }
  echo '</td>';

  // ===== Vacation (movement 5) =====
  echo '<td align="center">';
  $vacSec = $sumSeconds(5);
  if ($vacSec > 3600) {
    $edit = true;

    // When admin enters a full-day vacation, treat it as full-time (07:00-15:30)
    $isHolidayOrSick = true;
    // full-time span (07:00 - 15:30) in seconds = 30600; minus lunch 1800 => 28800
    $DaySchedule = 28800;

    // cap vacation to DaySchedule
    $vacDisplay = $vacSec;
    if ($DaySchedule !== null && $DaySchedule > 0 && $vacDisplay > $DaySchedule) {
      $vacDisplay = $DaySchedule;
    }

    $CasovyFond += $vacDisplay;
    if ($CasovyFond > 21600) { $TrebaObed = true; }

    // subtract lunch if needed (your original logic)
    if ($TrebaObed === true) {
      $CasovyFond -= 1800;
      $TrebaObed = false;
    }

    echo gmdate('H:i:s', $vacDisplay);
  } else {
    echo '--';
  }
  echo '</td>';

  // ===== Sick leave (movement 6) =====
  echo '<td align="center">';
  $sickSec = $sumSeconds(6);
  if ($sickSec > 3600) {
    $edit = true;
    // mark as holiday/sick day and enforce full-time day for admin-entered entries
    $isHolidayOrSick = true;
    $DaySchedule = 28800;

    // normalize sick display: full recorded day might be 30600, reduce to working time 28800
    $sickDisplay = $sickSec;
    if ($sickDisplay == 30600) {
      $sickDisplay = 28800;
    }
    // cap to DaySchedule
    if ($DaySchedule !== null && $sickDisplay > $DaySchedule) { $sickDisplay = $DaySchedule; }

    $CasovyFond += $sickDisplay;
    if ($CasovyFond > 21600) { $TrebaObed = true; }

    if ($TrebaObed === true) {
      $CasovyFond -= 1800;
      $TrebaObed = false;
    }

    echo gmdate('H:i:s', $sickDisplay);
  } else {
    echo '--';
  }
  echo '</td>';

  // ===== Saldo =====
  echo '<td align="center">';
  if (!empty($CasovyFond) && $ScheduleDiff !== null) {
    $edit = true;

    // use per-day effective schedule (may be overridden to full-time for holiday/sick)
    $effectiveSchedule = $DaySchedule;

    // For admin-entered Holidays or Sick days always show 00:00:00 (nothing missing)
    if ($isHolidayOrSick === true) {
      echo '<span style="color:black;">'.gmdate(' H:i:s', 0).'</span>';
    } else {
      if ($effectiveSchedule === null) { $effectiveSchedule = $ScheduleDiff; }
      if ($CasovyFond > $effectiveSchedule) {
        echo '<span style="color:#00ff00;">'.gmdate(' H:i:s', ($CasovyFond - $effectiveSchedule)).'</span>';
      } else {
        if ($volnyDen === true) {
          echo '<span style="color:#00ff00;">'.gmdate(' H:i:s', $CasovyFond).'</span>';
        } else {
          $diff = $CasovyFond - $effectiveSchedule;
          if ($diff == 0) {
            echo '<span style="color:black;">'.gmdate(' H:i:s', 0).'</span>';
          } else {
            echo '<span style="color:red;">'.gmdate(' H:i:s', ($diff * -1)).'</span>';
          }
        }
      }
    }
  } else {
    echo '';
    $edit = false;
  }
  echo '</td>';

  // ===== Details button =====
  echo '<td align="center">';
  if ($edit === true) {
    echo '<a href="?page=personal_attendance_detail&eno='.$eno.'&date='.$dateYmd.'&year='.$Year.'">
            <button type="button" class="btn btn-primary btn-details">
              <i class="fa fa-search"></i> Details
            </button>
          </a>';
  } else {
    echo '';
  }
  echo '</td>';

  echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
?>
