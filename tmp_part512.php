<?php
declare(strict_types=1);
session_start();
//out(200, ['ok'=>false,'error'=>'PHP '.PHP_VERSION]);
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload): void
{
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['permission'])) {
  out(403, ['ok' => false, 'error' => 'Not logged in']);
}

// robust path (works regardless of relative include quirks)
$base = dirname(__DIR__, 2); // /.../darkscrub
$connFile = $base . '/includes/conn.php';
if (!is_file($connFile)) {
  out(500, ['ok' => false, 'error' => 'conn.php not found: ' . $connFile]);
}
require_once $connFile;

$orderId = (int) ($_POST['order_id'] ?? 0);
if ($orderId <= 0)
  out(400, ['ok' => false, 'error' => 'Invalid order_id']);

$dpt = (int) ($_SESSION['dpt'] ?? 0);
$allAccess = in_array($dpt, [1, 3, 4, 5, 7], true);
$perm = (int)($_SESSION['permission'] ?? 0);
$isAdminLike = $perm >= 300;
function h($s): string
{
  return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function countryFlag($code): string
{
  $code = strtoupper(trim((string) $code));
  if ($code === '')
    return '';

  if ($code === 'UK')
    $code = 'GB';
  if ($code === 'UM')
    $code = 'US';
  if ($code === 'KX')
    $code = 'XK';

  $imgCode = strtolower($code);

  return '<img src="https://flagcdn.com/16x12/' . h($imgCode) . '.png" '
    . 'alt="' . h($code) . '" '
    . 'style="margin-right:5px; vertical-align:-1px;">';
}

function normalizeUsZipFromAddress(array $a): string
{
  $text = trim(
    ($a['zip'] ?? '') . ' ' .
    ($a['street'] ?? '') . ' ' .
    ($a['city'] ?? '')
  );

  if ($text === '')
    return '';

  // ZIP+4: 11706-4815 => 11706
  if (preg_match('/\b(\d{5})-\d{4}\b/', $text, $m)) {
    return $m[1];
  }

  // last standalone 5 digits
  if (preg_match_all('/\b\d{5}\b/', $text, $m) && !empty($m[0])) {
    return end($m[0]);
  }

  // MXLocker/Shoptet missing leading zero: 2703 => 02703
  if (preg_match('/\b(\d{4})\b\s*$/', $text, $m)) {
    return '0' . $m[1];
  }

  return '';
}

function usStateFromZip(string $zip): string
{
  $zip = preg_replace('/\D+/', '', $zip);
  if (strlen($zip) < 5)
    return '';

  $n = (int) substr($zip, 0, 5);

  $ranges = [
    'AL' => [[35000, 36999]],
    'AK' => [[99500, 99999]],
    'AZ' => [[85000, 86999]],
    'AR' => [[71600, 72999]],
    'CA' => [[90000, 96699]],
    'CO' => [[80000, 81999]],
    'CT' => [[6000, 6999]],
    'DE' => [[19700, 19999]],
    'DC' => [[20000, 20099], [20200, 20599], [56900, 56999]],
    'FL' => [[32000, 34999]],
    'GA' => [[30000, 31999], [39800, 39999]],
    'HI' => [[96700, 96899]],
    'ID' => [[83200, 83999]],
    'IL' => [[60000, 62999]],
    'IN' => [[46000, 47999]],
    'IA' => [[50000, 52999]],
    'KS' => [[66000, 67999]],
    'KY' => [[40000, 42999]],
    'LA' => [[70000, 71599]],
    'ME' => [[3900, 4999]],
    'MD' => [[20600, 21999]],
    'MA' => [[1000, 2799], [5500, 5599]],
    'MI' => [[48000, 49999]],
    'MN' => [[55000, 56799]],
    'MS' => [[38600, 39799]],
    'MO' => [[63000, 65999]],
    'MT' => [[59000, 59999]],
    'NE' => [[68000, 69999]],
    'NV' => [[88900, 89999]],
    'NH' => [[3000, 3899]],
    'NJ' => [[7000, 8999]],
    'NM' => [[87000, 88499]],
    'NY' => [[10000, 14999], [500, 599], [6390, 6390]],
    'NC' => [[27000, 28999]],
    'ND' => [[58000, 58999]],
    'OH' => [[43000, 45999]],
    'OK' => [[73000, 74999]],
    'OR' => [[97000, 97999]],
    'PA' => [[15000, 19699]],
    'RI' => [[2800, 2999]],
    'SC' => [[29000, 29999]],
    'SD' => [[57000, 57999]],
    'TN' => [[37000, 38599]],
    'TX' => [[75000, 79999], [88500, 88599]],
    'UT' => [[84000, 84999]],
    'VT' => [[5000, 5999]],
    'VA' => [[20100, 24699]],
    'WA' => [[98000, 99499]],
    'WV' => [[24700, 26999]],
    'WI' => [[53000, 54999]],
    'WY' => [[82000, 83199]],
  ];

  foreach ($ranges as $state => $rs) {
    foreach ($rs as $r) {
      if ($n >= $r[0] && $n <= $r[1])
        return $state;
    }
  }

  return '';
}

function addressCopyText(array $a, string $state = ''): string
{
  return trim(
    ($a['name'] ?? '') . "\n" .
    ($a['company'] ?? '') . "\n" .
    ($a['street'] ?? '') . "\n" .
    trim(($a['city'] ?? '') . ' ' . ($a['zip'] ?? '')) .
    ($state !== '' ? "\nState: " . $state : '')
  );
}

// tu upraviť farbu badge podľa statusu, používa sa v detailoch objednávky a v zozname objednávok
function status_badge_class($status): string
{
  $s = strtoupper(trim((string) $status));
  switch ($s) {
    case 'NEW':
      return 'bg-info';
    case 'IN_PROGRESS':
      return 'bg-warning';
    case 'HOLD':
      return 'bg-secondary';
    case 'DONE':
      return 'bg-success';
    case 'COMPLETED':
      return 'bg-success';
    case 'SHIPPED':
      return 'bg-success';
    case 'NEED_INFO':
      return 'bg-danger';
    case 'CANCELLED':
      return 'bg-secondary';
    default:
      return 'bg-secondary';
  }
}

// --- order header ---
$stmt = $conn->prepare(" SELECT 
    o.*,
    os.code AS source_code,
    cu.name AS customer_name,
    cu.email AS customer_email,
    cu.phone AS customer_phone
  FROM orders o
  JOIN order_sources os ON os.id = o.source_id
  LEFT JOIN customers cu ON cu.id = o.customer_id
  WHERE o.id = ?
  LIMIT 1
");
if (!$stmt)
  out(500, ['ok' => false, 'error' => 'SQL prepare failed: ' . $conn->error]);
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order)
  out(404, ['ok' => false, 'error' => 'Order not found']);

// --- ACL (dept) ---
if (!$allAccess) {
  $deptFilter = [
    2 => ['GRAPHICS'],
    6 => ['PLASTICS'],
    8 => ['SEATCOVER'],
    9 => ['FITTING'],
  ];
  $cats = $deptFilter[$dpt] ?? ['__NONE__'];

  $ph = implode(',', array_fill(0, count($cats), '?'));
  $types = 'i' . str_repeat('s', count($cats));
  $params = array_merge([$orderId], $cats);

  $q = $conn->prepare("SELECT 1
    FROM order_categories oc
    JOIN categories c ON c.id=oc.category_id
    WHERE oc.order_id=? AND c.code IN ($ph)
    LIMIT 1
  ");
  if (!$q)
    out(500, ['ok' => false, 'error' => 'ACL prepare failed: ' . $conn->error]);
  $q->bind_param($types, ...$params);
  $q->execute();
  $ok = (bool) $q->get_result()->fetch_row();
  $q->close();

  if (!$ok)
    out(403, ['ok' => false, 'error' => 'Forbidden']);
}

// --- categories ---
$stmt = $conn->prepare("SELECT c.code
  FROM order_categories oc
  JOIN categories c ON c.id=oc.category_id
  WHERE oc.order_id=?
  ORDER BY c.code
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$cats = [];
$r = $stmt->get_result();
while ($x = $r->fetch_assoc())
  $cats[] = $x['code'];
$stmt->close();

// --- addresses ---
$stmt = $conn->prepare("SELECT type, name, company, street, city, zip, country, email, phone
FROM order_addresses
WHERE order_id=?
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$addr = ['BILLING' => null, 'SHIPPING' => null];
$r = $stmt->get_result();
while ($a = $r->fetch_assoc()) {
  $addr[$a['type']] = $a;
}
$stmt->close();

$orderCountry = '';
if (!empty($addr['SHIPPING']['country'])) {
  $orderCountry = strtoupper((string) $addr['SHIPPING']['country']);
} elseif (!empty($addr['BILLING']['country'])) {
  $orderCountry = strtoupper((string) $addr['BILLING']['country']);
}
$displayCustomerPhone = '';

if (!empty($addr['SHIPPING']['phone'])) {
  $displayCustomerPhone = (string) $addr['SHIPPING']['phone'];
} elseif (!empty($addr['BILLING']['phone'])) {
  $displayCustomerPhone = (string) $addr['BILLING']['phone'];
} else {
  $displayCustomerPhone = (string) ($order['customer_phone'] ?? '');
}

// --- items (no fetch_all to avoid mysqlnd dependency issues) ---
$stmt = $conn->prepare("SELECT 
    id,
    line_no,
    sku,
    title,
    custom_label,
    item_type_code,
    qty,
    options_json,
    product_url,
   status AS item_status,
    waiting_note,
    expected_date,
    completed_by,
    completed_at,
    (
  SELECT GROUP_CONCAT(
    CONCAT(
      e.id, '|',
      e.firstname, ' ', e.lastname, '|',
      COALESCE(e.photo, '')
    )
    ORDER BY e.firstname, e.lastname
    SEPARATOR ';;'
  )
  FROM order_item_assignments oia
  JOIN employees e ON e.id = oia.employee_id
  WHERE oia.item_id = order_items.id
    AND oia.removed_at IS NULL
) AS item_assigned_users
FROM order_items
WHERE order_id=?
  AND deleted_at IS NULL
  AND item_type_code IS NOT NULL
  AND item_type_code <> ''
ORDER BY COALESCE(line_no, 999999), id
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$r = $stmt->get_result();
$items = [];
while ($it = $r->fetch_assoc())
  $items[] = $it;
$stmt->close();

$status = (string) ($order['status'] ?? '');
$badgeClass = status_badge_class($status);
function formatActivityText(string $text, string $actorName): string
{
  $text = preg_replace('/\[[^\]]*created_by\s*:\s*\d+[^\]]*\]/i', 'created_by: ' . $actorName, $text);
  $text = preg_replace('/"created_by"\s*:\s*\d+/i', '"created_by": "' . $actorName . '"', $text);
  $text = preg_replace('/created_by\s*:\s*\d+/i', 'created_by: ' . $actorName, $text);
  $text = preg_replace('/created_by\s*=>\s*\d+/i', 'created_by => ' . $actorName, $text);
  return trim($text);
}
function employeeNameById(mysqli $conn, int $id): string
{
  static $cache = [];

  if ($id <= 0)
    return '';

  if (isset($cache[$id])) {
    return $cache[$id];
  }

  $stmt = $conn->prepare("
    SELECT TRIM(CONCAT(firstname, ' ', lastname)) AS name
    FROM employees
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $cache[$id] = trim((string) ($row['name'] ?? ''));

  return $cache[$id];
}

function prepareOptionsJsonForModal(mysqli $conn, string $json): string
{
  $data = json_decode($json ?: '{}', true);

  if (!is_array($data)) {
    return $json;
  }

  foreach (['created_by', 'updated_by'] as $key) {
    if (isset($data[$key]) && is_numeric($data[$key])) {
      $name = employeeNameById($conn, (int) $data[$key]);
      if ($name !== '') {
        $data[$key] = $name;
      }
    }
  }

  return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function optionValue(array $data, array $keys): string
{
  foreach ($keys as $key) {
    if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
      return trim((string) $data[$key]);
    }
  }
  return '';
}

function itemProductUrl(array $order, array $item): string
{
  $source = strtoupper((string) ($order['source_code'] ?? ''));
  $sku = trim((string) ($item['sku'] ?? ''));
  $manualUrl = trim((string) ($item['product_url'] ?? ''));

  if ($manualUrl !== '') {
    return $manualUrl;
  }

  if (strpos($source, 'SHOPTET') !== false && $sku !== '') {
    return 'https://www.scrubdesignz.com/search/?string=' . rawurlencode($sku);
  }

  if (strpos($source, 'EBAY') !== false) {
    $data = json_decode((string) ($item['options_json'] ?? ''), true);
    if (!is_array($data)) {
      $data = [];
    }

    $itemNumber = optionValue($data, [
      'item_number',
      'Item number',
      'item_id',
      'Item ID',
      'ebay_item_id',
      'legacy_item_id'
    ]);

    if ($itemNumber === '') {
      foreach (['sku', 'custom_label', 'title'] as $field) {
        if (preg_match('/\b([13][0-9]{8,15})\b/', (string) ($item[$field] ?? ''), $m)) {
          $itemNumber = $m[1];
          break;
        }
      }
    }

    if ($itemNumber !== '') {
      if (strpos($itemNumber, '3') === 0) {
        return 'https://www.ebay.co.uk/itm/' . rawurlencode($itemNumber);
      }

      if (strpos($itemNumber, '1') === 0) {
        return 'https://www.ebay.de/itm/' . rawurlencode($itemNumber);
      }
    }
  }

  return '';
}
// --- build HTML ---
ob_start();
?>
<style>
  /* Detail objednávky – väčší komfort čítania */
  .order-detail-table {
    border-collapse: separate;
    border-spacing: 0;
  }

  .order-detail-table th,
  .order-detail-table td {
    padding: 0.6rem 0.75rem !important;
    vertical-align: middle !important;
  }

  .order-detail-table td {
    line-height: 1.4;
  }

  /* Trochu viac priestoru medzi riadkami */
  .order-detail-table tbody tr {
    height: 42px;
  }

  /* Jemnejší vzhľad v dark mode */
  .order-detail-table th {
    background-color: #343a40;
    font-weight: 600;
  }

  .order-detail-table tbody tr.qty-warning-row>td {
    background: rgba(255, 193, 7, 0.22) !important;
    box-shadow: inset 4px 0 0 #ffc107;
  }

  .activity-log-row {
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    padding: 6px 0;
  }
</style>
<div class="p-3">
  <div class="card card-dark mb-0" style="border-radius:14px; overflow:hidden;">
    <div class="<?php echo h($badgeClass); ?>" style="padding:10px 14px;">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <b>#<?php echo h($order['order_number'] ?? $order['external_order_id'] ?? $orderId); ?></b>
          <span class="ml-2 badge badge-light"><?php echo h($order['source_code'] ?? ''); ?></span>
          <?php if (!empty($cats)): ?>
            <span class="ml-2 text-dark badge badge-dark"><?php echo h(implode(' · ', $cats)); ?></span>
          <?php endif; ?>
        </div>
        <?php if ($isAdminLike): ?>
          <button type="button" class="btn btn-sm btn-light ml-2 btn-edit-order-header"
            data-order-id="<?php echo (int) $orderId; ?>">
