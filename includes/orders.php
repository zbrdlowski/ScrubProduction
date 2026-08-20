<?php
declare(strict_types=1);
/** @var mysqli $conn */
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/render_assigned_users.php';
require_once __DIR__ . '/orders_status_helpers.php';
require_once __DIR__ . '/orders_workflow_helpers.php';

if (!isset($conn) || !$conn instanceof mysqli) {
  echo '<div class="alert alert-danger">Database connection error.</div>';
  return;
}

function countryFlag($code)
{
  $code = strtoupper($code);
  if (strlen($code) !== 2)
    return '🏳️';

  return mb_convert_encoding(
    '&#' . (127397 + ord($code[0])) . ';&#' . (127397 + ord($code[1])) . ';',
    'UTF-8',
    'HTML-ENTITIES'
  );
}
function normalizeTypesOrder(string $types): string
{
  $weights = [
    'G' => 1,
    'F' => 2,
    'P' => 3,
    'S' => 4
  ];

  $typesArr = str_split(strtoupper($types));

  usort($typesArr, function ($a, $b) use ($weights) {
    $wa = $weights[$a] ?? 99;
    $wb = $weights[$b] ?? 99;
    return $wa <=> $wb;
  });

  return implode('', $typesArr);
}

function ordersFollowupTypeLabel(string $type): string
{
  $type = strtoupper(trim($type));
  $map = [
    'REPEAT' => 'Repeat Order',
    'WARRANTY' => 'Warranty Claim',
    'CRASH' => 'Crash Replacement',
    'SPLIT' => 'Order Split',
  ];

  return $map[$type] ?? $type;
}

function ordersExternalOrderDisplay(string $externalOrderId, ?string $sourceMetaJson = null): string
{
  $externalOrderId = trim($externalOrderId);
  if ($externalOrderId === '') {
    return '';
  }

  $sourceMeta = json_decode((string) $sourceMetaJson, true);
  if (is_array($sourceMeta) && !empty($sourceMeta['_followup']) && is_array($sourceMeta['_followup'])) {
    $followup = $sourceMeta['_followup'];
    $label = ordersFollowupTypeLabel((string) ($followup['type'] ?? ''));
    $parentOrderNumber = trim((string) ($followup['parent_order_number'] ?? ''));
    if ($parentOrderNumber !== '') {
      return $label . ' from ' . $parentOrderNumber;
    }

    $parentOrderId = (int) ($followup['parent_order_id'] ?? 0);
    if ($parentOrderId > 0) {
      return $label . ' from order #' . $parentOrderId;
    }

    return $label;
  }

  if (preg_match('/^FOLLOWUP:(\d+):([A-Z_]+):\d+$/i', $externalOrderId, $m)) {
    $label = ordersFollowupTypeLabel((string) $m[2]);
    return $label . ' from order #' . (int) $m[1];
  }

  return $externalOrderId;
}

// Statusy, pri ktorych sa ma v stlpci Detail namiesto assign/take tlacidiel
// zobrazit datum zmeny statusu. Pre dalsie statusy neskor staci doplnit mapu.
$statusDateDetailRules = [
  'SHIPPED' => [
    'label' => 'Shipped',
    'empty' => 'Shipped date not found',
  ],
  // 'READY_TO_SHIP' => ['label' => 'Ready to Ship', 'empty' => 'Ready to Ship date not found'],
  // 'DELIVERED' => ['label' => 'Delivered', 'empty' => 'Delivered date not found'],
];

function ordersGetTableColumns(mysqli $conn, string $table): array
{
  $cols = [];
  $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return $cols;
  }
  $stmt->bind_param('s', $table);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $cols[] = (string) $row['COLUMN_NAME'];
  }
  $stmt->close();
  return $cols;
}

function ordersFirstExistingColumn(array $columns, array $candidates): string
{
  foreach ($candidates as $candidate) {
    if (in_array($candidate, $columns, true)) {
      return $candidate;
    }
  }
  return '';
}

function ordersStatusDateColumnCandidates(string $status): array
{
  $base = strtolower($status);
  $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?: $base;
  return [
    $base . '_at',
    $base . '_date',
    $base . '_on',
    $base . '_status_at',
    $base . '_status_date',
  ];
}

function ordersFetchStatusEventDates(mysqli $conn, array $orderIds, array $statuses): array
{
  $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), fn($v) => $v > 0)));
  $statuses = array_values(array_unique(array_filter(array_map(fn($v) => strtoupper(trim((string) $v)), $statuses))));

  if (!$orderIds || !$statuses) {
    return [];
  }

  $out = [];
  foreach ($orderIds as $oid) {
    $out[$oid] = [];
  }

  // 1) Ak niekedy pribudnu priame stlpce v orders (napr. shipped_at), pouziju sa prednostne.
  $orderCols = ordersGetTableColumns($conn, 'orders');
  foreach ($statuses as $status) {
    $directCol = ordersFirstExistingColumn($orderCols, ordersStatusDateColumnCandidates($status));
    if ($directCol === '') {
      continue;
    }

    $idPh = implode(',', array_fill(0, count($orderIds), '?'));
    $sql = "SELECT id, `$directCol` AS status_date FROM orders WHERE id IN ($idPh) AND `$directCol` IS NOT NULL";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      continue;
    }
    $types = str_repeat('i', count($orderIds));
    $stmt->bind_param($types, ...$orderIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $oid = (int) $row['id'];
      if (!empty($row['status_date'])) {
        $out[$oid][$status] = (string) $row['status_date'];
      }
    }
    $stmt->close();
  }

  // 2) Preferovany zdroj: samostatna historia zmien statusov.
  // Vytvor ju cez SQL migration order_status_history_migration.sql.
  $historyCols = ordersGetTableColumns($conn, 'order_status_history');
  if (
    in_array('order_id', $historyCols, true)
    && in_array('new_status', $historyCols, true)
    && in_array('changed_at', $historyCols, true)
  ) {
    $idPh = implode(',', array_fill(0, count($orderIds), '?'));
    $statusPh = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT order_id, UPPER(new_status) AS status_code, MAX(changed_at) AS status_date
            FROM order_status_history
            WHERE order_id IN ($idPh)
              AND UPPER(new_status) IN ($statusPh)
            GROUP BY order_id, UPPER(new_status)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $types = str_repeat('i', count($orderIds)) . str_repeat('s', count($statuses));
      $params = array_merge($orderIds, $statuses);
      $stmt->bind_param($types, ...$params);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $oid = (int) $row['order_id'];
        $status = strtoupper((string) $row['status_code']);
        if (!empty($row['status_date'])) {
          $out[$oid][$status] = (string) $row['status_date'];
        }
      }
      $stmt->close();
    }
  }

  // 3) Fallback na activity log, kde sa bezpecne zisti schema cez INFORMATION_SCHEMA.
  $activityCols = ordersGetTableColumns($conn, 'order_activity');
  if (!$activityCols || !in_array('order_id', $activityCols, true)) {
    return $out;
  }

  $dateCol = ordersFirstExistingColumn($activityCols, ['created_at', 'activity_at', 'logged_at', 'changed_at', 'event_at', 'updated_at']);
  if ($dateCol === '') {
    return $out;
  }

  $newValueCol = ordersFirstExistingColumn($activityCols, ['new_value', 'new_status', 'status_to', 'to_status', 'value_after', 'after_value', 'status']);
  $fieldCol = ordersFirstExistingColumn($activityCols, ['field_name', 'field', 'changed_field', 'column_name']);
  $actionCol = ordersFirstExistingColumn($activityCols, ['action', 'event', 'type', 'activity_type']);

  $idPh = implode(',', array_fill(0, count($orderIds), '?'));
  $statusPh = implode(',', array_fill(0, count($statuses), '?'));

  $queries = [];
  if ($newValueCol !== '') {
    $extra = '';
    if ($fieldCol !== '') {
      $extra = " AND (LOWER(`$fieldCol`) IN ('status','order_status') OR `$fieldCol` IS NULL OR `$fieldCol` = '')";
    }
    $queries[] = [
      "SELECT order_id, UPPER(`$newValueCol`) AS status_code, MAX(`$dateCol`) AS status_date
       FROM order_activity
       WHERE order_id IN ($idPh)
         AND UPPER(`$newValueCol`) IN ($statusPh)
         $extra
       GROUP BY order_id, UPPER(`$newValueCol`)",
      str_repeat('i', count($orderIds)) . str_repeat('s', count($statuses)),
      array_merge($orderIds, $statuses),
    ];
  }

  if ($actionCol !== '') {
    $likeParts = [];
    $likeParams = [];
    foreach ($statuses as $status) {
      $likeParts[] = "UPPER(`$actionCol`) LIKE ?";
      $likeParams[] = '%' . $status . '%';
    }
    $queries[] = [
      "SELECT order_id, MAX(`$dateCol`) AS status_date
       FROM order_activity
       WHERE order_id IN ($idPh)
         AND (" . implode(' OR ', $likeParts) . ")
       GROUP BY order_id",
      str_repeat('i', count($orderIds)) . str_repeat('s', count($likeParams)),
      array_merge($orderIds, $likeParams),
    ];
  }

  foreach ($queries as [$sql, $types, $params]) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      continue;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $oid = (int) $row['order_id'];
      if (empty($row['status_date'])) {
        continue;
      }

      if (!empty($row['status_code'])) {
        $status = strtoupper((string) $row['status_code']);
        if (empty($out[$oid][$status])) {
          $out[$oid][$status] = (string) $row['status_date'];
        }
      } else {
        foreach ($statuses as $status) {
          if (empty($out[$oid][$status])) {
            $out[$oid][$status] = (string) $row['status_date'];
          }
        }
      }
    }
    $stmt->close();
  }

  return $out;
}

function ordersFormatDetailStatusDate(?string $dateRaw): string
{
  $dateRaw = trim((string) $dateRaw);
  if ($dateRaw === '') {
    return '';
  }

  try {
    $dt = new DateTime($dateRaw);
    return $dt->format('d.m.Y H:i');
  } catch (Throwable $e) {
    return $dateRaw;
  }
}

$dpt = (int) ($_SESSION['dpt'] ?? 0);
$allAccess = in_array($dpt, [1, 3, 4, 5, 7], true);

// --- Filters (GET) ---
$page = 'orders';
$fDept = isset($_GET['dept']) ? (int) $_GET['dept'] : 0;
$fCat = isset($_GET['cat']) ? trim((string) $_GET['cat']) : '';
$fType = isset($_GET['type']) ? trim((string) $_GET['type']) : '';
$fQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

// ── Nové filtre ──────────────────────────────────────────────────────────────
$fStatus = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$fSource = isset($_GET['source']) ? trim((string) $_GET['source']) : '';

// Špeciálny parameter pre "Open Orders" tab — vylúčenie viacerých statusov
// Hodnoty oddelené čiarkou, napr. "PENDING,SHIPPED"
$fExcludeStatuses = isset($_GET['exclude_status']) ? trim((string) $_GET['exclude_status']) : '';

// Defaultný "Open Orders" exclude (PENDING, SHIPPED) má platiť takmer vždy —
// aj keď si niekto zvolí department/cat/source/country/... filter. Zrušiť ho
// má iba explicitný status filter, explicitný exclude_status, alebo fulltext
// search (ten má hľadať naprieč úplne všetkým). Predtým stačilo nastaviť
// hocijaký iný filter (napr. len prepnúť department select) a exclude sa
// vôbec nezapol → natiahli sa všetky objednávky vrátane rokmi nazbieraných
// SHIPPED/PENDING (rádovo desaťtisíce záznamov).
$noStatusFilterSet = (
  empty($_GET['status']) &&
  empty($_GET['exclude_status']) &&
  empty($_GET['q'])
);
if ($noStatusFilterSet) {
  $fExcludeStatuses = 'CANCELLED,PENDING,SHIPPED';
}
$fCountry = isset($_GET['country']) ? strtoupper(trim((string) $_GET['country'])) : '';
$fPayment = isset($_GET['payment']) ? trim((string) $_GET['payment']) : '';
$fShipping = isset($_GET['shipping']) ? trim((string) $_GET['shipping']) : '';
$fPriority = isset($_GET['priority']) ? trim((string) $_GET['priority']) : '';
$fDateFrom = isset($_GET['date_from']) ? trim((string) $_GET['date_from']) : '';
$fDateTo = isset($_GET['date_to']) ? trim((string) $_GET['date_to']) : '';
$fWorker = isset($_GET['worker']) ? (int) $_GET['worker'] : 0;
// ── Print settings filters ─────────────────────────────────────────────────
$fPrinter = isset($_GET['print_printer']) ? trim((string) $_GET['print_printer']) : '';
$fPrintMat = isset($_GET['print_material']) ? trim((string) $_GET['print_material']) : '';
$fPrintFin = isset($_GET['print_finish']) ? trim((string) $_GET['print_finish']) : '';
// ── koniec print settings filtrov ─────────────────────────────────────────
// ── koniec nových filtrov ─────────────────────────────────────────────────

// ── Print settings filter options ──────────────────────────────────────────
$printPrinterOptions = [];
$printMaterialOptions = [];
$printFinishOptions = [];

