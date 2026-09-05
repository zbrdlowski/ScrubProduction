<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

date_default_timezone_set('Europe/Bratislava');

$dashboardKeyHash = strtolower((string) (getenv('DARKSCRUB_PRODUCTION_DASHBOARD_KEY_HASH') ?: 'c0fa7e8f09161184332cf504564dffd63079fb70b785a89dd148f041d6716617'));
$dashboardRequestKey = (string) ($_GET['key'] ?? '');
$dashboardHasSession = !empty($_SESSION['permission']);
$dashboardHasKey = $dashboardKeyHash !== ''
  && $dashboardRequestKey !== ''
  && hash_equals($dashboardKeyHash, hash('sha256', $dashboardRequestKey));
$dashboardHasShortcut = defined('DARKSCRUB_PRODUCTION_DASHBOARD_SHORTCUT') && DARKSCRUB_PRODUCTION_DASHBOARD_SHORTCUT === true;

if (!$dashboardHasSession && !$dashboardHasKey && !$dashboardHasShortcut) {
  http_response_code(403);
  ?>
  <!DOCTYPE html>
  <html lang="en">

  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Dashboard Access Required</title>
    <style>
      html,
      body {
        min-height: 100%;
        margin: 0;
        background: #242a31;
        color: #f4f7fb;
        font-family: Arial, sans-serif
      }

      body {
        display: grid;
        place-items: center
      }

      .message {
        max-width: 520px;
        padding: 28px;
        border: 1px solid #65717c;
        border-radius: 8px;
        background: #303840;
        text-align: center
      }
    </style>
  </head>

  <body>
    <div class="message">
      <h1>Dashboard access required</h1>
      <p>Open this dashboard from a signed-in browser or use the monitor access URL.</p>
    </div>
  </body>

  </html>
  <?php
  exit;
}

require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/scripts/orders/department_config.php';

/** @var mysqli $conn */
if (!isset($conn) || !$conn instanceof mysqli) {
  http_response_code(500);
  echo 'Database connection error.';
  exit;
}

$conn->set_charset('utf8mb4');
const DASHBOARD_EXCLUDE_QUERY = 'CANCELLED,PENDING,SHIPPED';

$dashboardDepartments = [
  'G' => ['key' => 'G', 'label' => 'Graphic kits', 'legendLabel' => 'Graphics kit', 'type' => 'G', 'filter' => 'G', 'color' => '#17e01f', 'dark' => '#0b8f3a', 'colorClass' => 'green'],
  'P' => ['key' => 'P', 'label' => 'Plastic kits', 'legendLabel' => 'Plastics kit', 'type' => 'P', 'filter' => 'P', 'color' => '#2dd4d7', 'dark' => '#148f9d', 'colorClass' => 'cyan'],
  'F' => ['key' => 'F', 'label' => 'GFP', 'legendLabel' => 'GFP', 'type' => 'F', 'filter' => 'F', 'color' => '#ff1616', 'dark' => '#a91010', 'colorClass' => 'red'],
  'S' => ['key' => 'S', 'label' => 'Seat covers', 'legendLabel' => 'Seat covers', 'type' => 'S', 'filter' => 'S', 'color' => '#f5f20a', 'dark' => '#a39c05', 'colorClass' => 'yellow'],
];

$dashboardDisplayStatuses = [
  'G' => [
    ['label' => 'RTP ✗', 'codes' => ['RTP_✗', 'RTP_X', 'RTP X', 'RTP']],
    ['label' => 'RIP', 'codes' => ['RIP', 'RIPPED']],
    ['label' => 'PRINTED', 'codes' => ['PRINTED']],
    ['label' => 'CUT', 'codes' => ['CUT']],
    ['label' => 'PRODUCED', 'codes' => ['PRODUCED']],
  ],
  'P' => [
    ['label' => 'Check Stock', 'codes' => ['CHECK_STOCK']],
    ['label' => 'PK ✗', 'codes' => ['PK_✗', 'PK_X', 'PK X']],
    ['label' => 'Scan Out', 'codes' => ['SCAN_OUT']],
  ],
  'F' => [
    ['label' => 'FIT ✗', 'codes' => ['FIT_✗', 'FIT_X', 'FIT X']],
    ['label' => 'STARTED', 'codes' => ['STARTED']],
    ['label' => 'CHECK 24', 'codes' => ['CHECK_24']],
    ['label' => 'PHOTO', 'codes' => ['PHOTO']],
    ['label' => 'REPRINT', 'codes' => ['REPRINT']],
  ],
  'S' => [
    ['label' => 'SEW ✗', 'codes' => ['SEW_✗', 'SEW_X', 'SEW X']],
    ['label' => 'STARTED', 'codes' => ['STARTED']],
    ['label' => 'PRODUCED', 'codes' => ['PRODUCED']],
  ],
];

