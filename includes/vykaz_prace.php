<?php
declare(strict_types=1);
/** @var mysqli $conn */
require_once __DIR__ . '/conn.php';

if (!isset($conn) || !$conn instanceof mysqli) {
  echo '<div class="alert alert-danger">Database connection error.</div>';
  return;
}

// ── ACL ──────────────────────────────────────────────────────────────────
// Prístup: superadmin (permission 900) alebo admini z dpt. Management (id 3).
$permission = (int) ($_SESSION['permission'] ?? 0);
$dpt = (int) ($_SESSION['dpt'] ?? 0);
$isSuperadmin = $permission === 900;

// dpt.id => minimálny permission floor pre prístup k tomuto reportu
$allowedDepartments = [
  3 => 400, // Management
  1 => 400, // Administration
];

$hasDeptAccess = isset($allowedDepartments[$dpt]) && $permission >= $allowedDepartments[$dpt];

if (!$isSuperadmin && !$hasDeptAccess) {
  echo '<div class="alert alert-danger">No permission for this page.</div>';
  return;
}

// ── Konfigurácia oddelení pre výkaz ─────────────────────────────────────
// item_type_code hodnoty v order_items: G = Graphics, F = Fitting, P = Plastics, S = Seatcover
// Výkaz je zameraný na Graphics + Fitting, ale necháme to konfigurovateľné.
$reportItemTypes = ['G' => 'Graphics', 'F' => 'Fitting'];

// position.id hodnoty pre pracovníkov, ktorých chceme vidieť vo filtri "Pracovník"
// (2 = Graphics, 9 = Fitting podľa deptCodeMap v orders.php)
$reportPositionIds = [2, 9];

// ── STĹPCE VÝKAZU ────────────────────────────────────────────────────────
// Pridávanie nového stĺpca = pridanie položky sem + naplnenie hodnoty nižšie
// v $rows cykle (označené komentárom "NOVÝ STĹPEC"). Nie je potrebné meniť
// štruktúru tabuľky ani hlavičku manuálne.
$reportColumns = [
  'order_number' => 'Order Number',
  'order_date' => 'Order Date',
  'started_at' => 'Taken (Take/Assign)',
  'completed_at' => 'Work Completed',
  'estimated_time' => 'Estimated Time',
  'department' => 'Department',
  'item_title' => 'Item',
  'workers' => 'Worker(s)',
];

// Mapovanie item_type_code -> prefix rolí v order_assignments (PRIMARY_/COLLAB_ + tento kód)
// Používa sa na nájdenie dátumu "prevzatia" objednávky pre dané oddelenie.
$deptRolePrefix = ['G' => 'GRAPHICS', 'F' => 'FITTING'];

// ── LIMITY ───────────────────────────────────────────────────────────────
// Objednávok pribúda cca 30k/rok, takže report NESMIE bežať bez filtra a
// nesmie dovoliť neobmedzený dátumový rozsah (napr. 1970–2070), inak padne
// na pamäti/timeoute. Max rozsah je nastaviteľný cez $maxRangeDays nižšie.
$maxRangeDays = 92; // ~3 mesiace
$hasSubmitted = isset($_GET['submitted']); // formulár má hidden input 'submitted'
$rangeWasClamped = false;

// ── FILTRE ───────────────────────────────────────────────────────────────
$fDateFrom = trim((string) ($_GET['date_from'] ?? ''));
$fDateTo = trim((string) ($_GET['date_to'] ?? ''));
$fDept = trim((string) ($_GET['dept'] ?? ''));           // '', 'G', 'F'
$fWorker = (int) ($_GET['worker'] ?? 0);
$fOnlyCompleted = (isset($_GET['only_completed']) && $_GET['only_completed'] === '1')
  // Spätná kompatibilita so starými uloženými URL reportu.
  || (isset($_GET['only_shipped']) && $_GET['only_shipped'] === '1');

// Validácia a orezanie dátumového rozsahu. Robí sa VŽDY (aj pri prvom
// načítaní bez odoslaného filtra), aby mal formulár rozumný prednastavený
// rozsah namiesto prázdnych/neobmedzených polí.
$today = new DateTimeImmutable('today');
$dtFrom = DateTimeImmutable::createFromFormat('Y-m-d', $fDateFrom) ?: null;
$dtTo = DateTimeImmutable::createFromFormat('Y-m-d', $fDateTo) ?: null;

