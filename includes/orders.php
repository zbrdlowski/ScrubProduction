<?php
declare(strict_types=1);
/** @var mysqli $conn */
require_once __DIR__ . '/conn.php';

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
$dpt = (int) ($_SESSION['dpt'] ?? 0);
$allAccess = in_array($dpt, [1, 3, 4, 5, 7], true);

// --- Filters (GET) ---
$page = 'orders';
$fDept = isset($_GET['dept']) ? (int) $_GET['dept'] : 0;
$fCat = isset($_GET['cat']) ? trim((string) $_GET['cat']) : '';
$fType = isset($_GET['type']) ? trim((string) $_GET['type']) : '';
$fQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

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

if ($allAccess && $fDept > 0) {
  $effectiveDept = $fDept;
}

$uiDept = $effectiveDept;
$uiDeptCode = $deptCodeMap[$uiDept] ?? null;
$rolePrimaryUI = $uiDeptCode ? ('PRIMARY_' . $uiDeptCode) : null;

$meUserId = (int) ($_SESSION['user_id'] ?? 0);
$perm = (int) ($_SESSION['permission'] ?? 0);

$aclCats = [];
$aclTypes = [];

if (!$allAccess) {
  $aclCats = $deptFilter[$dpt] ?? [];
  $aclTypes = $deptTypeFilter[$dpt] ?? [];
} else {
  $aclCats = $deptFilter[$effectiveDept] ?? [];
  $aclTypes = $deptTypeFilter[$effectiveDept] ?? [];
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
  $where[] = "(o.order_number LIKE CONCAT('%', ?, '%')
    OR o.external_order_id LIKE CONCAT('%', ?, '%')
    OR (cu.name LIKE CONCAT('%', ?, '%') OR cu.email LIKE CONCAT('%', ?, '%'))
  )";
  $types .= 'ssss';
  array_push($params, $fQ, $fQ, $fQ, $fQ);
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = " SELECT
  o.id,
  o.order_number,
  o.external_order_id,
  o.order_date,
  o.imported_at,
  o.status,
  o.traffic_light,
  o.traffic_blocker,
  o.traffic_summary_json,
  o.manual_types_override,
  o.payment_method,
  o.shipping_method,
  os.code AS source_code,
  cu.name AS customer_name,
  cu.email AS customer_email,
  COALESCE(oa_ship.country, oa_bill.country) AS country_code,

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
ORDER BY o.id ASC
LIMIT 500
";

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