function dashboard_h($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function dashboard_json($value): string
{
  $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  return $json === false ? 'null' : $json;
}
function dashboard_fedex_countdown_config(DateTimeImmutable $now): array
{
  $today = $now->setTime(0, 0, 0);
  $pickupMorning = $today->setTime(10, 0, 0);
  $pickupAfternoon = $today->setTime(14, 30, 0);

  if ($now < $pickupMorning) {
    $previous = $today->modify('-1 day')->setTime(14, 30, 0);
    $next = $pickupMorning;
  } elseif ($now < $pickupAfternoon) {
    $previous = $pickupMorning;
    $next = $pickupAfternoon;
  } else {
    $previous = $pickupAfternoon;
    $next = $today->modify('+1 day')->setTime(10, 0, 0);
  }

  return [
    'serverNowMs' => ((int) $now->format('U')) * 1000,
    'previousAtMs' => ((int) $previous->format('U')) * 1000,
    'nextAtMs' => ((int) $next->format('U')) * 1000,
    'targetLabel' => $next->format('H:i'),
    'targetDay' => $next->format('Y-m-d') === $now->format('Y-m-d') ? 'today' : 'tomorrow',
  ];
}
function dashboard_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
  $stmt = $conn->prepare($sql);
  if (!$stmt)
    return [];
  if ($types !== '')
    $stmt->bind_param($types, ...$params);
  if (!$stmt->execute()) {
    $stmt->close();
    return [];
  }
  $result = $stmt->get_result();
  if (!$result) {
    $stmt->close();
    return [];
  }
  $rows = [];
  while ($row = $result->fetch_assoc())
    $rows[] = $row;
  $stmt->close();
  return $rows;
}
function dashboard_table_exists(mysqli $conn, string $tableName): bool
{
  static $cache = [];
  if (array_key_exists($tableName, $cache))
    return $cache[$tableName];
  $safeName = $conn->real_escape_string($tableName);
  $result = $conn->query("SHOW TABLES LIKE '{$safeName}'");
  $exists = $result instanceof mysqli_result && $result->num_rows > 0;
  if ($result instanceof mysqli_result)
    $result->free();
  return $cache[$tableName] = $exists;
}
function dashboard_active_order_where(): string
{
  return "COALESCE(UPPER(o.status), '') NOT IN ('PENDING', 'CANCELLED', 'SHIPPED')";
}
function dashboard_normalize_department(?string $department): string
{
  $department = strtoupper(trim((string) $department));
  return ($department === 'T' || $department === 'M') ? 'P' : $department;
}
function dashboard_unique_departments(array $departments): array
{
  $out = [];
  foreach ($departments as $department) {
    $department = dashboard_normalize_department((string) $department);
    if (in_array($department, ['G', 'P', 'F', 'S'], true))
      $out[$department] = true;
  }
  return array_keys($out);
}
function dashboard_departments_from_manual(?string $manualTypes): array
{
  $manualTypes = strtoupper(trim((string) $manualTypes));
  if ($manualTypes === '')
    return [];
  $departments = [];
  foreach ((preg_split('/[^A-Z0-9_]+/', $manualTypes) ?: []) as $token) {
    $token = trim($token);
    if ($token === '')
      continue;
    if (defined('DEPT_PREFIX_MAP') && isset(DEPT_PREFIX_MAP[$token])) {
      $departments = array_merge($departments, DEPT_PREFIX_MAP[$token]);
      continue;
    }
    foreach (str_split($token) as $char)
      $departments[] = $char;
  }
  return dashboard_unique_departments($departments);
}
function dashboard_prefix_departments(?string $customLabel, ?string $sku): array
{
  return function_exists('dept_get_departments') ? dashboard_unique_departments(dept_get_departments($customLabel, $sku)) : [];
}
function dashboard_is_positive_option($value): bool
{
  if (is_array($value) || is_object($value) || $value === null)
    return false;
  if (is_bool($value))
    return $value;
  if (is_numeric($value))
    return (float) $value > 0;
  $normalized = strtolower(trim((string) $value));
  if ($normalized === '' || in_array($normalized, ['0', 'false', 'no', 'none', 'null', 'n/a', '-', 'x'], true))
    return false;
  return in_array($normalized[0], ['y', 'a', 'o', 'j', 's', '1'], true);
}
function dashboard_options_indicate_fitting(?string $optionsJson): bool
{
  $options = json_decode((string) $optionsJson, true);
  if (!is_array($options))
    return false;
  $keys = ['applyinggraphics', 'applying_graphics', 'applying-graphics', 'fitting'];
  foreach ($options as $key => $value) {
    if (in_array(strtolower((string) $key), $keys, true) && dashboard_is_positive_option($value))
      return true;
  }
  return false;
}
function dashboard_item_departments_for_status(array $item): array
{
  $type = dashboard_normalize_department((string) ($item['item_type_code'] ?? ''));
  if (in_array($type, ['G', 'P', 'F', 'S'], true))
    return [$type];
  return dashboard_prefix_departments((string) ($item['custom_label'] ?? ''), (string) ($item['sku'] ?? ''));
}
function dashboard_item_departments_for_order(array $item): array
{
  $departments = array_merge(
    dashboard_item_departments_for_status($item),
    dashboard_prefix_departments((string) ($item['custom_label'] ?? ''), (string) ($item['sku'] ?? ''))
  );
  if (dashboard_options_indicate_fitting((string) ($item['options_json'] ?? '')))
    $departments[] = 'F';
  return dashboard_unique_departments($departments);
}
function dashboard_status_fallback_label(string $status): string
{
  $status = trim($status);
  return $status === '' ? 'New' : ucwords(strtolower(str_replace('_', ' ', $status)));
}
function dashboard_load_item_status_meta(mysqli $conn): array
{
  $meta = ['G' => [], 'P' => [], 'F' => [], 'S' => []];
  if (!dashboard_table_exists($conn, 'status_definitions'))
    return $meta;
  $rows = dashboard_rows($conn, "SELECT department, code, label, sort_order FROM status_definitions WHERE scope = 'item' AND active = 1 ORDER BY department ASC, sort_order ASC, id ASC");
  foreach ($rows as $row) {
    $department = dashboard_normalize_department((string) ($row['department'] ?? ''));
    $code = strtoupper(trim((string) ($row['code'] ?? '')));
    if (!isset($meta[$department]) || $code === '')
      continue;
    $meta[$department][$code] = [
      'label' => trim((string) ($row['label'] ?? '')) !== '' ? trim((string) $row['label']) : dashboard_status_fallback_label($code),
      'sort_order' => (int) ($row['sort_order'] ?? 0),
    ];
  }
  return $meta;
}
function dashboard_status_label(array $statusMeta, string $department, string $status): string
{
  $department = dashboard_normalize_department($department);
  $status = strtoupper(trim($status));
  return (string) ($statusMeta[$department][$status]['label'] ?? dashboard_status_fallback_label($status));
}
function dashboard_status_sort(array $statusMeta, string $department, string $status): int
{
  $department = dashboard_normalize_department($department);
  $status = strtoupper(trim($status));
  return (int) ($statusMeta[$department][$status]['sort_order'] ?? 500);
}
function dashboard_visible_status_rows(string $department, array $rawCounts, array $displayStatuses): array
{
  $department = dashboard_normalize_department($department);
  $rows = [];
  foreach (($displayStatuses[$department] ?? []) as $definition) {
    $label = (string) ($definition['label'] ?? '');
    if ($label === '')
      continue;
    $count = 0;
    foreach (($definition['codes'] ?? []) as $code) {
      $normalized = strtoupper(trim((string) $code));
      $count += (int) ($rawCounts[$normalized] ?? 0);
    }
    $rows[$label] = $count;
  }
  return $rows;
}
function dashboard_department_status(string $department, array $items, array $statusMeta): string
{
  if (!$items)
    return 'NEW';
  $allReady = true;
  $hasWaiting = false;
  $hasStarted = false;
  $bestStatus = '';
  $bestSort = PHP_INT_MIN;
  foreach ($items as $item) {
    $status = strtoupper(trim((string) ($item['status'] ?? 'NEW'))) ?: 'NEW';
    if ($status === 'WAITING')
      $hasWaiting = true;
    if ($status !== 'READY')
      $allReady = false;
    if (!in_array($status, ['NEW', 'WAITING'], true))
      $hasStarted = true;
    if ($status !== 'READY') {
      $sortOrder = dashboard_status_sort($statusMeta, $department, $status);
      if ($sortOrder >= $bestSort) {
        $bestSort = $sortOrder;
        $bestStatus = $status;
      }
    }
  }
  if ($hasWaiting)
    return 'WAITING';
  if ($allReady)
    return 'READY';
  if ($bestStatus !== '')
    return $bestStatus;
  return $hasStarted ? 'PROCESSING' : 'NEW';
}
function dashboard_orders_link(array $params = []): string
{
  $query = array_merge(['page' => 'orders', 'exclude_status' => DASHBOARD_EXCLUDE_QUERY], $params);
  return 'index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
function dashboard_priority_breakdown(mysqli $conn, int $priority, array $departments): array
{
  $counts = [];
  foreach ($departments as $department => $_meta)
    $counts[$department] = 0;
  $rows = dashboard_rows($conn, "SELECT o.id AS order_id, o.manual_types_override, oi.item_type_code, oi.sku, oi.custom_label, oi.options_json FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id AND oi.deleted_at IS NULL WHERE " . dashboard_active_order_where() . " AND o.priority = ?", 'i', [$priority]);
  $orderDepartments = [];
  foreach ($rows as $row) {
    $orderId = (int) ($row['order_id'] ?? 0);
    if ($orderId <= 0)
      continue;
    if (!isset($orderDepartments[$orderId]))
      $orderDepartments[$orderId] = [];
    $manualDepartments = dashboard_departments_from_manual($row['manual_types_override'] ?? '');
    $rowDepartments = $manualDepartments ?: dashboard_item_departments_for_order($row);
    foreach ($rowDepartments as $department)
      if (isset($counts[$department]))
        $orderDepartments[$orderId][$department] = true;
  }
  foreach ($orderDepartments as $departmentSet)
    foreach (array_keys($departmentSet) as $department)
      $counts[$department]++;
  return ['total' => count($orderDepartments), 'departments' => $counts];
}
function dashboard_department_status_blocks(mysqli $conn, array $departments, array $statusMeta, array $displayStatuses): array
{
  $blocks = [];
  foreach ($departments as $department => $_meta)
    $blocks[$department] = ['total' => 0, 'statuses' => [], 'ready' => 0];
  $rows = dashboard_rows($conn, "SELECT o.id AS order_id, oi.item_type_code, oi.status, oi.sku, oi.custom_label, oi.options_json FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id AND oi.deleted_at IS NULL WHERE " . dashboard_active_order_where());
  $itemsByOrderDepartment = [];
  foreach ($rows as $row) {
    $orderId = (int) ($row['order_id'] ?? 0);
    if ($orderId <= 0)
      continue;
    foreach (dashboard_item_departments_for_status($row) as $department) {
      if (!isset($blocks[$department]))
        continue;
      $itemsByOrderDepartment[$orderId][$department][] = ['status' => strtoupper(trim((string) ($row['status'] ?? 'NEW'))) ?: 'NEW'];
    }
  }
  foreach ($itemsByOrderDepartment as $departmentsForOrder) {
    foreach ($departmentsForOrder as $department => $items) {
      $status = dashboard_department_status($department, $items, $statusMeta);
      $blocks[$department]['total']++;
      if ($status === 'READY') {
        $blocks[$department]['ready']++;
      } else {
        $blocks[$department]['statuses'][$status] = ($blocks[$department]['statuses'][$status] ?? 0) + 1;
      }
    }
  }
  foreach ($blocks as $department => &$block) {
    $block['statuses'] = dashboard_visible_status_rows($department, $block['statuses'], $displayStatuses);
  }
  unset($block);
  return $blocks;
}
function dashboard_fetch_shipped_orders(mysqli $conn, DateTimeImmutable $start, DateTimeImmutable $end): array
{
  $startSql = $start->format('Y-m-d H:i:s');
  $endSql = $end->format('Y-m-d H:i:s');
  if (dashboard_table_exists($conn, 'order_status_history')) {
    return dashboard_rows($conn, "SELECT h.order_id, MIN(h.changed_at) AS shipped_at FROM order_status_history h WHERE UPPER(h.new_status) = 'SHIPPED' AND h.changed_at >= ? AND h.changed_at < ? GROUP BY h.order_id", 'ss', [$startSql, $endSql]);
  }
  return dashboard_rows($conn, "SELECT o.id AS order_id, COALESCE(o.status_override_at, o.imported_at, o.order_date) AS shipped_at FROM orders o WHERE UPPER(o.status) = 'SHIPPED' AND COALESCE(o.status_override_at, o.imported_at, o.order_date) >= ? AND COALESCE(o.status_override_at, o.imported_at, o.order_date) < ?", 'ss', [$startSql, $endSql]);
}
function dashboard_fetch_order_departments(mysqli $conn, array $orderIds): array
{
  $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn(int $id): bool => $id > 0)));
  if (!$orderIds)
    return [];
  $departmentsByOrder = [];
  foreach ($orderIds as $orderId)
    $departmentsByOrder[$orderId] = [];
  foreach (array_chunk($orderIds, 300) as $chunk) {
    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
    $rows = dashboard_rows($conn, "SELECT o.id AS order_id, o.manual_types_override, oi.item_type_code, oi.sku, oi.custom_label, oi.options_json FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id AND oi.deleted_at IS NULL WHERE o.id IN ({$placeholders})", str_repeat('i', count($chunk)), $chunk);
    foreach ($rows as $row) {
      $orderId = (int) ($row['order_id'] ?? 0);
      if ($orderId <= 0)
        continue;
      $manualDepartments = dashboard_departments_from_manual($row['manual_types_override'] ?? '');
      $rowDepartments = $manualDepartments ?: dashboard_item_departments_for_order($row);
      foreach ($rowDepartments as $department)
        $departmentsByOrder[$orderId][$department] = true;
    }
  }
  foreach ($departmentsByOrder as $orderId => $departmentSet)
    $departmentsByOrder[$orderId] = array_keys($departmentSet);
  return $departmentsByOrder;
}