if (!$dtFrom) {
  $dtFrom = $today->modify('-30 days');
}
if (!$dtTo) {
  $dtTo = $today;
}
if ($dtFrom > $dtTo) {
  [$dtFrom, $dtTo] = [$dtTo, $dtFrom];
}
if ($dtFrom->diff($dtTo)->days > $maxRangeDays) {
  $dtTo = $dtFrom->modify("+{$maxRangeDays} days");
  $rangeWasClamped = true;
}

$fDateFrom = $dtFrom->format('Y-m-d');
$fDateTo = $dtTo->format('Y-m-d');

// Zoznam pracovníkov pre select (Graphics + Fitting)
// employees.active je varchar('Active'/'Inactive'...), rovnaký stĺpec, aký sa
// používa aj vo filtri dochádzkového reportu pre účtovné oddelenie.
//
// POZOR do budúcna: `active` bude pravdepodobne nahradené/doplnené iným
// stĺpcom, ktorý bude riešiť viditeľnosť v tabuľkách/reportoch nezávisle od
// pracovného pomeru (napr. externí subdodávatelia, ktorí sú "Inactive", ale
// reálne pracujú a majú sa objaviť vo výkaze). Keď ten stĺpec pribudne, stačí
// upraviť podmienku nižšie (alebo skombinovať oba stĺpce), netreba meniť nič
// iné v tomto súbore.
$empActiveWhere = "AND active = 'Active'";

$workerOptions = [];
$posPh = implode(',', array_fill(0, count($reportPositionIds), '?'));
$stmt = $conn->prepare("SELECT id, firstname, lastname, position_id
  FROM employees
  WHERE position_id IN ($posPh)
  $empActiveWhere
  ORDER BY firstname, lastname
");
if ($stmt) {
  $types = str_repeat('i', count($reportPositionIds));
  $stmt->bind_param($types, ...$reportPositionIds);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $workerOptions[] = $row;
  }
  $stmt->close();
}

// ── HLAVNÝ QUERY: order_items (G/F) + orders + priradení pracovníci ─────
// Beží iba ak používateľ formulár skutočne odoslal (submitted=1) — pri prvom
// načítaní stránky sa report nenačítava, aby sme zbytočne nezaťažovali DB.
$rows = [];
$orderIds = [];
$itemCompletionDates = [];
$startDates = [];

if ($hasSubmitted):
$itemTypeCodes = $fDept !== '' && isset($reportItemTypes[$fDept])
  ? [$fDept]
  : array_keys($reportItemTypes);

$where = [
  'oi.deleted_at IS NULL',
  "oi.item_type_code IN (" . implode(',', array_fill(0, count($itemTypeCodes), '?')) . ")",
];
$params = $itemTypeCodes;
$paramTypes = str_repeat('s', count($itemTypeCodes));

if ($fDateFrom !== '') {
  $where[] = 'DATE(o.order_date) >= ?';
  $params[] = $fDateFrom;
  $paramTypes .= 's';
}
if ($fDateTo !== '') {
  $where[] = 'DATE(o.order_date) <= ?';
  $params[] = $fDateTo;
  $paramTypes .= 's';
}
if ($fWorker > 0) {
  $where[] = "EXISTS (
    SELECT 1 FROM order_item_assignments oia_f
    WHERE oia_f.item_id = oi.id
      AND oia_f.employee_id = ?
      AND oia_f.removed_at IS NULL
  )";
  $params[] = $fWorker;
  $paramTypes .= 'i';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT
    o.id AS order_id,
    o.order_number,
    o.order_date,
    o.status AS order_status,
    oi.id AS item_id,
    oi.item_type_code,
    oi.title,
    oi.custom_label,
    oi.qty,
    (
      SELECT GROUP_CONCAT(DISTINCT CONCAT(e.firstname, ' ', e.lastname) ORDER BY e.firstname, e.lastname SEPARATOR ', ')
      FROM order_item_assignments oia
      JOIN employees e ON e.id = oia.employee_id
      WHERE oia.item_id = oi.id
        AND oia.removed_at IS NULL
    ) AS workers
  FROM order_items oi
  JOIN orders o ON o.id = oi.order_id
  $whereSql
  ORDER BY o.order_date DESC, o.order_number, COALESCE(oi.line_no, 999999), oi.id
  LIMIT 2000