$psRes = $conn->query("
  SELECT
    DISTINCT JSON_UNQUOTE(JSON_EXTRACT(internal_options_json, '$._printer'))       AS printer,
             JSON_UNQUOTE(JSON_EXTRACT(internal_options_json, '$._print_material')) AS material,
             JSON_UNQUOTE(JSON_EXTRACT(internal_options_json, '$._print_finish'))   AS finish
  FROM order_items
  WHERE deleted_at IS NULL
    AND item_type_code = 'G'
    AND internal_options_json IS NOT NULL
    AND internal_options_json != ''
    AND internal_options_json != '{}'
");
if ($psRes) {
  while ($psRow = $psRes->fetch_assoc()) {
    $v = trim((string) ($psRow['printer'] ?? ''));
    if ($v !== '' && $v !== 'null' && !in_array($v, $printPrinterOptions, true))
      $printPrinterOptions[] = $v;
    $v = trim((string) ($psRow['material'] ?? ''));
    if ($v !== '' && $v !== 'null' && !in_array($v, $printMaterialOptions, true))
      $printMaterialOptions[] = $v;
    $v = trim((string) ($psRow['finish'] ?? ''));
    if ($v !== '' && $v !== 'null' && !in_array($v, $printFinishOptions, true))
      $printFinishOptions[] = $v;
  }
  $psRes->free();
}
// Also pull base-material / graphics-finish from options_json
$psRes2 = $conn->query("
  SELECT DISTINCT
    JSON_UNQUOTE(JSON_EXTRACT(options_json, '$.\"base-material\"'))     AS material,
    JSON_UNQUOTE(JSON_EXTRACT(options_json, '$.\"graphics-finish\"'))   AS finish
  FROM order_items
  WHERE deleted_at IS NULL AND item_type_code = 'G'
    AND options_json IS NOT NULL AND options_json != ''
");
if ($psRes2) {
  while ($psRow2 = $psRes2->fetch_assoc()) {
    $v = trim((string) ($psRow2['material'] ?? ''));
    if ($v !== '' && $v !== 'null' && !in_array($v, $printMaterialOptions, true))
      $printMaterialOptions[] = $v;
    $v = trim((string) ($psRow2['finish'] ?? ''));
    if ($v !== '' && $v !== 'null' && !in_array($v, $printFinishOptions, true))
      $printFinishOptions[] = $v;
  }
  $psRes2->free();
}
sort($printPrinterOptions);
sort($printMaterialOptions);
sort($printFinishOptions);
// ── koniec print settings filter options ──────────────────────────────────

$workerOptions = [];
$workerRes = $conn->query("SELECT
    e.id,
    TRIM(CONCAT(e.firstname, ' ', e.lastname)) AS emp_name
  FROM employees e
  WHERE EXISTS (
    SELECT 1
    FROM order_assignments oa
    WHERE oa.employee_id = e.id
      AND oa.removed_at IS NULL
  )
  OR EXISTS (
    SELECT 1
    FROM order_item_assignments oia
    WHERE oia.employee_id = e.id
      AND oia.removed_at IS NULL
  )
  ORDER BY e.firstname, e.lastname, e.id
");
if ($workerRes) {
  while ($workerRow = $workerRes->fetch_assoc()) {
    $workerId = (int) ($workerRow['id'] ?? 0);
    $workerName = trim((string) ($workerRow['emp_name'] ?? ''));
    if ($workerId > 0 && $workerName !== '') {
      $workerOptions[$workerId] = $workerName;
    }
  }
  $workerRes->free();
}
if ($fWorker <= 0 || !isset($workerOptions[$fWorker])) {
  $fWorker = 0;
}

$orderStatusLabels = ordersGetOrderStatusLabels($conn, true);
$allowedStatuses = array_keys($orderStatusLabels);
if ($fStatus !== '' && !in_array($fStatus, $allowedStatuses, true))
  $fStatus = '';

// Ak je zvolený konkrétny status tabom alebo filtrom, má mať prednosť
// pred "open orders" exclude_status logikou.
if ($fStatus !== '') {
  $fExcludeStatuses = '';
}

// Horné fulltext vyhľadávanie má hľadať naprieč všetkými objednávkami,
// nie len v aktuálne aktívnom quick tabe.
if ($fQ !== '') {
  $fStatus = '';
  $fExcludeStatuses = '';
}

$detailColumnTitle = 'Detail';
$detailStatusCode = strtoupper(trim((string) $fStatus));

if ($detailStatusCode !== '' && isset($statusDateDetailRules[$detailStatusCode])) {
  $detailColumnTitle =
    ($statusDateDetailRules[$detailStatusCode]['label'] ?? str_replace('_', ' ', $detailStatusCode))
    . ' At';
}

// ── Počty objednávok pre jednotlivé taby ──────────────────────────────────────────────────────────
$quickTabCounts = [];
$qtRes = $conn->query("SELECT
  SUM(status='SHIPPED') AS cnt_shipped,
  SUM(status='PENDING') AS cnt_pending,
  SUM(status='COMMUNICATION') AS cnt_communication,
  SUM(status='DRAFT_READY') AS cnt_draft_ready,
  SUM(status='READY_TO_INVOICE') AS cnt_ready_to_invoice,
  SUM(status='READY_TO_SHIP') AS cnt_ready_to_ship,
  SUM(status NOT IN ('PENDING','SHIPPED')) AS cnt_open
FROM orders");
if ($qtRes && $qtRow = $qtRes->fetch_assoc()) {
  $quickTabCounts['shipped'] = (int) ($qtRow['cnt_shipped'] ?? 0);
  $quickTabCounts['pending'] = (int) ($qtRow['cnt_pending'] ?? 0);
  $quickTabCounts['communication'] = (int) ($qtRow['cnt_communication'] ?? 0);
  $quickTabCounts['draft_ready'] = (int) ($qtRow['cnt_draft_ready'] ?? 0);
  $quickTabCounts['ready_to_invoice'] = (int) ($qtRow['cnt_ready_to_invoice'] ?? 0);
  $quickTabCounts['ready_to_ship'] = (int) ($qtRow['cnt_ready_to_ship'] ?? 0);
  $quickTabCounts['open_orders'] = (int) ($qtRow['cnt_open'] ?? 0);
}
// ─────────────────────────────────────────────────────────────────────────────

$allowedSources = ['EBAY', 'SHOPTET', 'MX_LOCKER', 'SO'];
if ($fSource !== '' && !in_array($fSource, $allowedSources, true))
  $fSource = '';

$priorityOptions = [
  0 => 'Normal',
  10 => 'Deadline',
  20 => 'Priority',
];
$allowedPriorities = array_map('strval', array_keys($priorityOptions));
if ($fPriority !== '' && !in_array($fPriority, $allowedPriorities, true))
  $fPriority = '';

$allowedCats = ['GRAPHICS', 'PLASTICS', 'SEATCOVER', 'FITTING'];
if ($fCat !== '' && !in_array($fCat, $allowedCats, true))
  $fCat = '';

$allowedTypes = ['G', 'T', 'M', 'P', 'S', 'F', '(NULL)'];
if ($fType !== '' && !in_array($fType, $allowedTypes, true))
  $fType = '';

$deptFilter = [
  2 => ['GRAPHICS'],
  6 => ['PLASTICS'],
  8 => ['SEATCOVER'],
];

$deptTypeFilter = [
  9 => ['F'],
];

$effectiveDept = $dpt;
$deptCodeMap = [2 => 'GRAPHICS', 6 => 'PLASTICS', 8 => 'SEATCOVER', 9 => 'FITTING'];

if ($fDept > 0) {
  $effectiveDept = $fDept;
}

$uiDept = $effectiveDept;
$uiDeptCode = $deptCodeMap[$uiDept] ?? null;
$rolePrimaryUI = $uiDeptCode ? ('PRIMARY_' . $uiDeptCode) : null;

$meUserId = (int) ($_SESSION['user_id'] ?? 0);
$perm = (int) ($_SESSION['permission'] ?? 0);
$isSuperAdmin = $perm >= 900;

$aclCats = [];
$aclTypes = [];

if ($fDept === -1) {
  // "All Orders" — vedomé obídenie department ACL (napr. telefonát od zákazníka
  // mimo vlastného oddelenia, alebo hromadný export naprieč departmentmi).
  $aclCats = [];
  $aclTypes = [];
} elseif ($fDept > 0) {
  // Konkretny vyber z dropdownu (napr. "Seat Covers") - respektuje sa pre kohokolvek,
  // rovnako ako "All Orders". Detail objednavky uz tiez nie je blokovany podla dept,
  // takze niet dovodu blokovat ani tento explicitny filter.
  $aclCats = $deptFilter[$fDept] ?? [];
  $aclTypes = $deptTypeFilter[$fDept] ?? [];
} else {
  // Auto (dept=0) - podla vlastneho oddelenia prihlaseneho usera.
  $aclCats = $deptFilter[$dpt] ?? [];
  $aclTypes = $deptTypeFilter[$dpt] ?? [];
}
$fitWhere = "EXISTS (
  SELECT 1
  FROM order_items oifit
  WHERE oifit.order_id = o.id
    AND (
      UPPER(TRIM(COALESCE(oifit.item_type_code, ''))) = 'F'
      OR UPPER(COALESCE(oifit.sku, '')) LIKE 'GFP%'
      OR UPPER(COALESCE(oifit.custom_label, '')) LIKE 'GFP%'

      OR LOWER(COALESCE(oifit.options_json, '')) LIKE CONCAT('%applyinggraphics', CHAR(34), ':', CHAR(34), 'y%')
      OR LOWER(COALESCE(oifit.options_json, '')) LIKE CONCAT('%applyinggraphics', CHAR(34), ':', CHAR(34), 'o%')
      OR LOWER(COALESCE(oifit.options_json, '')) LIKE CONCAT('%applyinggraphics', CHAR(34), ':', CHAR(34), 'j%')
      OR LOWER(COALESCE(oifit.options_json, '')) LIKE CONCAT('%applyinggraphics', CHAR(34), ':', CHAR(34), 's%')

      OR LOWER(COALESCE(oifit.options_json, '')) LIKE CONCAT('%fitting', CHAR(34), ':', CHAR(34), 'y%')
      OR LOWER(COALESCE(oifit.options_json, '')) LIKE CONCAT('%fitting', CHAR(34), ':', CHAR(34), 'o%')
      OR LOWER(COALESCE(oifit.options_json, '')) LIKE CONCAT('%fitting', CHAR(34), ':', CHAR(34), 'j%')
      OR LOWER(COALESCE(oifit.options_json, '')) LIKE CONCAT('%fitting', CHAR(34), ':', CHAR(34), 's%')
    )
)";
$where = [];
$types = '';
$params = [];

if (!empty($aclCats)) {
  $ph = implode(',', array_fill(0, count($aclCats), '?'));
  $where[] = "EXISTS (
    SELECT 1
    FROM order_categories ocx
    JOIN categories cx ON cx.id = ocx.category_id
    WHERE ocx.order_id = o.id AND cx.code IN ($ph)
  )";
  $types .= str_repeat('s', count($aclCats));
  foreach ($aclCats as $c)
    $params[] = $c;
}

if (!empty($aclTypes)) {
  if (in_array('F', $aclTypes, true)) {
    $where[] = $fitWhere;
  }
}

if ($fCat !== '') {
  if ($fCat === 'FITTING') {
    $where[] = $fitWhere;
  } else {
    $where[] = "EXISTS (
      SELECT 1
      FROM order_categories ocf
      JOIN categories cf ON cf.id = ocf.category_id
      WHERE ocf.order_id = o.id AND cf.code = ?
    )";
    $types .= 's';
    $params[] = $fCat;
  }
}

if ($fType !== '') {
  if ($fType === '(NULL)') {
    $where[] = "EXISTS (
      SELECT 1
      FROM order_items oit
      WHERE oit.order_id = o.id
        AND (oit.item_type_code IS NULL OR TRIM(oit.item_type_code) = '')
    )";
  } else {
    if (strtoupper($fType) === 'F') {
      $where[] = $fitWhere;
    } else {
      $where[] = "EXISTS (
      SELECT 1
      FROM order_items oit
      WHERE oit.order_id = o.id
        AND UPPER(TRIM(COALESCE(oit.item_type_code, ''))) = ?
    )";
      $types .= 's';
      $params[] = strtoupper($fType);
    }
  }
}

if ($fQ !== '') {
  $where[] = "(
    o.order_number LIKE CONCAT('%', ?, '%')
    OR o.external_order_id LIKE CONCAT('%', ?, '%')
    OR cu.name LIKE CONCAT('%', ?, '%')
    OR cu.email LIKE CONCAT('%', ?, '%')

    OR EXISTS (
      SELECT 1
      FROM order_invoices oi_q
      WHERE oi_q.order_id = o.id
        AND oi_q.deleted_at IS NULL
        AND oi_q.invoice_number LIKE CONCAT('%', ?, '%')
    )

    OR EXISTS (
      SELECT 1
      FROM order_tracking_numbers otn_q
      WHERE otn_q.order_id = o.id
        AND otn_q.deleted_at IS NULL
        AND otn_q.tracking_number LIKE CONCAT('%', ?, '%')
    )
  )";

  $types .= 'ssssss';
  array_push($params, $fQ, $fQ, $fQ, $fQ, $fQ, $fQ);
}

if ($fWorker > 0) {
  $where[] = "(
    EXISTS (
      SELECT 1
      FROM order_assignments oaw
      WHERE oaw.order_id = o.id
        AND oaw.employee_id = ?
        AND oaw.removed_at IS NULL
    )
    OR EXISTS (
      SELECT 1
      FROM order_item_assignments oiaw
      JOIN order_items oiw ON oiw.id = oiaw.item_id
      WHERE oiw.order_id = o.id
        AND oiw.deleted_at IS NULL
        AND oiaw.employee_id = ?
        AND oiaw.removed_at IS NULL
    )
  )";
  $types .= 'ii';
  array_push($params, $fWorker, $fWorker);
}

// ── Nové WHERE podmienky ─────────────────────────────────────────────────────
// Pridaj ďalšie filtre sem podľa potreby — každý blok je samostatný a nezávislý

// Order status
if ($fStatus !== '') {
  $where[] = 'o.status = ?';
  $types .= 's';
  $params[] = $fStatus;
}

// Exclude statuses (pre Open Orders tab)
if ($fExcludeStatuses !== '') {
  $excList = array_filter(array_map('trim', explode(',', $fExcludeStatuses)));
  if ($excList) {
    $placeholders = implode(',', array_fill(0, count($excList), '?'));
    $where[] = "o.status NOT IN ($placeholders)";
    $types .= str_repeat('s', count($excList));
    array_push($params, ...$excList);
  }
}

// Order source (EBAY, SHOPTET, MX_LOCKER)
if ($fSource !== '') {
  $where[] = 'os.code = ?';
  $types .= 's';
  $params[] = $fSource;
}

// Country (z adresy)
if ($fCountry !== '') {
  $where[] = "UPPER(COALESCE(oa_ship.country, oa_bill.country, '')) = ?";
  $types .= 's';
  $params[] = $fCountry;
}

// Payment method (LIKE pre flexibilitu)
if ($fPayment !== '') {
  $where[] = 'o.payment_method LIKE CONCAT(\'%\', ?, \'%\')';
  $types .= 's';
  $params[] = $fPayment;
}

// Shipping method (LIKE)
if ($fShipping !== '') {
  $where[] = 'o.shipping_method LIKE CONCAT(\'%\', ?, \'%\')';
  $types .= 's';
  $params[] = $fShipping;
}

// Priority
if ($fPriority !== '') {
  $where[] = 'o.priority = ?';
  $types .= 'i';
  $params[] = (int) $fPriority;
}

// Date range (order_date)
if ($fDateFrom !== '') {
  $where[] = 'DATE(o.order_date) >= ?';
  $types .= 's';
  $params[] = $fDateFrom;
}
if ($fDateTo !== '') {
  $where[] = 'DATE(o.order_date) <= ?';
  $types .= 's';
  $params[] = $fDateTo;
}
// ── koniec nových WHERE podmienok ───────────────────────────────────────────

// Print settings filters (iba G položky s internal_options_json)
if ($fPrinter !== '') {
  $where[] = "EXISTS (
    SELECT 1 FROM order_items oips
    WHERE oips.order_id = o.id
      AND oips.deleted_at IS NULL
      AND oips.item_type_code = 'G'
      AND JSON_UNQUOTE(JSON_EXTRACT(oips.internal_options_json, '$._printer')) LIKE CONCAT('%', ?, '%')
  )";
  $types .= 's';
  $params[] = $fPrinter;
}
if ($fPrintMat !== '') {
  $where[] = "EXISTS (
    SELECT 1 FROM order_items oipm
    WHERE oipm.order_id = o.id
      AND oipm.deleted_at IS NULL
      AND oipm.item_type_code = 'G'
      AND (
        JSON_UNQUOTE(JSON_EXTRACT(oipm.internal_options_json, '$._print_material')) LIKE CONCAT('%', ?, '%')
        OR JSON_UNQUOTE(JSON_EXTRACT(oipm.options_json, '$.\x22base-material\x22')) LIKE CONCAT('%', ?, '%')
      )
  )";
  $types .= 'ss';
  $params[] = $fPrintMat;
  $params[] = $fPrintMat;
}
if ($fPrintFin !== '') {
  $where[] = "EXISTS (
    SELECT 1 FROM order_items oipf
    WHERE oipf.order_id = o.id
      AND oipf.deleted_at IS NULL
      AND oipf.item_type_code = 'G'
      AND (
        JSON_UNQUOTE(JSON_EXTRACT(oipf.internal_options_json, '$._print_finish')) LIKE CONCAT('%', ?, '%')
        OR JSON_UNQUOTE(JSON_EXTRACT(oipf.options_json, '$.\x22graphics-finish\x22')) LIKE CONCAT('%', ?, '%')
      )
  )";
  $types .= 'ss';
  $params[] = $fPrintFin;
  $params[] = $fPrintFin;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = " SELECT
  o.id,
  o.order_number,
  o.external_order_id,
  o.order_date,
  o.imported_at,
  o.status,
  o.priority,
  o.priority_date,
  o.traffic_light,
  o.traffic_blocker,
  o.traffic_summary_json,
  o.source_meta,
  o.manual_types_override,
  o.payment_method,
  o.shipping_method,
  os.code AS source_code,
  cu.name AS customer_name,
  cu.email AS customer_email,
  COALESCE(oa_ship.country, oa_bill.country) AS country_code,
  COALESCE(oa_bill.company, '') AS billing_company,
  COALESCE(oa_bill.company_id, '') AS billing_company_id,

  (
    SELECT GROUP_CONCAT(DISTINCT c.code ORDER BY c.code SEPARATOR ', ')
    FROM order_categories oc
    JOIN categories c ON c.id = oc.category_id
    WHERE oc.order_id = o.id
  ) AS categories,

  (
    SELECT GROUP_CONCAT(DISTINCT oi.item_type_code ORDER BY oi.item_type_code SEPARATOR ', ')
    FROM order_items oi
    WHERE oi.order_id = o.id
      AND oi.item_type_code IS NOT NULL
      AND oi.item_type_code <> ''
  ) AS item_types,

EXISTS (
  SELECT 1
  FROM order_items oigfp
  WHERE oigfp.order_id = o.id
    AND (
      UPPER(COALESCE(oigfp.sku, '')) LIKE 'GFP%'
      OR UPPER(COALESCE(oigfp.custom_label, '')) LIKE 'GFP%'

      OR LOWER(COALESCE(oigfp.options_json, '')) LIKE CONCAT('%applyinggraphics', CHAR(34), ':', CHAR(34), 'y%')
      OR LOWER(COALESCE(oigfp.options_json, '')) LIKE CONCAT('%applyinggraphics', CHAR(34), ':', CHAR(34), 'o%')
      OR LOWER(COALESCE(oigfp.options_json, '')) LIKE CONCAT('%applyinggraphics', CHAR(34), ':', CHAR(34), 'j%')
      OR LOWER(COALESCE(oigfp.options_json, '')) LIKE CONCAT('%applyinggraphics', CHAR(34), ':', CHAR(34), 's%')

      OR LOWER(COALESCE(oigfp.options_json, '')) LIKE CONCAT('%fitting', CHAR(34), ':', CHAR(34), 'y%')
      OR LOWER(COALESCE(oigfp.options_json, '')) LIKE CONCAT('%fitting', CHAR(34), ':', CHAR(34), 'o%')
      OR LOWER(COALESCE(oigfp.options_json, '')) LIKE CONCAT('%fitting', CHAR(34), ':', CHAR(34), 'j%')
      OR LOWER(COALESCE(oigfp.options_json, '')) LIKE CONCAT('%fitting', CHAR(34), ':', CHAR(34), 's%')
    )
) AS has_gfp,

  EXISTS (
    SELECT 1
    FROM order_items oi2
    WHERE oi2.order_id = o.id
      AND oi2.item_type_code IN ('T','M')
  ) AS has_tm,

  (
    SELECT oa.employee_id
    FROM order_assignments oa
    WHERE oa.order_id = o.id
      AND oa.role = ?
      AND oa.removed_at IS NULL
    LIMIT 1
  ) AS primary_emp_id,

  (
    SELECT CONCAT(e.firstname,' ',e.lastname)
    FROM order_assignments oa
    JOIN employees e ON e.id = oa.employee_id
    WHERE oa.order_id = o.id
      AND oa.role = ?
      AND oa.removed_at IS NULL
    LIMIT 1
  ) AS primary_emp_name,

(
  SELECT GROUP_CONCAT(
        CONCAT(
        oa.id, '|',
        e.id, '|',
        e.firstname, ' ', e.lastname, '|',
        oa.role, '|',
        oa.state, '|',
        COALESCE(e.photo, '')
      )
    ORDER BY
    CASE oa.role
    WHEN 'PRIMARY_GRAPHICS' THEN 10
    WHEN 'COLLAB_GRAPHICS' THEN 11
    WHEN 'PRIMARY_FITTING' THEN 20
    WHEN 'COLLAB_FITTING' THEN 21
    WHEN 'PRIMARY_PLASTICS' THEN 30
    WHEN 'COLLAB_PLASTICS' THEN 31
    WHEN 'PRIMARY_SEATCOVER' THEN 40
    WHEN 'COLLAB_SEATCOVER' THEN 41
    ELSE 99
  END,
  e.firstname,
  e.lastname
    SEPARATOR ';;'
  )
  FROM order_assignments oa
  JOIN employees e ON e.id = oa.employee_id
  WHERE oa.order_id = o.id
    AND oa.removed_at IS NULL
) AS assigned_users

FROM orders o
JOIN order_sources os ON os.id = o.source_id
LEFT JOIN customers cu ON cu.id = o.customer_id
LEFT JOIN order_addresses oa_ship
  ON oa_ship.order_id = o.id AND UPPER(oa_ship.type) = 'SHIPPING'
LEFT JOIN order_addresses oa_bill
  ON oa_bill.order_id = o.id AND UPPER(oa_bill.type) = 'BILLING'
$whereSql

ORDER BY
  CASE
    WHEN o.priority >= 20 THEN 0
    WHEN o.priority >= 10 THEN 1
    ELSE 2
  END ASC,

  CASE
    WHEN o.priority > 0 AND o.priority_date IS NOT NULL THEN 0
    WHEN o.priority > 0 THEN 1
    ELSE 2
  END ASC,

  CASE
    WHEN o.priority > 0 THEN o.priority_date
    ELSE o.order_date
  END ASC,

  o.order_date ASC,
  o.id ASC
LIMIT 1000";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  echo '<div class="alert alert-danger">SQL prepare error: ' . htmlspecialchars($conn->error) . '</div>';
  return;
}
// Bind params in correct placeholder order:
// 1) role placeholders in SELECT (2x) come FIRST
// 2) then all WHERE params ($types/$params built above)
$roleVal = $rolePrimaryUI ? $rolePrimaryUI : '__NO_ROLE__';
$bindTypes = 'ss' . $types;
$bindParams = array_merge([$roleVal, $roleVal], $params);
$stmt->bind_param($bindTypes, ...$bindParams);

$stmt->execute();
$res = $stmt->get_result();

$orderRows = [];
$orderIds = [];
while ($row = $res->fetch_assoc()) {
  $orderRows[] = $row;
  $orderIds[] = (int) ($row['id'] ?? 0);
}

// Order Split grouping: force SPLIT follow-up rows (Q1, Q2, ...) to sit directly
// under their parent order in the rendered list, regardless of where date/id-based
// sorting would otherwise place other unrelated orders in between.
$existingOrderIds = [];
foreach ($orderRows as $row) {
  $existingOrderIds[(int) ($row['id'] ?? 0)] = true;
}