$statusMeta = dashboard_load_item_status_meta($conn);
$priorityBreakdown = dashboard_priority_breakdown($conn, 20, $dashboardDepartments);
$deadlineBreakdown = dashboard_priority_breakdown($conn, 10, $dashboardDepartments);
$departmentBlocks = dashboard_department_status_blocks($conn, $dashboardDepartments, $statusMeta, $dashboardDisplayStatuses);
$fedexCountdown = dashboard_fedex_countdown_config(new DateTimeImmutable('now'));
$today = new DateTimeImmutable('today');
$weekStart = $today->modify('monday this week');
$weekEnd = $weekStart->modify('+7 days');
$monthStart = $today->modify('first day of this month');
$monthEnd = $monthStart->modify('+1 month');
$weekLabels = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$weeklyShipped = [];
foreach ($dashboardDepartments as $department => $_meta)
  $weeklyShipped[$department] = array_fill(0, count($weekLabels), 0);
$weeklyRows = dashboard_fetch_shipped_orders($conn, $weekStart, $weekEnd);
$weeklyOrderDepartments = dashboard_fetch_order_departments($conn, array_map(static fn(array $row): int => (int) ($row['order_id'] ?? 0), $weeklyRows));
foreach ($weeklyRows as $row) {
  $orderId = (int) ($row['order_id'] ?? 0);
  $shippedAt = trim((string) ($row['shipped_at'] ?? ''));
  if ($orderId <= 0 || $shippedAt === '')
    continue;
  try {
    $dayIndex = ((int) (new DateTimeImmutable($shippedAt))->format('N')) - 1;
  } catch (Throwable $e) {
    continue;
  }
  if ($dayIndex < 0 || $dayIndex >= count($weekLabels))
    continue;
  foreach ($weeklyOrderDepartments[$orderId] ?? [] as $department)
    if (isset($weeklyShipped[$department][$dayIndex]))
      $weeklyShipped[$department][$dayIndex]++;
}
$monthlyShipped = [];
foreach ($dashboardDepartments as $department => $_meta)
  $monthlyShipped[$department] = 0;