";

$stmt = $conn->prepare($sql);
if ($stmt) {
  $stmt->bind_param($paramTypes, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
  }
  $stmt->close();
}

// ── Dátum dokončenia práce na konkrétnej položke ────────────────────────────
// Graphics končí prechodom do RIP, Fitting prechodom do READY. Používame
// históriu statusov položky, nie aktuálny stav ani odoslanie objednávky.
$orderIds = array_values(array_unique(array_map(fn($r) => (int) $r['order_id'], $rows)));
$itemIds = array_values(array_unique(array_map(fn($r) => (int) $r['item_id'], $rows)));
$itemCompletionDates = [];

if ($itemIds) {
  $itemIdPh = implode(',', array_fill(0, count($itemIds), '?'));
  $completionSql = "SELECT
      ois.order_item_id,
      MAX(ois.changed_at) AS completed_at
    FROM order_item_statuses ois
    JOIN order_items oi_done ON oi_done.id = ois.order_item_id
    WHERE ois.order_item_id IN ($itemIdPh)
      AND (
        (UPPER(TRIM(oi_done.item_type_code)) = 'G' AND UPPER(TRIM(ois.new_status)) = 'RIP')
        OR
        (UPPER(TRIM(oi_done.item_type_code)) = 'F' AND UPPER(TRIM(ois.new_status)) = 'READY')
      )
    GROUP BY ois.order_item_id
  ";
  $completionStmt = $conn->prepare($completionSql);
  if ($completionStmt) {
    $completionTypes = str_repeat('i', count($itemIds));
    $completionStmt->bind_param($completionTypes, ...$itemIds);
    $completionStmt->execute();
    $completionRes = $completionStmt->get_result();
    while ($completionRow = $completionRes->fetch_assoc()) {
      $itemCompletionDates[(int) $completionRow['order_item_id']] = (string) $completionRow['completed_at'];
    }
    $completionStmt->close();
  }
}

// ── Dátum "prevzatia" objednávky pre dané oddelenie (Take / Assign to) ──
// Berie sa z order_assignments (PRIMARY_/COLLAB_ rola pre G alebo F), najskorší
// nezrušený záznam = moment, kedy si pracovník/oddelenie objednávku prevzalo.
// Stĺpec s časom sa hľadá dynamicky, keďže presný názov nepoznáme naisto.
$startDates = []; // [$orderId][$deptCode] => datetime

if ($orderIds) {
  $oaColsRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_assignments'");
  $oaCols = [];
  if ($oaColsRes) {
    while ($c = $oaColsRes->fetch_assoc()) {
      $oaCols[] = $c['COLUMN_NAME'];
    }
  }
  $oaDateCol = '';
  foreach (['created_at', 'assigned_at', 'taken_at'] as $cand) {
    if (in_array($cand, $oaCols, true)) {
      $oaDateCol = $cand;
      break;
    }
  }

  if ($oaDateCol !== '') {
    foreach ($deptRolePrefix as $deptCode => $rolePrefix) {
      $idPh = implode(',', array_fill(0, count($orderIds), '?'));
      $sql4 = "SELECT order_id, MIN(`$oaDateCol`) AS started_at
        FROM order_assignments
        WHERE order_id IN ($idPh)
          AND role IN (?, ?)
          AND removed_at IS NULL
        GROUP BY order_id
      ";
      $stmt4 = $conn->prepare($sql4);
      if ($stmt4) {
        $types4 = str_repeat('i', count($orderIds)) . 'ss';
        $roleParams = array_merge($orderIds, ["PRIMARY_$rolePrefix", "COLLAB_$rolePrefix"]);
        $stmt4->bind_param($types4, ...$roleParams);
        $stmt4->execute();
        $res4 = $stmt4->get_result();
        while ($r4 = $res4->fetch_assoc()) {
          $startDates[(int) $r4['order_id']][$deptCode] = (string) $r4['started_at'];
        }
        $stmt4->close();
      }
    }
  }
}
endif; // hasSubmitted

