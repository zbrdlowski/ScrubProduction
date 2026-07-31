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
  'shipped_at' => 'Completion Date (Shipped)',
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
$fOnlyShipped = isset($_GET['only_shipped']) && $_GET['only_shipped'] === '1';

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
$shippedDates = [];
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

// ── Dátum "Shipped" pre všetky nájdené objednávky ────────────────────────
// 1) Preferovaný zdroj: order_status_history (ak existuje).
// 2) Fallback: priamy stĺpec na orders (shipped_at / shipped_date), ak existuje.
$orderIds = array_values(array_unique(array_map(fn($r) => (int) $r['order_id'], $rows)));
$shippedDates = [];

if ($orderIds) {
  $historyColsRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_status_history'");
  $historyCols = [];
  if ($historyColsRes) {
    while ($c = $historyColsRes->fetch_assoc()) {
      $historyCols[] = $c['COLUMN_NAME'];
    }
  }

  if (in_array('order_id', $historyCols, true) && in_array('new_status', $historyCols, true) && in_array('changed_at', $historyCols, true)) {
    $idPh = implode(',', array_fill(0, count($orderIds), '?'));
    $sql2 = "SELECT order_id, MAX(changed_at) AS shipped_at
      FROM order_status_history
      WHERE order_id IN ($idPh) AND UPPER(new_status) = 'SHIPPED'
      GROUP BY order_id
    ";
    $stmt2 = $conn->prepare($sql2);
    if ($stmt2) {
      $types2 = str_repeat('i', count($orderIds));
      $stmt2->bind_param($types2, ...$orderIds);
      $stmt2->execute();
      $res2 = $stmt2->get_result();
      while ($r2 = $res2->fetch_assoc()) {
        $shippedDates[(int) $r2['order_id']] = (string) $r2['shipped_at'];
      }
      $stmt2->close();
    }
  }

  // Fallback pre objednávky, ktoré ešte nemajú záznam v histórii.
  $missing = array_values(array_diff($orderIds, array_keys($shippedDates)));
  if ($missing) {
    $ordersColsRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'");
    $ordersCols = [];
    if ($ordersColsRes) {
      while ($c = $ordersColsRes->fetch_assoc()) {
        $ordersCols[] = $c['COLUMN_NAME'];
      }
    }
    $directCol = '';
    foreach (['shipped_at', 'shipped_date', 'shipped_on'] as $cand) {
      if (in_array($cand, $ordersCols, true)) {
        $directCol = $cand;
        break;
      }
    }
    if ($directCol !== '') {
      $idPh = implode(',', array_fill(0, count($missing), '?'));
      $sql3 = "SELECT id, `$directCol` AS shipped_at FROM orders WHERE id IN ($idPh) AND `$directCol` IS NOT NULL";
      $stmt3 = $conn->prepare($sql3);
      if ($stmt3) {
        $types3 = str_repeat('i', count($missing));
        $stmt3->bind_param($types3, ...$missing);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        while ($r3 = $res3->fetch_assoc()) {
          $shippedDates[(int) $r3['id']] = (string) $r3['shipped_at'];
        }
        $stmt3->close();
      }
    }
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
$displayRows = [];
foreach ($rows as $r) {
  $orderId = (int) $r['order_id'];
  $shippedAt = $shippedDates[$orderId] ?? null;

  if ($fOnlyShipped && $shippedAt === null) {
    continue;
  }

  $itemLabel = trim((string) ($r['title'] ?? '')) !== ''
    ? (string) $r['title']
    : (string) ($r['custom_label'] ?? '');

  $itemDept = $r['item_type_code'];
  $displayRows[] = [
    'order_number' => $r['order_number'],
    'order_date' => $r['order_date'],
    'started_at' => $startDates[$orderId][$itemDept] ?? null,
    'shipped_at' => $shippedAt,
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
              <input type="checkbox" class="custom-control-input" id="onlyShipped" name="only_shipped" value="1" <?= $fOnlyShipped ? 'checked' : '' ?>>
              <label class="custom-control-label" for="onlyShipped">Only Completed (Shipped)</label>
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
            <tr>
              <?php foreach (array_keys($reportColumns) as $colKey): ?>
                <td>
                  <?php
                  $val = $dr[$colKey] ?? '';
                  if (in_array($colKey, ['order_date', 'started_at', 'shipped_at'], true)) {
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
    $('#vykazTable').DataTable({
      responsive: true,
      info: true,
      searching: true,
      lengthChange: true,
      autoWidth: false,
      pageLength: 200,
      order: [],
      dom: 'Bfrtip',
      buttons: ["copy", "csv", "excel", "pdf", "print", "colvis"]
    }).buttons().container().appendTo('#vykazTable_wrapper .col-md-6:eq(0)');
    <?php endif; ?>
  });
</script>