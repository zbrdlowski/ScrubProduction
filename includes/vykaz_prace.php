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
// item_type_code hodnoty v order_items: G = Graphics, F = Fitting, P = Plastics, S = Seat Cover
$reportItemTypes = ['G' => 'Graphics', 'S' => 'Seat Covers', 'F' => 'Fitting'];

// position.id hodnoty pre pracovníkov, ktorých chceme vidieť vo filtri "Pracovník"
// (2 = Graphics, 8 = Seat Covers Production, 9 = Production - Fitting)
$reportPositionIds = [2, 8, 9];

// ── DOČASNÝ CENNÍK SUBDODÁVATEĽSKEJ PRÁCE ─────────────────────────────
// Ceny sú zámerne sústredené na jednom mieste. Po dodaní reálneho cenníka
// stačí zmeniť hodnoty unit_price; uložené hodnoty položiek sa nemenia.
$subcontractorRateCards = [
  'S' => [
    'csa' => ['label' => 'CSA', 'unit_price' => 12.50],
    'sa'  => ['label' => 'SA',  'unit_price' => 10.00],
    'csb' => ['label' => 'CSB', 'unit_price' => 15.00],
    'sb'  => ['label' => 'SB',  'unit_price' => 12.00],
    'csc' => ['label' => 'CSC', 'unit_price' => 17.50],
    'sc'  => ['label' => 'SC',  'unit_price' => 14.00],
    'sd'  => ['label' => 'SD',  'unit_price' => 20.00],
  ],
  'F' => [
    'jm-1'   => ['label' => 'JM-1',   'unit_price' => 8.00],
    'jm-2'   => ['label' => 'JM-2',   'unit_price' => 12.00],
    'jm-3-a' => ['label' => 'JM-3-A', 'unit_price' => 16.00],
    'jm-3-b' => ['label' => 'JM-3-B', 'unit_price' => 18.00],
    'jm-3-c' => ['label' => 'JM-3-C', 'unit_price' => 20.00],
  ],
];

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
  'work_level' => 'Complexity / Fitting',
  'unit_price' => 'Unit Price',
  'line_total' => 'Total Price',
  'workers' => 'Worker(s)',
];

// Mapovanie item_type_code -> prefix rolí v order_assignments (PRIMARY_/COLLAB_ + tento kód)
// Používa sa na nájdenie dátumu "prevzatia" objednávky pre dané oddelenie.
$deptRolePrefix = ['G' => 'GRAPHICS', 'S' => 'SEATCOVER', 'F' => 'FITTING'];

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
$fDept = trim((string) ($_GET['dept'] ?? ''));           // '', 'G', 'S', 'F'
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

// Zoznam pracovníkov pre select (Graphics + Seat Covers + Fitting)
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