// ── Príprava riadkov na vykreslenie ──────────────────────────────────────
$formatEstimatedDuration = static function (?string $startedAt, ?string $completedAt): array {
  if (!$startedAt || !$completedAt) {
    return ['label' => '—', 'seconds' => null];
  }

  $startedTimestamp = strtotime($startedAt);
  $completedTimestamp = strtotime($completedAt);
  if ($startedTimestamp === false || $completedTimestamp === false || $completedTimestamp < $startedTimestamp) {
    return ['label' => '—', 'seconds' => null];
  }

  $elapsedSeconds = $completedTimestamp - $startedTimestamp;
  $totalMinutes = (int) floor($elapsedSeconds / 60);
  if ($totalMinutes < 1) {
    return ['label' => '< 1 min', 'seconds' => $elapsedSeconds];
  }

  $days = intdiv($totalMinutes, 1440);
  $hours = intdiv($totalMinutes % 1440, 60);
  $minutes = $totalMinutes % 60;
  $parts = [];
  if ($days > 0) {
    $parts[] = $days . ' d';
  }
  if ($hours > 0) {
    $parts[] = $hours . ' h';
  }
  $parts[] = $minutes . ' min';

  return ['label' => implode(' ', $parts), 'seconds' => $elapsedSeconds];
};

$displayRows = [];
foreach ($rows as $r) {
  $orderId = (int) $r['order_id'];
  $completedAt = $itemCompletionDates[(int) $r['item_id']] ?? null;

  if ($fOnlyCompleted && $completedAt === null) {
    continue;
  }

  $itemLabel = trim((string) ($r['title'] ?? '')) !== ''
    ? (string) $r['title']
    : (string) ($r['custom_label'] ?? '');

  $itemDept = $r['item_type_code'];
  $startedAt = $startDates[$orderId][$itemDept] ?? null;
  $estimatedDuration = $formatEstimatedDuration($startedAt, $completedAt);
  $displayRows[] = [
    'order_number' => $r['order_number'],
    'order_date' => $r['order_date'],
    'started_at' => $startedAt,
    'completed_at' => $completedAt,
    'estimated_time' => $estimatedDuration['label'],
    'estimated_time_seconds' => $estimatedDuration['seconds'],
    'department' => $reportItemTypes[$itemDept] ?? $itemDept,
    'item_title' => $itemLabel . ($r['qty'] > 1 ? ' (x' . (int) $r['qty'] . ')' : ''),
    'workers' => $r['workers'] ?: '—',
    // NOVÝ STĹPEC: sem pridaj ďalší kľúč zodpovedajúci $reportColumns vyššie
  ];
}
?>