$splitChildrenByParent = [];
$movedSplitChildIds = [];
foreach ($orderRows as $row) {
  $rowMeta = json_decode((string) ($row['source_meta'] ?? ''), true);
  $rowFollowupMeta = (is_array($rowMeta) && !empty($rowMeta['_followup']) && is_array($rowMeta['_followup']))
    ? $rowMeta['_followup']
    : null;
  if (!$rowFollowupMeta || strtoupper((string) ($rowFollowupMeta['type'] ?? '')) !== 'SPLIT') {
    continue;
  }

  $parentId = (int) ($rowFollowupMeta['parent_order_id'] ?? 0);
  if ($parentId > 0 && isset($existingOrderIds[$parentId])) {
    $splitChildrenByParent[$parentId][] = $row;
    $movedSplitChildIds[(int) ($row['id'] ?? 0)] = true;
  }
}

if ($movedSplitChildIds) {
  $groupedOrderRows = [];
  foreach ($orderRows as $row) {
    $rowId = (int) ($row['id'] ?? 0);
    if (isset($movedSplitChildIds[$rowId])) {
      continue; // re-inserted right after its parent below
    }

    $groupedOrderRows[] = $row;
    if (isset($splitChildrenByParent[$rowId])) {
      foreach ($splitChildrenByParent[$rowId] as $childRow) {
        $groupedOrderRows[] = $childRow;
      }
    }
  }
  $orderRows = $groupedOrderRows;
}

$detailStatusDateRule = $statusDateDetailRules[$detailStatusCode] ?? null;
$detailStatusDates = $detailStatusDateRule
  ? ordersFetchStatusEventDates($conn, $orderIds, [$detailStatusCode])
  : [];

$orderDepartmentStatusMap = [];
if ($orderIds) {
  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
  $types = str_repeat('i', count($orderIds));
  $stmtDeptStatuses = $conn->prepare("
    SELECT order_id, item_type_code, status, options_json, internal_options_json
    FROM order_items
    WHERE deleted_at IS NULL
      AND order_id IN ($placeholders)
      AND item_type_code IS NOT NULL
      AND item_type_code <> ''
    ORDER BY order_id ASC, id ASC
  ");

  if ($stmtDeptStatuses) {
    $stmtDeptStatuses->bind_param($types, ...$orderIds);
    $stmtDeptStatuses->execute();
    $resDeptStatuses = $stmtDeptStatuses->get_result();

    $groupsByOrder = [];
    while ($deptRow = $resDeptStatuses->fetch_assoc()) {
      $orderId = (int)($deptRow['order_id'] ?? 0);
      $itemType = ordersNormalizeDepartmentCode((string)($deptRow['item_type_code'] ?? ''));

      if ($orderId <= 0 || $itemType === '') {
        continue;
      }

      if (!isset($groupsByOrder[$orderId])) {
        $groupsByOrder[$orderId] = [];
      }
      if (!isset($groupsByOrder[$orderId][$itemType])) {
        $groupsByOrder[$orderId][$itemType] = [];
      }

      $groupsByOrder[$orderId][$itemType][] = [
        'status' => strtoupper((string)($deptRow['status'] ?? 'NEW')),
        'options_json' => $deptRow['options_json'] ?? null,
        'internal_options_json' => $deptRow['internal_options_json'] ?? null,
      ];
    }

    $stmtDeptStatuses->close();

    foreach ($groupsByOrder as $orderId => $groups) {
      $orderDepartmentStatusMap[$orderId] = ordersResolveDepartmentStatusesFromGroups($conn, $groups);
    }
  }
}

$deptOptions = [
  0 => 'Auto (By my department)',
  -1 => '🌐 All Orders',
  2 => 'Graphics',
  6 => 'Plastics',
  8 => 'Seat Covers',
  9 => 'Fitting',
];
?>
<style>
  table td,
  table th {
    vertical-align: middle;
  }

  .tm-highlight {
    background: rgba(255, 193, 7, 0.12) !important;
  }

  .badge-type {
    font-size: 0.85rem;
    padding: .35em .55em;
  }

  .order-detail-row td {
    padding: 0 !important;
    border-top: none !important;
  }

  .detail-wrap {
    display: none;
  }

  /* Detail table - force "air" */
  .detail-wrap table.table-detail>thead>tr>th,
  .detail-wrap table.table-detail>tbody>tr>td {
    padding: .75rem 1rem !important;
    line-height: 1.35 !important;
    vertical-align: middle !important;
  }

  /* trochu väčšie riadky aj vizuálne */
  .detail-wrap table.table-detail>tbody>tr {
    height: 44px;
  }

  /* dark mode borders + head bg */
  .dark-mode .detail-wrap table.table-detail {
    color: #e9ecef;
  }

  .dark-mode .detail-wrap table.table-detail th,
  .dark-mode .detail-wrap table.table-detail td {
    border-color: rgba(255, 255, 255, .12) !important;
  }

  .dark-mode .detail-wrap table.table-detail thead th {
    background: rgba(255, 255, 255, .06) !important;
  }

  /* Väčšie badge iba v order detail */
  .detail-wrap .badge {
    font-size: 1rem !important;
    /* väčší text */
    padding: .55em .9em !important;
    /* viac priestoru */
    border-radius: 10px;
    font-weight: 600;
  }

  .btn-order-action {
    min-width: 72px;
  }

  .order-row {
    cursor: pointer;
    transition: opacity .2s;
  }

  /* Keď je v tabuľke otvorený riadok — ostatné sa stlmia */
  #ordersTable.table-has-open .order-row:not(.order-row-open) {
    opacity: 0.35;
  }

  #ordersTable.table-has-open .order-detail-row {
    opacity: 1 !important;
  }

  /* Otvorený riadok — výrazný highlight */
  #ordersTable .order-row.order-row-open>td {
    background: linear-gradient(90deg, rgba(63, 158, 255, 0.24), rgba(63, 158, 255, 0.11)) !important;
    border-top: 1px solid rgba(63, 158, 255, 0.45) !important;
    border-bottom: 1px solid rgba(63, 158, 255, 0.45) !important;
    box-shadow: inset 4px 0 0 #3f9eff;
    opacity: 1 !important;
  }

  #ordersTable .order-row.order-row-open>td:first-child {
    border-left: 1px solid rgba(63, 158, 255, 0.45) !important;
  }

  #ordersTable .order-row.order-row-open>td:last-child {
    border-right: 1px solid rgba(63, 158, 255, 0.45) !important;
  }

  /* Detail riadok pod otvoreným — vizuálne "lepí" sa naň */
  #ordersTable .order-row-open+.order-detail-row>td {
    border-top: none !important;
    background: rgba(63, 158, 255, 0.035);
    box-shadow: inset 4px 0 0 #3f9eff, 0 3px 0 rgba(63, 158, 255, 0.22);
  }

  .btn-copy-inline {
    background: transparent;
    border: none;
    color: #adb5bd;
    cursor: pointer;
    padding: 0 4px;
  }

  .btn-copy-inline:hover {
    color: #17a2b8;
  }

  .btn-delete-tracking:hover {
    background: #dc3545;
    color: white;
  }

  .production-note-textarea {
    background: rgba(255, 255, 255, 0.06) !important;
    border: 1px solid rgba(255, 255, 255, 0.2) !important;
    color: #fff !important;
  }

  .production-note-textarea:focus {
    background: rgba(255, 255, 255, 0.10) !important;
    border-color: #17a2b8 !important;
    box-shadow: 0 0 0 0.1rem rgba(23, 162, 184, .25);
  }

  .assigned-users {
    display: flex;
    align-items: center;
    justify-content: center;
    /* 👈 toto pridaj */
    gap: 4px;
    white-space: nowrap;
    width: 100%;
  }

  .assigned-avatar,
  .assigned-more {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    font-weight: 700;
    cursor: default;
    border: 1px solid rgba(255, 255, 255, .22);
  }

  .assigned-primary {
    background: rgba(23, 162, 184, .35);
    color: #fff;
  }

  .assigned-collab {
    background: rgba(108, 117, 125, .45);
    color: #fff;
  }

  .assigned-more {
    background: rgba(255, 255, 255, .12);
    color: #ddd;
  }

  .assigned-photo {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid rgba(255, 255, 255, .22);
  }

  .assigned-photo.assigned-primary {
    border-color: #17a2b8;
  }

  .assigned-photo.assigned-collab {
    border-color: rgba(255, 255, 255, .35);
  }

  .order-in-progress {
    background: rgba(23, 162, 184, 0.12) !important;
    box-shadow: inset 4px 0 0 #17a2b8;
  }

  .order-priority-high {
    background: rgba(255, 193, 7, 0.10) !important;
    box-shadow: inset 4px 0 0 rgba(255, 193, 7, 0.65);
  }

  .order-priority-urgent {
    background: rgba(220, 53, 69, 0.12) !important;
    box-shadow: inset 4px 0 0 rgba(220, 53, 69, 0.75);
  }

  .order-pending {
    background: rgba(111, 66, 193, 0.10) !important;
    box-shadow: inset 4px 0 0 rgba(111, 66, 193, 0.70);
    opacity: 0.82;
  }

  /* Order Split (Q1, Q2, ...) child rows: visually attach to the parent order above them */
  .order-split-child-row {
    background: rgba(255, 193, 7, 0.07) !important;
    box-shadow: inset 4px 0 0 rgba(255, 193, 7, 0.55);
  }

  .order-split-child-row>td {
    border-top: none !important;
  }

  .order-split-arrow {
    color: #ffc107;
    font-weight: 700;
    margin-right: 4px;
  }

  .btn-outline-pending {
    color: #a78bfa;
    border-color: #6f42c1;
    background: transparent;
  }

  .btn-outline-pending:hover {
    background: rgba(111, 66, 193, 0.20);
    border-color: #a78bfa;
    color: #c4b5fd;
  }

  .orders-priority-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 28px;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    border-width: 1px;
    border-radius: .2rem;
  }

  .orders-status-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    padding: 4px 12px;
    border: 1px solid transparent;
    border-radius: .2rem;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
    box-shadow: inset 0 -1px 0 rgba(0, 0, 0, .14);
  }

  .orders-priority-chip.badge-danger {
    color: #dc3545;
    background: transparent;
    border: 1px solid #dc3545;
  }

  .orders-priority-chip.badge-warning {
    color: #ffc107;
    background: transparent;
    border: 1px solid #ffc107;
  }

  .orders-priority-chip.badge-success {
    color: #6b6767;
    background: transparent;
    border: 1px solid #6b6767;
  }

  .orders-priority-chip.priority-badge-clickable {
    cursor: pointer;
  }

  .assigned-avatar-wrap {
    position: relative;
    display: inline-flex;
    width: 28px;
    height: 28px;
  }

  .btn-remove-assignment {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, .45);
    background: #dc3545;
    color: #fff;
    font-size: 11px;
    line-height: 13px;
    padding: 0;
    display: none;
    cursor: pointer;
  }

  .assigned-avatar-wrap:hover .btn-remove-assignment {
    display: block;
  }

  .table td,
  .table th {
    vertical-align: middle !important;
  }

  /* ── Quick-tab lišta ─────────────────────────────────────────────────── */
  .orders-quicktabs {
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;
    background: transparent;
    padding: 8px 12px 0 12px;
    gap: 3px;
    border-bottom: 2px solid rgba(255, 255, 255, 0.12);
    z-index: 900;
  }

  .orders-quicktabs .qtab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 16px;
    font-size: 12.5px;
    font-weight: 400;
    color: rgba(255, 255, 255, 0.45);
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.10);
    border-bottom: none;
    border-radius: 5px 5px 0 0;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    margin-bottom: -2px;
    transition: background .15s, color .15s, border-color .15s;
  }

  .orders-quicktabs .qtab:hover {
    background: rgba(255, 255, 255, 0.10);
    color: rgba(255, 255, 255, 0.75);
    border-color: rgba(255, 255, 255, 0.18);
    text-decoration: none;
  }

  .orders-quicktabs .qtab.active {
    font-weight: 600;
    color: #fff;
    background: rgba(255, 255, 255, 0.10);
    border-color: rgba(255, 255, 255, 0.20);
    border-bottom: 2px solid #3f9eff;
    padding-bottom: 9px;
    text-decoration: none;
  }

  .orders-quicktabs .qtab .qtab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    font-size: 10px;
    font-weight: 700;
    background: #dc3545;
    color: #fff;
  }

  .orders-quicktabs .qtab .qtab-badge.badge-purple {
    background: #6f42c1;
  }

  .orders-quicktabs .qtab .qtab-badge.badge-info {
    background: #17a2b8;
  }

  /* ── Filter collapse bar ────────────────────────────────────────────── */
  .orders-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 12px;
    background: rgba(0, 0, 0, .12);
    border-bottom: 1px solid rgba(255, 255, 255, .06);
    min-height: 38px;
    flex-wrap: wrap;
    gap: 6px;
    z-index: 898;
  }

  .orders-toolbar .active-pills {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    flex: 1;
  }

  #ordersFilterCollapse {
    border-bottom: 1px solid rgba(255, 255, 255, .07);
  }

  /* ── Sticky thead ───────────────────────────────────────────────────── */
  :root {
    --orders-sticky-top: 50px;
    --orders-header-h: 58px;
    --orders-tabs-h: 42px;
    --orders-toolbar-h: 38px;
    --orders-table-head-top: calc(var(--orders-sticky-top) + var(--orders-header-h) + var(--orders-tabs-h) + var(--orders-toolbar-h));
  }

  /* Vypnute scroll anchoring - prehliadac by inak pri zbaleni/rozbaleni
     filtra sam potichu upravoval scroll poziciu (aby "vizualne" nic neskoclo),
     co v kombinacii so sticky theadom sposobovalo, ze hlavicka skoncila
     na nespravnom mieste (napr. medzi riadkami tabulky). */
  .orders-search-header,
  .orders-quicktabs,
  .orders-toolbar,
  #ordersFilterCollapse,
  #ordersTableWrap {
    overflow-anchor: none;
  }

  /* Search header (hore, nad quick tabs) sticky */
  .orders-search-header {
    position: sticky;
    top: var(--orders-sticky-top);
    z-index: 1001;
    background: #343a40;
  }

  /* Quick tabs sticky pod search headerom */
  .orders-quicktabs {
    position: sticky;
    top: calc(var(--orders-sticky-top) + var(--orders-header-h));
    z-index: 1000;
    background: #343a40;
  }

  /* Filter toolbar sticky pod tabs */
  .orders-toolbar {
    position: sticky;
    top: calc(var(--orders-sticky-top) + var(--orders-header-h) + var(--orders-tabs-h));
    z-index: 999;
    background: #2f343a;
  }

  /* Rozbalený filter - NIE je sticky, scrolluje sa spolu s tabuľkou.
     Prilepené (sticky) ostávajú len search header, quick tabs a toolbar. */
  #ordersFilterCollapse {
    background: #2f343a;
  }

  /* Table wrapper bez vnútorného scrollbaru */
  #ordersTableWrap {
    overflow-x: visible;
    overflow-y: visible;
    max-height: none;
    margin-top: -2px;
  }

  /* Table header sticky pod tabs + toolbar.
   Rozbalený filter už nie je sticky, takže neovplyvňuje offset thead-u. */
  #ordersTableWrap>table#ordersTable>thead>tr>th {
    position: sticky;
    top: var(--orders-table-head-top);
    z-index: 897;
    background: #343a40;
    color: #fff;
    box-shadow: 0 1px 0 rgba(255, 255, 255, .12);
  }

  /* ────────────────────────────────────────────────────────────────────── */
</style>