// ── HLAVNÝ QUERY: order_items (G/S/F) + orders + priradení pracovníci ───
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
    oi.options_json,
    oi.internal_options_json,
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
// Graphics končí prechodom do RIP, Seat Covers a Fitting prechodom do READY. Používame
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
        OR (UPPER(TRIM(oi_done.item_type_code)) IN ('S', 'F') AND UPPER(TRIM(ois.new_status)) = 'READY')
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
$readSubcontractorLevel = static function (array $row): string {
  $department = strtoupper(trim((string) ($row['item_type_code'] ?? '')));
  $sourceKey = $department === 'S' ? 'seat' : ($department === 'F' ? 'fitting' : '');
  if ($sourceKey === '') {
    return '';
  }

  $options = json_decode((string) ($row['options_json'] ?? ''), true);
  $internalOptions = json_decode((string) ($row['internal_options_json'] ?? ''), true);
  $options = is_array($options) ? $options : [];
  $internalOptions = is_array($internalOptions) ? $internalOptions : [];

  $internalKey = '_' . $sourceKey;
  $value = $internalOptions[$internalKey] ?? $options[$sourceKey] ?? '';
  return is_scalar($value) ? strtolower(trim((string) $value)) : '';
};

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
  $workLevelKey = $readSubcontractorLevel($r);
  $rate = $subcontractorRateCards[$itemDept][$workLevelKey] ?? null;
  $quantity = max(1, (int) ($r['qty'] ?? 1));
  $unitPrice = $rate !== null ? (float) $rate['unit_price'] : null;
  $lineTotal = $unitPrice !== null ? $unitPrice * $quantity : null;
  $displayRows[] = [
    'order_number' => $r['order_number'],
    'order_date' => $r['order_date'],
    'started_at' => $startedAt,
    'completed_at' => $completedAt,
    'estimated_time' => $estimatedDuration['label'],
    'estimated_time_seconds' => $estimatedDuration['seconds'],
    'department' => $reportItemTypes[$itemDept] ?? $itemDept,
    'item_title' => $itemLabel . ($quantity > 1 ? ' (x' . $quantity . ')' : ''),
    'work_level' => $rate['label'] ?? ($workLevelKey !== '' ? strtoupper($workLevelKey) : '—'),
    'unit_price' => $unitPrice !== null ? number_format($unitPrice, 2, '.', '') . ' €' : '—',
    'unit_price_value' => $unitPrice,
    'line_total' => $lineTotal !== null ? number_format($lineTotal, 2, '.', '') . ' €' : '—',
    'line_total_value' => $lineTotal,
    'department_code' => $itemDept,
    'quantity' => $quantity,
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
            <div class="input-group input-group-sm date job-report-date-picker" id="dateFromPicker" data-target-input="nearest">
              <input type="text" class="form-control form-control-sm datetimepicker-input" id="dateFromDisplay"
                     value="<?= htmlspecialchars($dtFrom->format('d.m.Y')) ?>" data-target="#dateFromPicker" readonly
                     aria-label="Date From in day, month, year format">
              <div class="input-group-append" data-target="#dateFromPicker" data-toggle="datetimepicker">
                <div class="input-group-text"><i class="far fa-calendar-alt"></i></div>
              </div>
            </div>
            <input type="hidden" name="date_from" id="dateFrom" value="<?= htmlspecialchars($fDateFrom) ?>">
          </div>
          <div class="col-auto">
            <label class="mb-1">Date To <small class="text-muted">(max <?= (int) $maxRangeDays ?> days range)</small></label>
            <div class="input-group input-group-sm date job-report-date-picker" id="dateToPicker" data-target-input="nearest">
              <input type="text" class="form-control form-control-sm datetimepicker-input" id="dateToDisplay"
                     value="<?= htmlspecialchars($dtTo->format('d.m.Y')) ?>" data-target="#dateToPicker" readonly
                     aria-label="Date To in day, month, year format">
              <div class="input-group-append" data-target="#dateToPicker" data-toggle="datetimepicker">
                <div class="input-group-text"><i class="far fa-calendar-alt"></i></div>
              </div>
            </div>
            <input type="hidden" name="date_to" id="dateTo" value="<?= htmlspecialchars($fDateTo) ?>">
          </div>
          <div class="col-auto">
            <label class="mb-1">Department</label>
            <select class="form-control form-control-sm" name="dept">
              <option value="">All (Graphics + Seat Covers + Fitting)</option>
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

  .job-report-date-picker {
    width: 142px;
  }

  .job-report-date-picker input[readonly] {
    cursor: pointer;
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

  #vykazTable tfoot th {
    padding: 10px 16px !important;
    border-top: 2px solid #20c997;
    background: #252b31;
    color: #f8f9fa;
    white-space: nowrap;
  }

  #vykazTable tfoot [data-summary-total] {
    color: #69e0ba;
    font-size: 1rem;
  }

  #vykazTable_wrapper .dt-buttons {
    margin: 0;
  }

  #vykazTable_wrapper .dataTables_filter {
    margin: 0;
  }

  #vykazTable_wrapper .dataTables_filter label,
  #vykazTable_wrapper .dataTables_length label {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0;
    white-space: nowrap;
  }

  #vykazTable_wrapper .dataTables_filter input {
    margin-left: 0;
  }

  .job-report-table-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px 18px;
    margin-bottom: 12px;
  }

  .job-report-table-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
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

  @page {
    size: A4 landscape;
    margin: 10mm;
  }

  @media print {
    #vykazTable {
      width: 100% !important;
      font-size: 10px;
    }

    #vykazTable th,
    #vykazTable td {
      padding: 5px 7px !important;
      white-space: normal !important;
    }

    #vykazTable tfoot th {
      padding: 5px 7px !important;
    }

    .job-report-day-separator-row > td {
      padding: 4px 0 !important;
    }
  }