<div class="container-fluid">
  <div class="card card-dark">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-file-invoice mr-1"></i> Job Reports</h3>
    </div>

    <div class="card-body">
      <form method="GET" class="mb-3">
        <input type="hidden" name="page" value="vykaz_prace">
        <input type="hidden" name="submitted" value="1">
        <div class="form-row align-items-end">
          <div class="col-auto">
            <label class="mb-1">Date From</label>
            <input type="date" class="form-control form-control-sm" name="date_from" id="dateFrom" max="<?= htmlspecialchars($today->format('Y-m-d')) ?>" value="<?= htmlspecialchars($fDateFrom) ?>">
          </div>
          <div class="col-auto">
            <label class="mb-1">Date To <small class="text-muted">(max <?= (int) $maxRangeDays ?> days range)</small></label>
            <input type="date" class="form-control form-control-sm" name="date_to" id="dateTo" max="<?= htmlspecialchars($today->format('Y-m-d')) ?>" value="<?= htmlspecialchars($fDateTo) ?>">
          </div>
          <div class="col-auto">
            <label class="mb-1">Department</label>
            <select class="form-control form-control-sm" name="dept">
              <option value="">All (Graphics + Fitting)</option>
              <?php foreach ($reportItemTypes as $code => $label): ?>
                <option value="<?= htmlspecialchars($code) ?>" <?= $fDept === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <label class="mb-1">Worker</label>
            <select class="form-control form-control-sm" name="worker">
              <option value="0">All</option>
              <?php foreach ($workerOptions as $w): ?>
                <option value="<?= (int) $w['id'] ?>" <?= $fWorker === (int) $w['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($w['firstname'] . ' ' . $w['lastname']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <div class="custom-control custom-checkbox mt-4">
              <input type="checkbox" class="custom-control-input" id="onlyCompleted" name="only_completed" value="1" <?= $fOnlyCompleted ? 'checked' : '' ?>>
              <label class="custom-control-label" for="onlyCompleted">Only Completed</label>
            </div>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm mt-4">Filter</button>
            <a href="index.php?page=vykaz_prace" class="btn btn-secondary btn-sm mt-4">Reset</a>
          </div>
        </div>
      </form>

      <?php if ($rangeWasClamped): ?>
        <div class="alert alert-warning py-2">
          The selected date range was larger than <?= (int) $maxRangeDays ?> days, so it was automatically limited to
          <strong><?= htmlspecialchars($fDateFrom) ?> – <?= htmlspecialchars($fDateTo) ?></strong>.
        </div>
      <?php endif; ?>

<style>
  #vykazTable {
    border-collapse: separate;
    border-spacing: 0;
  }

  #vykazTable th,
  #vykazTable td {
    padding: 12px 16px !important;
    vertical-align: middle;
    line-height: 1.5;
  }

  #vykazTable thead th {
    padding-top: 14px !important;
    padding-bottom: 14px !important;
    white-space: nowrap;
    background-color: #161616;
  }

  #vykazTable_wrapper .dt-buttons {
    margin-bottom: 12px;
  }

  #vykazTable_wrapper .dataTables_filter {
    margin-bottom: 12px;
  }

  .job-report-day-separator-row > td {
    padding: 7px 0 6px !important;
    border-top: 0 !important;
    border-bottom: 0 !important;
    background: #171b20 !important;
  }

  .job-report-day-separator {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #9ed6ff;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    white-space: nowrap;
  }

  .job-report-day-separator::before,
  .job-report-day-separator::after {
    content: "";
    flex: 1 1 auto;
    height: 1px;
    background: linear-gradient(90deg, rgba(63, 158, 255, .12), rgba(63, 158, 255, .72));
  }

  .job-report-day-separator::after {
    background: linear-gradient(90deg, rgba(63, 158, 255, .72), rgba(63, 158, 255, .12));
  }

  .job-order-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 8px;
    border: 1px solid rgba(23, 162, 184, .5);
    border-radius: 6px;
    background: rgba(23, 162, 184, .12);
    color: #67d5e8 !important;
    font-weight: 700;
    line-height: 1.35;
    white-space: nowrap;
    text-decoration: none !important;
    transition: background-color .15s ease, border-color .15s ease, color .15s ease, transform .15s ease;
  }

  .job-order-link i {
    font-size: .72em;
    opacity: .8;
  }

  .job-order-link:hover,
  .job-order-link:focus-visible {
    color: #c0f4fb !important;
    background: rgba(23, 162, 184, .28);
    border-color: rgba(103, 213, 232, .9);
    transform: translateY(-1px);
    box-shadow: 0 2px 7px rgba(0, 0, 0, .2);
    outline: none;
  }