<div class="card card-dark">
  <div class="card-header orders-search-header d-flex align-items-center justify-content-between flex-wrap" style="gap:10px;">
    <h3 class="card-title mb-0">
      Orders
      <?php if ($dpt === 6): ?><span class="badge badge-warning ml-2">T/M highlighted</span><?php endif; ?>
    </h3>

    <form method="get" class="mb-0" style="min-width:320px; max-width:520px; flex:1;">
      <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>" />

      <?php foreach ($_GET as $k => $v): ?>
        <?php
        if (in_array($k, ['page', 'q'], true)) {
          continue;
        }
        if (is_array($v)) {
          continue;
        }
        ?>
        <input type="hidden" name="<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
      <?php endforeach; ?>

      <div class="input-group input-group-sm <?= fActive($fQ) ?>">
        <input class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($fQ) ?>"
          placeholder="Order #, Ext. ID, Customer Name, Email, Invoice, Tracking Number…" />

        <div class="input-group-append">
          <button class="btn btn-primary btn-sm" type="submit">
            <i class="fas fa-search"></i>
          </button>

          <?php if ($fQ !== ''): ?>
            <?php
            $clearQ = $_GET;
            unset($clearQ['q']);
            $clearQ['page'] = $page;
            ?>
            <a class="btn btn-secondary btn-sm" href="?<?= htmlspecialchars(http_build_query($clearQ)) ?>">
              <i class="fas fa-times"></i>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>

  <?php
  // ── Quick-tab helpers ────────────────────────────────────────────────────
  function qtabIsActive(array $tabParams): bool
  {
    $filterKeys = ['status', 'exclude_status', 'source', 'country', 'payment', 'shipping', 'priority', 'date_from', 'date_to', 'worker', 'dept', 'cat', 'type', 'q', 'print_printer', 'print_material', 'print_finish'];
    foreach ($tabParams as $k => $v) {
      if (($_GET[$k] ?? '') !== $v)
        return false;
    }
    if (empty($tabParams)) {
      foreach ($filterKeys as $k) {
        if (!empty($_GET[$k]))
          return false;
      }
    }
    return true;
  }
  function qtabUrl(array $tabParams): string
  {
    $current = $_GET;
    foreach (['status', 'exclude_status', 'source', 'country', 'payment', 'shipping', 'priority', 'date_from', 'date_to', 'worker', 'dept', 'cat', 'type', 'q', 'print_printer', 'print_material', 'print_finish'] as $k) {
      unset($current[$k]);
    }
    $qs = http_build_query(array_merge($current, $tabParams));
    return '?' . ($qs ?: '');
  }
  // taby v hornej časti
  $quickTabs = [
    /*['id' => 'all',         'label' => 'Všetky objednávky', 'params' => []],*/
    ['id' => 'open_orders', 'label' => 'Open Orders', 'params' => ['exclude_status' => 'CANCELLED,PENDING,SHIPPED'], 'badge_key' => 'open_orders'],
    ['id' => 'cnt_communication', 'label' => 'Communication', 'params' => ['status' => 'COMMUNICATION'], 'badge_key' => 'communication'],
    [
      'id' => 'draft_ready',
      'label' => 'Draft Ready',
      'params' => ['status' => 'DRAFT_READY'],
      'badge_key' => 'draft_ready',
      'badge_class' => 'badge-info'
    ],
    ['id' => 'ready_to_invoice', 'label' => 'Ready to Invoice', 'params' => ['status' => 'READY_TO_INVOICE'], 'badge_key' => 'ready_to_invoice', 'badge_class' => 'badge-success'],
    ['id' => 'ready_to_ship', 'label' => 'Ready to Ship', 'params' => ['status' => 'READY_TO_SHIP'], 'badge_key' => 'ready_to_ship', 'badge_class' => 'badge-success'],
    ['id' => 'pending', 'label' => '⏳ Pending', 'params' => ['status' => 'PENDING'], 'badge_key' => 'pending', 'badge_class' => 'badge-purple'],
    // -- sem pridaj ďalšie taby --
  ];
  ?>

  <div class="orders-quicktabs">
    <?php foreach ($quickTabs as $tab):
      $isActive = qtabIsActive($tab['params']);
      $url = qtabUrl($tab['params']);
      $badgeHtml = '';

      if (!empty($tab['badge_key'])) {
        $cnt = (int) ($quickTabCounts[$tab['badge_key']] ?? 0);

        if ($cnt > 0) {
          $bc = htmlspecialchars($tab['badge_class'] ?? '');
          $badgeHtml = '<span class="qtab-badge ' . $bc . '">' . $cnt . '</span>';
        }
      }
      ?>
      <a href="<?= htmlspecialchars($url) ?>" class="qtab <?= $isActive ? 'active' : '' ?>">
        <?= htmlspecialchars($tab['label']) ?>   <?= $badgeHtml ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="card-body">

    <?php
    // ── Pomocná funkcia pre <option> ─────────────────────────────────────────
    function fOpt(string $val, string $label, string $current): string
    {
      $sel = ($current === $val) ? ' selected' : '';
      return '<option value="' . htmlspecialchars($val) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }


    // ── Zostavenie zoznamu aktívnych filtrov pre badge ───────────────────────
    // Každý záznam: [ 'label' => 'Status', 'value' => 'IN_PROGRESS', 'display' => 'In Progress' ]
    // Ak chceš pridať nový filter do badgu, pridaj sem ďalší riadok.
    $activeFilterBadges = [];
    if ($fStatus !== '')
      $activeFilterBadges[] = ['label' => 'Status', 'display' => str_replace('_', ' ', $fStatus)];
    if ($fSource !== '')
      $activeFilterBadges[] = ['label' => 'Source', 'display' => $fSource];
    if ($fPriority !== '')
      $activeFilterBadges[] = ['label' => 'Priority', 'display' => ($priorityOptions[(int) $fPriority] ?? $fPriority)];
    if ($fQ !== '')
      $activeFilterBadges[] = ['label' => 'Search', 'display' => '"' . $fQ . '"'];
    if ($fCat !== '')
      $activeFilterBadges[] = ['label' => 'Category', 'display' => $fCat];
    if ($fType !== '')
      $activeFilterBadges[] = ['label' => 'Type', 'display' => $fType];
    if ($fWorker > 0)
      $activeFilterBadges[] = ['label' => 'Worker', 'display' => ($workerOptions[$fWorker] ?? ('#' . $fWorker))];
    if ($fCountry !== '')
      $activeFilterBadges[] = ['label' => 'Country', 'display' => $fCountry];
    if ($fPayment !== '')
      $activeFilterBadges[] = ['label' => 'Payment', 'display' => $fPayment];
    if ($fShipping !== '')
      $activeFilterBadges[] = ['label' => 'Shipping', 'display' => $fShipping];
    if ($fDateFrom !== '')
      $activeFilterBadges[] = ['label' => 'From', 'display' => $fDateFrom];
    if ($fDateTo !== '')
      $activeFilterBadges[] = ['label' => 'To', 'display' => $fDateTo];
    if ($fDept === -1)
      $activeFilterBadges[] = ['label' => 'Dept', 'display' => 'All Orders'];
    elseif ($fDept > 0)
      $activeFilterBadges[] = ['label' => 'Dept', 'display' => ($deptOptions[$fDept] ?? $fDept)];
    if ($fPrinter !== '')
      $activeFilterBadges[] = ['label' => '🖨️ Printer', 'display' => $fPrinter];
    if ($fPrintMat !== '')
      $activeFilterBadges[] = ['label' => '🧱 Material', 'display' => $fPrintMat];
    if ($fPrintFin !== '')
      $activeFilterBadges[] = ['label' => '✨ Finish', 'display' => $fPrintFin];

    // Pomocná funkcia — CSS trieda pre aktívne pole
    // Vracia 'filter-active' ak hodnota nie je prázdna, inak ''
    function fActive(string $val): string
    {
      return $val !== '' ? 'filter-active' : '';
    }
    function fActiveDept(int $val): string
    {
      return $val !== 0 ? 'filter-active' : '';
    }
    ?>

    <style>
      /* Filter panel — AdminLTE dark mode */
      #ordersFilterForm .filter-panel {
        background: rgba(255, 255, 255, .04);
        border: 1px solid rgba(255, 255, 255, .09);
        border-radius: 6px;
        padding: 14px 16px 4px 16px;
        margin-bottom: 10px;
      }

      /* Rovnomerná mriežka — 7 stĺpcov */
      #ordersFilterForm .filter-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 0 12px;
      }

      #ordersFilterForm .filter-grid-row2 {
        grid-template-columns:
          minmax(220px, 1.35fr) minmax(125px, .8fr) minmax(125px, .8fr) minmax(90px, .65fr) minmax(150px, 1fr) minmax(150px, 1fr);
      }

      /* Aktívny filter — žltý lem + žltý label */
      #ordersFilterForm .filter-active .form-control,
      #ordersFilterForm .filter-active input.form-control {
        border-color: #ffc107 !important;
        box-shadow: 0 0 0 1px rgba(255, 193, 7, .35) !important;
      }

      #ordersFilterForm .filter-active label {
        color: #ffc107 !important;
        font-weight: 600;
      }

      /* Oddeľovač riadkov */
      #ordersFilterForm .filter-row-divider {
        border: none;
        border-top: 1px solid rgba(255, 255, 255, .07);
        margin: 4px 0 10px 0;
      }

      /* Badge zoznam aktívnych filtrov */
      .active-filter-pill {
        display: inline-flex;
        align-items: center;
        background: rgba(255, 193, 7, .15);
        border: 1px solid rgba(255, 193, 7, .4);
        border-radius: 20px;
        padding: 2px 10px 2px 8px;
        font-size: .76rem;
        color: #ffc107;
        margin: 2px 4px 2px 0;
        white-space: nowrap;
      }

      .active-filter-pill .pill-label {
        opacity: .7;
        margin-right: 4px;
        font-weight: 400;
      }

      .active-filter-pill .pill-value {
        font-weight: 600;
      }
      .active-filter-pill.pill-action {
  background: rgba(40, 167, 69, .15);
  border: 1px solid rgba(40, 167, 69, .5);
  color: #28a745;
  cursor: pointer;
  text-decoration: none;
}

.active-filter-pill.pill-action:hover {
  background: rgba(40, 167, 69, .28);
  color: #34d058;
  text-decoration: none;
}

.active-filter-pill.pill-upload {
  background: rgba(23, 162, 184, .15);
  border: 1px solid rgba(23, 162, 184, .45);
  color: #4dc7dc;
}

.active-filter-pill.pill-upload:hover {
  background: rgba(23, 162, 184, .26);
  color: #7ddff0;
  text-decoration: none;
}

.active-filter-pill.pill-action:focus {
  outline: none;
  box-shadow: 0 0 0 0.15rem rgba(40, 167, 69, .25);
}

.active-filter-pill.pill-upload:focus {
  outline: none;
  box-shadow: 0 0 0 0.15rem rgba(23, 162, 184, .22);
}

.fedex-export-loader {
  min-height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.fedex-preview-table input.form-control {
  min-width: 100%;
}

#fedexExportModal .modal-dialog {
  width: min(95vw, 1400px);
  max-width: none;
}

#fedexExportModal .modal-content {
  position: relative;
  overflow: hidden;
}

#fedexExportModal .modal-body {
  max-height: calc(100vh - 180px);
  overflow: auto;
}

#fedexExportModal .modal-content.ui-resizable {
  width: min(95vw, 1400px);
}

#fedexExportModal .fedex-resize-handle {
  position: absolute;
  right: 4px;
  bottom: 4px;
  width: 18px;
  height: 18px;
  background:
    linear-gradient(135deg, transparent 0 45%, rgba(255,255,255,.28) 45% 55%, transparent 55% 100%),
    linear-gradient(135deg, transparent 0 62%, rgba(255,255,255,.45) 62% 72%, transparent 72% 100%);
  background-repeat: no-repeat;
  background-position: center;
  cursor: se-resize;
  opacity: .9;
  z-index: 20;
}

#fedexEodImportModal .modal-body {
  max-height: calc(100vh - 220px);
  overflow: auto;
}

.fedex-eod-dropzone {
  border: 1px dashed rgba(255, 255, 255, .25);
  border-radius: 8px;
  padding: 18px;
  background: rgba(255, 255, 255, .02);
}