$monthlyRows = dashboard_fetch_shipped_orders($conn, $monthStart, $monthEnd);
$monthlyOrderDepartments = dashboard_fetch_order_departments($conn, array_map(static fn(array $row): int => (int) ($row['order_id'] ?? 0), $monthlyRows));
foreach ($monthlyRows as $row) {
  $orderId = (int) ($row['order_id'] ?? 0);
  foreach ($monthlyOrderDepartments[$orderId] ?? [] as $department)
    if (isset($monthlyShipped[$department]))
      $monthlyShipped[$department]++;
}
$monthlyActive = [];
$monthlyScaleMax = 1;
foreach ($dashboardDepartments as $department => $_meta) {
  $monthlyActive[$department] = (int) ($departmentBlocks[$department]['total'] ?? 0);
  $monthlyScaleMax = max($monthlyScaleMax, $monthlyShipped[$department] + $monthlyActive[$department]);
}
$weeklyDatasets = [];
foreach ($dashboardDepartments as $department => $meta) {
  $weeklyDatasets[] = ['label' => ($meta['legendLabel'] ?? $meta['label']), 'backgroundColor' => $meta['color'], 'borderColor' => $meta['color'], 'data' => $weeklyShipped[$department]];
}
$updatedAt = date('d.m.Y H:i');
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Darkscrub Production Dashboard</title>
  <link rel="stylesheet" href="js/googleapis.css">
  <script src="plugins/chart.js/Chart.min.js"></script>
  <style>
    :root {
      --bg: #252b30;
      --panel: #30363c;
      --panel-soft: #353b41;
      --line: #67727b;
      --text: #f4f7f8;
      --muted: #cbd2d6;
      --green: #10e015;
      --green-deep: #10e015;
      --cyan: #2ed1d2;
      --cyan-deep: #149096;
      --deadline: #b48607;
      --deadline-deep: #7a5c08;
      --red: #ff1010;
      --red-deep: #9a160c;
      --yellow: #b4a90b;
      --yellow-deep: #b4a90b;
    }

    * {
      box-sizing: border-box;
    }

    html,
    body {
      margin: 0;
      width: 100%;
      height: 100%;
      overflow: hidden;
      background: var(--bg);
      color: var(--text);
      font-family: Tahoma, Geneva, Verdana, Arial, sans-serif;
      font-weight: 400;
      -webkit-font-smoothing: antialiased;
      text-rendering: geometricPrecision;
    }

    body {
      overflow: hidden;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    .dashboard-shell {
      width: 100%;
      height: 100vh;
      max-height: 100vh;
      padding: clamp(8px, .85vw, 15px);
      display: grid;
      grid-template-rows: auto auto auto minmax(0, .82fr) minmax(0, .92fr);
      gap: clamp(6px, .55vw, 11px);
      overflow: hidden;
    }

    .topbar {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
      align-items: center;
      gap: clamp(12px, 1.4vw, 26px);
      min-height: 38px;
    }

    .brand {
      justify-self: start;
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 0;
    }

    .brand img {
      height: clamp(28px, 2.6vw, 46px);
      width: auto;
      object-fit: contain;
    }

    .brand-title {
      font-size: clamp(22px, 2.3vw, 40px);
      font-weight: 700;
      line-height: 1;
      white-space: nowrap;
    }

    .courier-countdown {
      --courier-color: var(--green);
      justify-self: center;
      display: inline-grid;
      grid-template-columns: auto auto auto;
      align-items: center;
      gap: clamp(8px, .8vw, 16px);
      min-width: clamp(260px, 25vw, 430px);
      padding: clamp(4px, .35vw, 7px) clamp(10px, .8vw, 16px);
      border: 1px solid rgba(255, 255, 255, .18);
      border-radius: 8px;
      background: rgba(0, 0, 0, .16);
      color: var(--courier-color);
    }

    .courier-logo {
      display: block;
      width: auto;
      height: clamp(14px, 1vw, 20px);
      max-width: clamp(45px, 4vw, 72px);
      object-fit: contain;
    }

    .courier-time {
      color: var(--courier-color);
      font-size: clamp(20px, 2.15vw, 38px);
      font-weight: 700;
      font-variant-numeric: tabular-nums;
      line-height: 1;
      text-shadow: 0 0 10px rgba(255, 255, 255, .15);
      white-space: nowrap;
    }

    .courier-target {
      color: var(--text);
      font-size: clamp(11px, .82vw, 16px);
      opacity: .86;
      white-space: nowrap;
    }

    .clock {
      justify-self: end;
      text-align: right;
      color: var(--muted);
      font-size: clamp(14px, 1vw, 20px);
      line-height: 1.15;
    }

    .clock strong {
      color: var(--text);
      font-size: clamp(19px, 1.7vw, 30px);
      display: block;
    }

    .top-panels {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: clamp(10px, .85vw, 16px);
      min-height: 0;
    }

    .panel,
    .department-card,
    .chart-card,
    .performance-card {
      background: var(--panel);
      border: 2px solid var(--line);
      border-radius: 8px;
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .03);
    }

    .panel {
      padding: clamp(8px, .65vw, 12px);
      display: grid;
      gap: clamp(6px, .5vw, 10px);
      min-height: 0;
    }

    .priority-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      min-height: clamp(44px, 4vw, 68px);
      border-radius: 8px;
      padding: 6px clamp(12px, .85vw, 18px);
      color: #fff;
      font-size: clamp(22px, 2.4vw, 44px);
      font-weight: 700;
      line-height: 1;
    }

    .priority-head.red,
    .badge.red,
    .department-count.red {
      background: var(--red);
    }

    .priority-head.cyan,
    .badge.cyan {
      background: var(--deadline);
    }

    .priority-head .badge {
      background: #252b30;
      color: #f5f7f8;
    }

    .badge {
      min-width: clamp(32px, 2.6vw, 48px);
      height: clamp(30px, 2.5vw, 44px);
      padding: 0 10px;
      border-radius: 8px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: clamp(19px, 1.9vw, 32px);
      font-weight: 600;
      line-height: 1;
    }

    .priority-list {
      display: grid;
      gap: clamp(4px, .35vw, 8px);
    }

    .priority-row {
      display: grid;
      grid-template-columns: 1fr auto;
      align-items: center;
      gap: 12px;
      min-height: clamp(30px, 2.35vw, 44px);
      padding: 1px clamp(6px, .55vw, 10px);
      border-radius: 6px;
      font-size: clamp(18px, 1.9vw, 34px);
      line-height: 1;
    }

    .priority-row:hover,
    .priority-head:hover,
    .department-card:hover {
      filter: brightness(1.08);
    }

    .priority-row:focus-visible,
    .priority-head:focus-visible,
    .department-card:focus-visible {
      outline: 3px solid #fff;
      outline-offset: 3px;
    }

    .section-title {
      font-size: clamp(17px, 1.32vw, 26px);
      font-weight: 600;
      margin: -1px 0 -1px;
      line-height: 1;
    }

    .departments-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: clamp(8px, .65vw, 13px);
      min-height: 0;
    }

    .department-card {
      min-height: 0;
      height: 100%;
      padding: clamp(7px, .55vw, 11px);
      display: grid;
      grid-template-rows: auto minmax(0, 1fr) auto;
      gap: clamp(3px, .28vw, 6px);
      overflow: hidden;
    }

    .department-head {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      align-items: center;
      gap: 10px;
      border-bottom: 2px solid var(--line);
      padding-bottom: clamp(4px, .3vw, 7px);
    }

    .department-title {
      min-width: 0;
      font-size: clamp(18px, 1.5vw, 30px);
      font-weight: 700;
      line-height: 1;
      overflow-wrap: anywhere;
    }

    .department-count {
      min-width: clamp(32px, 2.4vw, 46px);
      height: clamp(28px, 2.05vw, 38px);
      border-radius: 8px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0 8px;
      font-size: clamp(17px, 1.45vw, 26px);
      font-weight: 600;
    }

    .status-list {
      align-self: stretch;
      min-height: 0;
      display: grid;
      align-content: start;
      gap: clamp(2px, .18vw, 4px);
      overflow: hidden;
      font-size: clamp(14px, 1.05vw, 21px);
      line-height: .98;
    }

    .status-row,
    .ready-row {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      align-items: baseline;
      gap: 10px;
      min-height: 0;
    }

    .status-name {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .status-count {
      font-variant-numeric: tabular-nums;
    }

    .ready-row {
      color: var(--green);
      border-top: 2px solid var(--line);
      padding-top: clamp(4px, .32vw, 7px);
      font-size: clamp(14px, 1.05vw, 21px);
      line-height: 1;
    }

    .bottom-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.08fr) minmax(360px, .92fr);
      gap: clamp(8px, .65vw, 13px);
      align-items: stretch;
      min-height: 0;
    }

    .chart-card,
    .performance-card {
      background: transparent;
      border: 0;
      border-radius: 0;
      box-shadow: none;
      display: grid;
      grid-template-rows: auto minmax(0, 1fr);
      align-content: stretch;
      gap: clamp(5px, .45vw, 8px);
      min-width: 0;
      min-height: 0;
      height: 100%;
      overflow: hidden;
      padding: 0;
    }

    .chart-heading-row {
      display: flex;
      align-items: center;
      gap: clamp(12px, 1.4vw, 26px);
      min-width: 0;
    }

    .chart-title,
    .performance-title {
      font-size: clamp(17px, 1.35vw, 26px);
      line-height: 1;
      font-weight: 600;
      white-space: nowrap;
    }

    .chart-legend {
      display: flex;
      align-items: center;
      gap: clamp(8px, 1vw, 18px);
      min-width: 0;
      flex-wrap: nowrap;
      color: var(--text);
      font-size: clamp(10px, .82vw, 16px);
    }

    .legend-item {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
    }

    .legend-swatch {
      width: clamp(18px, 1.5vw, 30px);
      height: clamp(7px, .6vw, 12px);
      border-radius: 2px;
      flex: 0 0 auto;
    }

    .chart-wrap {
      position: relative;
      height: 100%;
      min-height: 0;
      width: 100%;
      min-width: 0;
    }

    .performance-list {
      display: flex;
      flex-direction: column;
      justify-content: space-evenly;
      gap: clamp(4px, .35vw, 7px);
      min-height: 0;
      overflow: hidden;
      padding-top: 0;
    }

    .performance-row {
      display: flex;
      align-items: center;
      gap: clamp(7px, .7vw, 13px);
      min-width: 0;
      min-height: 0;
      flex-wrap: nowrap;
    }

    .performance-name {
      flex: 0 0 clamp(105px, 9vw, 165px);
      font-size: clamp(13px, .95vw, 18px);
      line-height: 1;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .performance-value {
      flex: 0 0 clamp(62px, 5vw, 92px);
      font-size: clamp(13px, .95vw, 18px);
      line-height: 1;
      font-variant-numeric: tabular-nums;
      color: var(--text);
      white-space: nowrap;
    }

    .bar {
      flex: 1 1 auto;
      height: clamp(15px, 1.15vw, 22px);
      display: flex;
      background: rgba(255, 255, 255, .05);
      min-width: 100px;
      overflow: hidden;
    }

    .bar span {
      display: block;
      min-width: 0;
    }

    .bar .active.green {
      background: var(--green-deep);
    }

    .bar .active.cyan {
      background: var(--cyan-deep);
    }

    .bar .active.red {
      background: var(--red-deep);
    }

    .bar .active.yellow {
      background: var(--yellow-deep);
    }

    .bar .shipped.green {
      background: var(--green);
    }

    .bar .shipped.cyan {
      background: var(--cyan);
    }

    .bar .shipped.red {
      background: var(--red);
    }

    .bar .shipped.yellow {
      background: var(--yellow);
    }

    .bar .shipped {
      flex: 0 0 var(--shipped);
    }

    .bar .active {
      flex: 0 0 var(--active);
    }

    .bar span:last-child {
      flex: 1 1 auto;
    }

    .empty {
      color: var(--muted);
      font-size: clamp(16px, 1.25vw, 24px);
      padding: 8px 6px;
    }

    @media (max-width: 900px) {

      html,
      body {
        height: auto;
        overflow: auto;
      }

      .dashboard-shell {
        height: auto;
        min-height: 100vh;
        grid-template-rows: none;
        overflow: visible;
      }

      .departments-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .bottom-grid {
        grid-template-columns: 1fr;
      }

      .department-card {
        min-height: 220px;
      }

      .chart-wrap {
        height: 280px;
      }
    }

    @media (max-width: 720px) {
      .topbar {
        grid-template-columns: 1fr;
        align-items: flex-start;
      }

      .courier-countdown {
        justify-self: start;
        min-width: 0;
        width: 100%;
        max-width: 430px;
      }

      .clock {
        justify-self: start;
        text-align: left;
      }

      .top-panels,
      .departments-grid {
        grid-template-columns: 1fr;
      }

      .performance-row {
        display: grid;
        grid-template-columns: 1fr auto;
      }

      .bar {
        grid-column: 1 / -1;
        width: 100%;
      }
    }
  </style>
</head>

<body>
  <main class="dashboard-shell">
    <header class="topbar" aria-label="Production dashboard header">
      <div class="brand">
        <img src="dist/img/ScrubLogo.png" alt="Darkscrub">
        <div class="brand-title">Production Dashboard</div>
      </div>
      <div class="courier-countdown" id="fedexCountdown" aria-label="FedEx courier countdown">
        <img class="courier-logo" src="images/logo/fedex.png" alt="FedEx">
        <strong class="courier-time" id="fedexCountdownTime">--:--:--</strong>
        <span class="courier-target" id="fedexCountdownTarget">do --:--</span>
      </div>
      <div class="clock">
        <strong id="dashClock"><?php echo dashboard_h(date('H:i')); ?></strong>
        Updated <?php echo dashboard_h($updatedAt); ?>
      </div>
    </header>

    <section class="top-panels" aria-label="Priority and deadline orders">
      <div class="panel">
        <a class="priority-head red" href="<?php echo dashboard_h(dashboard_orders_link(['priority' => '20'])); ?>"
          title="Open priority orders">
          <span>Today&apos;s Priority</span>
          <span class="badge"><?php echo (int) $priorityBreakdown['total']; ?></span>
        </a>
        <div class="priority-list">
          <?php foreach ($dashboardDepartments as $department): ?>
            <a class="priority-row red"
              href="<?php echo dashboard_h(dashboard_orders_link(['priority' => '20', 'type' => $department['filter']])); ?>"
              title="<?php echo dashboard_h($department['label']); ?> priority orders">
              <span><?php echo dashboard_h($department['label']); ?></span>
              <span
                class="badge red"><?php echo (int) ($priorityBreakdown['departments'][$department['key']] ?? 0); ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="panel">
        <a class="priority-head cyan" href="<?php echo dashboard_h(dashboard_orders_link(['priority' => '10'])); ?>"
          title="Open deadline orders">
          <span>Today&apos;s Deadline</span>
          <span class="badge"><?php echo (int) $deadlineBreakdown['total']; ?></span>
        </a>
        <div class="priority-list">
          <?php foreach ($dashboardDepartments as $department): ?>
            <a class="priority-row cyan"
              href="<?php echo dashboard_h(dashboard_orders_link(['priority' => '10', 'type' => $department['filter']])); ?>"
              title="<?php echo dashboard_h($department['label']); ?> deadline orders">
              <span><?php echo dashboard_h($department['label']); ?></span>
              <span
                class="badge cyan"><?php echo (int) ($deadlineBreakdown['departments'][$department['key']] ?? 0); ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <div class="section-title">Active orders by departments</div>
    <section class="departments-grid" aria-label="Active department orders">
      <?php foreach ($dashboardDepartments as $department):
        $block = $departmentBlocks[$department['key']];
        ?>
        <a class="department-card"
          href="<?php echo dashboard_h(dashboard_orders_link(['type' => $department['filter']])); ?>"
          title="Open <?php echo dashboard_h($department['label']); ?> orders">
          <div class="department-head">
            <div class="department-title"><?php echo dashboard_h($department['label']); ?></div>
            <div class="department-count red"><?php echo (int) $block['total']; ?></div>
          </div>
          <div class="status-list">
            <?php if (!empty($block['statuses'])): ?>
              <?php foreach ($block['statuses'] as $statusLabel => $count): ?>
                <div class="status-row">
                  <span class="status-name"><?php echo dashboard_h($statusLabel); ?></span>
                  <span class="status-count"><?php echo (int) $count; ?></span>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty">No active orders</div>
            <?php endif; ?>
          </div>
          <div class="ready-row">
            <span>READY</span>
            <span><?php echo (int) $block['ready']; ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </section>

    <section class="bottom-grid" aria-label="Production charts">
      <div class="chart-card">
        <div class="chart-heading-row">
          <div class="chart-title">Weekly Shipped orders</div>
          <div class="chart-legend" aria-label="Weekly shipped departments">
            <?php foreach ($dashboardDepartments as $department): ?>
              <span class="legend-item"><span class="legend-swatch"
                  style="background: <?php echo dashboard_h($department['color']); ?>;"></span><span><?php echo dashboard_h($department['legendLabel']); ?></span></span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="chart-wrap"><canvas id="weeklyShippedChart"></canvas></div>
      </div>
      <div class="performance-card">
        <div class="performance-title">Monthly Performance</div>
        <div class="performance-list">
          <?php foreach ($dashboardDepartments as $department):
            $key = $department['key'];
            $shipped = (int) ($monthlyShipped[$key] ?? 0);
            $active = (int) ($monthlyActive[$key] ?? 0);
            $shippedUnits = $monthlyScaleMax > 0 ? ($shipped / $monthlyScaleMax) * 100 : 0;
            $activeUnits = $monthlyScaleMax > 0 ? ($active / $monthlyScaleMax) * 100 : 0;
            ?>
            <a class="performance-row"
              href="<?php echo dashboard_h(dashboard_orders_link(['type' => $department['filter']])); ?>"
              title="Open <?php echo dashboard_h($department['label']); ?> orders">
              <span class="performance-name"><?php echo dashboard_h($department['label']); ?></span>
              <span class="performance-value"><?php echo $shipped; ?> / <?php echo $active; ?></span>
              <span class="bar"
                style="--shipped: <?php echo dashboard_h(number_format($shippedUnits, 4, '.', '')); ?>%; --active: <?php echo dashboard_h(number_format($activeUnits, 4, '.', '')); ?>%;">
                <span class="shipped <?php echo dashboard_h($department['colorClass']); ?>"></span>
                <span class="active <?php echo dashboard_h($department['colorClass']); ?>"></span>
                <span></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  </main>
  <script>
    (function () {
      var weeklyLabels = <?php echo dashboard_json($weekLabels); ?>;
      var weeklyDatasets = <?php echo dashboard_json($weeklyDatasets); ?>;
      var fedexCountdown = <?php echo dashboard_json($fedexCountdown); ?>;
      var fedexClientLoadedAt = Date.now();
      var ctx = document.getElementById('weeklyShippedChart');
      if (ctx && window.Chart) {
        new Chart(ctx, {
          type: 'bar',
          data: { labels: weeklyLabels, datasets: weeklyDatasets },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: { display: false },
            tooltips: { enabled: true },
            scales: {
              xAxes: [{ gridLines: { color: 'rgba(203,210,214,.28)' }, ticks: { fontColor: '#cbd2d6' } }],
              yAxes: [{ gridLines: { color: 'rgba(203,210,214,.28)' }, ticks: { beginAtZero: true, precision: 0, fontColor: '#cbd2d6' } }]
            }
          }
        });
      }
      function pad2(value) {
        value = Math.floor(Math.abs(value));
        return value < 10 ? '0' + value : String(value);
      }

      function updateClock() {
        var now = new Date();
        var clock = document.getElementById('dashClock');
        if (!clock) return;
        clock.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      }

      function updateFedexCountdown() {
        var box = document.getElementById('fedexCountdown');
        var timeEl = document.getElementById('fedexCountdownTime');
        var targetEl = document.getElementById('fedexCountdownTarget');
        if (!box || !timeEl || !targetEl || !fedexCountdown) return;

        var nowMs = Number(fedexCountdown.serverNowMs || 0) + (Date.now() - fedexClientLoadedAt);
        var nextAtMs = Number(fedexCountdown.nextAtMs || 0);
        var previousAtMs = Number(fedexCountdown.previousAtMs || 0);
        var remainingMs = nextAtMs - nowMs;

        if (remainingMs <= 0) {
          timeEl.textContent = '00:00:00';
          if (!window.__fedexReloading) {
            window.__fedexReloading = true;
            setTimeout(function () { window.location.reload(); }, 500);
          }
          return;
        }

        var warningWindowMs = 60 * 60 * 1000;
        var urgency = remainingMs >= warningWindowMs ? 0 : 1 - (remainingMs / warningWindowMs);
        urgency = Math.max(0, Math.min(1, urgency));
        var hue = Math.round(120 * (1 - urgency));
        var lightness = Math.round(48 + (urgency * 4));
        box.style.setProperty('--courier-color', 'hsl(' + hue + ', 88%, ' + lightness + '%)');

        var totalSeconds = Math.floor(remainingMs / 1000);
        var hours = Math.floor(totalSeconds / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var seconds = totalSeconds % 60;
        timeEl.textContent = pad2(hours) + ':' + pad2(minutes) + ':' + pad2(seconds);

        var targetText = 'until ' + (fedexCountdown.targetLabel || '--:--');
        if (fedexCountdown.targetDay === 'tomorrow') targetText += ' tomorrow';
        targetEl.textContent = targetText;
      }

      updateClock();
      updateFedexCountdown();
      setInterval(updateClock, 15000);
      setInterval(updateFedexCountdown, 1000);
      setTimeout(function () { window.location.reload(); }, 60000);
    }());
  </script>
</body>

</html>