</style>

      <?php if (!$hasSubmitted): ?>
        <div class="alert alert-info">
          Select a date range (and optionally a department/worker) and click <strong>Filter</strong> to generate the report.
          The order volume is large (~30k/year), so the report is not loaded until you filter.
        </div>
      <?php else: ?>

      <table class="table table-bordered table-striped" id="vykazTable">
        <thead>
          <tr>
            <?php foreach ($reportColumns as $label): ?>
              <th><?= htmlspecialchars($label) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$displayRows): ?>
            <tr>
              <td colspan="<?= count($reportColumns) ?>" class="text-center text-muted">No records for the selected filters.</td>
            </tr>
          <?php endif; ?>
          <?php foreach ($displayRows as $dr): ?>
            <?php
            $rowDayTimestamp = !empty($dr['order_date']) ? strtotime((string) $dr['order_date']) : false;
            $rowDayKey = $rowDayTimestamp !== false ? date('Y-m-d', $rowDayTimestamp) : 'unknown';
            $rowDayLabel = $rowDayTimestamp !== false ? date('d.m.Y', $rowDayTimestamp) : 'Unknown date';
            ?>
            <tr data-report-day="<?= htmlspecialchars($rowDayKey, ENT_QUOTES, 'UTF-8') ?>"
                data-report-day-label="<?= htmlspecialchars($rowDayLabel, ENT_QUOTES, 'UTF-8') ?>">
              <?php foreach (array_keys($reportColumns) as $colKey): ?>
                <?php
                $sortAttribute = '';
                if ($colKey === 'estimated_time') {
                  $sortSeconds = $dr['estimated_time_seconds'] ?? null;
                  $sortAttribute = ' data-order="' . ($sortSeconds === null ? -1 : (int) $sortSeconds) . '"';
                }
                ?>
                <td<?= $sortAttribute ?>>
                  <?php
                  $val = $dr[$colKey] ?? '';
                  if ($colKey === 'order_number' && trim((string) $val) !== '') {
                    $orderNumber = trim((string) $val);
                    $orderUrl = 'index.php?' . http_build_query([
                      'page' => 'orders',
                      'q' => $orderNumber,
                    ]);
                    echo '<a class="job-order-link" href="' . htmlspecialchars($orderUrl, ENT_QUOTES, 'UTF-8') . '" title="Open order in Orders">'
                      . htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8')
                      . '<i class="fas fa-arrow-right" aria-hidden="true"></i>'
                      . '</a>';
                  } elseif (in_array($colKey, ['order_date', 'started_at', 'completed_at'], true)) {
                    echo $val ? htmlspecialchars(date('d.m.Y H:i', strtotime((string) $val))) : '—';
                  } else {
                    echo htmlspecialchars((string) $val);
                  }
                  ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  $(function () {
    // Client-side pomôcka: obmedz "Date To" na max <?= (int) $maxRangeDays ?> dní od "Date From".
    // Server aj tak rozsah oreže, toto len zabráni zbytočnému submitu s príliš veľkým rozsahom.
    var maxRangeDays = <?= (int) $maxRangeDays ?>;
    function clampDateTo() {
      var from = $('#dateFrom').val();
      if (!from) return;
      var fromDate = new Date(from);
      var maxTo = new Date(fromDate);
      maxTo.setDate(maxTo.getDate() + maxRangeDays);
      var maxToStr = maxTo.toISOString().slice(0, 10);
      $('#dateTo').attr('max', maxToStr);
      $('#dateTo').attr('min', from);
      if ($('#dateTo').val() && $('#dateTo').val() > maxToStr) {
        $('#dateTo').val(maxToStr);
      }
    }
    $('#dateFrom').on('change', clampDateTo);
    clampDateTo();

    <?php if ($hasSubmitted): ?>
    function addJobReportDaySeparators(dataTableApi) {
      var $body = $(dataTableApi.table().body());
      $body.find('tr.job-report-day-separator-row').remove();

      var previousDay = null;
      $(dataTableApi.rows({ page: 'current', search: 'applied' }).nodes()).each(function () {
        var $row = $(this);
        var dayKey = String($row.attr('data-report-day') || 'unknown');
        if (dayKey === previousDay) return;

        previousDay = dayKey;
        var dayLabel = String($row.attr('data-report-day-label') || 'Unknown date');
        $('<tr/>', { class: 'job-report-day-separator-row' })
          .append(
            $('<td/>', { colspan: <?= count($reportColumns) ?> }).append(
              $('<div/>', { class: 'job-report-day-separator' }).append(
                $('<span/>').text(dayLabel)
              )
            )
          )
          .insertBefore($row);
      });
    }

    $('#vykazTable').DataTable({
      responsive: true,
      info: true,
      searching: true,
      lengthChange: true,
      autoWidth: false,
      pageLength: 200,
      order: [],
      dom: 'Bfrtip',
      buttons: ["copy", "csv", "excel", "pdf", "print", "colvis"],
      drawCallback: function () {
        addJobReportDaySeparators(this.api());
      }
    }).buttons().container().appendTo('#vykazTable_wrapper .col-md-6:eq(0)');
    <?php endif; ?>
  });
</script>