.fedex-eod-result .badge {
  font-size: .72rem;
}
    </style>

    <?php
    $hasActiveFilters = !empty($activeFilterBadges);

    // Zisti či je aktívny filter nastavený TABom — v takom prípade collapse NEOTVÁRAŤ
    $tabStatuses = [];

    foreach ($quickTabs as $qt) {
      if (!empty($qt['params']['status'])) {
        $tabStatuses[] = strtoupper($qt['params']['status']);
      }
    }

    $filterIsOnlyFromTab = (
      (
        count($activeFilterBadges) === 1
        && isset($activeFilterBadges[0]['label'])
        && $activeFilterBadges[0]['label'] === 'Status'
        && in_array(strtoupper($fStatus), $tabStatuses, true)
      )
      || (
        $fExcludeStatuses !== ''
        && empty($fStatus)
        && count($activeFilterBadges) === 0
      )
    );
    $filterIsOnlyQuickSearch = (
      $fQ !== ''
      && $fStatus === ''
      && $fSource === ''
      && $fPriority === ''
      && $fCat === ''
      && $fType === ''
      && $fWorker <= 0
      && $fCountry === ''
      && $fPayment === ''
      && $fShipping === ''
      && $fDateFrom === ''
      && $fDateTo === ''
      && $fDept === 0
      && $fPrinter === ''
      && $fPrintMat === ''
      && $fPrintFin === ''
    );

    $collapseShow = ($hasActiveFilters && !$filterIsOnlyFromTab && !$filterIsOnlyQuickSearch) ? 'show' : '';
    ?>

    <!-- ── Toolbar: active pills + Filters button ──────────────────────── -->
    <div class="orders-toolbar">
      <div class="active-pills">
        <?php if ($hasActiveFilters): ?>
          <span class="text-muted small mr-1" style="white-space:nowrap;">Filters:</span>
          <?php foreach ($activeFilterBadges as $af): ?>
            <span class="active-filter-pill">
              <span class="pill-label"><?= htmlspecialchars($af['label']) ?>:</span>
              <span class="pill-value"><?= htmlspecialchars((string) $af['display']) ?></span>
            </span>
          <?php endforeach; ?>
          <?php if (strtoupper($fStatus) === 'READY_TO_SHIP'): ?>
            <button type="button" class="active-filter-pill pill-action border-0 js-open-fedex-export-modal"
              data-dept="<?= (int) $fDept ?>"
              title="Otvoriť FedEx export CSV pre Ready to Ship objednávky">
              <i class="fas fa-file-csv mr-1"></i>
              <span class="pill-value">Download CSV</span>
            </button>
            <button type="button" class="active-filter-pill pill-upload border-0 js-open-fedex-eod-import-modal"
              title="Import FedEx EOD">
              <i class="fas fa-file-upload mr-1"></i>
              <span class="pill-value">Import EOD</span>
            </button>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-muted small">No filters active</span>
        <?php endif; ?>
      </div>
      <button class="btn btn-sm btn-outline-secondary ml-auto" type="button" data-toggle="collapse"
        data-target="#ordersFilterCollapse" aria-expanded="<?= $collapseShow === 'show' ? 'true' : 'false' ?>">
        <i class="fas fa-filter mr-1"></i>+ Filters
      </button>
    </div>

    <!-- ── Filter form (collapse) ──────────────────────────────────────── -->
    <div class="collapse <?= $collapseShow ?>" id="ordersFilterCollapse">
      <form method="get" id="ordersFilterForm" class="mb-2">
        <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>" />

        <div class="filter-panel">

          <!-- ── ROW 1: 6 polí ─────────────────────────────────────────────────
             Pridať pole: skopíruj <div class="form-group [fActive(...)]"> blok,
             vlož do filter-grid, pridaj $fXxx do PHP filtrov na začiatku súboru.
             ──────────────────────────────────────────────────────────────── -->
          <div class="filter-grid">

            <!-- 1. Department -->
            <div class="form-group <?= fActiveDept($fDept) ?>">
              <label class="small mb-1">Department</label>

              <select class="form-control form-control-sm" name="dept">
                <?php foreach ($deptOptions as $k => $dLabel): ?>
                  <option value="<?= (int) $k ?>" <?= ($fDept === (int) $k ? 'selected' : '') ?>>
                    <?= htmlspecialchars($dLabel) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 2. Worker -->
            <div class="form-group <?= fActiveDept($fWorker) ?>">
              <label class="small mb-1">Worker</label>
              <select class="form-control form-control-sm" name="worker">
                <option value="">&mdash; All &mdash;</option>
                <?php foreach ($workerOptions as $workerId => $workerName): ?>
                  <option value="<?= (int) $workerId ?>" <?= ($fWorker === (int) $workerId ? 'selected' : '') ?>>
                    <?= htmlspecialchars($workerName) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 3. Order Status -->
            <div class="form-group <?= fActive($fStatus) ?>">
              <label class="small mb-1">Order Status</label>
              <select class="form-control form-control-sm" name="status">
                <option value="">— All —</option>
                <?php foreach ($orderStatusLabels as $val => $lbl): ?>
                  <?= fOpt($val, $lbl, $fStatus) ?>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 4. Source -->
            <div class="form-group <?= fActive($fSource) ?>">
              <label class="small mb-1">Source</label>
              <select class="form-control form-control-sm" name="source">
                <option value="">— All —</option>
                <?php foreach ([
                  'EBAY' => 'eBay',
                  'SHOPTET' => 'Shoptet',
                  'MX_LOCKER' => 'MX Locker',
                  // ── sem pridaj ──
                ] as $val => $lbl): ?>
                  <?= fOpt($val, $lbl, $fSource) ?>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 5. Priority -->
            <div class="form-group <?= fActive($fPriority) ?>">
              <label class="small mb-1">Priority</label>
              <select class="form-control form-control-sm" name="priority">
                <option value="">— All —</option>
                <?php foreach ([
                  '20' => '🔴 Priority',
                  '10' => '🟡 Deadline',
                   '0' => '⚫ Normal',
                  // ── sem pridaj ──
                ] as $val => $lbl): ?>
                  <?= fOpt((string) $val, $lbl, $fPriority) ?>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 6. Category -->
            <div class="form-group <?= fActive($fCat) ?>">
              <label class="small mb-1">Category</label>
              <select class="form-control form-control-sm" name="cat">
                <option value="">— All —</option>
                <?php foreach ([
                  'GRAPHICS' => 'Graphics',
                  'PLASTICS' => 'Plastics',
                  'SEATCOVER' => 'Seat Cover',
                  'FITTING' => 'Fitting',
                  // ── sem pridaj ──
                ] as $val => $lbl): ?>
                  <?= fOpt($val, $lbl, $fCat) ?>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 8. Item Type -->
            <div class="form-group <?= fActive($fType) ?>">
              <label class="small mb-1">Item Type</label>
              <select class="form-control form-control-sm" name="type">
                <option value="">— All —</option>
                <?php foreach (['G', 'T', 'M', 'P', 'S', 'F', '(NULL)'] as $t): ?>
                  <?= fOpt($t, $t, $fType) ?>
                <?php endforeach; ?>
              </select>
            </div>

          </div><!-- /filter-grid row 1 -->

          <hr class="filter-row-divider">

          <!-- ── ROW 2: 6 polí ───────────────────────────────────────────────── -->
          <div class="filter-grid filter-grid-row2">

            <!-- 9. Search -->
            <div class="form-group col-span-2 <?= fActive($fQ) ?>">
              <label class="small mb-1">Search</label>
              <input class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($fQ) ?>"
                placeholder="Order #, ext. ID, customer, email, invoice, tracking…" />
            </div>

            <!-- 10. Date from -->
            <div class="form-group <?= fActive($fDateFrom) ?>">
              <label class="small mb-1">Date from</label>
              <input type="date" class="form-control form-control-sm" name="date_from"
                value="<?= htmlspecialchars($fDateFrom) ?>" />
            </div>

            <!-- 11. Date to — pridaj ďalší filter sem ako 7. stĺpec alebo rozšír span -->
            <div class="form-group <?= fActive($fDateTo) ?>">
              <label class="small mb-1">Date to</label>
              <input type="date" class="form-control form-control-sm" name="date_to"
                value="<?= htmlspecialchars($fDateTo) ?>" />
            </div>

            <!-- 12. Country -->
            <div class="form-group <?= fActive($fCountry) ?>">
              <label class="small mb-1">Country</label>
              <input class="form-control form-control-sm" name="country" value="<?= htmlspecialchars($fCountry) ?>"
                placeholder="SK, US, DE…" maxlength="3" style="text-transform:uppercase;" />
            </div>

            <!-- 13. Payment -->
            <div class="form-group <?= fActive($fPayment) ?>">
              <label class="small mb-1">Payment</label>
              <select class="form-control form-control-sm" name="payment">
                <option value="">— All —</option>
                <?php foreach ([
                  'PayPal' => 'PayPal',
                  'Bank transfer' => 'Bank Transfer',
                  'Google Pay' => 'Google Pay',
                  'Apple Pay' => 'Apple Pay',
                  'Online payment via credit card' => 'Credit Card',
                  // ── sem pridaj ──
                ] as $val => $lbl): ?>
                  <?= fOpt($val, $lbl, $fPayment) ?>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 14. Shipping -->
            <div class="form-group <?= fActive($fShipping) ?>">
              <label class="small mb-1">Shipping</label>
              <select class="form-control form-control-sm" name="shipping">
                <option value="">— All —</option>
                <?php foreach ([
                  'FedEx Economy' => 'FedEx Economy',
                  'FedEx Express' => 'FedEx Express',
                  'FedEx International Economy' => 'FedEx Intl Economy',
                  'DHL Express Worldwide' => 'DHL Express WW',
                  'DHL Paket International' => 'DHL Paket Intl',
                  'GLS Paket' => 'GLS Paket',
                  // ── sem pridaj ──
                ] as $val => $lbl): ?>
                  <?= fOpt($val, $lbl, $fShipping) ?>
                <?php endforeach; ?>
              </select>
            </div>
          </div><!-- /filter-grid row 2 -->

          <?php if (!empty($printPrinterOptions) || !empty($printMaterialOptions) || !empty($printFinishOptions)): ?>
            <hr class="filter-row-divider">

            <!-- ── ROW 3: Print settings filters ──────────────────────────────── -->
            <div class="filter-grid" style="grid-template-columns: repeat(3, 1fr); gap: 10px;">

              <!-- Printer -->
              <div class="form-group <?= !empty($fPrinter) ? 'filter-active' : '' ?>">
                <label class="small mb-1">🖨️ Printer</label>
                <select class="form-control form-control-sm" name="print_printer">
                  <option value="">— All —</option>
                  <?php foreach ($printPrinterOptions as $pv): ?>
                    <option value="<?= htmlspecialchars($pv) ?>" <?= ($fPrinter === $pv ? 'selected' : '') ?>>
                      <?= htmlspecialchars($pv) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Material -->
              <div class="form-group <?= !empty($fPrintMat) ? 'filter-active' : '' ?>">
                <label class="small mb-1">🧱 Material</label>
                <select class="form-control form-control-sm" name="print_material">
                  <option value="">— All —</option>
                  <?php foreach ($printMaterialOptions as $pv): ?>
                    <option value="<?= htmlspecialchars($pv) ?>" <?= ($fPrintMat === $pv ? 'selected' : '') ?>>
                      <?= htmlspecialchars($pv) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Finish -->
              <div class="form-group <?= !empty($fPrintFin) ? 'filter-active' : '' ?>">
                <label class="small mb-1">✨ Finish</label>
                <select class="form-control form-control-sm" name="print_finish">
                  <option value="">— All —</option>
                  <?php foreach ($printFinishOptions as $pv): ?>
                    <option value="<?= htmlspecialchars($pv) ?>" <?= ($fPrintFin === $pv ? 'selected' : '') ?>>
                      <?= htmlspecialchars($pv) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

            </div><!-- /filter-grid row 3 -->
          <?php endif; ?>

          <!-- ── Tlačidlá + active filter pills ─────────────────────────────── -->
          <div class="d-flex align-items-center flex-wrap mt-1" style="gap: 6px;">

            <button class="btn btn-primary btn-sm" type="submit">
              <i class="fas fa-search mr-1"></i>Search
            </button>

            <a class="btn btn-secondary btn-sm" href="?page=orders&exclude_status=CANCELLED%2CPENDING%2CSHIPPED">
              <i class="fas fa-times mr-1"></i>Reset
            </a>

          </div>

        </div><!-- /filter-panel -->

      </form>
    </div><!-- /collapse -->

    <div id="ordersTableWrap">
      <table id="ordersTable" class="table table-bordered table-hover table-sm">
        <thead>
          <tr style="background:#343a40;color:#fff;">
            <th class="text-center" width="5%">Date</th>
            <th class="text-center" width="5%">Source</th>
            <th class="text-center" width="11%">Order #</th>
            <th>Customer</th>
            <th class="text-center" width="4%">Country</th>
            <?php if ($isSuperAdmin): ?>
              <th class="text-center">Types</th>
            <?php endif; ?>
            <th class="text-center">Semafor</th>
            <th class="text-center">Priority</th>
            <th class="text-center">Status</th>
            <th class="text-center">Assigned</th>
            <th><?= htmlspecialchars($detailColumnTitle) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orderRows as $row): ?>
            <?php
            $orderId = (int) $row['id'];
            $hasTM = (int) ($row['has_tm'] ?? 0) === 1;
            $rowClasses = [];

            $statusUpper = strtoupper((string) ($row['status'] ?? ''));

            if ($statusUpper === 'PENDING') {
              $rowClasses[] = 'order-pending';
            } elseif ($statusUpper === 'IN_PROGRESS') {
              $rowClasses[] = 'order-in-progress';
            } elseif ($dpt === 6 && $hasTM) {
              $rowClasses[] = 'tm-highlight';
            }

            $priorityValue = (int) ($row['priority'] ?? 0);
            if ($priorityValue >= 20) {
              $rowClasses[] = 'order-priority-urgent';
            } elseif ($priorityValue >= 10) {
              $rowClasses[] = 'order-priority-high';
            }

            $rowSourceMeta = json_decode((string) ($row['source_meta'] ?? ''), true);
            $rowFollowup = (is_array($rowSourceMeta) && !empty($rowSourceMeta['_followup']) && is_array($rowSourceMeta['_followup']))
              ? $rowSourceMeta['_followup']
              : null;
            $isFollowupRow = (bool) $rowFollowup;
            $isSplitChildRow = $rowFollowup && strtoupper((string) ($rowFollowup['type'] ?? '')) === 'SPLIT';
            if ($isSplitChildRow) {
              $rowClasses[] = 'order-split-child-row';
            }

            $rowClass = implode(' ', $rowClasses);

            $typesStr = normalizeTypesOrder((string) ($row['manual_types_override'] ?: ($row['item_types'] ?? '')));
            $hasManualTypes = trim((string) ($row['manual_types_override'] ?? '')) !== '';
            $customer = trim((string) ($row['customer_name'] ?? ''));
            if ($customer === '')
              $customer = (string) ($row['customer_email'] ?? '-');

            $billingCompany = trim((string) ($row['billing_company'] ?? ''));
            $billingCompanyId = trim((string) ($row['billing_company_id'] ?? ''));
            $hasCompanyInfo = ($billingCompany !== '' || $billingCompanyId !== '');
            $detailStatusDateRaw = $detailStatusDateRule ? ($detailStatusDates[$orderId][$detailStatusCode] ?? '') : '';
            $detailStatusDateFmt = ordersFormatDetailStatusDate($detailStatusDateRaw);
            $externalOrderDisplay = ordersExternalOrderDisplay(
              (string) ($row['external_order_id'] ?? ''),
              (string) ($row['source_meta'] ?? '')
            );
            ?>
            <tr class="<?= $rowClass ?> order-row" data-order-id="<?= $orderId ?>"
              data-split-parent-id="<?= $isSplitChildRow ? (int) ($rowFollowup['parent_order_id'] ?? 0) : 0 ?>"
              data-priority-sort="<?= ($priorityValue >= 20 ? 0 : ($priorityValue >= 10 ? 1 : 2)) ?>" data-date-sort="<?= htmlspecialchars((string) (
                              ($priorityValue > 0 && !empty($row['priority_date']))
                              ? $row['priority_date']
                              : ($row['order_date'] ?? '9999-12-31')
                            )) ?>">
              <td class="text-center">
                <?php
                $dateRaw = $row['order_date'] ?? null;
                if (!empty($dateRaw)) {
                  $dt = new DateTime($dateRaw);
                  echo $dt->format('d.m.Y');
                } else {
                  echo '—';
                }
                ?>
              </td>
              <td class="text-center"><?= htmlspecialchars((string) $row['source_code']) ?></td>
              <td class="text-center">
                <div><?php if ($isSplitChildRow): ?><span class="order-split-arrow">↳</span><?php endif; ?><b><?= htmlspecialchars((string) ($row['order_number'] ?? $row['external_order_id'] ?? '')) ?></b>
                </div>

                <?php if (!empty($row['external_order_id']) && $row['external_order_id'] !== $row['order_number'] && !$isFollowupRow): ?>
                  <small class="text-muted"><?= htmlspecialchars($externalOrderDisplay) ?></small>

                <?php endif; ?>

              </td>
              <td>
                <span
                  style="display:flex; justify-content:space-between; align-items:center; gap:6px; white-space:nowrap;">
                  <span style="padding-left:5px;"><?= htmlspecialchars($customer) ?></span>
                  <?php if ($hasCompanyInfo): ?>
                    <span
                      title="<?= htmlspecialchars('Company: ' . ($billingCompany ?: '-') . ' | ID: ' . ($billingCompanyId ?: '-')) ?>"
                      style="font-size:1.1em; flex-shrink:0;">🏢</span>
                  <?php else: ?>
                    <span title="Individual customer" style="font-size:1.1em; flex-shrink:0;">👤</span>
                  <?php endif; ?>
                </span>
              </td>
              <td class="text-center">
                <?php
                $cc = strtoupper(trim((string) ($row['country_code'] ?? '')));

                if ($cc === 'UM') {
                  $cc = 'US';
                }

                if ($cc !== '') {
                  $ccLower = strtolower($cc);

                  echo '<span style="white-space:nowrap;">';
                  echo '<img src="https://flagcdn.com/16x12/' . htmlspecialchars($ccLower) . '.png" ';
                  echo 'alt="' . htmlspecialchars($cc) . '" ';
                  echo 'style="margin-right:5px; vertical-align:-1px;">';
                  echo htmlspecialchars($cc);
                  echo '</span>';
                } else {
                  echo '-';
                }
                ?>
              </td>
              <?php if ($isSuperAdmin): ?>
                <td class="text-center">
                  <?php
                  if ($hasManualTypes) {
                    $types = [normalizeTypesOrder($typesStr)];
                  } else {
                    if ((int) ($row['has_gfp'] ?? 0) === 1) {
                      $types = ['GFP'];
                    } else {
                      $types = array_filter(array_map('trim', explode(',', str_replace(' ', '', $typesStr))));
                    }
                  }

                  if (!$types)
                    $types = ['NULL'];
                  ?>

                  <?php foreach ($types as $t): ?>
                    <?php
                    $tClean = strtoupper(trim($t));
                    $badge = 'badge-secondary';

                    if (in_array($tClean, ['T', 'M'], true))
                      $badge = 'badge-warning';
                    elseif ($tClean === 'G')
                      $badge = 'badge-info';
                    elseif ($tClean === 'P')
                      $badge = 'badge-primary';
                    elseif ($tClean === 'S')
                      $badge = 'badge-success';
                    elseif ($tClean === 'F')
                      $badge = 'badge-danger';
                    elseif (strpos($tClean, 'F') !== false)
                      $badge = 'badge-danger';
                    elseif (strpos($tClean, 'S') !== false)
                      $badge = 'badge-success';
                    elseif (strpos($tClean, 'P') !== false)
                      $badge = 'badge-primary';
                    elseif (strpos($tClean, 'G') !== false)
                      $badge = 'badge-info';
                    ?>
                    <span class="badge <?= $badge ?> badge-type mr-1"><?= htmlspecialchars($tClean) ?></span>
                  <?php endforeach; ?>
                </td>
              <?php endif; ?>
              <td class="text-center traffic-cell">
                <?php
                $summaryRaw = (string) ($row['traffic_summary_json'] ?? '');
                $summary = json_decode($summaryRaw, true);
                $departmentStatuses = $orderDepartmentStatusMap[$orderId] ?? [];

                if (!is_array($summary) || !$summary) {
                  $typesFallback = strtoupper((string) ($row['item_types'] ?? ''));
                  $typesFallback = str_replace([' ', ','], '', $typesFallback);

                  $summary = [];
                  foreach (str_split($typesFallback) as $t) {
                    if ($t !== '') {
                      $summary[$t] = strtoupper((string) ($row['traffic_light'] ?? 'RED'));
                    }
                  }
                }

                $order = ['G', 'F', 'P', 'S'];

                foreach ($order as $type):
                  if (!isset($summary[$type]))
                    continue;

                  $state = strtoupper((string) $summary[$type]);
                  $departmentStatus = strtoupper((string)($departmentStatuses[$type] ?? ''));
                  $departmentLabel = $departmentStatus !== ''
                    ? ordersGetStatusLabel($conn, 'item', $departmentStatus, $type)
                    : $state;
                  $departmentColor = $departmentStatus !== ''
                    ? ordersGetStatusColor($conn, 'item', $departmentStatus, $type)
                    : null;
                  $badgeStyle = 'font-size:1rem; padding:.5em .7em;';
                  if ($departmentColor) {
                    $safeColor = htmlspecialchars($departmentColor, ENT_QUOTES, 'UTF-8');
                    $badgeStyle .= 'background-color:' . $safeColor . ';border-color:' . $safeColor . ';color:#fff;';
                  } elseif ($state === 'GREEN') {
                    $badgeStyle .= 'background-color:#28a745;border-color:#28a745;color:#fff;';
                  } elseif ($state === 'ORANGE') {
                    $badgeStyle .= 'background-color:#ffc107;border-color:#ffc107;color:#212529;';
                  } else {
                    $badgeStyle .= 'background-color:#dc3545;border-color:#dc3545;color:#fff;';
                  }
                  ?>
                  <span class="badge mr-1" style="<?= $badgeStyle ?>"
                    title="<?= htmlspecialchars($type . ' - ' . $departmentLabel) ?>">
                    <?= htmlspecialchars($type) ?>
                  </span>
                <?php endforeach; ?>
              </td>
              <td class="text-center" data-priority-cell="<?= $orderId ?>">
                <?php
                $priorityValue = (int) ($row['priority'] ?? 0);
                $priorityLabel = $priorityOptions[$priorityValue] ?? ('Priority ' . $priorityValue);
                $priorityDateRaw = !empty($row['priority_date'])
                  ? (new DateTime($row['priority_date']))->format('Y-m-d') : '';
                $priorityDateFmt = !empty($row['priority_date'])
                  ? (new DateTime($row['priority_date']))->format('d.m.Y') : null;

                if ($priorityValue >= 20) {
                  $priorityBadge = 'badge-danger';
                  $priorityEmoji = '🔴';
                } elseif ($priorityValue >= 10) {
                  $priorityBadge = 'badge-warning';
                  $priorityEmoji = '🟡';
                } else {
                  $priorityBadge = 'badge-success';
                  $priorityEmoji = '⚫';
                }

                $badgeStyle = 'display:inline-flex;align-items:center;gap:6px;padding:4px 10px;font-size:12px;font-weight:500;white-space:nowrap;';
                if ($priorityValue > 0)
                  $badgeStyle .= 'cursor:pointer;';
                ?>
                <?php if ($perm >= 300): ?>
                  <button type="button" class="badge <?= $priorityBadge ?> orders-priority-chip priority-badge-clickable" style="<?= $badgeStyle ?>"
                    data-order-id="<?= $orderId ?>" data-priority="<?= $priorityValue ?>"
                    data-priority-date="<?= htmlspecialchars($priorityDateRaw) ?>">
                    <?= $priorityEmoji ?>     <?= htmlspecialchars($priorityLabel) ?>
                    <?php if ($priorityDateFmt): ?>
                      <span style="opacity:.75;font-weight:400;">· <?= htmlspecialchars($priorityDateFmt) ?></span>
                    <?php endif; ?>
                  </button>
                <?php else: ?>
                  <button type="button" class="badge <?= $priorityBadge ?> orders-priority-chip" style="<?= $badgeStyle ?> pointer-events:none;">
                    <?= $priorityEmoji ?>     <?= htmlspecialchars($priorityLabel) ?>
                    <?php if ($priorityDateFmt): ?>
                      <span style="opacity:.75;font-weight:400;">· <?= htmlspecialchars($priorityDateFmt) ?></span>
                    <?php endif; ?>
                  </button>
                <?php endif; ?>
              </td>
              <?php
              $status = strtoupper((string) ($row['status'] ?? ''));
              $statusLabel = ordersGetStatusLabel($conn, 'order', $status);
              $statusColor = ordersGetStatusColor($conn, 'order', $status) ?: '#6c757d';
              $statusStyle = 'background-color:' . htmlspecialchars($statusColor, ENT_QUOTES, 'UTF-8') . ';'
                . 'border-color:' . htmlspecialchars($statusColor, ENT_QUOTES, 'UTF-8') . ';'
                . 'color:#fff;';
              ?>
              <td class="text-center" data-status-cell="<?= $orderId ?>"
                data-status-color="<?= htmlspecialchars($statusColor, ENT_QUOTES, 'UTF-8') ?>"
                data-status-label="<?= htmlspecialchars($statusLabel ?: '-', ENT_QUOTES, 'UTF-8') ?>">
                <button type="button" class="btn btn-xs orders-status-chip" style="<?= $statusStyle ?> pointer-events:none;">
                  <?= htmlspecialchars($statusLabel ?: '-') ?>
                </button>
              </td>
              <td data-assigned-cell="<?= $orderId ?>"><?= render_assigned_users_html($conn, $orderId, (string) ($row['assigned_users'] ?? '')) ?></td>


              <td class="text-nowrap">
                <span style="padding-left:5px;"></span>
                <button type="button" class="btn btn-sm btn-outline-light btn-toggle-detail mr-1"
                  data-order-id="<?= $orderId ?>">
                  <i class="fas fa-search"></i>
                </button>
                </span>

                <?php if ($detailStatusDateRule): ?>
                  <?php if ($detailStatusDateFmt !== ''): ?>
                    <span class="badge badge-secondary ml-2 px-3 py-2"
                      title="<?= htmlspecialchars((string) ($detailStatusDateRule['label'] ?? $detailStatusCode)) ?>">
                      <?= htmlspecialchars($detailStatusDateFmt) ?>
                    </span>
                  <?php else: ?>
                    <span class="badge badge-warning ml-2 px-3 py-2"
                      title="<?= htmlspecialchars((string) ($detailStatusDateRule['empty'] ?? 'Status date not found')) ?>">
                      —
                    </span>
                  <?php endif; ?>
                <?php else: ?>
                <span data-take-assign-cell="<?= $orderId ?>" data-dept-code="<?= htmlspecialchars((string) $uiDeptCode) ?>">
                  <?= render_order_take_assign_html(
                    $conn,
                    $orderId,
                    (string) $uiDeptCode,
                    $perm,
                    $meUserId,
                    (string) ($row['assigned_users'] ?? '')
                  ) ?>
                </span>
                <?php endif; ?>
              </td>
            </tr>

            <!-- Detail row (hidden, will be filled via AJAX) -->
            <tr class="order-detail-row">
              <td colspan="11">

              <div id="detail-<?= $orderId ?>" class="detail-wrap"></div>
              </td>
            </tr>

          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>
<div class="modal fade" id="inviteModal" tabindex="-1" role="dialog" aria-labelledby="inviteModalLabel"
  aria-hidden="true">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content bg-dark text-light">

      <div class="modal-header">
        <h5 class="modal-title" id="inviteModalLabel">Assign / Invite</h5>
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" data-bs-dismiss="modal">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <input type="hidden" id="inviteOrderId" value="">
        <input type="hidden" id="inviteMode" value="">
        <input type="hidden" id="inviteDeptCode" value="">

        <label class="text-muted">Search employee</label>
        <input type="text" id="empSearch" class="form-control form-control-sm bg-dark text-light"
          placeholder="Type name, e.g. Andrej">

        <div id="empResults" class="list-group mt-2"></div>

        <small class="text-muted d-block mt-2">
          Search shows employees enabled for personal orders.
        </small>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" data-bs-dismiss="modal">
          Close
        </button>
      </div>

    </div>
  </div>