$deptOptions = [
  0 => 'Auto (By my department)',
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

  .order-row-open {
    background: rgba(23, 162, 184, 0.18) !important;
    box-shadow: inset 4px 0 0 #17a2b8;
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
</style>

<div class="card card-dark">
  <div class="card-header">
    <h3 class="card-title">
      Orders
      <?php if ($dpt === 6): ?><span class="badge badge-warning ml-2">T/M highlighted</span><?php endif; ?>
    </h3>
  </div>

  <div class="card-body">

    <form method="get" class="mb-3">
      <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>" />

      <div class="form-row">
        <div class="form-group col-md-3">
          <label>Department</label>
          <?php if ($allAccess): ?>
            <select class="form-control" name="dept">
              <?php foreach ($deptOptions as $k => $label): ?>
                <option value="<?= (int) $k ?>" <?= ($fDept === (int) $k ? 'selected' : '') ?>>
                  <?= htmlspecialchars($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input class="form-control"
              value="<?= htmlspecialchars((string) ($_SESSION['dpt_name'] ?? ('dpt ' . $dpt))) ?>" disabled />
          <?php endif; ?>
        </div>

        <div class="form-group col-md-3">
          <label>Category</label>
          <select class="form-control" name="cat">
            <option value="" <?= ($fCat === '' ? 'selected' : '') ?>>All</option>
            <?php foreach (['GRAPHICS', 'PLASTICS', 'SEATCOVER', 'FITTING'] as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>" <?= ($fCat === $c ? 'selected' : '') ?>><?= htmlspecialchars($c) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group col-md-3">
          <label>Item Type</label>
          <select class="form-control" name="type">
            <option value="" <?= ($fType === '' ? 'selected' : '') ?>>All</option>
            <?php foreach (['G', 'T', 'M', 'P', 'S', 'F', '(NULL)'] as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>" <?= ($fType === $t ? 'selected' : '') ?>>
                <?= htmlspecialchars($t) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group col-md-3">
          <label>Search</label>
          <input class="form-control" name="q" value="<?= htmlspecialchars($fQ) ?>"
            placeholder="Order #, customer, email..." />
        </div>
      </div>

      <div class="d-flex">
        <button class="btn btn-primary mr-2" type="submit"><i class="fas fa-filter"></i> Filter</button>
        <a class="btn btn-secondary" href="index.php?page=orders"><i class="fas fa-times"></i> Reset</a>
      </div>
    </form>

    <div class="table-responsive">
      <table id="example1" class="table table-bordered table-hover table-sm">
        <thead>
          <tr>
            <th width="5%">Date</th>
            <th width="5%">Source</th>
            <th width="4%">Country</th>
            <th width="8%">Order #</th>
            <th>Types</th>
            <th>Customer</th>
            <th class="text-center">Semafor</th>
            <th class="text-center">Status</th>
            <th>Assigned</th>
            <th>Detail</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $res->fetch_assoc()): ?>
            <?php
            $orderId = (int) $row['id'];
            $hasTM = (int) ($row['has_tm'] ?? 0) === 1;
            $rowClass = '';

            $statusUpper = strtoupper((string) ($row['status'] ?? ''));

            if ($statusUpper === 'IN_PROGRESS') {
              $rowClass = 'order-in-progress';
            } elseif ($dpt === 6 && $hasTM) {
              $rowClass = 'tm-highlight';
            }

            $typesStr = normalizeTypesOrder((string) ($row['manual_types_override'] ?: ($row['item_types'] ?? '')));
            $hasManualTypes = trim((string) ($row['manual_types_override'] ?? '')) !== '';
            $customer = trim((string) ($row['customer_name'] ?? ''));
            if ($customer === '')
              $customer = (string) ($row['customer_email'] ?? '-');
            ?>
            <tr class="<?= $rowClass ?> order-row" data-order-id="<?= $orderId ?>">
              <td>
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
              <td><?= htmlspecialchars((string) $row['source_code']) ?></td>
              <td>
                <?php
                $cc = strtoupper(trim((string) ($row['country_code'] ?? '')));

                if ($cc === 'UM')
                  $cc = 'US';

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

              <td>
                <div><b><?= htmlspecialchars((string) ($row['order_number'] ?? $row['external_order_id'] ?? '')) ?></b>
                </div>

                <?php if (!empty($row['external_order_id']) && $row['external_order_id'] !== $row['order_number']): ?>
                  <small class="text-muted">Ext: <?= htmlspecialchars((string) $row['external_order_id']) ?></small>

                <?php endif; ?>

              </td>
              <td align="center">
                <?php
                if ($hasManualTypes) {
                  // manual override – napr. GFPS
                  $types = [normalizeTypesOrder($typesStr)];
                } else {
                  // AUTO režim
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
              <td><?= htmlspecialchars($customer) ?></td>

              <!-- semafor -->

              <td class="text-center">
                <?php
                $summaryRaw = (string) ($row['traffic_summary_json'] ?? '');
                $summary = json_decode($summaryRaw, true);

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

                  if ($state === 'GREEN') {
                    $color = 'badge-success';
                  } elseif ($state === 'ORANGE') {
                    $color = 'badge-warning';
                  } else {
                    $color = 'badge-danger';
                  }
                  ?>
                  <span class="badge <?= $color ?> mr-1" style="font-size:1rem; padding:.5em .7em;"
                    title="<?= htmlspecialchars($type . ' ' . $state) ?>">
                    <?= htmlspecialchars($type) ?>
                  </span>
                <?php endforeach; ?>
              </td>

              <td class="text-center">
                <?php
                $status = strtoupper((string) ($row['status'] ?? ''));
                $statusBadge = 'badge-secondary';
                // nastavenie farby badge podľa statusu
                if ($status === 'NEW')
                  $statusBadge = 'badge-danger';
                elseif ($status === 'IN_PROGRESS')
                  $statusBadge = 'badge-primary';
                elseif ($status === 'HOLD')
                  $statusBadge = 'badge-info';
                elseif ($status === 'DONE' || $status === 'SHIPPED')
                  $statusBadge = 'badge-success';
                ?>
                <?php
                $status = strtoupper((string) ($row['status'] ?? ''));

                switch ($status) {
                  case 'NEW':
                    $btnClass = 'btn-outline-danger';
                    break;
                  case 'READY_TO_INVOICE':
                    $btnClass = 'btn-outline-warning';
                    break;

                  case 'IN_PROGRESS':
                    $btnClass = 'btn-outline-warning';
                    break;

                  case 'WAITING_PARTS':
                    $btnClass = 'btn-outline-warning';
                    break;

                  case 'HOLD':
                  case 'CANCELLED':
                    $btnClass = 'btn-outline-secondary';
                    break;

                  case 'DONE':
                  case 'COMPLETED':
                  case 'SHIPPED':
                  case 'READY':
                  case 'READY_TO_SHIP':
                    $btnClass = 'btn-outline-success';
                    break;

                  case 'NEED_INFO':
                    $btnClass = 'btn-outline-danger';
                    break;

                  default:
                    $btnClass = 'btn-outline-secondary';
                    break;
                }
                ?>
                <button class="btn btn-xs <?= $btnClass ?>" style="pointer-events:none;">
                  <?= htmlspecialchars(str_replace('_', ' ', $status) ?: '-') ?>
                </button>
              </td>
              <td>

                <?php
                $assignedRaw = (string) ($row['assigned_users'] ?? '');
                // debug: zobrazit surová data v title pro případ problémů s parsováním
                htmlspecialchars($assignedRaw);
                $assigned = [];

                if ($assignedRaw !== '') {
                  foreach (explode(';;', $assignedRaw) as $part) {
                    $bits = explode('|', $part);
                    if (count($bits) >= 6) {
                      $assigned[] = [
                        'assignment_id' => (int) $bits[0],
                        'id' => (int) $bits[1],
                        'name' => $bits[2],
                        'role' => $bits[3],
                        'state' => $bits[4],
                        'photo' => $bits[5],
                      ];
                    }
                  }
                }

                $maxVisible = 12;
                $visible = array_slice($assigned, 0, $maxVisible);
                $hiddenCount = max(0, count($assigned) - $maxVisible);
                ?>

                <?php if (!$assigned): ?>
                  <span class="text-muted"></span>
                <?php else: ?>
                  <div class="assigned-users">
                    <?php foreach ($visible as $a): ?>
                      <?php
                      $name = trim((string) $a['name']);
                      $role = trim((string) $a['role']);
                      $photo = trim((string) ($a['photo'] ?? ''));

                      $initials = '';
                      foreach (preg_split('/\s+/', $name) as $p) {
                        if ($p !== '') {
                          $initials .= mb_strtoupper(mb_substr($p, 0, 1));
                        }
                      }
                      $initials = mb_substr($initials, 0, 2);

                      $roleLabel = str_replace(
                        ['PRIMARY_', 'COLLAB_', '_'],
                        ['', 'Collab ', ' '],
                        $role
                      );

                      $roleClass = (strpos($role, 'PRIMARY_') === 0) ? 'assigned-primary' : 'assigned-collab';
                      ?>

                      <?php if ($photo !== ''): ?>
                        <span class="assigned-avatar-wrap">

                          <?php if ($photo !== ''): ?>
                            <img src="images/<?= htmlspecialchars($photo) ?>"
                              class="assigned-photo <?= htmlspecialchars($roleClass) ?>"
                              title="<?= htmlspecialchars($name . ' — ' . $roleLabel) ?>">
                          <?php else: ?>
                            <span class="assigned-avatar <?= htmlspecialchars($roleClass) ?>"
                              title="<?= htmlspecialchars($name . ' — ' . $roleLabel) ?>">
                              <?= htmlspecialchars($initials ?: '?') ?>
                            </span>
                          <?php endif; ?>

                          <?php if ($perm >= 300 && !empty($a['assignment_id'])): ?>
                            <button type="button" class="btn-remove-assignment"
                              data-assignment-id="<?= (int) $a['assignment_id'] ?>" title="Remove assignment">
                              ×
                            </button>
                          <?php endif; ?>

                        </span>
                      <?php else: ?>
                        <span class="assigned-avatar <?= htmlspecialchars($roleClass) ?>"
                          title="<?= htmlspecialchars($name . ' — ' . $roleLabel) ?>">
                          <?= htmlspecialchars($initials ?: '?') ?>
                        </span>
                      <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($hiddenCount > 0): ?>
                      <span class="assigned-more" title="<?= htmlspecialchars($assignedRaw) ?>">
                        +<?= (int) $hiddenCount ?>
                      </span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

              </td>

              <td class="text-nowrap">
                <button type="button" class="btn btn-sm btn-outline-light btn-toggle-detail mr-1"
                  data-order-id="<?= $orderId ?>">
                  <i class="fas fa-search"></i>
                </button>
                <?php if ($perm >= 400 && empty($uiDeptCode)): ?>
                  <span class="badge badge-info ml-2" title="Select department filter first">
                    Select dept
                  </span>
                <?php endif; ?>
                <?php
                $primaryId = isset($row['primary_emp_id']) ? (int) $row['primary_emp_id'] : 0;
                $primaryName = (string) ($row['primary_emp_name'] ?? '');
                $canUseDeptButtons = !empty($uiDeptCode);
                if ($perm >= 400 && empty($uiDeptCode)) {
                  $canUseDeptButtons = false;
                }
                $currentPrimaryRole = $uiDeptCode ? ('PRIMARY_' . $uiDeptCode) : '';

                $isTakenForDept = false;
                $takenByMeForDept = false;
                $takenNameForDept = '';

                foreach ($assigned as $a) {
                  if ($currentPrimaryRole !== '' && $a['role'] === $currentPrimaryRole) {
                    $isTakenForDept = true;

                    if ((int) $a['id'] === $meUserId) {
                      $takenByMeForDept = true;
                    }

                    if ($takenNameForDept === '') {
                      $takenNameForDept = $a['name'];
                    }
                  }
                }
                ?>

                <?php if ($canUseDeptButtons): ?>

                  <?php if (!$isTakenForDept): ?>

                    <button type="button" class="btn btn-sm btn-success btn-take-order mr-1" data-order-id="<?= $orderId ?>"
                      title="Take order">
                      TAKE
                    </button>
                    <!-- Assign To (len admin/mod) -->
                    <?php if ($perm >= 400): ?>
                      <button type="button" class="btn btn-sm btn-info btn-invite-collab" data-order-id="<?= $orderId ?>"
                        data-dept-code="<?= htmlspecialchars((string) $uiDeptCode) ?>" data-mode="assign">
                        Assign To
                      </button>
                    <?php endif; ?>

                  <?php else: ?>

                    <button type="button" class="btn btn-sm btn-secondary btn-take-order mr-1" data-order-id="<?= $orderId ?>"
                      disabled title="Already assigned">
                      TAKE
                    </button>

                    <?php if ($takenByMeForDept): ?>
                      <span class="badge badge-warning mr-1 px-3 py-2" style="font-size:0.85rem;">
                        MINE
                      </span>
                    <?php else: ?>
                      <span class="badge badge-warning mr-1">
                        Taken<?= $takenNameForDept ? ': ' . htmlspecialchars($takenNameForDept) : '' ?>
                      </span>
                    <?php endif; ?>

                    <?php
                    $canInvite = ($perm >= 400) || ($primaryId === $meUserId);
                    ?>
                    <button type="button" class="btn btn-sm btn-info btn-invite-collab" data-order-id="<?= $orderId ?>"
                      data-dept-code="<?= htmlspecialchars((string) $uiDeptCode) ?>"
                      data-mode="<?= ($perm >= 400 ? 'assign' : 'invite') ?>" <?= $canInvite ? '' : 'disabled' ?>>
                      <?= ($perm >= 400 ? 'Assign To' : 'INVITE') ?>
                    </button>

                  <?php endif; ?>

                <?php endif; ?>
              </td>
            </tr>

            <!-- Detail row (hidden, will be filled via AJAX) -->
            <tr class="order-detail-row">
              <td colspan="10">
                <div id="detail-<?= $orderId ?>" class="detail-wrap"></div>
              </td>
            </tr>

          <?php endwhile; ?>
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

        <label class="text-muted">Search active employee</label>
        <input type="text" id="empSearch" class="form-control form-control-sm bg-dark text-light"
          placeholder="Type name, e.g. Andrej">

        <div id="empResults" class="list-group mt-2"></div>

        <small class="text-muted d-block mt-2">
          Search is filtered by selected department.
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
  $(function () {

    function escapeHtml(s) {
      return ('' + s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    }

    $('.btn-toggle-detail').on('click', function () {
      const orderId = $(this).data('order-id');
      const $wrap = $('#detail-' + orderId);

      // toggle if already loaded
      if ($wrap.data('loaded')) {
        $wrap.slideToggle(120);
        return;
      }

      $wrap.html('<div class="p-3 text-muted"><span class="spinner-border spinner-border-sm"></span> Načítavam detail…</div>');
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
          $wrap.html('<div class="p-3"><div class="alert alert-danger mb-0">Chyba pri načítaní detailu</div></div>');
        }
      });
    });
  });
  // TAKE order
  $(document).on('click', '.btn-take-order', function () {
    const orderId = $(this).data('order-id');
    const $btn = $(this);
    $btn.prop('disabled', true).text('...');

    $.ajax({
      url: 'scripts/orders/take_order.php',
      method: 'POST',
      dataType: 'json',
      data: { order_id: orderId },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert('TAKE error: ' + (resp && resp.error ? resp.error : 'unknown'));
          $btn.prop('disabled', false).text('TAKE');
          return;
        }
        // najjednoduchšie: refresh page (aby sa načítali badges)
        location.reload();
      },
      error: function () {
        alert('TAKE error (request failed)');
        $btn.prop('disabled', false).text('TAKE');
      }
    });
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
    if ($(e.target).closest('button, a, .btn').length) {
      return;
    }

    const orderId = $(this).data('order-id');
    const $btn = $(this).find('.btn-toggle-detail');
    const $row = $(this).closest('tr.order-row');

    if ($row.hasClass('order-row-open')) {
      $row.removeClass('order-row-open');
    } else {
      $('.order-row').removeClass('order-row-open');
      $row.addClass('order-row-open');
    }

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

  // VIEW
  $(document).on('click', '.btn-view-options', function (e) {
    e.stopPropagation(); // aby neklikol row

    const data = getOptionsData($(this));

    $('#optionsModalBody').html(renderOptionsPretty(data));
    $('#optionsModal').modal('show');
  });

  // COPY
  $(document).on('click', '.btn-copy-options', function (e) {
    e.stopPropagation();

    const data = getOptionsData($(this));
    let text = '';

    for (let k in data) {
      if (k.startsWith('_')) continue;
      if (typeof data[k] === 'object') continue;
      text += `${k}: ${data[k]}\n`;
    }

    navigator.clipboard.writeText(text);

    const $btn = $(this);
    $btn.text('COPIED');
    setTimeout(() => $btn.text('COPY'), 1000);
  });

  // fallback pre zatváranie modalu
  $(document).on('click', '.btn-copy-inline', async function (e) {
    e.preventDefault();
    e.stopPropagation();

    const $btn = $(this);
    const text = $btn.attr('data-copy') || '';

    let copied = false;

    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        copied = true;
      } else {
        copied = copyTextFallback(text);
      }
    } catch (err) {
      copied = copyTextFallback(text);
    }

    if (copied) {
      $btn.text('✔');

      setTimeout(() => {
        $btn.text('📋');
      }, 800);
    } else {
      $btn.text('!');
      console.error('Copy failed:', text);

      setTimeout(() => {
        $btn.text('📋');
      }, 800);
    }
  });
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
    const $detail = $(this).closest('.detail-wrap');
    $detail.find('.order-header-edit').slideDown(150);
  });

  $(document).on('click', '.btn-cancel-order-header', function () {
    $(this).closest('.order-header-edit').slideUp(150);
  });

  $(document).on('click', '.btn-save-order-header', function () {
    const $box = $(this).closest('.order-header-edit');
    const orderId = $box.find('.edit-order-id').val();
    const $btn = $(this);

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
        'billing[street]': $box.find('.edit-billing-street').val(),
        'billing[city]': $box.find('.edit-billing-city').val(),
        'billing[zip]': $box.find('.edit-billing-zip').val(),
        'billing[country]': $box.find('.edit-billing-country').val(),
        'billing[email]': $box.find('.edit-billing-email').val(),
        'billing[phone]': $box.find('.edit-billing-phone').val(),

        'shipping[name]': $box.find('.edit-shipping-name').val(),
        'shipping[company]': $box.find('.edit-shipping-company').val(),
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

        const $wrap = $('#detail-' + orderId);
        $wrap.removeData('loaded');
        $wrap.html('');
        $('.btn-toggle-detail[data-order-id="' + orderId + '"]').trigger('click');
      },
      error: function () {
        alert('Save request failed');
        $btn.prop('disabled', false).text('Save changes');
      }
    });
  });

  $(document).on('click', '.btn-add-tracking', function () {
    const orderId = $(this).data('order-id');
    const $box = $(this).closest('.form-row');

    const trackingNumber = $box.find('.tracking-number').val().trim();
    const carrier = $box.find('.tracking-carrier').val().trim();

    $.post('scripts/orders/add_tracking.php', {
      order_id: orderId,
      tracking_number: trackingNumber,
      carrier: carrier
    }, function (res) {
      if (!res.ok) {
        alert(res.error || 'Error');
        return;
      }

      $box.find('.tracking-number').val('');
      $box.find('.tracking-carrier').val('');

      const $wrap = $('#detail-' + orderId);
      $wrap.removeData('loaded').html('');
      $('.btn-toggle-detail[data-order-id="' + orderId + '"]').click();

    }, 'json');
  });

  $(document).on('keypress', '.tracking-number', function (e) {
    if (e.which === 13) {
      $(this).closest('.form-row').find('.btn-add-tracking').click();
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

      // reload detail
      const $wrap = $('#detail-' + orderId);
      $wrap.removeData('loaded').html('');
      $('.btn-toggle-detail[data-order-id="' + orderId + '"]').click();

    }, 'json');
  });
  function reloadOrderDetail(orderId) {
    const $wrap = $('#detail-' + orderId);
    $wrap.removeData('loaded').html('');
    $('.btn-toggle-detail[data-order-id="' + orderId + '"]').click();
  }
  $(document).on('click', '.btn-delete-tracking', function () {
    const id = $(this).data('id');
    const orderId = $(this).data('order-id');

    if (!confirm('Delete tracking?')) return;

    $.post('scripts/orders/delete_tracking.php', { id: id }, function (res) {
      if (!res || !res.ok) {
        alert(res && res.error ? res.error : 'Delete failed');
        return;
      }

      reloadOrderDetail(orderId);
    }, 'json');
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

  $(document).on('click', '.btn-save-production-note', function () {
    const orderId = $(this).data('order-id');
    const $box = $(this).closest('.production-note-box');
    const note = $box.find('.production-note-input').val();
    const $btn = $(this);

    $btn.prop('disabled', true).text('Saving...');

    $.post('scripts/orders/update_production_note.php', {
      order_id: orderId,
      production_note: note
    }, function (res) {
      if (!res || !res.ok) {
        alert(res && res.error ? res.error : 'Save failed');
        $btn.prop('disabled', false).text('Save note');
        return;
      }

      const $wrap = $('#detail-' + orderId);
      $wrap.removeData('loaded').html('');
      $('.btn-toggle-detail[data-order-id="' + orderId + '"]').click();

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
  $(document).on('change', '.order-status-select', function () {
    const $select = $(this);
    const orderId = $select.data('order-id');
    const status = $select.val();

    $select.prop('disabled', true);

    $.post('scripts/orders/update_order_status.php', {
      order_id: orderId,
      status: status
    }, function (res) {
      if (!res || !res.ok) {
        alert(res && res.error ? res.error : 'Status update failed');
        $select.prop('disabled', false);
        return;
      }

      location.reload();

    }, 'json').fail(function () {
      alert('Status update request failed');
      $select.prop('disabled', false);
    });
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

    $btn.prop('disabled', true).text('Adding...');

    $.post('scripts/orders/add_order_item.php', {
      order_id: orderId,
      title: title,
      item_type_code: type,
      qty: qty,
      sku: sku,
      reason: reason
    }, function (res) {
      if (!res || !res.ok) {
        alert(res && res.error ? res.error : 'Add item failed');
        $btn.prop('disabled', false).text('Add item');
        return;
      }

      const $wrap = $('#detail-' + orderId);
      $wrap.removeData('loaded').html('');
      $('.btn-toggle-detail[data-order-id="' + orderId + '"]').click();

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

      const $wrap = $('#detail-' + orderId);
      $wrap.removeData('loaded').html('');
      $('.btn-toggle-detail[data-order-id="' + orderId + '"]').click();

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

      const $wrap = $('#detail-' + orderId);
      $wrap.removeData('loaded').html('');
      $('.btn-toggle-detail[data-order-id="' + orderId + '"]').click();

    }, 'json').fail(() => {
      alert('Update request failed');
    });
  });
  $(document).on('change', '.item-status-select', function () {
    const itemId = $(this).data('item-id');
    const status = $(this).val();

    const note = $('.item-waiting-note[data-item-id="' + itemId + '"]').val() || '';
    const expectedDate = $('.item-expected-date[data-item-id="' + itemId + '"]').val() || '';

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
        if (!resp || !resp.success) {
          alert(resp && resp.message ? resp.message : 'Status update failed');
          return;
        }

        location.reload();
      },
      error: function (xhr) {
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
  $(document).on('click', '.btn-assign-item', function (e) {
    e.preventDefault();
    e.stopPropagation();

    const itemId = $(this).data('item-id');

    $.ajax({
      url: 'scripts/orders/assign_order_item.php',
      method: 'POST',
      dataType: 'json',
      data: {
        item_id: itemId
      },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert(resp && resp.error ? resp.error : 'Assign item failed');
          return;
        }

        const detailWrap = $('.detail-wrap:visible').first();
        const orderId = detailWrap.closest('tr').prev('.order-row').data('order-id');

        if (orderId) {
          $.post('scripts/orders/get_order_detail.php', {
            order_id: orderId
          }, function (res) {
            if (res && res.ok) {
              $('#detail-' + orderId).html(res.html).show();
            }
          }, 'json');
        }
      },
      error: function (xhr) {
        console.log(xhr.responseText);
        alert('Assign item request failed');
      }
    });
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
        location.reload();
      },
      error: function (xhr) {
        console.log(xhr.responseText);
        alert('Assign / Invite request failed');
      }
    });
  });
  $(document).on('click', '.btn-remove-assignment', function (e) {
    e.preventDefault();
    e.stopPropagation();

    const assignmentId = $(this).data('assignment-id');

    if (!confirm('Remove this assignment?')) {
      return;
    }

    $.ajax({
      url: 'scripts/orders/remove_order_assignment.php',
      method: 'POST',
      dataType: 'json',
      data: {
        assignment_id: assignmentId
      },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert(resp && resp.error ? resp.error : 'Remove assignment failed');
          return;
        }

        location.reload();
      },
      error: function (xhr) {
        console.log(xhr.responseText);
        alert('Remove assignment request failed');
      }
    });
  });
</script>
<div class="modal fade" id="optionsModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header">
        <h5 class="modal-title">Product Options</h5>
        <button type="button" class="close text-light" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="optionsModalBody"></div>
    </div>
  </div>
</div>