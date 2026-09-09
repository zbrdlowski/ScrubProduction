<?php
if (intval($_SESSION['permission'] ?? 0) <= 300) {
  echo '<div class="alert alert-danger">Nemáš oprávnenie na správu databáz dochádzky.</div>';
  return;
}

function attendanceDatabaseHtml($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function attendanceDatabaseTableName($table)
{
  return '`' . str_replace('`', '``', $table) . '`';
}

function attendanceDatabaseMovementLabel($movement)
{
  switch ((int)$movement) {
    case 1:
      return 'Práca';
    case 2:
      return 'Doma';
    case 3:
      return 'Prestávka';
    case 4:
      return 'Obed';
    case 5:
      return 'Dovolenka';
    case 6:
      return 'Lekár / PN';
    default:
      return 'Neznáme';
  }
}

$attendanceDatabases = [];
$attendanceDatabaseErrors = [];
$attendanceTotalRecords = 0;
$attendanceTotalProblems = 0;
$latestAttendanceYear = null;
$currentAttendanceYear = (int)date('Y');
$currentAttendanceDate = date('Y-m-d');
$attendanceDbName = $dbname ?? 'scrubproduction';
$currentAttendanceDateSql = $conn->real_escape_string($currentAttendanceDate);

$tableResult = $conn->query('SHOW TABLES');
if ($tableResult) {
  while ($tableRow = $tableResult->fetch_array()) {
    $tableName = (string)$tableRow[0];
    if (!preg_match('/^attdn_(\d{4})$/', $tableName, $match)) {
      continue;
    }

    $year = (int)$match[1];
    $latestAttendanceYear = max($latestAttendanceYear ?? $year, $year);
    $quotedTable = attendanceDatabaseTableName($tableName);
    $statsSql = "
      SELECT
        COUNT(*) AS record_count,
        COUNT(DISTINCT employee_id) AS employee_count,
        MIN(`date`) AS first_date,
        MAX(`date`) AS last_date,
        SUM(CASE WHEN movement = 1 THEN 1 ELSE 0 END) AS work_count,
        SUM(CASE WHEN movement IN (3, 4) THEN 1 ELSE 0 END) AS break_count,
        SUM(CASE WHEN movement IN (5, 6) THEN 1 ELSE 0 END) AS absence_count,
        SUM(CASE WHEN time_out = '23:59:59' AND `date` < '{$currentAttendanceDateSql}' THEN 1 ELSE 0 END) AS problem_count,
        SUM(CASE WHEN time_out = '23:59:59' AND `date` = '{$currentAttendanceDateSql}' THEN 1 ELSE 0 END) AS today_open_count
      FROM {$quotedTable}
    ";

    $statsResult = $conn->query($statsSql);
    if (!$statsResult) {
      $attendanceDatabaseErrors[] = 'Nepodarilo sa načítať štatistiky pre ' . $tableName . ': ' . $conn->error;
      $stats = [
        'record_count' => 0,
        'employee_count' => 0,
        'first_date' => null,
        'last_date' => null,
        'work_count' => 0,
        'break_count' => 0,
        'absence_count' => 0,
        'problem_count' => 0,
        'today_open_count' => 0
      ];
    } else {
      $stats = $statsResult->fetch_assoc();
    }

    $recordCount = (int)($stats['record_count'] ?? 0);
    $problemCount = (int)($stats['problem_count'] ?? 0);
    $attendanceTotalRecords += $recordCount;
    $attendanceTotalProblems += $problemCount;

    $attendanceDatabases[] = [
      'year' => $year,
      'table' => $tableName,
      'record_count' => $recordCount,
      'employee_count' => (int)($stats['employee_count'] ?? 0),
      'first_date' => $stats['first_date'] ?? null,
      'last_date' => $stats['last_date'] ?? null,
      'work_count' => (int)($stats['work_count'] ?? 0),
      'break_count' => (int)($stats['break_count'] ?? 0),
      'absence_count' => (int)($stats['absence_count'] ?? 0),
      'problem_count' => $problemCount,
      'today_open_count' => (int)($stats['today_open_count'] ?? 0)
    ];
  }
} else {
  $attendanceDatabaseErrors[] = 'Nepodarilo sa načítať zoznam tabuliek: ' . $conn->error;
}

usort($attendanceDatabases, function ($left, $right) {
  return $right['year'] <=> $left['year'];
});

$existingAttendanceYears = array_column($attendanceDatabases, 'year');
$suggestedAttendanceYear = max($latestAttendanceYear ? $latestAttendanceYear + 1 : $currentAttendanceYear, $currentAttendanceYear);
while (in_array($suggestedAttendanceYear, $existingAttendanceYears, true)) {
  $suggestedAttendanceYear++;
}

$currentYearExists = in_array($currentAttendanceYear, $existingAttendanceYears, true);
$nextYearExists = in_array($currentAttendanceYear + 1, $existingAttendanceYears, true);
$selectedProblemYear = null;
$problemRecords = [];

if (isset($_GET['problem_year']) && preg_match('/^\d{4}$/', (string)$_GET['problem_year'])) {
  $selectedProblemYear = (int)$_GET['problem_year'];

  if (in_array($selectedProblemYear, $existingAttendanceYears, true)) {
    $problemTable = attendanceDatabaseTableName('attdn_' . $selectedProblemYear);
    $problemSql = "
      SELECT
        att.id AS attid,
        att.employee_id,
        att.date,
        att.time_in,
        att.time_out,
        att.movement,
        employees.employee_id AS empid,
        employees.firstname,
        employees.lastname
      FROM {$problemTable} att
      LEFT JOIN employees ON employees.id = att.employee_id
      WHERE att.time_out = '23:59:59'
        AND att.date < '{$currentAttendanceDateSql}'
      ORDER BY att.date DESC, att.time_in DESC, att.id DESC
    ";
    $problemResult = $conn->query($problemSql);

    if ($problemResult) {
      while ($problemRow = $problemResult->fetch_assoc()) {
        $problemRecords[] = $problemRow;
      }
    } else {
      $attendanceDatabaseErrors[] = 'Nepodarilo sa načítať problémové záznamy pre rok ' . $selectedProblemYear . ': ' . $conn->error;
    }
  } else {
    $attendanceDatabaseErrors[] = 'Databáza dochádzky pre rok ' . $selectedProblemYear . ' neexistuje.';
    $selectedProblemYear = null;
  }
}
?>

<style>
  .attendance-db-toolbar {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: space-between;
    margin-bottom: 16px;
  }

  .attendance-db-toolbar h2 {
    font-size: 22px;
    font-weight: 700;
    line-height: 1.25;
    margin: 0;
  }

  .attendance-db-summary {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    margin-bottom: 16px;
  }

  .attendance-db-stat {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 6px;
    display: flex;
    gap: 12px;
    min-height: 78px;
    padding: 14px;
  }

  .attendance-db-stat .attendance-db-stat-icon {
    align-items: center;
    border-radius: 6px;
    display: inline-flex;
    flex: 0 0 42px;
    font-size: 15px;
    height: 42px;
    justify-content: center;
    line-height: 1;
    margin-top: 0;
    width: 42px;
  }

  .attendance-db-stat .attendance-db-stat-icon i {
    display: block;
    line-height: 1;
    margin: 0;
  }

  .attendance-db-stat strong {
    display: block;
    font-size: 20px;
    line-height: 1.1;
  }

  .attendance-db-stat > div span {
    color: #adb5bd;
    display: block;
    font-size: 12px;
    margin-top: 4px;
  }

  .attendance-db-table-wrap {
    overflow-x: hidden;
    width: 100%;
  }

  .attendance-db-table-wrap .dataTables_wrapper {
    width: 100%;
  }

  .attendance-db-table {
    table-layout: auto;
    width: 100% !important;
  }

  .attendance-db-status {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: flex-start;
  }

  .attendance-db-status .badge {
    align-items: center;
    display: inline-flex;
    min-height: 18px;
  }

  .attendance-db-status a.badge {
    text-decoration: none;
  }

  .attendance-db-status a.badge:hover {
    filter: brightness(1.08);
  }

  .attendance-db-table td,
  .attendance-db-table th {
    vertical-align: middle !important;
    white-space: normal;
  }

  .attendance-problem-panel {
    margin-top: 18px;
  }

  .attendance-problem-head {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: space-between;
    margin-bottom: 10px;
  }

  .attendance-problem-head h3 {
    font-size: 18px;
    font-weight: 700;
    line-height: 1.25;
    margin: 0;
  }

  .attendance-problem-table {
    margin-bottom: 0;
    width: 100%;
  }

  .attendance-problem-table td,
  .attendance-problem-table th {
    vertical-align: middle !important;
    white-space: normal;
  }

  .attendance-db-modal-note {
    color: #adb5bd;
    font-size: 12px;
    margin-top: 6px;
  }
</style>

<div class="container-fluid">
  <div class="attendance-db-toolbar">
    <div>
      <h2><i class="fa fa-database"></i> Databázy dochádzky</h2>
      <div class="text-muted">Aktívne tabuľky <code>attdn_YYYY</code> v databáze <?= attendanceDatabaseHtml($attendanceDbName) ?></div>
    </div>
    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#createAttendanceDatabaseModal">
      <i class="fa fa-plus"></i> Vytvoriť novú databázu
    </button>
  </div>

  <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible">
      <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
      <h5><i class="icon fa fa-warning"></i> Nie je to dobré!</h5>
      <?= $_SESSION['error'] ?>
    </div>
    <?php unset($_SESSION['error']); ?>
  <?php endif; ?>

  <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible">
      <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
      <h5><i class="icon fa fa-check"></i> Podarilo sa!</h5>
      <?= $_SESSION['success'] ?>
    </div>
    <?php unset($_SESSION['success']); ?>
  <?php endif; ?>

  <?php foreach ($attendanceDatabaseErrors as $attendanceDatabaseError): ?>
    <div class="alert alert-warning"><?= attendanceDatabaseHtml($attendanceDatabaseError) ?></div>
  <?php endforeach; ?>

  <div class="attendance-db-summary">
    <div class="attendance-db-stat">
      <span class="attendance-db-stat-icon bg-info"><i class="fa fa-table"></i></span>
      <div>
        <strong><?= number_format(count($attendanceDatabases), 0, ',', ' ') ?></strong>
        <span>databáz dochádzky</span>
      </div>
    </div>
    <div class="attendance-db-stat">
      <span class="attendance-db-stat-icon bg-success"><i class="fa fa-list"></i></span>
      <div>
        <strong><?= number_format($attendanceTotalRecords, 0, ',', ' ') ?></strong>
        <span>záznamov spolu</span>
      </div>
    </div>
    <div class="attendance-db-stat">
      <span class="attendance-db-stat-icon <?= $attendanceTotalProblems > 0 ? 'bg-danger' : 'bg-success' ?>"><i class="fa fa-exclamation-triangle"></i></span>
      <div>
        <strong><?= number_format($attendanceTotalProblems, 0, ',', ' ') ?></strong>
        <span>problémových záznamov</span>
      </div>
    </div>
    <div class="attendance-db-stat">
      <span class="attendance-db-stat-icon <?= $currentYearExists ? 'bg-success' : 'bg-warning' ?>"><i class="fa fa-calendar"></i></span>
      <div>
        <strong><?= $currentAttendanceYear ?></strong>
        <span><?= $currentYearExists ? 'aktuálny rok existuje' : 'aktuálny rok chýba' ?></span>
      </div>
    </div>
    <div class="attendance-db-stat">
      <span class="attendance-db-stat-icon <?= $nextYearExists ? 'bg-success' : 'bg-secondary' ?>"><i class="fa fa-forward"></i></span>
      <div>
        <strong><?= $suggestedAttendanceYear ?></strong>
        <span>ďalší voľný rok na vytvorenie</span>
      </div>
    </div>
  </div>

  <div class="attendance-db-table-wrap">
    <table id="example1" class="table table-bordered table-striped attendance-db-table">
      <thead>
        <tr>
          <th>Rok</th>
          <th>Tabuľka</th>
          <th>Záznamy</th>
          <th>Ľudia</th>
          <th>Rozsah dátumov</th>
          <th>Práca</th>
          <th>Prestávky</th>
          <th>Dovolenky / PN</th>
          <th>Stav</th>
          <th>Prejsť na dochádzku</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($attendanceDatabases as $attendanceDatabase): ?>
          <?php
          $dateRange = 'bez dát';
          if (!empty($attendanceDatabase['first_date']) && !empty($attendanceDatabase['last_date'])) {
            $dateRange = date('d.m.Y', strtotime($attendanceDatabase['first_date'])) . ' - ' . date('d.m.Y', strtotime($attendanceDatabase['last_date']));
          }
          ?>
          <tr>
            <td><?= (int)$attendanceDatabase['year'] ?></td>
            <td><code><?= attendanceDatabaseHtml($attendanceDatabase['table']) ?></code></td>
            <td><?= number_format($attendanceDatabase['record_count'], 0, ',', ' ') ?></td>
            <td><?= number_format($attendanceDatabase['employee_count'], 0, ',', ' ') ?></td>
            <td><?= attendanceDatabaseHtml($dateRange) ?></td>
            <td><?= number_format($attendanceDatabase['work_count'], 0, ',', ' ') ?></td>
            <td><?= number_format($attendanceDatabase['break_count'], 0, ',', ' ') ?></td>
            <td><?= number_format($attendanceDatabase['absence_count'], 0, ',', ' ') ?></td>
            <td>
              <span class="attendance-db-status">
                <?php if ($attendanceDatabase['problem_count'] > 0): ?>
                  <a class="badge badge-danger" href="index.php?page=attendance_databases&amp;problem_year=<?= (int)$attendanceDatabase['year'] ?>#attendanceProblemRecords">
                    <?= number_format($attendanceDatabase['problem_count'], 0, ',', ' ') ?> problémové
                  </a>
                <?php endif; ?>
                <?php if ($attendanceDatabase['today_open_count'] > 0): ?>
                  <span class="badge badge-warning"><?= number_format($attendanceDatabase['today_open_count'], 0, ',', ' ') ?> dnes otvorené</span>
                <?php endif; ?>
                <?php if ($attendanceDatabase['problem_count'] <= 0 && $attendanceDatabase['today_open_count'] <= 0): ?>
                  <span class="badge badge-success">OK</span>
                <?php endif; ?>
                <?php if ((int)$attendanceDatabase['year'] === $currentAttendanceYear): ?>
                  <span class="badge badge-info">aktuálna</span>
                <?php endif; ?>
              </span>
            </td>
            <td>
              <a class="btn btn-sm btn-outline-info" href="index.php?page=calendar&amp;year=<?= (int)$attendanceDatabase['year'] ?>">
                <i class="fa fa-calendar"></i> Otvoriť
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($selectedProblemYear !== null): ?>
    <div class="attendance-problem-panel" id="attendanceProblemRecords">
      <div class="attendance-problem-head">
        <h3><i class="fa fa-exclamation-triangle text-danger"></i> Problémové záznamy - <?= (int)$selectedProblemYear ?></h3>
        <a class="btn btn-sm btn-outline-secondary" href="index.php?page=attendance_databases">
          <i class="fa fa-times"></i> Skryť
        </a>
      </div>

      <?php if (empty($problemRecords)): ?>
        <div class="alert alert-success mb-0">Pre rok <?= (int)$selectedProblemYear ?> nie sú žiadne staré neukončené záznamy.</div>
      <?php else: ?>
        <div class="attendance-db-table-wrap">
          <table class="table table-bordered table-striped attendance-problem-table">
            <thead>
              <tr>
                <th>Dátum</th>
                <th>Zamestnanec</th>
                <th>Interné ID</th>
                <th>Príchod</th>
                <th>Odchod</th>
                <th>Činnosť</th>
                <th>Detail</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($problemRecords as $problemRecord): ?>
                <?php
                $problemDate = (string)($problemRecord['date'] ?? '');
                $problemMonth = date('m', strtotime($problemDate));
                $employeeName = trim((string)($problemRecord['firstname'] ?? '') . ' ' . (string)($problemRecord['lastname'] ?? ''));
                if ($employeeName === '') {
                  $employeeName = 'Neznámy zamestnanec';
                }
                $detailUrl = 'index.php?page=attendance_detail'
                  . '&eno=' . (int)($problemRecord['employee_id'] ?? 0)
                  . '&date=' . rawurlencode($problemDate)
                  . '&year=' . (int)$selectedProblemYear
                  . '&month=' . rawurlencode($problemMonth)
                  . '&activedisp=attendance';
                ?>
                <tr>
                  <td><?= attendanceDatabaseHtml(date('d.m.Y', strtotime($problemDate))) ?></td>
                  <td><?= attendanceDatabaseHtml($employeeName) ?></td>
                  <td><?= attendanceDatabaseHtml($problemRecord['empid'] ?? $problemRecord['employee_id'] ?? '') ?></td>
                  <td><?= attendanceDatabaseHtml($problemRecord['time_in'] ?? '') ?></td>
                  <td><span class="badge badge-danger"><?= attendanceDatabaseHtml($problemRecord['time_out'] ?? '') ?></span></td>
                  <td><?= attendanceDatabaseHtml(attendanceDatabaseMovementLabel($problemRecord['movement'] ?? 0)) ?></td>
                  <td>
                    <a class="btn btn-sm btn-outline-info" href="<?= attendanceDatabaseHtml($detailUrl) ?>">
                      <i class="fa fa-calendar"></i> Otvoriť deň
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="modal fade" id="createAttendanceDatabaseModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form class="form-horizontal" method="POST" action="scripts/attendance_database_create.php">
        <div class="modal-header">
          <h4 class="modal-title"><b>Vytvoriť novú databázu dochádzky</b></h4>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="redirect" value="../index.php?page=attendance_databases">
          <div class="form-group row">
            <label for="attdn_year" class="col-sm-3 col-form-label">Rok</label>
            <div class="col-sm-9">
              <input type="number" class="form-control" id="attdn_year" name="attdn_year"
                     min="2000" max="2100" value="<?= (int)$suggestedAttendanceYear ?>" required>
              <div class="attendance-db-modal-note">
                Vytvorí sa tabuľka <code>attdn_<span id="attendance_database_preview"><?= (int)$suggestedAttendanceYear ?></span></code>.
                Existujúce tabuľky sa neprepisujú.
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">
            <i class="fa fa-close"></i> Zrušiť
          </button>
          <button type="submit" class="btn btn-primary" name="add">
            <i class="fa fa-save"></i> Vytvoriť
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
$(function () {
  $('#attdn_year').on('input change', function () {
    $('#attendance_database_preview').text($(this).val() || 'YYYY');
  });
});
</script>