</div>
<script>
  function orderRowById(orderId) {
    return $('#ordersTable .order-row[data-order-id="' + orderId + '"]');
  }

  function openOrderDetailState(orderId) {
    const $wrap = $('#detail-' + orderId);

    $('.detail-wrap').not($wrap).filter(':visible').stop(true, true).slideUp(120);
    $('#ordersTable .order-row').removeClass('order-row-open');
    orderRowById(orderId).addClass('order-row-open');
    $('#ordersTable').addClass('table-has-open');
  }

  function closeOrderDetailState(orderId) {
    const $wrap = $('#detail-' + orderId);
    const $row = orderRowById(orderId);

    $wrap.stop(true, true).slideUp(120, function () {
      $row.removeClass('order-row-open');

      if (!$('#ordersTable .order-row-open').length) {
        $('#ordersTable').removeClass('table-has-open');
      }
    });
  }

  $(function () {

    function escapeHtml(s) {
      return ('' + s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    }

    $('.btn-toggle-detail').on('click', function (e) {
      e.preventDefault();
      e.stopPropagation();

      const orderId = $(this).data('order-id');
      const $wrap = $('#detail-' + orderId);
      const isOpen = $wrap.is(':visible') && orderRowById(orderId).hasClass('order-row-open');

      // toggle if already loaded
      if ($wrap.data('loaded')) {
        if (isOpen) {
          closeOrderDetailState(orderId);
        } else {
          openOrderDetailState(orderId);
          $wrap.stop(true, true).slideDown(120);
        }
        return;
      }

      $wrap.html('<div class="p-3 text-muted"><span class="spinner-border spinner-border-sm"></span> Načítavam detail…</div>');
      openOrderDetailState(orderId);
      $wrap.show();

      $.ajax({
        url: 'scripts/orders/get_order_detail.php',
        method: 'POST',
        dataType: 'json',
        data: { order_id: orderId },
        success: function (resp) {
          if (!resp || !resp.ok) {
            $wrap.html('<div class="p-3"><div class="alert alert-danger mb-0">Chyba: ' + escapeHtml(resp && resp.error ? resp.error : 'unknown') + '</div></div>');
            return;
          }
          $wrap.html(resp.html);
          $wrap.data('loaded', true);
        },
        error: function (xhr) {
          const msg = xhr && xhr.responseText
            ? xhr.responseText.substring(0, 600)
            : 'No response body';
          $wrap.html(
            '<div class="p-3"><div class="alert alert-danger mb-0">' +
            '<b>Chyba pri načítaní detailu</b><br>' +
            'HTTP ' + escapeHtml(xhr.status || 'error') + '<br>' +
            '<small style="white-space:pre-wrap;">' + escapeHtml(msg) + '</small>' +
            '</div></div>'
          );
        }
      });
    });
  });


  $(document).on('click', '.btn-close-order-detail', function (e) {
    e.preventDefault();
    e.stopPropagation();
    closeOrderDetailState($(this).data('order-id'));
  });


  // Open invite modal
  $(document).on('click', '.btn-invite-collab', function () {
    const orderId = $(this).data('order-id');
    $('#inviteOrderId').val(orderId);
    $('#inviteDeptCode').val($(this).data('dept-code') || '');
    $('#inviteMode').val($(this).data('mode') || 'invite');
    $('#empSearch').val('');
    $('#empResults').html('');
    $('#inviteModal').modal('show');
  });

  // Debounced employee search
  let empTimer = null;

  $(document).on('input', '#empSearch', function () {
    const q = $(this).val().trim();
    clearTimeout(empTimer);

    empTimer = setTimeout(function () {
      if (q.length < 2) {
        $('#empResults').html('');
        return;
      }

      $('#empResults').html('<div class="text-muted p-2"><span class="spinner-border spinner-border-sm"></span> Searching…</div>');

      $.ajax({
        url: 'scripts/employees/employees_search.php',
        method: 'GET',
        dataType: 'json',
        data: {
          q: q,
          dept_code: $('#inviteDeptCode').val()
        },
        success: function (resp) {
          if (!resp || !resp.ok) {
            $('#empResults').html('<div class="text-danger p-2">Search error</div>');
            return;
          }

          const items = resp.items || [];
          if (!items.length) {
            $('#empResults').html('<div class="text-muted p-2">No results</div>');
            return;
          }

          let html = '';
          items.forEach(function (it) {
            const mode = $('#inviteMode').val() || 'invite';
            const label = mode === 'assign' ? 'Assign To' : 'Invite';

            html += `
            <button type="button"
                    class="list-group-item list-group-item-action bg-dark text-light d-flex justify-content-between align-items-center btn-emp-pick"
                    data-emp-id="${it.id}">
              <span>${it.name}</span>
              <span class="btn btn-info btn-sm">${label}</span>
            </button>
          `;
          });
          $('#empResults').html(html);
        },
        error: function () {
          $('#empResults').html('<div class="text-danger p-2">Search request failed</div>');
        }
      });

    }, 220);
  });

  // Click on employee -> invite
  $(document).on('click', '.btn-emp-pick', function () {
    const empId = $(this).data('emp-id');
    const orderId = $('#inviteOrderId').val();

    $.ajax({
      url: 'scripts/orders/invite_collab.php',
      method: 'POST',
      dataType: 'json',
      data: {
        order_id: orderId,
        employee_id: empId,
        dept_code: $('#inviteDeptCode').val(),
        mode: $('#inviteMode').val()
      },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert('Invite error: ' + (resp && resp.error ? resp.error : 'unknown'));
          return;
        }
        $('#inviteModal').modal('hide');

        if (resp.avatars_html !== undefined && resp.order_id) {
          $('[data-assigned-cell="' + resp.order_id + '"]').html(resp.avatars_html);

          if (resp.take_assign_html !== undefined) {
            $('[data-take-assign-cell="' + resp.order_id + '"]').html(resp.take_assign_html);
          }

          const $wrap = $('#detail-' + resp.order_id);
          if ($wrap.length && $wrap.data('loaded')) {
            reloadOrderDetail(resp.order_id);
          }
          return;
        }

        location.reload();
      },
      error: function () {
        alert('Invite error (request failed)');
      }
    });
  });
  // klik na celý riadok otvorí detail
  $(document).on('click', '.order-row', function (e) {

    // ak klikol na tlačidlo alebo ikonku → ignoruj
    if ($(e.target).closest('button, a, .btn, .priority-badge-clickable').length) {
      return;
    }

    const $btn = $(this).find('.btn-toggle-detail');

    if ($btn.length) {
      $btn.trigger('click');
    }
  });
  function renderOptionsPretty(data) {
    if (!data || Object.keys(data).length === 0) {
      return '<div class="text-muted">No options</div>';
    }

    function esc(s) {
      return ('' + s).replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[m]));
    }

    function section(title, obj) {
      if (!obj || Object.keys(obj).length === 0) return '';

      let rows = '';
      for (let k in obj) {
        rows += `
        <div class="mb-1">
          <span class="text-muted">${esc(k)}:</span>
          <b>${esc(obj[k])}</b>
        </div>
      `;
      }

      return `
      <div class="card bg-secondary mb-3">
        <div class="card-header py-2">
          <b>${esc(title)}</b>
        </div>
        <div class="card-body py-2">
          ${rows}
        </div>
      </div>
    `;
    }

    const bike = {};
    const personal = {};
    const graphics = {};
    const seat = {};
    const files = {};
    const other = {};

    for (let k in data) {
      let v = data[k];
      let displayKey = k;

      if (k === 'name-color') {
        displayKey = 'number plates color';
      }

      if (k === 'applyinggraphics') {
        displayKey = 'Fitting';
      }

      if (k === 'number-font' || k === 'name-font') {
        const match = ('' + v).match(/(\d+)$/);
        if (match) {
          v = match[1];
        }
      }

      if (v === null || v === '' || typeof v === 'object') continue;

      const key = k.toLowerCase();

      if (k === 'Category Info' || key.includes('category')) {
        bike[displayKey] = v;
      } else if (key.includes('name') || key.includes('number')) {
        personal[displayKey] = v;
      } else if (
        key.includes('material') ||
        key.includes('finish') ||
        key.includes('fork') ||
        key.includes('draft')
      ) {
        graphics[displayKey] = v;
      } else if (key.includes('seat')) {
        seat[displayKey] = v;
      } else if (key === 'file' || key.includes('image') || key.includes('upload')) {
        files[displayKey] = v;
      } else if (!k.startsWith('_')) {
        other[displayKey] = v;
      }
    }

    let warnings = [];

    if (!data['Category Info']) warnings.push('Missing category / bike info');
    if (!data['name']) warnings.push('Missing rider name');
    if (!data['number']) warnings.push('Missing number');
    if (!data['file']) warnings.push('Missing uploaded file / logo');

    let html = '';

    if (warnings.length) {
      html += `
      <div class="alert alert-warning">
        <b>Check before production:</b><br>
        ${warnings.map(w => `<span class="badge badge-danger mr-1 mb-1">${esc(w)}</span>`).join('')}
      </div>
    `;
    } else {
      html += `
      <div class="alert alert-success py-2">
        <b>Production data looks complete.</b>
      </div>
    `;
    }

    if (data['file']) {
      const url = esc(data['file']);

      html += `
      <div class="card bg-dark border-info mb-3">
        <div class="card-header py-2">
          <b>Uploaded File / Logo Preview</b>
        </div>
        <div class="card-body">
          <a href="${url}" target="_blank" rel="noopener">
            <img src="${url}"
                 alt="Uploaded file preview"
                 style="max-width:220px; max-height:160px; border-radius:10px; border:1px solid rgba(255,255,255,.25); object-fit:contain; background:#fff; padding:6px;">
          </a>
          <div class="mt-2">
            <a href="${url}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info">
              Open original file
            </a>
          </div>
        </div>
      </div>
    `;
    }

    html += section('Bike / Category', bike);
    html += section('Personalization', personal);
    html += section('Graphics', graphics);
    html += section('Seat Cover', seat);
    html += section('Files', files);
    html += section('Other', other);

    return html;
  }

  // ===== FIX JSON + MODAL =====

  // helper – vždy bezpečne načíta JSON
  function getOptionsData($btn) {
    const raw = $btn.attr('data-options') || '';

    try {
      return JSON.parse(raw);
    } catch (e) {
      return {};
    }
  }

  $(document).on('click', '#btnEditInternalOptions', function () {
    renderInternalEditor(currentInternalOptions);

    $('#internalOptionsEditBox').show();
    $('#btnEditInternalOptions').hide();
  });
  $(document).on('click', '#btnAddInternalBlock', function () {
    $('#internalBlocksEditor').append(`
    <div class="card bg-secondary mb-2 internal-block">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <input type="text"
               class="form-control form-control-sm internal-block-name"
               value=""
               placeholder="Block name"
               style="max-width:320px;">

        <div>
          <button type="button" class="btn btn-xs btn-outline-light btn-add-internal-field">
            <i class="fas fa-plus"></i> Field
          </button>
          <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-block">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </div>

      <div class="card-body py-2 internal-fields">
        <div class="form-row align-items-center mb-2 internal-field">
          <div class="col-md-4">
            <input type="text" class="form-control form-control-sm internal-field-key" placeholder="Field name">
          </div>
          <div class="col-md-7">
            <input type="text" class="form-control form-control-sm internal-field-value" placeholder="Value">
          </div>
          <div class="col-md-1 text-right">
            <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-field">×</button>
          </div>
        </div>
      </div>
    </div>
  `);
  });

  $(document).on('click', '.btn-add-internal-field', function () {
    $(this).closest('.internal-block').find('.internal-fields').append(`
    <div class="form-row align-items-center mb-2 internal-field">
      <div class="col-md-4">
        <input type="text" class="form-control form-control-sm internal-field-key" placeholder="Field name">
      </div>
      <div class="col-md-7">
        <input type="text" class="form-control form-control-sm internal-field-value" placeholder="Value">
      </div>
      <div class="col-md-1 text-right">
        <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-field">×</button>
      </div>
    </div>
  `);
  });

  $(document).on('click', '.btn-remove-internal-field', function () {
    $(this).closest('.internal-field').remove();
  });

  $(document).on('click', '.btn-remove-internal-block', function () {
    if (confirm('Remove this block?')) {
      $(this).closest('.internal-block').remove();
    }
  });
  /*
    $(document).on('click', '#btnSaveInternalOptions', function () {
      const data = collectInternalEditorData();
      const raw = JSON.stringify(data);
  
      $.post('scripts/orders/update_item_internal_options.php', {
        item_id: currentOptionsItemId,
        internal_options_json: raw
      }, function (res) {
        if (!res || !res.ok) {
          alert(res && res.error ? res.error : 'Save failed');
          return;
        }
  
        $('#optionsModal').modal('hide');
        location.reload();
      }, 'json');
    });
  */
  $(document).on('click', '.btn-edit-country', function (e) {
    e.stopPropagation();

    const $btn = $(this);
    const orderId = $btn.data('order-id');
    const current = ($btn.attr('data-country') || '').toUpperCase();

    const next = prompt('New country code (2 letters, e.g. GB, US, DE):', current);
    if (next === null) return;

    const country = next.trim().toUpperCase();
    if (!/^[A-Z]{2}$/.test(country)) {
      alert('Country must be 2-letter code, e.g. GB, US, DE');
      return;
    }

    $.ajax({
      url: 'scripts/orders/update_order_country.php',
      method: 'POST',
      dataType: 'json',
      data: {
        order_id: orderId,
        country: country
      },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert('Country update error: ' + (resp && resp.error ? resp.error : 'unknown'));
          return;
        }

        const finalCountry = resp.country || country;

        $btn.attr('data-country', finalCountry);
        $btn.closest('div').find('.order-country-display').text(finalCountry);

        // grid country column sa najistejšie zosúladí refreshom
        setTimeout(function () {
          location.reload();
        }, 300);
      },
      error: function () {
        alert('Country update request failed');
      }
    });
  });
  $(document).on('click', '.btn-edit-order-header', function () {
    const $editBtn = $(this);
    const mode = $editBtn.data('mode') || 'edit';
    const $detail = $editBtn.closest('.detail-wrap');
    const $panel = $detail.find('.order-header-edit');

    if (mode === 'edit') {
      $panel.slideDown(150);
      $editBtn.data('mode', 'save')
        .removeClass('btn-light').addClass('btn-warning')
        .html('💾 Save changes');
    } else {
      // Deleguj na skrytý save button
      $panel.find('.btn-save-order-header').trigger('click');
    }
  });

  $(document).on('click', '.btn-cancel-order-header', function () {
    const $panel = $(this).closest('.order-header-edit');
    const $editBtn = $panel.closest('.detail-wrap').find('.btn-edit-order-header');
    $panel.slideUp(150);
    $editBtn.data('mode', 'edit')
      .removeClass('btn-warning').addClass('btn-light')
      .html('✏️ Edit header');
  });

  $(document).on('click', '.btn-save-order-header', function () {
    const $box = $(this).closest('.order-header-edit');
    const orderId = $box.find('.edit-order-id').val();
    const $btn = $(this);
    const $editBtn = $box.closest('.detail-wrap').find('.btn-edit-order-header');

    $btn.prop('disabled', true).text('Saving...');

    $.ajax({
      url: 'scripts/orders/update_order_header.php',
      method: 'POST',
      dataType: 'json',
      data: {
        order_id: orderId,
        delivery: $box.find('.edit-delivery').val(),
        payment: $box.find('.edit-payment').val(),

        'billing[name]': $box.find('.edit-billing-name').val(),
        'billing[company]': $box.find('.edit-billing-company').val(),
        'billing[company_id]': $box.find('.edit-billing-company-id').val(),
        'billing[street]': $box.find('.edit-billing-street').val(),
        'billing[city]': $box.find('.edit-billing-city').val(),
        'billing[zip]': $box.find('.edit-billing-zip').val(),
        'billing[country]': $box.find('.edit-billing-country').val(),
        'billing[email]': $box.find('.edit-billing-email').val(),
        'billing[phone]': $box.find('.edit-billing-phone').val(),

        'shipping[name]': $box.find('.edit-shipping-name').val(),
        'shipping[company]': $box.find('.edit-shipping-company').val(),
        'shipping[company_id]': $box.find('.edit-shipping-company-id').val(),
        'shipping[street]': $box.find('.edit-shipping-street').val(),
        'shipping[city]': $box.find('.edit-shipping-city').val(),
        'shipping[zip]': $box.find('.edit-shipping-zip').val(),
        'shipping[country]': $box.find('.edit-shipping-country').val(),
        'shipping[email]': $box.find('.edit-shipping-email').val(),
        'shipping[phone]': $box.find('.edit-shipping-phone').val()
      },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert('Save error: ' + (resp && resp.error ? resp.error : 'unknown'));
          $btn.prop('disabled', false).text('Save changes');
          return;
        }

        // Reset Edit header buttonu pred refreshom detailu
        $editBtn.data('mode', 'edit')
          .removeClass('btn-warning').addClass('btn-light')
          .html('✏️ Edit header');

        reloadOrderDetail(orderId);
      },
      error: function () {
        alert('Save request failed');
        $btn.prop('disabled', false).text('Save changes');
      }
    });
  });

  $(document).on('keypress', '.tracking-number', function (e) {
    if (e.which === 13) {
      e.preventDefault();
      e.stopPropagation();
      $(this).closest('.tracking-add-row, .form-row').find('.btn-add-tracking').trigger('click');
    }
  });

  $(document)
    .off('keydown.orderDetailEnterInvoice')
    .on('keydown.orderDetailEnterInvoice', '.invoice-number', function (e) {
      if ((e.key && e.key !== 'Enter') || (!e.key && e.which !== 13)) return;

      e.preventDefault();
      e.stopImmediatePropagation();
      $(this).closest('.form-row').find('.btn-add-invoice').trigger('click');
    });

  $(document)
    .off('keydown.orderDetailEnterItemSave')
    .on('keydown.orderDetailEnterItemSave', '.item-title, .item-sku, .item-label, .item-qty', function (e) {
      if ((e.key && e.key !== 'Enter') || (!e.key && e.which !== 13)) return;

      e.preventDefault();
      e.stopImmediatePropagation();
      $(this).closest('tr').find('.btn-save-item').trigger('click');
    });

  $(document)
    .off('keydown.orderDetailEnterWaitingSave')
    .on('keydown.orderDetailEnterWaitingSave', '.item-waiting-note, .item-expected-date', function (e) {
      if ((e.key && e.key !== 'Enter') || (!e.key && e.which !== 13)) return;

      e.preventDefault();
      e.stopImmediatePropagation();

      const $row = $(this).closest('tr');
      const $status = $row.find('.item-status-select').first();
      if ($status.length) {
        $status.trigger('change');
      } else {
        $row.find('.btn-save-item').trigger('click');
      }
    });


  $(document).on('click', '.btn-add-invoice', function () {
    const orderId = $(this).data('order-id');
    const $box = $(this).closest('.form-row');

    $.post('scripts/orders/add_invoice.php', {
      order_id: orderId,
      invoice_number: $box.find('.invoice-number').val()
    }, function (res) {
      if (!res.ok) {
        alert(res.error || 'Error');
        return;
      }

      // clear input
      $box.find('.invoice-number').val('');

      reloadOrderDetail(orderId);

    }, 'json');
  });
  function reloadOrderDetail(orderId) {
    const $wrap = $('#detail-' + orderId);
    if (!$wrap.length) return;

    // Zapamätaj si presnú scroll pozíciu PRED akoukoľvek manipuláciou s DOM.
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;

    // Ak je práve fokusovaný input/select vnútri detailu (napr. rozeditovaný
    // riadok položky), odfokusuj ho skôr než ho zmažeme z DOM. Inak prehliadač
    // stráca focus target a niektoré browsery na to reagujú skokom scrollu
    // na začiatok stránky / na <body>.
    const activeEl = document.activeElement;
    if (activeEl && $wrap.find(activeEl).length) {
      $(activeEl).trigger('blur');
    }

    $wrap.removeData('loaded');
    $wrap.html('<div class="p-3 text-muted"><span class="spinner-border spinner-border-sm"></span> Načítavam detail…</div>');

    // Vynútený "open" stav — nikdy netogglujeme cez .btn-toggle-detail click,
    // lebo ten je toggle (open/close) a pri pretrvávajúcom data('loaded')=true
    // by mohol detail namiesto refreshu rovno zavrieť.
    openOrderDetailState(orderId);
    $wrap.stop(true, true).show();
    window.scrollTo(scrollX, scrollY);

    $.ajax({
      url: 'scripts/orders/get_order_detail.php',
      method: 'POST',
      dataType: 'json',
      data: { order_id: orderId },
      success: function (resp) {
        if (!resp || !resp.ok) {
          $wrap.html('<div class="p-3"><div class="alert alert-danger mb-0">Chyba: ' +
            (resp && resp.error ? resp.error : 'unknown') + '</div></div>');
          return;
        }
        $wrap.html(resp.html);
        $wrap.data('loaded', true);

        // Výška detailu sa po naplnení obsahu zmenila — vráť scroll presne
        // tam, kde bol pred refreshom.
        window.scrollTo(scrollX, scrollY);
      },
      error: function () {
        $wrap.html('<div class="p-3"><div class="alert alert-danger mb-0">Chyba pri refreshi detailu</div></div>');
      }
    });
  }

  function applyFollowupTypeState($panel) {
    const type = String($panel.find('.followup-type-select').val() || 'REPEAT').toUpperCase();
    const $checkbox = $panel.find('.followup-do-not-invoice');
    const $state = $panel.find('.followup-invoice-state');
    const $hint = $panel.find('.followup-hint');
    const wasLockedByWarranty = $checkbox.prop('disabled');

    if (type === 'WARRANTY') {
      $checkbox.prop('checked', true).prop('disabled', true);
      $state.text('Do not invoice').removeClass('bg-secondary').addClass('bg-danger');
      $hint.text('Warranty claim creates a no-invoice production order and keeps the workflow out of Ready to Invoice.');
      return;
    }

    if (type === 'SPLIT') {
      $checkbox.prop('checked', true).prop('disabled', true);
      $state.text('Do not invoice').removeClass('bg-secondary').addClass('bg-danger');
      $hint.text('Order split moves selected items into a separate order for their own box and tracking number (Q1, Q2, ...).');
      return;
    }

    $checkbox.prop('disabled', false);
    if (wasLockedByWarranty) {
      $checkbox.prop('checked', false);
    }
    if ($checkbox.is(':checked')) {
      $state.text('Do not invoice').removeClass('bg-secondary').addClass('bg-danger');
    } else {
      $state.text('Standard invoicing').removeClass('bg-danger').addClass('bg-secondary');
    }

    if (type === 'CRASH') {
      $hint.text('Crash replacement lets you keep only the damaged parts and adjust quantities.');
    } else {
      $hint.text('Repeat order will copy selected items into a new production order.');
    }
  }

  $(document).on('click', '.btn-toggle-followup-panel', function () {
    const $panel = $(this).closest('.order-followup-panel');
    const $form = $panel.find('.order-followup-form');
    $form.slideToggle(150);
    $(this).text($form.is(':visible') ? 'Open' : 'Hide');
    setTimeout(function () {
      applyFollowupTypeState($panel);
    }, 0);
  });

  $(document).on('click', '.btn-followup-select-all', function () {
    const $panel = $(this).closest('.order-followup-panel');
    const $checks = $panel.find('.followup-item-check');
    const shouldCheck = $checks.filter(':checked').length !== $checks.length;
    $checks.prop('checked', shouldCheck);
    $(this).text(shouldCheck ? 'Clear all' : 'Select all');
  });

  $(document).on('change', '.followup-type-select, .followup-do-not-invoice', function () {
    applyFollowupTypeState($(this).closest('.order-followup-panel'));
  });

  $(document).on('click', '.btn-create-followup-order', function () {
    const $panel = $(this).closest('.order-followup-panel');
    const orderId = parseInt($panel.find('.followup-order-id').val() || '0', 10);
    const type = String($panel.find('.followup-type-select').val() || 'REPEAT').toUpperCase();
    const reason = String($panel.find('.followup-reason').val() || '').trim();
    const doNotInvoice = $panel.find('.followup-do-not-invoice').is(':checked') ? 1 : 0;
    const payload = {
      order_id: orderId,
      followup_type: type,
      do_not_invoice: doNotInvoice,
      reason: reason
    };

    let selectedCount = 0;
    $panel.find('.followup-item-check:checked').each(function () {
      const itemId = parseInt($(this).data('item-id') || '0', 10);
      const qty = parseInt($panel.find('.followup-item-qty[data-item-id="' + itemId + '"]').val() || '0', 10);
      if (!itemId || qty <= 0) return;
      payload['selected_items[' + itemId + ']'] = qty;
      selectedCount++;
    });

    if (!orderId || selectedCount === 0) {
      alert('Select at least one item for the follow-up order.');
      return;
    }

    const $btn = $(this);
    $btn.prop('disabled', true).text('Creating...');

    $.ajax({
      url: 'scripts/orders/create_followup_order.php',
      method: 'POST',
      dataType: 'json',
      data: payload,
      success: function (res) {
        if (!res || !res.ok) {
          alert(res && res.error ? res.error : 'Follow-up creation failed');
          $btn.prop('disabled', false).text('Create Follow-up');
          return;
        }

        const url = new URL(window.location.href);
        url.searchParams.set('page', 'orders');
        url.searchParams.set('q', String(res.order_number || ''));
        window.location.href = url.toString();
      },
      error: function () {
        alert('Follow-up creation request failed');
        $btn.prop('disabled', false).text('Create Follow-up');
      }
    });
  });
  $(document).on('click', '.btn-delete-invoice', function () {
    const id = $(this).data('id');
    const orderId = $(this).data('order-id');

    if (!confirm('Delete invoice?')) return;

    $.post('scripts/orders/delete_invoice.php', { id: id }, function (res) {
      if (!res || !res.ok) {
        alert(res && res.error ? res.error : 'Delete failed');
        return;
      }

      reloadOrderDetail(orderId);
    }, 'json');
  });

  $(document).on('click', '.btn-toggle-activity', function () {
    $(this).closest('.card-body').find('.activity-log-panel').slideToggle(150);
  });

  $(document).on('click', '.btn-load-older-activity', function () {
    const $btn = $(this);
    const orderId = $btn.data('order-id');
    const offset = parseInt($btn.data('offset') || 0, 10);
    const $panel = $btn.closest('.activity-log-panel');
    const $list = $panel.find('.activity-log-list');

    $btn.prop('disabled', true).text('Loading...');

    $.post('scripts/orders/load_activity_log.php', {
      order_id: orderId,
      offset: offset
    }, function (res) {
      if (!res || !res.ok) {
        alert(res && res.error ? res.error : 'Load failed');
        $btn.prop('disabled', false).text('Load older');
        return;
      }

      if (res.html) {
        $list.append(res.html);
        $btn.data('offset', offset + 30);
        $btn.prop('disabled', false).text('Load older');
      } else {
        $btn.text('No older records').prop('disabled', true);
      }
    }, 'json');
  });

  $(document).on('change', '.order-types-select', function () {
    const $select = $(this);
    const orderId = $select.data('order-id');
    const types = $select.val();

    $select.prop('disabled', true);

    $.post('scripts/orders/update_order_types.php', {
      order_id: orderId,
      types: types
    }, function (res) {
      if (!res || !res.ok) {
        alert(res && res.error ? res.error : 'Types update failed');
        $select.prop('disabled', false);
        return;
      }

      location.reload();
    }, 'json').fail(function () {
      alert('Types update request failed');
      $select.prop('disabled', false);
    });
  });

  function applyManualItemTypeTheme($box, type) {
    const themeClasses = 'manual-item-type-neutral manual-item-type-G manual-item-type-P manual-item-type-T manual-item-type-M manual-item-type-S manual-item-type-F';
    $box.removeClass(themeClasses);

    const normalizedType = String(type || '').trim();
    if (!normalizedType) {
      $box.addClass('manual-item-type-neutral');
      return;
    }

    $box.addClass('manual-item-type-' + normalizedType);
  }

  $(document).on('change', '.manual-item-type', function () {
    const $box = $(this).closest('.manual-item-box');
    const type = String($(this).val() || '').trim();
    const $target = $box.find('.manual-item-generated-fields');

    applyManualItemTypeTheme($box, type);
    $target.html('');
    if (!type) {
      return;
    }

    $target.html('<div class="small text-muted"><span class="spinner-border spinner-border-sm"></span> Loading fields...</div>');

    $.post('scripts/orders/get_manual_item_builder.php', {
      item_type_code: type
    }, function (res) {
      if (!res || !res.ok) {
        $target.html('<div class="small text-danger">Could not load fields.</div>');
        return;
      }

      $target.html(String(res.html || ''));
    }, 'json').fail(function () {
      $target.html('<div class="small text-danger">Could not load fields.</div>');
    });
  });

  $(document).on('click', '.btn-add-manual-item', function () {
    const $box = $(this).closest('.manual-item-box');
    const orderId = $(this).data('order-id');
    const $btn = $(this);

    const title = $box.find('.manual-item-title').val().trim();
    const type = $box.find('.manual-item-type').val();
    if (!type) {
      alert('Please select item type');
      return;
    }
    if (!title) {
      alert('Item title is required');
      return;
    }
    const qty = $box.find('.manual-item-qty').val();
    const sku = $box.find('.manual-item-sku').val().trim();
    const reason = $box.find('.manual-item-reason').val().trim();
    const payload = {
      order_id: orderId,
      title: title,
      item_type_code: type,
      qty: qty,
      sku: sku,
      reason: reason
    };

    $box.find('.manual-item-generated-fields :input[name]').each(function () {
      payload[$(this).attr('name')] = $(this).val();
    });

    $btn.prop('disabled', true).text('Adding...');

    $.post('scripts/orders/add_order_item.php', payload, function (res) {
      if (!res || !res.ok) {
        alert(res && res.error ? res.error : 'Add item failed');
        $btn.prop('disabled', false).text('Add item');
        return;
      }

      window.location.hash = 'order-' + orderId;
      location.reload();

    }, 'json').fail(function () {
      alert('Add item request failed');
      $btn.prop('disabled', false).text('Add item');
    });
  });
  $(document).on('click', '.btn-delete-order-item', function () {
    const itemId = $(this).data('item-id');
    const orderId = $(this).data('order-id');

    if (!confirm('Delete this item?')) return;

    $.post('scripts/orders/delete_order_item.php', {
      item_id: itemId
    }, function (res) {
      if (!res || !res.ok) {
        alert(res && res.error ? res.error : 'Delete item failed');
        return;
      }

      reloadOrderDetail(orderId);

    }, 'json').fail(function () {
      alert('Delete item request failed');
    });
  });
  $(document).on('click', '.btn-save-item', function () {
    const $tr = $(this).closest('tr');

    const itemId = $(this).data('id');
    const orderId = $(this).data('order-id');

    const title = $tr.find('.item-title').val();
    const type = $tr.find('.item-type').val() || $tr.data('item-type') || '';
    const qty = $tr.find('.item-qty').val();
    const sku = $tr.find('.item-sku').val();
    const label = $tr.find('.item-label').val();

    $.post('scripts/orders/update_order_item.php', {
      item_id: itemId,
      title: title,
      type: type,
      qty: qty,
      sku: sku,
      custom_label: label
    }, function (res) {
      if (!res.ok) {
        alert(res.error || 'Update failed');
        return;
      }

      reloadOrderDetail(orderId);

    }, 'json').fail(() => {
      alert('Update request failed');
    });
  });
  $(document).on('change.orderDetailActions', '.item-status-select', function () {
    const $select = $(this);
    const itemId = $select.data('item-id');
    const status = $select.val();

    const note = $('.item-waiting-note[data-item-id="' + itemId + '"]').val() || '';
    const expectedDate = $('.item-expected-date[data-item-id="' + itemId + '"]').val() || '';

    $select.prop('disabled', true);

    $.ajax({
      url: 'scripts/orders/update_item_status.php',
      method: 'POST',
      dataType: 'json',
      data: {
        item_id: itemId,
        status: status,
        note: note,
        expected_date: expectedDate
      },
      success: function (resp) {
        $select.prop('disabled', false);

        if (!resp || !resp.success) {
          alert(resp && resp.message ? resp.message : 'Status update failed');
          return;
        }

        // Aplikujeme semafor priamo z odpovede — bez reloadu
        if (resp.traffic_summary && resp.order_id) {
          applyTrafficSummaryToRow(resp.order_id, resp.traffic_summary, resp.order_status);
        }

        // Refreshneme len otvorený detail panel
        const orderId = resp.order_id || 0;
        if (orderId && $('#detail-' + orderId).length) {
          reloadOrderDetail(orderId);
          return;
        }

        location.reload();
      },
      error: function (xhr) {
        $select.prop('disabled', false);
        console.log(xhr.responseText);
        alert('Status update request failed');
      }
    });
  });
  $(document).on('click', '.btn-set-product-url', function () {
    const itemId = $(this).data('item-id');
    const url = prompt('Paste product URL');

    if (url === null) return;

    $.ajax({
      url: 'scripts/orders/update_item_product_url.php',
      method: 'POST',
      dataType: 'json',
      data: {
        item_id: itemId,
        product_url: url
      },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert(resp && resp.error ? resp.error : 'Product URL save failed');
          return;
        }

        location.reload();
      },
      error: function (xhr) {
        console.log(xhr.responseText);
        alert('Product URL request failed');
      }
    });
  });
  $(document).on('click', '.btn-edit-production-note', function () {
    const $box = $(this).closest('.production-note-box');

    $box.find('.production-note-display').hide();
    $box.find('.btn-edit-production-note').hide();
    $box.find('.production-note-editor').show();
    $box.find('.production-note-input').focus();
  });

  $(document).on('click', '.btn-cancel-production-note', function () {
    const $box = $(this).closest('.production-note-box');

    $box.find('.production-note-editor').hide();
    $box.find('.production-note-display').show();
    $box.find('.btn-edit-production-note').show();
  });

  $(document).on('click', '.btn-open-invite-modal', function (e) {
    e.preventDefault();
    e.stopPropagation();

    const orderId = $(this).data('order-id');
    const mode = $(this).data('mode');
    const deptCode = $(this).data('dept-code') || '';

    $('#inviteOrderId').val(orderId);
    $('#inviteMode').val(mode);
    $('#inviteDeptCode').val(deptCode);
    $('#inviteEmployeeSearch').val('');
    $('#inviteEmployeeResults').html('');

    $('#inviteModalTitle').text(
      (mode === 'assign' ? 'Assign To' : 'Invite') + (deptCode ? ' - ' + deptCode : '')
    );

    $('#inviteModal').modal('show');
  });

  let inviteSearchTimer = null;

  $(document).on('input', '#inviteEmployeeSearch', function () {
    const q = $(this).val().trim();
    const deptCode = $('#inviteDeptCode').val();

    clearTimeout(inviteSearchTimer);

    if (q.length < 2) {
      $('#inviteEmployeeResults').html('');
      return;
    }

    inviteSearchTimer = setTimeout(function () {
      $.ajax({
        url: 'scripts/employees/employees_search.php',
        method: 'GET',
        dataType: 'json',
        data: {
          q: q,
          dept_code: deptCode
        },
        success: function (resp) {
          if (!resp || !resp.ok) {
            $('#inviteEmployeeResults').html(
              '<div class="text-danger">' + (resp && resp.error ? resp.error : 'Search failed') + '</div>'
            );
            return;
          }

          let html = '';

          if (!resp.items || resp.items.length === 0) {
            html = '<div class="text-muted">No active employee found.</div>';
          } else {
            resp.items.forEach(function (emp) {
              html += `
              <button type="button"
                      class="btn btn-sm btn-outline-light btn-block text-left btn-select-invite-employee"
                      data-employee-id="${emp.id}">
                ${emp.name}
              </button>
            `;
            });
          }

          $('#inviteEmployeeResults').html(html);
        },
        error: function (xhr) {
          console.log(xhr.responseText);
          $('#inviteEmployeeResults').html('<div class="text-danger">Search request failed</div>');
        }
      });
    }, 250);
  });

  $(document).on('click', '.btn-select-invite-employee', function (e) {
    e.preventDefault();
    e.stopPropagation();

    const employeeId = $(this).data('employee-id');

    $.ajax({
      url: 'scripts/orders/invite_collab.php',
      method: 'POST',
      dataType: 'json',
      data: {
        order_id: $('#inviteOrderId').val(),
        employee_id: employeeId,
        mode: $('#inviteMode').val(),
        dept_code: $('#inviteDeptCode').val()
      },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert(resp && resp.error ? resp.error : 'Assign / Invite failed');
          return;
        }

        $('#inviteModal').modal('hide');

        if (resp.avatars_html !== undefined && resp.order_id) {
          $('[data-assigned-cell="' + resp.order_id + '"]').html(resp.avatars_html);

          if (resp.take_assign_html !== undefined) {
            $('[data-take-assign-cell="' + resp.order_id + '"]').html(resp.take_assign_html);
          }

          const $wrap = $('#detail-' + resp.order_id);
          if ($wrap.length && $wrap.data('loaded')) {
            reloadOrderDetail(resp.order_id);
          }
          return;
        }

        location.reload();
      },
      error: function (xhr) {
        console.log(xhr.responseText);
        alert('Assign / Invite request failed');
      }
    });
  });

  $(document).on('change', 'select[name="dept"], select[name="cat"], select[name="type"], select[name="worker"]', function () {
    $(this).closest('form').submit();
  });
  $(document).ready(function () {
    if (window.location.hash && window.location.hash.indexOf('#order-') === 0) {
      const orderId = window.location.hash.replace('#order-', '');

      setTimeout(function () {
        $('.btn-toggle-detail[data-order-id="' + orderId + '"]').click();
      }, 300);
    }
  });
  let currentOptionsItemId = 0;
  let currentInternalOptions = {};

  function renderInternalOptions(data) {
    if (!data || Object.keys(data).length === 0) {
      return '<div class="text-muted">No internal production blocks yet.</div>';
    }

    let html = '';

    Object.keys(data).forEach(function (blockName) {
      html += `
      <div class="card bg-secondary mb-2">
        <div class="card-header py-2">
          <b>${blockName}</b>
        </div>
        <div class="card-body py-2">
    `;

      const fields = data[blockName] || {};

      Object.keys(fields).forEach(function (key) {
        html += `
        <div class="mb-1">
          <span class="text-muted">${key}:</span>
          <b>${fields[key]}</b>
        </div>
      `;
      });

      html += `
        </div>
      </div>
    `;
    });

    return html;
  }
  function escapeHtml(str) {
    return String(str ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function renderInternalEditor(data) {
    let html = '';

    if (!data || Object.keys(data).length === 0) {
      data = {
        'Production Info': {
          'Note': ''
        }
      };
    }

    Object.keys(data).forEach(function (blockName) {
      const fields = data[blockName] || {};

      html += `
      <div class="card bg-secondary mb-2 internal-block">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <input type="text"
                 class="form-control form-control-sm internal-block-name"
                 value="${escapeHtml(blockName)}"
                 placeholder="Block name"
                 style="max-width:320px;">

          <div class="d-flex flex-column align-items-end" style="gap:4px; min-width:70px;">
            <button type="button" class="btn btn-xs btn-outline-light btn-add-internal-field" style="width:70px;">
              <i class="fas fa-plus"></i> Field
                </button>

                <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-block" style="width:70px;">
                  <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
        <div class="card-body py-2 internal-fields">
    `;

      Object.keys(fields).forEach(function (key) {
        html += `
        <div class="form-row align-items-center mb-2 internal-field">
          <div class="col-md-4">
            <input type="text"
                   class="form-control form-control-sm internal-field-key"
                   value="${escapeHtml(key)}"
                   placeholder="Field name">
          </div>

          <div class="col-md-7">
            <input type="text"
                   class="form-control form-control-sm internal-field-value"
                   value="${escapeHtml(fields[key])}"
                   placeholder="Value">
          </div>

          <div class="col-md-1 text-right">
            <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-field">
              ×
            </button>
          </div>
        </div>
      `;
      });

      html += `
        </div>
      </div>
    `;
    });

    $('#internalBlocksEditor').html(html);
  }

  function collectInternalEditorData() {
    const data = {};

    $('#internalBlocksEditor .internal-block').each(function () {
      const blockName = $(this).find('.internal-block-name').val().trim();

      if (!blockName) return;

      data[blockName] = {};

      $(this).find('.internal-field').each(function () {
        const key = $(this).find('.internal-field-key').val().trim();
        const value = $(this).find('.internal-field-value').val().trim();

        if (key) {
          data[blockName][key] = value;
        }
      });

      if (Object.keys(data[blockName]).length === 0) {
        delete data[blockName];
      }
    });

    return data;
  }
  $(document).on('change', '#ordersFilterForm select', function () {
    $('#ordersFilterForm').trigger('submit');
  });
  function updateOrdersStickyOffsets() {
    const baseTop = 50;

    const headerH = $('.orders-search-header').outerHeight() || 58;
    const tabsH = $('.orders-quicktabs').outerHeight() || 42;
    const toolbarH = $('.orders-toolbar').outerHeight() || 38;

    document.documentElement.style.setProperty('--orders-header-h', headerH + 'px');
    document.documentElement.style.setProperty('--orders-tabs-h', tabsH + 'px');
    document.documentElement.style.setProperty('--orders-toolbar-h', toolbarH + 'px');
    document.documentElement.style.setProperty(
      '--orders-table-head-top',
      (baseTop + headerH + tabsH + toolbarH) + 'px'
    );
  }

  // Prehliadace prepocitavaju "prilepenu" poziciu position:sticky prvkov
  // hlavne pri scroll evente. Ked sa filter panel otvori/zatvori a stranka
  // je pritom prilis kratka na scrollovanie (malo vysledkov), ziadny scroll
  // event nenastane a hlavicka tabulky ostane vizualne "zamrznuta" na starom
  // offsete, aj ked CSS premenna --orders-table-head-top je uz spravna.
  // Kratke prepnutie position: static -> sticky vynuti reflow a donuti
  // prehliadac si stickiness prepocitat aj bez scrollu.
  function forceOrdersTheadReflow() {
    const $ths = $('#ordersTableWrap > table#ordersTable > thead > tr > th');
    if (!$ths.length) return;
    $ths.css('position', 'static');
    void document.body.offsetHeight; // vynuti reflow
    $ths.css('position', 'sticky');
  }

  function refreshOrdersStickySoon() {
    updateOrdersStickyOffsets();
    forceOrdersTheadReflow();
    // Zalozne aj na nizsom ResizeObserveri, ale tento fallback nechavame
    // pre pripad, ze by observer nestihol prvy frame (napr. tesne po load).
    [50, 180, 360].forEach(function (delay) {
      setTimeout(function () {
        updateOrdersStickyOffsets();
        forceOrdersTheadReflow();
      }, delay);
    });
  }

  // ResizeObserver sleduje realnu vysku tabs/toolbar/filter panelu a prepocita
  // offset presne v momente, ked sa (aj priebezne pocas bootstrap collapse
  // animacie) skutocne zmeni - namiesto hadania cez pevne casove oneskorenia.
  if (typeof ResizeObserver !== 'undefined') {
    const ordersStickyResizeObserver = new ResizeObserver(function () {
      updateOrdersStickyOffsets();
      forceOrdersTheadReflow();
    });
    ['.orders-search-header', '.orders-quicktabs', '.orders-toolbar'].forEach(function (sel) {
      const el = document.querySelector(sel);
      if (el) ordersStickyResizeObserver.observe(el);
    });
  }

  $(document).ready(refreshOrdersStickySoon);
  $(window).on('resize scroll', updateOrdersStickyOffsets);

  $(window).on('resize', updateOrdersStickyOffsets);
  function sortOrdersByPriorityAndDate() {
    const $tbody = $('#ordersTable tbody');

    const pairs = [];

    $tbody.find('tr.order-row').each(function () {
      const $orderRow = $(this);
      const $detailRow = $orderRow.next('.order-detail-row');

      pairs.push({
        priority: parseInt($orderRow.data('priority-sort'), 10),
        date: String($orderRow.data('date-sort') || '9999-12-31'),
        id: parseInt($orderRow.data('order-id'), 10),
        splitParentId: parseInt($orderRow.data('split-parent-id'), 10) || 0,
        orderRow: $orderRow,
        detailRow: $detailRow
      });
    });

    pairs.sort(function (a, b) {
      if (a.priority !== b.priority) return a.priority - b.priority;
      if (a.date !== b.date) return a.date.localeCompare(b.date);
      return a.id - b.id;
    });

    // Order Split grouping: pull SPLIT rows (Q1, Q2, ...) out of the sorted list
    // and reinsert each directly after its parent order, so an unrelated order
    // sorting in between (same date/priority) can never split them apart.
    const byId = new Map();
    pairs.forEach(function (p) { byId.set(p.id, p); });

    const splitChildrenByParent = new Map();
    const topLevel = [];
    pairs.forEach(function (p) {
      if (p.splitParentId && byId.has(p.splitParentId)) {
        if (!splitChildrenByParent.has(p.splitParentId)) {
          splitChildrenByParent.set(p.splitParentId, []);
        }
        splitChildrenByParent.get(p.splitParentId).push(p);
      } else {
        topLevel.push(p);
      }
    });

    const grouped = [];
    topLevel.forEach(function (p) {
      grouped.push(p);
      if (splitChildrenByParent.has(p.id)) {
        splitChildrenByParent.get(p.id).forEach(function (child) {
          grouped.push(child);
        });
      }
    });

    grouped.forEach(function (p) {
      $tbody.append(p.orderRow);
      $tbody.append(p.detailRow);
    });
  }

  $(document).ready(function () {
    sortOrdersByPriorityAndDate();
  });
</script>
<?php $orderDetailActionsVersion = @filemtime(__DIR__ . '/../scripts/orders/order_detail_actions.js') ?: time(); ?>
<script src="scripts/orders/order_detail_actions.js?v=<?= (int) $orderDetailActionsVersion ?>"></script>
<div class="modal fade" id="optionsModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content bg-dark text-light">

      <div class="modal-header border-secondary">
        <h5 class="modal-title">
          <i class="fas fa-list-alt mr-1"></i> Product Options
        </h5>

        <button type="button" class="close text-light" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">

        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="text-muted mb-0">
              <i class="fas fa-download mr-1"></i> Imported Product Options
            </h6>
          </div>

          <div id="optionsModalBody"></div>
        </div>

        <hr class="border-secondary my-3">

        <div class="mb-2">
          <div class="d-flex justify-content-between align-items-center mb-2">

            <h6 class="text-muted mb-0">
              <i class="fas fa-tools mr-1"></i> Internal Production Blocks
            </h6>
            <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
              <button type="button" class="btn btn-sm btn-outline-warning" id="btnEditInternalOptions">
                <i class="fas fa-edit mr-1"></i> Edit internal
              </button>
            <?php endif; ?>
          </div>

          <div id="internalOptionsView" class="mb-2"></div>

          <div id="internalOptionsEditBox" style="display:none;">

            <div class="d-flex justify-content-between align-items-center mb-2">
              <small class="text-muted">Add production information as blocks and fields.</small>

              <button type="button" class="btn btn-sm btn-outline-info" id="btnAddInternalBlock">
                <i class="fas fa-plus mr-1"></i> Add block
              </button>
            </div>

            <div id="internalBlocksEditor"></div>

            <textarea id="internalOptionsJson"
              class="form-control form-control-sm bg-dark text-light border-warning mt-2" rows="6" spellcheck="false"
              style="display:none;"></textarea>

            <div class="d-flex justify-content-end mt-2">
              <button type="button" class="btn btn-sm btn-success" id="btnSaveInternalOptions">
                <i class="fas fa-save mr-1"></i> Save internal blocks
              </button>
            </div>

          </div>
        </div>

      </div>

      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" data-bs-dismiss="modal">
          Close
        </button>
      </div>

    </div>
  </div>
</div>

<!-- ── Priority Date Modal ────────────────────────────────────────── -->
<div class="modal fade" id="priorityDateModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-sm" role="document">
    <div class="modal-content bg-dark text-white">
      <div class="modal-header border-secondary">
        <h5 class="modal-title"><i class="fas fa-flag mr-2"></i>Set Priority</h5>
        <button type="button" class="close text-white" onclick="$('#priorityDateModal').modal('hide')"
          aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="priorityModalOrderId">
        <div class="form-group mb-3">
          <label class="text-muted small mb-1">Priority level</label>
          <select id="priorityModalLevel" class="form-control form-control-sm bg-dark text-white border-secondary">
            <option value="0">⚫ Normal</option>
            <option value="10">🟡 Deadline</option>
            <option value="20">🔴 Priority</option>
          </select>
        </div>
        <div class="form-group mb-0" id="priorityModalDateWrap">
          <label class="text-muted small mb-1">Date</label>
          <input type="date" id="priorityModalDate"
            class="form-control form-control-sm bg-dark text-white border-secondary">
          <small class="text-muted">Required for Deadline and Priority</small>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary btn-sm"
          onclick="$('#priorityDateModal').modal('hide')">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="priorityModalSave">
          <i class="fas fa-save mr-1"></i>Save
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="fedexExportModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content bg-dark text-light">
      <form method="post" action="export_fedex_ready_to_ship.php" target="_blank">
        <div class="modal-header border-secondary">
          <h5 class="modal-title">
            <i class="fas fa-file-csv mr-2"></i>FedEx CSV Preview
          </h5>
          <button type="button" class="close text-light" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div id="fedexExportModalBody" class="fedex-export-loader text-muted">
            <div><span class="spinner-border spinner-border-sm mr-2"></span>Loading export preview...</div>
          </div>
        </div>
        <div class="modal-footer border-secondary">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" data-bs-dismiss="modal">
            Cancel
          </button>
          <button type="submit" class="btn btn-success btn-sm" id="fedexExportSubmitBtn" disabled>
            <i class="fas fa-download mr-1"></i>Generate CSV
          </button>
        </div>
      </form>
      <div class="fedex-resize-handle" aria-hidden="true"></div>
    </div>
  </div>
</div>

<div class="modal fade" id="fedexEodImportModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title">
          <i class="fas fa-file-upload mr-2"></i>FedEx EOD Import
        </h5>
        <button type="button" class="close text-light" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <form id="fedexEodImportForm" enctype="multipart/form-data">
          <div class="fedex-eod-dropzone mb-3">
            <div class="font-weight-bold mb-2">Upload EOD report (.xlsx)</div>
            <div class="text-muted small mb-3">
              Použijú sa stĺpce <code>Reference Notes</code>, <code>Shipping Date</code>,
              <code>Master Tracking Number</code> a <code>Service Type</code>.
            </div>
            <div class="custom-file">
              <input type="file" class="custom-file-input" id="fedexEodFile" name="file" accept=".xlsx,.xls">
              <label class="custom-file-label" for="fedexEodFile">Choose EOD file</label>
            </div>
          </div>
        </form>
        <div id="fedexEodImportResult" class="fedex-eod-result"></div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" data-bs-dismiss="modal">
          Close
        </button>
        <button type="button" class="btn btn-info btn-sm" id="fedexEodImportSubmitBtn">
          <i class="fas fa-upload mr-1"></i>Import Tracking
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  $(function () {
    const $fedexExportModal = $('#fedexExportModal');
    const $fedexExportDialog = $fedexExportModal.find('.modal-dialog');
    const $fedexExportContent = $fedexExportModal.find('.modal-content');
    const $fedexExportModalBody = $('#fedexExportModalBody');
    const $fedexExportSubmitBtn = $('#fedexExportSubmitBtn');
    const $fedexEodImportModal = $('#fedexEodImportModal');
    const $fedexEodFile = $('#fedexEodFile');
    const $fedexEodImportResult = $('#fedexEodImportResult');
    const $fedexEodImportSubmitBtn = $('#fedexEodImportSubmitBtn');
    let fedexEodImportShouldReload = false;
    let fedexExportResizableInit = false;

    function initFedexExportResizable() {
      if (fedexExportResizableInit || typeof $fedexExportContent.resizable !== 'function') {
        return;
      }

      $fedexExportContent.resizable({
        handles: {
          se: '.fedex-resize-handle'
        },
        minWidth: 900,
        minHeight: 500,
        maxWidth: Math.floor(window.innerWidth * 0.97),
        maxHeight: Math.floor(window.innerHeight * 0.92),
        resize: function (event, ui) {
          ui.element.css({ width: ui.size.width + 'px', height: ui.size.height + 'px' });
        }
      });

      fedexExportResizableInit = true;
    }

    function loadFedexExportPreview() {
      $fedexExportSubmitBtn.prop('disabled', true);
      $fedexExportModalBody
        .removeClass('alert alert-danger')
        .addClass('fedex-export-loader text-muted')
        .html('<div><span class="spinner-border spinner-border-sm mr-2"></span>Loading export preview...</div>');

      $.ajax({
        url: 'export_fedex_ready_to_ship.php',
        method: 'GET',
        data: { preview: 1 },
        cache: false,
        success: function (html) {
          $fedexExportModalBody
            .removeClass('fedex-export-loader text-muted')
            .html(html);
          $fedexExportSubmitBtn.prop('disabled', false);
        },
        error: function (xhr) {
          const msg = xhr && xhr.responseText
            ? xhr.responseText.substring(0, 400)
            : 'Failed to load export preview.';
          $fedexExportModalBody
            .removeClass('fedex-export-loader text-muted')
            .addClass('alert alert-danger mb-0')
            .text(msg);
        }
      });
    }

    function resetFedexEodImportModal() {
      $('#fedexEodImportForm')[0].reset();
      $fedexEodImportResult.html('');
      $fedexEodImportSubmitBtn.prop('disabled', false).html('<i class="fas fa-upload mr-1"></i>Import Tracking');
      $fedexEodImportModal.find('.custom-file-label').text('Choose EOD file');
    }

    $(document).on('click', '.priority-badge-clickable', function (e) {
      e.preventDefault();
      e.stopPropagation();

      var orderId = $(this).data('order-id');
      var priority = parseInt($(this).data('priority'), 10) || 0;
      var date = $(this).data('priority-date') || '';

      $('#priorityModalOrderId').val(orderId);
      $('#priorityModalLevel').val(priority);
      $('#priorityModalDate').val(date);
      $('#priorityModalDateWrap').toggle(priority > 0);
      $('#priorityDateModal').modal('show');
    });

    $('#priorityModalLevel').on('change', function () {
      var val = parseInt($(this).val(), 10);
      $('#priorityModalDateWrap').toggle(val > 0);
      if (val === 0) $('#priorityModalDate').val('');
    });

    $('#priorityModalSave').on('click', function () {
      var orderId = $('#priorityModalOrderId').val();
      var priority = $('#priorityModalLevel').val();
      var date = $('#priorityModalDate').val();

      if (parseInt(priority) > 0 && !date) {
        alert('Please select a date for Deadline or Priority.');
        return;
      }

      var $btn = $(this).prop('disabled', true).html('Saving…');

      $.ajax({
        url: 'scripts/orders/update_order_priority.php',
        method: 'POST',
        dataType: 'json',
        data: { order_id: orderId, priority: priority, priority_date: date },
        success: function (resp) {
          if (!resp || !resp.ok) {
            alert(resp && resp.error ? resp.error : 'Failed to save priority');
            $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i>Save');
            return;
          }
          $('#priorityDateModal').modal('hide');
          location.reload();
        },
        error: function () {
          alert('Request failed');
          $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i>Save');
        }
      });
    });

    $(document).on('click', '.js-open-fedex-export-modal', function (e) {
      e.preventDefault();
      e.stopPropagation();
      $fedexExportModal.modal('show');
      loadFedexExportPreview();
    });

    $(document).on('click', '.js-open-fedex-eod-import-modal', function (e) {
      e.preventDefault();
      e.stopPropagation();
      resetFedexEodImportModal();
      $fedexEodImportModal.modal('show');
    });

    $fedexEodFile.on('change', function () {
      const fileName = this.files && this.files.length ? this.files[0].name : 'Choose EOD file';
      $fedexEodImportModal.find('.custom-file-label').text(fileName);
    });

    $fedexEodImportSubmitBtn.on('click', function () {
      if (!$fedexEodFile[0].files || !$fedexEodFile[0].files.length) {
        alert('Please choose an EOD XLSX file first.');
        return;
      }

      const formData = new FormData();
      formData.append('file', $fedexEodFile[0].files[0]);

      $fedexEodImportSubmitBtn.prop('disabled', true).html('Importing...');
      fedexEodImportShouldReload = false;
      $fedexEodImportResult.html(
        '<div class="alert alert-secondary mb-0"><span class="spinner-border spinner-border-sm mr-2"></span>Processing uploaded EOD report...</div>'
      );

      $.ajax({
        url: 'scripts/orders/import_fedex_eod.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        cache: false,
        success: function (html) {
          $fedexEodImportResult.html(html);
          $fedexEodImportSubmitBtn.prop('disabled', false).html('<i class="fas fa-upload mr-1"></i>Import Tracking');
          fedexEodImportShouldReload = $fedexEodImportResult.find('.alert-info').length > 0;
        },
        error: function (xhr) {
          const html = xhr && xhr.responseText
            ? xhr.responseText
            : '<div class="alert alert-danger mb-0">Import failed.</div>';
          $fedexEodImportResult.html(html);
          $fedexEodImportSubmitBtn.prop('disabled', false).html('<i class="fas fa-upload mr-1"></i>Import Tracking');
        }
      });
    });

    $fedexEodImportModal.on('hidden.bs.modal', function () {
      if (fedexEodImportShouldReload) {
        window.location.reload();
      }
    });

    $fedexExportModal.on('shown.bs.modal', function () {
      initFedexExportResizable();
      if (fedexExportResizableInit) {
        $fedexExportContent.resizable('option', {
          maxWidth: Math.floor(window.innerWidth * 0.97),
          maxHeight: Math.floor(window.innerHeight * 0.92)
        });
      }
    });

  });
</script>