</style>

      <?php if (!$hasSubmitted): ?>
        <div class="alert alert-info">
          Select a date range (and optionally a department/worker) and click <strong>Filter</strong> to generate the report.
          The order volume is large (~30k/year), so the report is not loaded until you filter.
        </div>
      <?php else: ?>

      <div class="alert alert-warning py-2">
        <strong>Temporary pricing:</strong> Seat Covers and Fitting prices are test values. Replace the rate card in
        <code>includes/vykaz_prace.php</code> before using this report as an invoice attachment.
      </div>

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
                data-report-day-label="<?= htmlspecialchars($rowDayLabel, ENT_QUOTES, 'UTF-8') ?>"
                data-report-department="<?= htmlspecialchars((string) ($dr['department_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-report-quantity="<?= (int) ($dr['quantity'] ?? 0) ?>"
                data-report-priced="<?= ($dr['line_total_value'] ?? null) !== null ? '1' : '0' ?>"
                data-report-line-total="<?= htmlspecialchars((string) ($dr['line_total_value'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
              <?php foreach (array_keys($reportColumns) as $colKey): ?>
                <?php
                $sortAttribute = '';
                if ($colKey === 'estimated_time') {
                  $sortSeconds = $dr['estimated_time_seconds'] ?? null;
                  $sortAttribute = ' data-order="' . ($sortSeconds === null ? -1 : (int) $sortSeconds) . '"';
                } elseif (in_array($colKey, ['unit_price', 'line_total'], true)) {
                  $sortValue = $dr[$colKey . '_value'] ?? null;
                  $sortAttribute = ' data-order="' . ($sortValue === null ? -1 : (float) $sortValue) . '"';
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
        <tfoot>
          <?php foreach (['S' => 'Seat Covers', 'F' => 'Fitting'] as $summaryCode => $summaryLabel): ?>
            <tr class="job-report-summary-row"
                data-report-summary="<?= htmlspecialchars($summaryCode, ENT_QUOTES, 'UTF-8') ?>"
                data-summary-label="<?= htmlspecialchars($summaryLabel, ENT_QUOTES, 'UTF-8') ?>">
              <th colspan="9" class="text-right">
                <?= htmlspecialchars($summaryLabel) ?> total
                <span class="font-weight-normal text-muted" data-summary-detail></span>
              </th>
              <th class="text-right" data-summary-total>0.00 €</th>
              <th></th>
            </tr>
          <?php endforeach; ?>
        </tfoot>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  $(function () {
    // Dátumy sú používateľovi vždy zobrazené ako DD.MM.RRRR. Hidden inputy
    // ponechávajú serverový formát YYYY-MM-DD, takže databázový filter ostáva stabilný.
    var maxRangeDays = <?= (int) $maxRangeDays ?>;
    var todayLimit = moment('<?= htmlspecialchars($today->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>', 'YYYY-MM-DD', true).endOf('day');
    var initialFrom = moment($('#dateFrom').val(), 'YYYY-MM-DD', true);
    var initialTo = moment($('#dateTo').val(), 'YYYY-MM-DD', true);

    $('#dateFromPicker').datetimepicker({
      format: 'DD.MM.YYYY',
      useCurrent: false,
      defaultDate: initialFrom,
      maxDate: todayLimit,
      ignoreReadonly: true,
      allowInputToggle: true
    });
    $('#dateToPicker').datetimepicker({
      format: 'DD.MM.YYYY',
      useCurrent: false,
      defaultDate: initialTo,
      maxDate: todayLimit,
      ignoreReadonly: true,
      allowInputToggle: true
    });

    function clampDateTo() {
      var from = moment($('#dateFrom').val(), 'YYYY-MM-DD', true);
      if (!from.isValid()) return;

      var maxTo = from.clone().add(maxRangeDays, 'days');
      if (maxTo.isAfter(todayLimit)) maxTo = todayLimit.clone();

      $('#dateToPicker').datetimepicker('minDate', from.clone().startOf('day'));
      $('#dateToPicker').datetimepicker('maxDate', maxTo.clone().endOf('day'));

      var currentTo = moment($('#dateTo').val(), 'YYYY-MM-DD', true);
      if (!currentTo.isValid() || currentTo.isBefore(from, 'day') || currentTo.isAfter(maxTo, 'day')) {
        $('#dateToPicker').datetimepicker('date', maxTo.clone());
      }
    }

    $('#dateFromPicker').on('change.datetimepicker', function (event) {
      if (!event.date) return;
      $('#dateFrom').val(event.date.format('YYYY-MM-DD'));
      clampDateTo();
    });
    $('#dateToPicker').on('change.datetimepicker', function (event) {
      if (!event.date) return;
      $('#dateTo').val(event.date.format('YYYY-MM-DD'));
    });

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

    function updateJobReportSummary(dataTableApi) {
      var summaries = {
        S: { pricedQuantity: 0, unpricedQuantity: 0, total: 0 },
        F: { pricedQuantity: 0, unpricedQuantity: 0, total: 0 }
      };

      $(dataTableApi.rows({ search: 'applied' }).nodes()).each(function () {
        var $row = $(this);
        var department = String($row.attr('data-report-department') || '');
        if (!summaries[department]) return;

        var quantity = parseInt($row.attr('data-report-quantity'), 10) || 0;
        if ($row.attr('data-report-priced') === '1') {
          summaries[department].pricedQuantity += quantity;
          summaries[department].total += parseFloat($row.attr('data-report-line-total')) || 0;
        } else {
          summaries[department].unpricedQuantity += quantity;
        }
      });

      Object.keys(summaries).forEach(function (department) {
        var summary = summaries[department];
        var $footerRow = $('#vykazTable tfoot [data-report-summary="' + department + '"]');
        var totalQuantity = summary.pricedQuantity + summary.unpricedQuantity;
        $footerRow.toggle(totalQuantity > 0);

        var detail = '(' + summary.pricedQuantity + ' priced pcs';
        if (summary.unpricedQuantity > 0) {
          detail += ', ' + summary.unpricedQuantity + ' without price';
        }
        detail += ')';

        $footerRow.find('[data-summary-detail]').text(detail);
        $footerRow.find('[data-summary-total]').text(summary.total.toFixed(2) + ' €');
      });
    }

    function jobReportExportOptions() {
      return {
        customizeData: function (data) {
          var summaryParts = [];
          var grandTotal = 0;

          $('#vykazTable tfoot [data-report-summary]:visible').each(function () {
            var $row = $(this);
            var label = String($row.attr('data-summary-label') || 'Summary');
            var detail = String($row.find('[data-summary-detail]').text() || '');
            var totalText = String($row.find('[data-summary-total]').text() || '0');
            var numericTotal = parseFloat(totalText.replace(',', '.')) || 0;
            grandTotal += numericTotal;
            summaryParts.push(label + ' ' + detail + ': ' + numericTotal.toFixed(2) + ' EUR');
          });

          data.footer = new Array(data.header.length).fill('');
          if (data.footer.length > 6) {
            data.footer[6] = summaryParts.join(' | ');
          }
          if (data.footer.length > 9) {
            data.footer[9] = grandTotal.toFixed(2) + ' EUR';
          }
        }
      };
    }

    $('#vykazTable').DataTable({
      responsive: true,
      info: true,
      searching: true,
      lengthChange: true,
      autoWidth: false,
      pageLength: 200,
      order: [],
      dom: "<'job-report-table-toolbar'<'job-report-table-actions'Bl>f>rt<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
      buttons: [
        { extend: "copy", footer: true, exportOptions: jobReportExportOptions() },
        { extend: "csv", footer: true, exportOptions: jobReportExportOptions() },
        { extend: "excel", footer: true, exportOptions: jobReportExportOptions() },
        {
          extend: "pdf",
          orientation: "landscape",
          pageSize: "A4",
          footer: true,
          exportOptions: jobReportExportOptions()
        },
        {
          extend: "print",
          footer: true,
          exportOptions: jobReportExportOptions(),
          customize: function (win) {
            $(win.document.head).append(
              '<style>@page{size:A4 landscape;margin:10mm;}#vykazTable{width:100%!important;font-size:10px;}#vykazTable th,#vykazTable td{padding:5px 7px!important;white-space:normal!important;}.job-report-day-separator-row>td{padding:4px 0!important;}</style>'
            );
          }
        },
        "colvis"
      ],
      drawCallback: function () {
        addJobReportDaySeparators(this.api());
        updateJobReportSummary(this.api());
      }
    });
    <?php endif; ?>
  });
</script>
