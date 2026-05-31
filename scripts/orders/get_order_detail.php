<?php
declare(strict_types=1);
session_start();
//out(200, ['ok'=>false,'error'=>'PHP '.PHP_VERSION]);
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload): void
{
  http_response_code($code);
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  echo $json !== false ? $json : '{"ok":false,"error":"JSON encode failed"}';
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

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentUserHasPersonalOrders = ((int) ($_SESSION['personal_orders'] ?? 0) === 1);

if ($currentUserId > 0) {
  $userAccessStmt = $conn->prepare("
    SELECT active, personal_orders
    FROM employees
    WHERE id = ?
    LIMIT 1
  ");

  if ($userAccessStmt) {
    $userAccessStmt->bind_param('i', $currentUserId);
    $userAccessStmt->execute();
    $userAccess = $userAccessStmt->get_result()->fetch_assoc();
    $userAccessStmt->close();

    $currentUserHasPersonalOrders = (
      $userAccess
      && (string) ($userAccess['active'] ?? '') === 'Active'
      && (int) ($userAccess['personal_orders'] ?? 0) === 1
    );
    $_SESSION['personal_orders'] = $currentUserHasPersonalOrders ? 1 : 0;
  }
}

$dpt = (int) ($_SESSION['dpt'] ?? 0);
$allAccess = in_array($dpt, [1, 3, 4, 5, 7], true);

// Funkcia na bezpečnú konverziu textu do HTML (ochrana proti XSS útokám)
function h($s): string
{
  return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Generuje HTML obrázok s vlajkou krajiny podľa kódu
function countryFlag($code): string
{
  // Normalizácia kódu krajiny na veľké písmená
  $code = strtoupper(trim((string) $code));
  if ($code === '')
    return '';

  // Zmeny niektorých kódov na štandardizované ISO kódy
  if ($code === 'UK')
    $code = 'GB';
  if ($code === 'UM')
    $code = 'US';
  if ($code === 'KX')
    $code = 'XK';

  // Konverzia na malé písmená pre URL
  $imgCode = strtolower($code);

  return '<img src="https://flagcdn.com/16x12/' . h($imgCode) . '.png" '
    . 'alt="' . h($code) . '" '
    . 'style="margin-right:5px; vertical-align:-1px;">';
}

function normalizeUsZipFromAddress(array $a): string
{
  // Spojenie PSČ, ulice a mesta pre spracovanie
  $text = trim(
    ($a['zip'] ?? '') . ' ' .
    ($a['street'] ?? '') . ' ' .
    ($a['city'] ?? '')
  );

  if ($text === '')
    return '';

  // Vzor ZIP+4: 11706-4815 => extrahni 11706
  if (preg_match('/\b(\d{5})-\d{4}\b/', $text, $m)) {
    return $m[1];
  }

  // Hľadaj posledný samostatný 5-miestny kód
  if (preg_match_all('/\b\d{5}\b/', $text, $m) && !empty($m[0])) {
    return end($m[0]);
  }

  // MXLocker/Shoptet občas chýba vedúca nula: 2703 => 02703
  if (preg_match('/\b(\d{4})\b\s*$/', $text, $m)) {
    return '0' . $m[1];
  }

  return '';
}

// Vracia kód amerického štátu na základe PSČ
function usStateFromZip(string $zip): string
{
  // Odstráni všetky nečíselné znaky
  $zip = preg_replace('/\D+/', '', $zip);
  if (strlen($zip) < 5)
    return '';

  // Extrahuje prvých 5 číslic PSČ
  $n = (int) substr($zip, 0, 5);

  // Mapy ZIP codes pre jednotlivé štáty USA
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

  // Hľadá PSČ v rozsahoch konkrétneho štátu
  foreach ($ranges as $state => $rs) {
    foreach ($rs as $r) {
      if ($n >= $r[0] && $n <= $r[1])
        return $state;
    }
  }

  return '';
}

// Pripraví textovú verziu adresy na kopírovanie
function addressCopyText(array $a, string $state = ''): string
{
  // Kombinuje adresné polia do formátu vhodného na kopírovanie
  return trim(
    ($a['name'] ?? '') . "\n" .
    ($a['company'] ?? '') . "\n" .
    ($a['street'] ?? '') . "\n" .
    trim(($a['city'] ?? '') . ' ' . ($a['zip'] ?? '')) .
    ($state !== '' ? "\nState: " . $state : '')
  );
}

// Vracia CSS triedu pre farebný badge statusu objednávky
// Používa sa v detailoch objednávky aj v zozname objednávok
function status_badge_class($status): string
{
  // Normalizácia statusu
  $s = strtoupper(trim((string) $status));
  // Mapovanie statusov na CSS farby
  switch ($s) {
    case 'NEW':
      return 'bg-info';
    case 'PENDING':
      return 'bg-pending';
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

function status_accent_color($status): string
{
  $s = strtoupper(trim((string) $status));

  switch ($s) {
    case 'NEW':
      return '#17a2b8';
    case 'PENDING':
      return '#7c3aed';
    case 'IN_PROGRESS':
      return '#ffc107';
    case 'NEED_INFO':
      return '#dc3545';
    case 'DRAFT_READY':
      return '#20c997';
    case 'READY_TO_INVOICE':
    case 'READY_TO_SHIP':
    case 'DONE':
    case 'COMPLETED':
    case 'SHIPPED':
      return '#28a745';
    case 'HOLD':
    case 'CANCELLED':
      return '#6c757d';
    default:
      return '#3f9eff';
  }
}

function item_type_category_badge(array $item): string
{
  $map = [
    'G' => ['Graphics', 'badge-info'],
    'P' => ['Plastics', 'badge-primary'],
    'S' => ['Seat Cover', 'badge-success'],
    'F' => ['Fitting', 'badge-danger'],
    'T' => ['Plastics', 'badge-primary'],
    'M' => ['Plastics', 'badge-primary'],
  ];

  $type = strtoupper(trim((string) ($item['item_type_code'] ?? '')));
  [$label, $class] = $map[$type] ?? ['Unknown', 'badge-secondary'];

  return '<span class="badge ' . h($class) . '">' . h($label) . '</span>';
}

// --- order header ---
$stmt = $conn->prepare(" SELECT 
    o.*,
    os.code AS source_code,
    cu.name AS customer_name,
    cu.email AS customer_email,
    cu.phone AS customer_phone,
    pn.firstname AS production_note_firstname,
    pn.lastname AS production_note_lastname,
    pn.photo AS production_note_photo
  FROM orders o
  JOIN order_sources os ON os.id = o.source_id
  LEFT JOIN customers cu ON cu.id = o.customer_id
  LEFT JOIN employees pn ON pn.id = o.production_note_updated_by
  WHERE o.id = ?
  LIMIT 1
");
if (!$stmt)
  out(500, ['ok' => false, 'error' => 'SQL prepare failed: ' . mysqli_error($conn)]);
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
    out(500, ['ok' => false, 'error' => 'ACL prepare failed: ' . mysqli_error($conn)]);
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
$stmt = $conn->prepare("SELECT type, name, company, company_id, street, city, zip, country, email, phone
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
    unit_price,
    options_json,
    internal_options_json,
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
          COALESCE(e.photo, ''), '|',
          COALESCE((
            SELECT oa.id
            FROM order_assignments oa
            WHERE oa.order_id = oia.order_id
              AND oa.employee_id = oia.employee_id
              AND oa.removed_at IS NULL
            ORDER BY
              CASE
                WHEN oa.role LIKE 'PRIMARY_%' THEN 1
                ELSE 2
              END,
              oa.id
            LIMIT 1
          ), 0)
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

// Zoradiť položky podľa departmentu: G → P → F → ostatné
$deptOrder = ['G' => 1, 'P' => 2, 'T' => 2, 'M' => 2, 'S' => 2, 'F' => 3];
usort($items, function (array $a, array $b) use ($deptOrder): int {
  $ta = strtoupper(trim((string)($a['item_type_code'] ?? '')));
  $tb = strtoupper(trim((string)($b['item_type_code'] ?? '')));
  $wa = $deptOrder[$ta] ?? 99;
  $wb = $deptOrder[$tb] ?? 99;
  if ($wa !== $wb) return $wa <=> $wb;
  // V rámci rovnakého departmentu zachovaj pôvodné poradie (line_no, id)
  $la = (int)($a['line_no'] ?? 999999);
  $lb = (int)($b['line_no'] ?? 999999);
  if ($la !== $lb) return $la <=> $lb;
  return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
});

$status = (string) ($order['status'] ?? '');
$badgeClass = status_badge_class($status);
$detailAccentColor = status_accent_color($status);

$priorityOptions = [
  0 => 'Normal',
  10 => 'Deadline',
  20 => 'Priority',
];
$currentPriority = (int) ($order['priority'] ?? 0);
if (!isset($priorityOptions[$currentPriority])) {
  $currentPriority = 0;
}

$statusOptions = [
  'PENDING',
  'NEW',
  'IN_PROGRESS',
  'NEED_INFO',
  'DRAFT_READY',
  'READY_TO_INVOICE',
  'READY_TO_SHIP',
  'SHIPPED',
  'HOLD',
  'CANCELLED'
];

$currentStatus = strtoupper(trim((string) ($order['status'] ?? 'NEW')));
if ($currentStatus === '') {
  $currentStatus = 'NEW';
}
if (!in_array($currentStatus, $statusOptions, true)) {
  $statusOptions[] = $currentStatus;
}

$statusLabels = [
  'PENDING' => 'Pending payment',
  'NEW' => 'New',
  'IN_PROGRESS' => 'In Progress',
  'NEED_INFO' => 'Need Info',
  'DRAFT_READY' => 'Draft Ready',
  'READY_TO_INVOICE' => 'Ready to Invoice',
  'READY_TO_SHIP' => 'Ready to Ship',
  'SHIPPED' => 'Shipped',
  'HOLD' => 'Hold',
  'CANCELLED' => 'Cancelled',
];
$currentStatusLabel = $statusLabels[$currentStatus] ?? str_replace('_', ' ', $currentStatus);

$manualTypes = strtoupper((string) ($order['manual_types_override'] ?? ''));
$hasManualTypes = $manualTypes !== '';
$typeOptions = [
  '' => 'AUTO',
  'G' => 'G',
  'P' => 'P',
  'S' => 'S',
  'F' => 'F',
  'GP' => 'GP',
  'GS' => 'GS',
  'GF' => 'GF',
  'PS' => 'PS',
  'PF' => 'PF',
  'SF' => 'SF',
  'GPS' => 'GPS',
  'GPF' => 'GFP',
  'GSF' => 'GSF',
  'PSF' => 'PSF',
  'GPSF' => 'GFPS',
];

// Čistí text aktivity od technických informácií o ID tvorcov
function formatActivityText(string $text): string
{
  // Odstráni hranaté zátvorky s created_by informáciami
  $text = preg_replace('/\[[^\]]*created_by\s*:\s*\d+[^\]]*\]/i', '', $text);
  // Odstráni created_by bez zátvoriek
  $text = preg_replace('/created_by\s*:\s*\d+/i', '', $text);
  return trim($text);
}

// Vráti meno zamestnanca podľa ID s cachovaním výsledkov
function employeeNameById(mysqli $conn, int $id): string
{
  // Statická cache na uchovávanie už načítaných mien
  static $cache = [];

  // Validácia, že ID je kladné číslo
  if ($id <= 0)
    return '';

  // Vráti meno z cache ak existuje
  if (isset($cache[$id])) {
    return $cache[$id];
  }

  // Dotaz do databázy na meno zamestnanca
  $stmt = $conn->prepare("SELECT TRIM(CONCAT(firstname, ' ', lastname)) AS name
    FROM employees
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  // Uloženie mena do cache
  $cache[$id] = trim((string) ($row['name'] ?? ''));

  return $cache[$id];
}

// Pripraví JSON údaje o voľbách, nahradí ID tvorcov za ich mená
function prepareOptionsJsonForModal(mysqli $conn, string $json): string
{
  // Dekódovanie JSON na asociatívne pole
  $data = json_decode($json ?: '{}', true);

  // Ak nie je pole, vráti pôvodný JSON
  if (!is_array($data)) {
    return $json;
  }

  // Nahradí ID tvorcov/aktualizátorov za ich mená
  foreach (['created_by', 'updated_by'] as $key) {
    if (isset($data[$key]) && is_numeric($data[$key])) {
      $name = employeeNameById($conn, (int) $data[$key]);
      if ($name !== '') {
        $data[$key] = $name;
      }
    }
  }

  return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function prepareEditableOptionsJsonForModal(string $json): string
{
  $data = json_decode($json ?: '{}', true);
  if (!is_array($data)) {
    return '{}';
  }

  $editable = [];
  foreach ($data as $key => $value) {
    $key = (string) $key;
    if ($key === '' || strpos($key, '_') === 0) {
      continue;
    }
    if ($value === null || $value === '' || is_array($value) || is_object($value)) {
      continue;
    }
    $editable[$key] = $value;
  }

  return json_encode($editable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

// Hľadá prvú existujúcu a neprázdnu hodnotu z poľa kľúčov
function optionValue(array $data, array $keys): string
{
  // Iteruje cez kľúče a vracia prvú nájdenú hodnotu
  foreach ($keys as $key) {
    if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
      return trim((string) $data[$key]);
    }
  }
  return '';
}

// Generuje URL produktu podľa zdroja objednávky (SHOPTET, EBAY, atď.)
function itemProductUrl(array $order, array $item): string
{
  // Extrahuje zdroj, SKU a manuálnu URL z údajov
  $source = strtoupper((string) ($order['source_code'] ?? ''));
  $sku = trim((string) ($item['sku'] ?? ''));
  $manualUrl = trim((string) ($item['product_url'] ?? ''));

  // Ak je zadaná manuálna URL, použije sa
  if ($manualUrl !== '') {
    return $manualUrl;
  }

  // Pre SHOPTET objednávky vracia vyhľadávací link s SKU
  if (strpos($source, 'SHOPTET') !== false && $sku !== '') {
    return 'https://www.scrubdesignz.com/search/?string=' . rawurlencode($sku);
  }

  // Pre EBAY objednávky vytvorí link na základe čísla položky
  if (strpos($source, 'EBAY') !== false) {
    // Dekódovanie voliteľných parametrov z JSON
    $data = json_decode((string) ($item['options_json'] ?? ''), true);
    if (!is_array($data)) {
      $data = [];
    }

    // Hľadá číslo položky v rôznych možných kľúčoch
    $itemNumber = optionValue($data, [
      'item_number',
      'Item number',
      'item_id',
      'Item ID',
      'ebay_item_id',
      'legacy_item_id'
    ]);

    // Ak sa nenašlo číslo, hľadaj ho v SKU, návestí alebo názve pomocou regex
    if ($itemNumber === '') {
      foreach (['sku', 'custom_label', 'title'] as $field) {
        if (preg_match('/\b([13][0-9]{8,15})\b/', (string) ($item[$field] ?? ''), $m)) {
          $itemNumber = $m[1];
          break;
        }
      }
    }

    // Ak sa našlo číslo, vytvorí správny link podľa domény
    if ($itemNumber !== '') {
      // Položky začínajúce 3 = eBay UK
      if (strpos($itemNumber, '3') === 0) {
        return 'https://www.ebay.co.uk/itm/' . rawurlencode($itemNumber);
      }

      // Položky začínajúce 1 = eBay DE
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

  /* PENDING status — fialová hlavička karty */
  .bg-pending {
    background-color: #4a1d96 !important;
    color: #e9d5ff !important;
  }

  .bg-pending .badge-light {
    background-color: rgba(233, 213, 255, 0.18) !important;
    color: #e9d5ff !important;
  }

  .bg-pending select,
  .bg-pending .form-control {
    background-color: rgba(74, 29, 150, 0.6) !important;
    border-color: #7c3aed !important;
    color: #e9d5ff !important;
  }

  /* get_order_detail.php → <style> blok 
.badge {
    font-size: 1rem !important;
    padding: .55em .9em !important;
    border-radius: 10px;
    font-weight: 600;
}*/
  .order-detail-table td,
  .order-detail-table th {
    box-shadow: none !important;
    outline: none !important;
  }

  .order-detail-card {
    border: 1px solid rgba(255, 255, 255, 0.12) !important;
    border-left: 4px solid var(--order-detail-accent, #3f9eff) !important;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.20);
  }

  .order-detail-header {
    background: rgba(255, 255, 255, 0.025);
    border-bottom: 1px solid rgba(255, 255, 255, 0.10);
    padding: 12px 14px;
  }

  .order-detail-header-title {
    min-width: 220px;
  }

  .order-detail-header-actions {
    gap: 6px;
    flex: 0 0 auto;
    flex-wrap: nowrap !important;
    max-width: 100%;
  }

  .order-detail-header-selects {
    display: inline-flex;
    align-items: center;
    flex: 0 0 auto;
    flex-wrap: nowrap;
    gap: 6px;
  }

  .order-detail-header-selects .form-control {
    width: auto !important;
    min-width: 0 !important;
    flex: 0 0 auto;
  }

  .order-detail-header-selects .order-status-select {
    width: 180px !important;
  }

  .order-detail-header-selects .order-types-select {
    width: 86px !important;
  }

  .order-detail-header .form-control {
    background-color: rgba(0, 0, 0, 0.18) !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
    color: #f8f9fa !important;
  }

  .order-detail-header .btn-edit-order-header.btn-light {
    background: transparent !important;
    border-color: rgba(255, 255, 255, 0.22) !important;
    color: #f8f9fa !important;
  }

  .order-detail-header .btn-close-order-detail {
    border-color: var(--order-detail-accent, #3f9eff) !important;
    color: #f8f9fa !important;
  }

  .order-detail-header .btn-close-order-detail:hover {
    background: var(--order-detail-accent, #3f9eff) !important;
    border-color: var(--order-detail-accent, #3f9eff) !important;
    color: #0f1720 !important;
  }

  @media (max-width: 575.98px) {
    .order-detail-header-actions {
      justify-content: flex-start !important;
      margin-top: 8px;
      width: 100%;
    }
  }

  .assigned-avatar-wrap {
    position: relative;
    display: inline-flex;
    width: 28px;
    height: 28px;
  }

  .btn-remove-item-assignment {
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

  .assigned-avatar-wrap:hover .btn-remove-item-assignment {
    display: block;
  }

  /* ── Printing settings autocomplete ─────────────────────────── */
  .print-ac-dropdown {
    position: absolute;
    left: 0; right: 0;
    top: 100%;
    background: #1e2530;
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 4px;
    z-index: 9999;
    max-height: 160px;
    overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.45);
  }
  .print-ac-item {
    padding: 5px 10px;
    cursor: pointer;
    font-size: 12px;
    color: #e2e8f0;
    border-bottom: 1px solid rgba(255,255,255,.06);
  }
  .print-ac-item:hover, .print-ac-item.active {
    background: rgba(63,158,255,.22);
    color: #fff;
  }
  .print-settings-cell .form-control-sm {
    font-size: 11px;
    padding: 2px 6px;
    height: auto;
  }

  /* ── Printing Settings block in modal ───────────────────────── */
  .printing-settings-block {
    background: rgba(63,158,255,.08);
    border: 1px solid rgba(63,158,255,.25);
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 12px;
  }
  .printing-settings-block .ps-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .5px;
    opacity: .7;
    margin-bottom: 2px;
  }
  .printing-settings-block .ps-value {
    font-size: 14px;
    font-weight: 600;
  }
</style>
<div class="p-3">
  <div class="card card-dark order-detail-card mb-0"
    style="--order-detail-accent: <?php echo h($detailAccentColor); ?>; border-radius:14px; overflow:hidden;">
    <div class="order-detail-header">
      <div class="d-flex justify-content-between align-items-start flex-wrap">
        <div class="order-detail-header-title">
          <b>#<?php echo h($order['order_number'] ?? $order['external_order_id'] ?? $orderId); ?></b>
          <span class="ml-2 badge badge-light"><?php echo h($order['source_code'] ?? ''); ?></span>
          <?php if (!empty($cats)): ?>
            <span class="ml-2 text-dark badge badge-dark"><?php echo h(implode(' · ', $cats)); ?></span>
          <?php endif; ?>
          <span class="ml-2 badge <?php echo h($badgeClass); ?>"><?php echo h($currentStatusLabel); ?></span>
        </div>
        <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
          <button type="button" class="btn btn-sm btn-light ml-2 btn-edit-order-header"
            data-order-id="<?php echo (int) $orderId; ?>" data-mode="edit">
            ✏️ Edit header
          </button>
        <?php endif; ?>
        <div class="d-flex justify-content-end align-items-center flex-wrap order-detail-header-actions">
          <?php
          $priorityOptions = [
            0 => 'Normal',
            10 => 'Deadline',
            20 => 'Priority',
          ];
          $currentPriority = (int) ($order['priority'] ?? 0);
          if (!isset($priorityOptions[$currentPriority])) {
            $currentPriority = 0;
          }

          $statusOptions = [
            'PENDING',
            'NEW',
            'IN_PROGRESS',
            'NEED_INFO',
            'DRAFT_READY',
            'READY_TO_INVOICE',
            'READY_TO_SHIP',
            'SHIPPED',
            'HOLD',
            'CANCELLED'
          ];

          $currentStatus = strtoupper(trim((string) ($order['status'] ?? 'NEW')));
          if ($currentStatus === '') {
            $currentStatus = 'NEW';
          }
          // Ak je objednávka v stave ktorý nie je v zozname, pridaj ho
          if (!in_array($currentStatus, $statusOptions, true)) {
            $statusOptions[] = $currentStatus;
          }

          $statusLabels = [
            'PENDING' => '⏳ Pending payment',
            'NEW' => 'New',
            'IN_PROGRESS' => 'In Progress',
            'NEED_INFO' => 'Need Info',
            'DRAFT_READY' => 'Draft Ready',
            'READY_TO_INVOICE' => 'Ready to Invoice',
            'READY_TO_SHIP' => 'Ready to Ship',
            'SHIPPED' => 'Shipped',
            'HOLD' => 'Hold',
            'CANCELLED' => 'Cancelled',
          ];
          ?>



          <div class="order-detail-header-selects">
            <select class="form-control form-control-sm order-status-select" data-order-id="<?php echo (int) $orderId; ?>"
              data-original-status="<?php echo h($currentStatus); ?>">

              <?php foreach ($statusOptions as $st): ?>
                <option value="<?php echo h($st); ?>" <?php echo ($currentStatus === $st ? 'selected' : ''); ?>>
                  <?php echo h($statusLabels[$st] ?? str_replace('_', ' ', $st)); ?>
                </option>
              <?php endforeach; ?>

            </select>
          <?php
          $manualTypes = strtoupper((string) ($order['manual_types_override'] ?? ''));
          $hasManualTypes = $manualTypes !== '';
          $typeOptions = [
            '' => 'AUTO',
            'G' => 'G',
            'P' => 'P',
            'S' => 'S',
            'F' => 'F',
            'GP' => 'GP',
            'GS' => 'GS',
            'GF' => 'GF',
            'PS' => 'PS',
            'PF' => 'PF',
            'SF' => 'SF',
            'GPS' => 'GPS',
            'GPF' => 'GFP',
            'GSF' => 'GSF',
            'PSF' => 'PSF',
            'GPSF' => 'GFPS',
          ];
          ?>

          <?php if ((int) ($_SESSION['permission'] ?? 0) === 900): ?>
            <select class="form-control form-control-sm order-types-select"
              data-order-id="<?php echo (int) $orderId; ?>">
              <?php foreach ($typeOptions as $val => $label): ?>
                <option value="<?php echo h($val); ?>" <?php echo ($manualTypes === $val ? 'selected' : ''); ?>>
                  <?php echo h($label); ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          </div>
          <button type="button" class="btn btn-sm btn-outline-light btn-close-order-detail"
            data-order-id="<?php echo (int) $orderId; ?>" title="Close detail">
            <i class="fas fa-chevron-up"></i> Close
          </button>
        </div>
      </div>
    </div>



    <div class="card-body">

      <div class="row">
        <div class="col-md-6">
          <div>
            <b>Zákazník:</b><br />
            <?php $val = $order['customer_name'] ?: $order['customer_email'] ?: '-'; ?>
            <?php echo h($val); ?>
            <button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($val); ?>">📋</button>
          </div>
          <?php if (!empty($order['customer_email'])): ?>
            <div class="text-muted">
              <?php echo h($order['customer_email']); ?>
              <button class="btn btn-xs btn-copy-inline ml-1"
                data-copy="<?php echo h($order['customer_email']); ?>">📋</button>
            </div>
          <?php endif; ?>
          <?php if ($displayCustomerPhone !== ''): ?>
            <div class="text-muted">
              <?php echo h($displayCustomerPhone); ?>
              <button class="btn btn-xs btn-copy-inline ml-1"
                data-copy="<?php echo h($displayCustomerPhone); ?>">📋</button>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-md-6">
          <div><b>Shipping:</b> <?php echo h($order['shipping_method'] ?? '-'); ?></div>
          <div><b>Payment:</b> <?php echo h($order['payment_method'] ?? '-'); ?></div>

          <div>
            <b>Country:</b>
            <span class="order-country-display"><?php echo h($orderCountry ?: '-'); ?></span>

            <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
              <button type="button" class="btn btn-xs btn-outline-warning btn-edit-country ml-2"
                data-order-id="<?php echo (int) $orderId; ?>" data-country="<?php echo h($orderCountry); ?>">
                Edit
              </button>
            <?php endif; ?>
          </div>

          <div class="text-muted">
            <b>Dátum:</b> <?php echo h($order['order_date'] ?? '-'); ?>
            <span class="ml-2"><b>Import:</b> <?php echo h($order['imported_at'] ?? '-'); ?></span>
          </div>
        </div>
      </div>

      <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
        <div class="order-header-edit mt-3" style="display:none;">
          <div class="card bg-dark border-warning">
            <div class="card-header">
              <b>Edit order header</b>
            </div>

            <div class="card-body">
              <input type="hidden" class="edit-order-id" value="<?php echo (int) $orderId; ?>">

              <div class="form-row">
                <div class="form-group col-md-6">
                  <label>Payment</label>
                  <input class="form-control form-control-sm edit-payment"
                    value="<?php echo h($order['payment_method'] ?? ''); ?>">
                </div>

                <div class="form-group col-md-6">
                  <label>Shipping</label>
                  <input class="form-control form-control-sm edit-delivery"
                    value="<?php echo h($order['shipping_method'] ?? ''); ?>">
                </div>
              </div>

              <?php $b = $addr['BILLING'] ?? []; ?>
              <?php $s = $addr['SHIPPING'] ?? []; ?>

              <div class="row">
                <!-- LEFT: Billing -->
                <div class="col-md-6">
                  <h6>Billing</h6>
                  <input class="form-control form-control-sm mb-1 edit-billing-name" placeholder="Name"
                    value="<?php echo h($b['name'] ?? ''); ?>">
                  <div class="form-row mb-1">
                    <div class="col-md-8">
                      <input class="form-control form-control-sm edit-billing-company" placeholder="Company"
                        value="<?php echo h($b['company'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                      <input class="form-control form-control-sm edit-billing-company-id" placeholder="Company ID"
                        value="<?php echo h($b['company_id'] ?? ''); ?>">
                    </div>
                  </div>
                  <input class="form-control form-control-sm mb-1 edit-billing-street" placeholder="Street"
                    value="<?php echo h($b['street'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-city" placeholder="City"
                    value="<?php echo h($b['city'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-zip" placeholder="ZIP"
                    value="<?php echo h($b['zip'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-country" placeholder="Country"
                    value="<?php echo h($b['country'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-email" placeholder="Email"
                    value="<?php echo h($b['email'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-phone" placeholder="Phone"
                    value="<?php echo h($b['phone'] ?? ''); ?>">
                </div>

                <!-- RIGHT: Shipping -->
                <div class="col-md-6">
                  <h6>Shipping</h6>
                  <input class="form-control form-control-sm mb-1 edit-shipping-name" placeholder="Name"
                    value="<?php echo h($s['name'] ?? ''); ?>">
                  <div class="form-row mb-1">
                    <div class="col-md-8">
                      <input class="form-control form-control-sm edit-shipping-company" placeholder="Company"
                        value="<?php echo h($s['company'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                      <input class="form-control form-control-sm edit-shipping-company-id" placeholder="Company ID"
                        value="<?php echo h($s['company_id'] ?? ''); ?>">
                    </div>
                  </div>
                  <input class="form-control form-control-sm mb-1 edit-shipping-street" placeholder="Street"
                    value="<?php echo h($s['street'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-city" placeholder="City"
                    value="<?php echo h($s['city'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-zip" placeholder="ZIP"
                    value="<?php echo h($s['zip'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-country" placeholder="Country"
                    value="<?php echo h($s['country'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-email" placeholder="Email"
                    value="<?php echo h($s['email'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-phone" placeholder="Phone"
                    value="<?php echo h($s['phone'] ?? ''); ?>">
                </div>
              </div>


              <button type="button" class="btn btn-warning btn-sm mt-2 btn-save-order-header" style="display:none;">
                Save changes
              </button>

              <button type="button" class="btn btn-secondary btn-sm mt-2 btn-cancel-order-header">
                Cancel
              </button>
            </div>
          </div>
        </div>
      <?php endif; ?>
      <hr />
      <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
        <div class="row">
          <div class="col-md-6">
            <h6 class="text-muted"><span class="badge badge-secondary">Billing</span></h6>
            <?php $b = $addr['BILLING']; ?>
            <?php if ($b): ?>
              <?php
              $billingState = '';
              if (strtoupper($b['country'] ?? '') === 'US') {
                $billingZip = normalizeUsZipFromAddress($b);
                $billingState = usStateFromZip($billingZip);
              }

              $fullBilling = trim(
                ($b['name'] ?? '') . "\n" .
                ($b['company'] ?? '') . "\n" .
                ($b['street'] ?? '') . "\n" .
                trim(($b['city'] ?? '') . " " . ($b['zip'] ?? '')) .
                ($billingState !== '' ? "\n" . $billingState : '')
              );
              ?>
              <button class="btn btn-xs btn-copy-inline mb-2" data-copy="<?php echo h($fullBilling); ?>">
                📋 Copy address
              </button>
              <div>
                <?php echo h($b['name'] ?? '-'); ?>
                <?php 
                $companyPart = '';
                if (!empty($b['company'])) {
                  $companyPart = h($b['company']);
                }
                if (!empty($b['company_id'])) {
                  if ($companyPart) {
                    $companyPart .= ' [' . h($b['company_id']) . ']';
                  } else {
                    $companyPart = '[' . h($b['company_id']) . ']';
                  }
                }
                if ($companyPart) {
                  echo ' (' . $companyPart . ')';
                }
                ?>
              </div>
              <div class="text-muted">
                <?php echo h(trim(($b['street'] ?? '') . ', ' . ($b['city'] ?? '') . ' ' . ($b['zip'] ?? ''))); ?>
              </div>
              <?php if (!empty($b['phone'])): ?>
                <div class="text-muted">
                  <b>Phone:</b> <?php echo h($b['phone']); ?>
                  <button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($b['phone']); ?>">📋</button>
                </div>
              <?php endif; ?>
              <?php if (!empty($b['country'])): ?>
                <div class="text-muted">
                  <?php if ($billingState !== ''): ?>
                    <div>
                      <span><b><?php echo h($billingState); ?></b></span>
                    </div>
                  <?php endif; ?>

                  <?php
                  $cc = strtoupper($b['country']);
                  echo countryFlag($cc) . ' ' . h($cc);
                  ?>

                  <hr class="my-2">

                  <h6 class="text-muted mb-2">
                    <span class="badge badge-secondary">Invoices</span>
                  </h6>

                  <?php
                  $invStmt = $conn->prepare("
                  SELECT id, invoice_number
                  FROM order_invoices
                  WHERE order_id = ? AND deleted_at IS NULL
                  ORDER BY id DESC
                ");
                  $invStmt->bind_param('i', $orderId);
                  $invStmt->execute();
                  $invRes = $invStmt->get_result();
                  ?>

                  <?php while ($inv = $invRes->fetch_assoc()): ?>
                    <div class="small mb-1 d-flex align-items-center">

                      <div>
                        <b><?php echo h($inv['invoice_number']); ?></b>
                      </div>

                      <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                        <button class="btn btn-xs btn-outline-danger ml-2 py-0 px-2 btn-delete-invoice"
                          data-id="<?php echo (int) $inv['id']; ?>" data-order-id="<?php echo (int) $orderId; ?>"> × </button>
                      <?php endif; ?>

                    </div>

                  <?php endwhile; ?>
                  <?php $invStmt->close(); ?>

                  <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                    <div class="form-row mt-2">
                      <div class="col-md-8">
                        <input class="form-control form-control-sm invoice-number" placeholder="Invoice number">
                      </div>
                      <div class="col-md-4">
                        <button class="btn btn-sm btn-info btn-block btn-add-invoice"
                          data-order-id="<?php echo (int) $orderId; ?>">
                          Add Invoice
                        </button>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="text-muted">—</div>
            <?php endif; ?>
          </div>

          <div class="col-md-6">
            <h6 class="text-muted"><span class="badge badge-secondary">Delivery</span></h6>
            <?php $s = $addr['SHIPPING']; ?>
            <?php if ($s): ?>
              <?php
              $shippingZip = normalizeUsZipFromAddress($s);
              $shippingState = '';

              if (strtoupper($s['country'] ?? '') === 'US') {
                $shippingZip = normalizeUsZipFromAddress($s);
                $shippingState = usStateFromZip($shippingZip);
              }
              $fullShipping = addressCopyText($s, $shippingState);
              ?>

              <button class="btn btn-xs btn-copy-inline mb-2" data-copy="<?php echo h($fullShipping); ?>">
                📋 Copy address
              </button>

              <div>
                <?php echo h($s['name'] ?? '-'); ?>
                <?php 
                $companyPart = '';
                if (!empty($s['company'])) {
                  $companyPart = h($s['company']);
                }
                if (!empty($s['company_id'])) {
                  if ($companyPart) {
                    $companyPart .= ' [' . h($s['company_id']) . ']';
                  } else {
                    $companyPart = '[' . h($s['company_id']) . ']';
                  }
                }
                if ($companyPart) {
                  echo ' (' . $companyPart . ')';
                }
                ?>
              </div>

              <div class="text-muted">
                <?php echo h(trim(($s['street'] ?? '') . ', ' . ($s['city'] ?? '') . ' ' . ($s['zip'] ?? ''))); ?>
              </div>

              <?php if ($shippingState !== ''): ?>
                <div>
                  <span><?php echo h($shippingState); ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($s['phone'])): ?>
                <div class="text-muted">
                  <b>Phone:</b> <?php echo h($s['phone']); ?>
                  <button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($s['phone']); ?>">📋</button>
                </div>
              <?php endif; ?>
              <?php if (!empty($s['country'])): ?>
                <div class="text-muted">
                  <?php
                  $cc = strtoupper($s['country']);
                  echo countryFlag($cc) . ' ' . h($cc);
                  ?>
                </div>
              <?php endif; ?>

            <?php else: ?>
              <div class="text-muted">—</div>
            <?php endif; ?>
            <hr class="my-2">

            <h6 class="text-muted mb-2">
              <span class="badge badge-secondary">Tracking</span>
            </h6>


            <?php
            $trackingStmt = $conn->prepare("
            SELECT id, tracking_number, carrier
            FROM order_tracking_numbers
            WHERE order_id = ? AND deleted_at IS NULL
            ORDER BY id DESC
          ");
            $trackingStmt->bind_param('i', $orderId);
            $trackingStmt->execute();
            $trackingRes = $trackingStmt->get_result();
            ?>

            <?php while ($t = $trackingRes->fetch_assoc()): ?>
              <div class="small mb-1 d-flex align-items-center">

                <div>
                  <b><?php echo h($t['tracking_number']); ?></b>
                  <?php if (!empty($t['carrier'])): ?>
                    <span class="text-muted">(<?php echo h($t['carrier']); ?>)</span>
                  <?php endif; ?>
                </div>

                <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                  <button class="btn btn-xs btn-outline-danger ml-2 py-0 px-2 btn-delete-tracking"
                    data-id="<?php echo (int) $t['id']; ?>" data-order-id="<?php echo (int) $orderId; ?>">
                    ×
                  </button>
                <?php endif; ?>

              </div>
            <?php endwhile; ?>
            <?php $trackingStmt->close(); ?>

            <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
              <div class="form-row mt-2">
                <div class="col-md-7">
                  <input class="form-control form-control-sm tracking-number" placeholder="Tracking number">
                </div>
                <div class="col-md-3">
                  <input class="form-control form-control-sm tracking-carrier" placeholder="Carrier">
                </div>
                <div class="col-md-2">
                  <button class="btn btn-sm btn-info btn-block btn-add-tracking"
                    data-order-id="<?php echo (int) $orderId; ?>">
                    Add Tracking
                  </button>

                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <hr />
      <?php
      $noteAuthor = trim((string) ($order['production_note_firstname'] ?? '') . ' ' . (string) ($order['production_note_lastname'] ?? ''));
      $notePhoto = trim((string) ($order['production_note_photo'] ?? ''));
      $noteAt = trim((string) ($order['production_note_updated_at'] ?? ''));
      ?>

      <h6 class="text-muted mb-2">Production note</h6>

      <div class="card bg-dark border-info p-2 production-note-box">
        <?php if ($noteAuthor !== ''): ?>
          <div class="d-flex align-items-center mb-2 text-muted">
            <?php if ($notePhoto !== ''): ?>
              <img src="images/<?= h($notePhoto) ?>" class="img-circle mr-2"
                style="width:24px; height:24px; object-fit:cover;" alt="<?= h($noteAuthor) ?>">
            <?php else: ?>
              <i class="fas fa-user-circle mr-2"></i>
            <?php endif; ?>

            <small>
              Note by <b>
                <?= h($noteAuthor) ?>
              </b>
              <?php if ($noteAt !== ''): ?>
                ·
                <?= h($noteAt) ?>
              <?php endif; ?>
            </small>
          </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-start">
          <div class="production-note-display text-light" style="white-space:pre-wrap; flex:1;">
            <?php if (trim((string) ($order['production_note'] ?? '')) !== ''): ?>
              <?php echo h($order['production_note'] ?? ''); ?>
            <?php else: ?>
              <span class="text-muted">No production note.</span>
            <?php endif; ?>
          </div>


          <button type="button" class="btn btn-xs btn-outline-info ml-2 btn-edit-production-note">
            Edit
          </button>

        </div>


        <div class="production-note-editor mt-2" style="display:none;">
          <textarea class="form-control form-control-sm production-note-input production-note-textarea" rows="2"
            placeholder="Customer changes / production instructions..."><?php echo h($order['production_note'] ?? ''); ?></textarea>

          <div class="mt-2">
            <button class="btn btn-sm btn-info btn-save-production-note" data-order-id="<?php echo (int) $orderId; ?>">
              Save
            </button>

            <button type="button" class="btn btn-sm btn-secondary btn-cancel-production-note">
              Cancel
            </button>
          </div>
        </div>

      </div>
      <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>

        <h6 class="text-muted mb-2">Položky </h6>
        <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
          <div class="card bg-dark border-info p-2 mb-3 manual-item-box">
            <div class="d-flex justify-content-between align-items-center">
              <b class="text-info">Add manual item</b>
            </div>
          <?php endif; ?>
          <div class="form-row mt-2">
            <div class="col-md-2">
              <select class="form-control form-control-sm manual-item-type">
                <option value="">Select type...</option>
                <option value="G">G - Graphics</option>
                <option value="P">P - Plastics</option>
                <option value="S">S - Seat Cover</option>
                <option value="F">F - Fitting</option>
                <option value="T">T - Trim Kit</option>
                <option value="M">M - Bike Mats</option>
              </select>
            </div>

            <div class="col-md-1">
              <input type="number" class="form-control form-control-sm manual-item-qty" value="1" min="1"
                placeholder="Qty">
            </div>

            <div class="col-md-3">
              <input class="form-control form-control-sm manual-item-sku" placeholder="SKU" value="MANUAL">
            </div>

            <div class="col-md-4">
              <input class="form-control form-control-sm manual-item-title" placeholder="Item title / service name">
            </div>

            <div class="col-md-2">
              <button type="button" class="btn btn-sm btn-info btn-block btn-add-manual-item"
                data-order-id="<?php echo (int) $orderId; ?>">
                Add item
              </button>
            </div>
          </div>

          <div class="mt-2">
            <input class="form-control form-control-sm manual-item-reason" placeholder="Reason / customer request note">
          </div>
        </div>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0 order-detail-table">
          <thead>
            <tr>
              <th class="text-center">Assigned</th>
              <th>Názov</th>
              <th>SKU</th>
              <th>Label</th>
              <th>Qty</th>
              <th>Price</th>
              <th>Status</th>
              <th>Waiting</th>
              <th>Action</th>
              <th title="Printer / Material / Finish — iba pre grafiku">🖨️ Print</th>
              <th>Product</th>
              <th class="text-center">Detail</th>
              <th class="text-center">Copy</th>
              <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                <th class="text-center">Save</th>
                <th class="text-center">Delete</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>

            <?php foreach ($items as $it): ?>
              <?php
              $t = strtoupper((string) ($it['item_type_code'] ?? 'NULL'));
              $badge = 'badge-secondary';

              if ($t === 'T' || $t === 'M')
                $badge = 'badge-warning';
              elseif ($t === 'G')
                $badge = 'badge-info';
              elseif ($t === 'P')
                $badge = 'badge-primary';
              elseif ($t === 'S')
                $badge = 'badge-success';
              elseif ($t === 'F')
                $badge = 'badge-danger';
              $qty = (int) ($it['qty'] ?? 1);
              $rowClass = $qty > 1 ? 'qty-warning-row' : '';
              $optPreview = '';
              if (!empty($it['options_json'])) {
                $decoded = json_decode((string) $it['options_json'], true);
                if (is_array($decoded)) {
                  $pairs = [];
                  foreach ($decoded as $k => $v) {
                    if ($k === '_item')
                      continue;
                    if (is_array($v))
                      continue;
                    $pairs[] = $k . ': ' . (string) $v;
                    if (count($pairs) >= 4)
                      break;
                  }
                  $optPreview = implode(' | ', $pairs);
                } else {
                  $optPreview = substr((string) $it['options_json'], 0, 120);
                }
              }
              ?>
              <tr class="<?php echo ((int) $it['qty'] > 1 ? 'qty-warning-row' : ''); ?>"
                data-item-type="<?php echo h($it['item_type_code'] ?? ''); ?>">
                <td class="text-center" style="min-width:80px;">
                  <?php
                  $assignedRaw = trim((string) ($it['item_assigned_users'] ?? ''));
                  $itemAssigned = [];

                  if ($assignedRaw !== '') {
                    foreach (explode(';;', $assignedRaw) as $part) {
                      $bits = explode('|', $part);
                      if (count($bits) >= 3) {
                        $itemAssigned[] = [
                          'id' => (int) $bits[0],
                          'name' => $bits[1],
                          'photo' => $bits[2],
                          'assignment_id' => (int) ($bits[3] ?? 0),
                        ];
                      }
                    }
                  }

                  $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
                  $currentUserAssignedToItem = false;

                  foreach ($itemAssigned as $a) {
                    if ((int) $a['id'] === $currentUserId) {
                      $currentUserAssignedToItem = true;
                      break;
                    }
                  }

                  $itemType = strtoupper((string) ($it['item_type_code'] ?? ''));
                  $userDpt = (int) ($_SESSION['dpt'] ?? 0);

                  $dptItemMap = [
                    2 => 'G',
                    6 => 'P',
                    8 => 'S',
                    9 => 'F',
                  ];

                  $canAssignThisItem = false;
                  $perm = (int) ($_SESSION['permission'] ?? 0);

                  if (isset($dptItemMap[$userDpt]) && $dptItemMap[$userDpt] === $itemType) {
                    if ($perm >= 400) {
                      $canAssignThisItem = true;
                    } else {
                      $deptRoleMap = [
                        2 => ['PRIMARY_GRAPHICS', 'COLLAB_GRAPHICS'],
                        6 => ['PRIMARY_PLASTICS', 'COLLAB_PLASTICS'],
                        8 => ['PRIMARY_SEATCOVER', 'COLLAB_SEATCOVER'],
                        9 => ['PRIMARY_FITTING', 'COLLAB_FITTING'],
                      ];

                      $allowedRoles = $deptRoleMap[$userDpt] ?? [];

                      if ($allowedRoles) {
                        $stmtPerm = $conn->prepare("
                        SELECT 1
                        FROM order_assignments
                        WHERE order_id = ?
                          AND employee_id = ?
                          AND role IN ('" . implode("','", array_map([$conn, 'real_escape_string'], $allowedRoles)) . "')
                          AND removed_at IS NULL
                        LIMIT 1
                      ");
                        $stmtPerm->bind_param('ii', $orderId, $currentUserId);
                        $stmtPerm->execute();
                        $canAssignThisItem = (bool) $stmtPerm->get_result()->fetch_row();
                        $stmtPerm->close();
                      }
                    }
                  }
                  $deptPrimaryRoleMap = [
                    2 => 'PRIMARY_GRAPHICS',
                    6 => 'PRIMARY_PLASTICS',
                    8 => 'PRIMARY_SEATCOVER',
                    9 => 'PRIMARY_FITTING',
                  ];
                  $deptCodeMap = [
                    2 => 'GRAPHICS',
                    6 => 'PLASTICS',
                    8 => 'SEATCOVER',
                    9 => 'FITTING',
                  ];

                  $isFittingItem = ($itemType === 'F');
                  $currentDeptPrimaryRole = $isFittingItem
                    ? 'PRIMARY_FITTING'
                    : ($deptPrimaryRoleMap[$userDpt] ?? '');
                  $currentDeptCode = $isFittingItem
                    ? 'FITTING'
                    : ($deptCodeMap[$userDpt] ?? '');
                  $currentUserCanPersonalOrders = $currentUserHasPersonalOrders;

                  $orderTakenForMyDept = false;

                  if ($currentDeptPrimaryRole !== '') {
                    $stmtTaken = $conn->prepare("
                      SELECT 1
                      FROM order_assignments
                      WHERE order_id = ?
                        AND role = ?
                        AND removed_at IS NULL
                      LIMIT 1
                    ");
                    $stmtTaken->bind_param('is', $orderId, $currentDeptPrimaryRole);
                    $stmtTaken->execute();
                    $orderTakenForMyDept = (bool) $stmtTaken->get_result()->fetch_row();
                    $stmtTaken->close();
                  }

                  $canTakeOrderFromDetail = (
                    $currentDeptPrimaryRole !== ''
                    && !$orderTakenForMyDept
                    && (
                      ($isFittingItem && $currentUserCanPersonalOrders)
                      || (isset($dptItemMap[$userDpt]) && $dptItemMap[$userDpt] === $itemType)
                    )
                  );
                  ?>

                  <div class="d-flex justify-content-center align-items-center flex-wrap" style="gap:4px;">
                    <?php foreach ($itemAssigned as $a): ?>
                      <?php
                      $name = trim((string) $a['name']);
                      $photo = trim((string) $a['photo']);

                      $initials = '';
                      foreach (preg_split('/\s+/', $name) as $p) {
                        if ($p !== '') {
                          $initials .= mb_strtoupper(mb_substr($p, 0, 1));
                        }
                      }
                      $initials = mb_substr($initials, 0, 2);

                      $canRemoveThisAssignment = (
                        !empty($a['assignment_id'])
                        && (
                          (int) ($_SESSION['permission'] ?? 0) >= 300
                          || (int) $a['id'] === $currentUserId
                        )
                      );
                      ?>

                      <span class="assigned-avatar-wrap">

                        <?php if ($photo !== ''): ?>
                          <img src="images/<?= h($photo) ?>" class="img-circle elevation-2"
                            style="width:28px; height:28px; object-fit:cover;" title="<?= h($name) ?>">
                        <?php else: ?>
                          <span class="badge badge-secondary"
                            style="width:28px; height:28px; line-height:28px; border-radius:50%;" title="<?= h($name) ?>">
                            <?= h($initials ?: '?') ?>
                          </span>
                        <?php endif; ?>

                        <?php if ($canRemoveThisAssignment): ?>
                          <button type="button" class="btn-remove-assignment"
                            data-assignment-id="<?= (int) $a['assignment_id'] ?>"
                            title="<?= ((int) $a['id'] === $currentUserId ? 'Remove my assignment' : 'Remove assignment') ?>">
                            ×
                          </button>
                        <?php endif; ?>

                      </span>
                    <?php endforeach; ?>

                    <?php if ($canTakeOrderFromDetail && empty($itemAssigned)): ?>
                      <button type="button" class="btn btn-sm btn-warning btn-take-order px-2 py-1"
                        style="font-size:11px; font-weight:700; border-radius:8px; letter-spacing:.3px;"
                        data-order-id="<?= (int) $orderId ?>" data-dept-code="<?= h($currentDeptCode) ?>"
                        title="Take this order for my department"
                        style="font-size:11px; font-weight:700; padding:2px 8px; border-radius:8px;">
                        TAKE
                      </button>
                    <?php endif; ?>
                  </div>
                </td>


                <td>
                  <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                    <input class="form-control form-control-sm item-title" value="<?php echo h($it['title'] ?? ''); ?>">
                  <?php else: ?>
                    <?php echo h($it['title'] ?? ''); ?>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                    <input class="form-control form-control-sm item-sku" value="<?php echo h($it['sku'] ?? ''); ?>">
                  <?php else: ?>
                    <?php echo h($it['sku'] ?? ''); ?>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                    <input class="form-control form-control-sm item-label"
                      value="<?php echo h($it['custom_label'] ?? ''); ?>">
                  <?php else: ?>
                    <?php echo h($it['custom_label'] ?? ''); ?>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                    <input type="number" class="form-control form-control-sm item-qty"
                      value="<?php echo (int) $it['qty']; ?>" min="1">
                  <?php else: ?>
                    <?php echo (int) $it['qty']; ?>
                  <?php endif; ?>
                </td>

                <td style="min-width:90px;">
                  <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                    <div class="input-group input-group-sm">
                      <input type="number" class="form-control form-control-sm item-unit-price"
                        value="<?php echo $it['unit_price'] !== null ? number_format((float) $it['unit_price'], 2, '.', '') : ''; ?>"
                        min="0" step="0.01" placeholder="0.00">
                    </div>
                  <?php else: ?>
                    <?php echo $it['unit_price'] !== null ? number_format((float) $it['unit_price'], 2, '.', '') : '—'; ?>
                  <?php endif; ?>
                </td>

                <td>
                  <?php echo item_type_category_badge($it); ?>
                </td>

                <td style="min-width:220px;">
                  <input type="text" class="form-control form-control-sm item-waiting-note"
                    data-item-id="<?= (int) $it['id'] ?>" value="<?= h($it['waiting_note'] ?? '') ?>"
                    placeholder="Na čo čakáme?">

                  <input type="date" class="form-control form-control-sm mt-1 item-expected-date"
                    data-item-id="<?= (int) $it['id'] ?>" value="<?= h($it['expected_date'] ?? '') ?>">
                </td>

                <td>
                  <?php
                  $type = strtoupper((string) ($it['item_type_code'] ?? ''));

                  if ($type === 'G') {
                    $statuses = ['NEW', 'RTP', 'PRINT_QUEUE', 'PRINTED', 'CUT', 'READY', 'WAITING'];
                  } elseif ($type === 'F') {
                    $statuses = ['NEW', 'PROCESSING', 'DONE', 'READY', 'WAITING'];
                  } else {
                    $statuses = ['NEW', 'PROCESSING', 'READY', 'WAITING'];
                  }

                  $currentStatus = strtoupper(trim((string) ($it['item_status'] ?? 'NEW')));
                  if ($currentStatus === '') {
                    $currentStatus = 'NEW';
                  }

                  if (!in_array($currentStatus, $statuses, true)) {
                    $statuses[] = $currentStatus;
                  }
                  ?>

                  <select class="form-control form-control-sm item-status-select" data-item-id="<?= (int) $it['id'] ?>">
                    <?php foreach ($statuses as $s): ?>
                      <option value="<?= h($s) ?>" <?= ($currentStatus === $s ? 'selected' : '') ?>>
                        <?= h(str_replace('_', ' ', $s)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <?php
                $productUrl = itemProductUrl($order, $it);
                // --- Printing settings (stored in internal_options_json) ---
                $internalOptRaw = (string) ($it['internal_options_json'] ?? '{}');
                if (trim($internalOptRaw) === '') $internalOptRaw = '{}';
                $internalOptArr = json_decode($internalOptRaw, true);
                if (!is_array($internalOptArr)) $internalOptArr = [];
                // Also read base-material / graphics-finish from options_json
                $extOptArr = json_decode((string)($it['options_json'] ?? '{}'), true);
                if (!is_array($extOptArr)) $extOptArr = [];

                $printPrinter  = (string)($internalOptArr['_printer']        ?? '');
                $printMaterial = (string)($internalOptArr['_print_material']  ?? ($extOptArr['base-material'] ?? ''));
                $printFinish   = (string)($internalOptArr['_print_finish']    ?? ($extOptArr['graphics-finish'] ?? ''));
                $isGraphicsItem = (strtoupper(trim((string)($it['item_type_code'] ?? ''))) === 'G');
                $canEditPrint = ((int)($_SESSION['permission'] ?? 0) >= 300);
                ?>

                <?php if ($isGraphicsItem): ?>
                <td class="print-settings-cell" style="min-width:170px;">
                  <?php if ($canEditPrint): ?>
                    <div class="print-setting-field mb-1" style="position:relative;">
                      <input type="text"
                        class="form-control form-control-sm item-print-printer print-ac-input"
                        data-ac-key="printer"
                        data-item-id="<?= (int)$it['id'] ?>"
                        value="<?= h($printPrinter) ?>"
                        placeholder="🖨️ Printer"
                        autocomplete="off">
                      <div class="print-ac-dropdown" style="display:none;"></div>
                    </div>
                    <div class="print-setting-field mb-1" style="position:relative;">
                      <input type="text"
                        class="form-control form-control-sm item-print-material print-ac-input"
                        data-ac-key="material"
                        data-item-id="<?= (int)$it['id'] ?>"
                        value="<?= h($printMaterial) ?>"
                        placeholder="🧱 Material"
                        autocomplete="off">
                      <div class="print-ac-dropdown" style="display:none;"></div>
                    </div>
                    <div class="print-setting-field" style="position:relative;">
                      <input type="text"
                        class="form-control form-control-sm item-print-finish print-ac-input"
                        data-ac-key="finish"
                        data-item-id="<?= (int)$it['id'] ?>"
                        value="<?= h($printFinish) ?>"
                        placeholder="✨ Finish"
                        autocomplete="off">
                      <div class="print-ac-dropdown" style="display:none;"></div>
                    </div>
                  <?php else: ?>
                    <div class="small">
                      <?php if ($printPrinter !== ''): ?><div>🖨️ <?= h($printPrinter) ?></div><?php endif; ?>
                      <?php if ($printMaterial !== ''): ?><div>🧱 <?= h($printMaterial) ?></div><?php endif; ?>
                      <?php if ($printFinish !== ''): ?><div>✨ <?= h($printFinish) ?></div><?php endif; ?>
                      <?php if ($printPrinter === '' && $printMaterial === '' && $printFinish === ''): ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <?php else: ?>
                <td class="text-center text-muted print-settings-cell" style="font-size:11px; color:#555 !important;">
                  <span title="Printing settings sa netýka tejto kategórie">—</span>
                </td>
                <?php endif; ?>

                <td class="text-center">
                  <?php if ($productUrl !== ''): ?>
                    <a href="<?= h($productUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info"
                      title="<?= h($productUrl) ?>">
                      <i class="fas fa-external-link-alt mr-1"></i> Product
                    </a>
                  <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-warning btn-set-product-url"
                      data-item-id="<?= (int) $it['id'] ?>">
                      Set URL
                    </button>
                  <?php endif; ?>
                </td>

                <?php
                $rawOptions = (string) ($it['options_json'] ?? '{}');
                $formattedOptions = prepareOptionsJsonForModal($conn, $rawOptions);
                $editableOptions = prepareEditableOptionsJsonForModal($rawOptions);
                // Strip _printer/_print_material/_print_finish from modal display — they are shown separately
                $internalOptForModal = $internalOptArr;
                unset($internalOptForModal['_printer'], $internalOptForModal['_print_material'], $internalOptForModal['_print_finish']);
                $internalOptions = json_encode($internalOptForModal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
                ?>
                <td class="text-center">
                  <button type="button" class="btn btn-xs btn-outline-info btn-view-options"
                    data-item-id="<?= (int) $it['id'] ?>" data-options="<?= h($formattedOptions) ?>"
                    data-options-raw="<?= h($editableOptions) ?>"
                    data-can-edit-options="<?= ((int) ($_SESSION['permission'] ?? 0) >= 300 ? '1' : '0') ?>"
                    data-internal-options="<?= h($internalOptions) ?>"
                    data-is-graphics="<?= $isGraphicsItem ? '1' : '0' ?>"
                    data-print-printer="<?= h($printPrinter) ?>"
                    data-print-material="<?= h($printMaterial) ?>"
                    data-print-finish="<?= h($printFinish) ?>">
                    Detail
                  </button>
                </td>

                <td class="text-center">
                  <button type="button" class="btn btn-xs btn-outline-warning btn-copy-options"
                    data-options="<?php echo h($formattedOptions); ?>">
                    Copy
                  </button>
                </td>

                <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                  <td class="text-center">
                    <button type="button" class="btn btn-xs btn-outline-success btn-save-item"
                      data-id="<?php echo (int) $it['id']; ?>" data-order-id="<?php echo (int) $orderId; ?>">
                      Save
                    </button>
                  </td>

                  <td class="text-center">
                    <button type="button" class="btn btn-xs btn-outline-danger btn-delete-order-item"
                      data-item-id="<?php echo (int) $it['id']; ?>" data-order-id="<?php echo (int) $orderId; ?>">
                      Delete
                    </button>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
          <hr />

          <button type="button" class="btn btn-sm btn-outline-info btn-toggle-activity"
            data-order-id="<?php echo (int) $orderId; ?>">
            Activity log
          </button>

          <div class="activity-log-panel mt-2" style="display:none;">
            <?php
            $actStmt = $conn->prepare("SELECT
        oa.id,
        oa.action,
        oa.entity_type,
        oa.entity_id,
        oa.payload,
        oa.note,
        oa.created_at,
        COALESCE(
        NULLIF(TRIM(CONCAT(e.firstname, ' ', e.lastname)), ''),
        NULLIF(TRIM(CONCAT(ec.firstname, ' ', ec.lastname)), ''),
        CONCAT('Employee #', COALESCE(oa.actor_employee_id, JSON_UNQUOTE(JSON_EXTRACT(oa.payload, '$.created_by'))))
      ) AS actor_name
      FROM order_activity oa
      LEFT JOIN employees e ON e.id = oa.actor_employee_id
      LEFT JOIN employees ec ON ec.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(oa.payload, '$.created_by')) AS UNSIGNED)
      WHERE oa.order_id = ?
      ORDER BY oa.id DESC
      LIMIT 30
    ");
            $actStmt->bind_param('i', $orderId);
            $actStmt->execute();
            $actRes = $actStmt->get_result();
            ?>

            <div class="small activity-log-list">
              <?php while ($a = $actRes->fetch_assoc()): ?>
                <div class="py-1 activity-log-row" style="border-bottom: 1px solid rgba(255,255,255,0.08);">
                  <span class="text-muted"><?php echo h($a['created_at']); ?></span>
                  —
                  <b><?php echo h($a['actor_name'] ?? 'System'); ?></b>
                  :
                  <?php
                  $actorName = (string) ($a['actor_name'] ?? 'System');
                  $rawActivity = trim((string) ($a['note'] ?? ''));

                  if ($rawActivity === '') {
                    $rawActivity = trim((string) ($a['action'] ?? ''));
                  }

                  $activityText = preg_replace('/\s*\[created_by\s*:\s*\d+\]\s*/i', ' ', $rawActivity);
                  $activityText = trim((string) $activityText);
                  ?>
                  <span><?php echo h($activityText); ?></span>
                </div>
              <?php endwhile; ?>
            </div>

            <?php $actStmt->close(); ?>

            <button type="button" class="btn btn-xs btn-outline-secondary mt-2 btn-load-older-activity"
              data-order-id="<?php echo (int) $orderId; ?>" data-offset="30">
              Load older
            </button>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<script>
/* ── Printing Settings: Autocomplete + Save-on-Enter ─────────────────────── */
(function () {
  'use strict';

  // Cache for suggestions per key (printer / material / finish)
  var acCache = {};

  function fetchSuggestions(key, query, cb) {
    var cacheKey = key + ':' + query;
    if (acCache[cacheKey] !== undefined) { cb(acCache[cacheKey]); return; }
    $.post('scripts/orders/get_print_suggestions.php', { key: key, q: query }, function (res) {
      if (res && res.ok && Array.isArray(res.items)) {
        acCache[cacheKey] = res.items;
        cb(res.items);
      } else {
        cb([]);
      }
    }, 'json').fail(function () { cb([]); });
  }

  function showDropdown($input, items) {
    var $drop = $input.siblings('.print-ac-dropdown');
    if (!items.length) { $drop.hide().empty(); return; }
    $drop.empty();
    items.forEach(function (val) {
      $('<div class="print-ac-item">').text(val).on('mousedown', function (e) {
        e.preventDefault();
        $input.val(val).trigger('change');
        $drop.hide().empty();
      }).appendTo($drop);
    });
    $drop.show();
  }

  function hideAllDropdowns() {
    $('.print-ac-dropdown').hide().empty();
  }

  function savePrintSettings($tr, itemId, orderId) {
    var printer  = $tr.find('.item-print-printer').val()  || '';
    var material = $tr.find('.item-print-material').val() || '';
    var finish   = $tr.find('.item-print-finish').val()   || '';

    // Read existing internal_options_json from the Detail button's data attribute
    var $detailBtn = $tr.find('.btn-view-options');
    var existing = {};
    try { existing = JSON.parse($detailBtn.attr('data-internal-options') || '{}'); } catch(e) {}

    existing['_printer']       = printer;
    existing['_print_material']= material;
    existing['_print_finish']  = finish;

    var newJson = JSON.stringify(existing);

    $.post('scripts/orders/update_item_internal_options.php', {
      item_id: itemId,
      internal_options_json: newJson
    }, function (res) {
      if (!res || !res.ok) {
        alert(res && res.error ? res.error : 'Save failed');
        return;
      }
      // Update data attr on Detail button so modal will reflect new values
      $detailBtn.attr('data-internal-options', newJson);
      $detailBtn.attr('data-print-printer', printer);
      $detailBtn.attr('data-print-material', material);
      $detailBtn.attr('data-print-finish', finish);
      // Brief visual feedback
      $tr.find('.print-settings-cell input').css('border-color', '#28a745');
      setTimeout(function () {
        $tr.find('.print-settings-cell input').css('border-color', '');
      }, 1000);
    }, 'json').fail(function () { alert('Request failed'); });
  }

  // Input events — autocomplete
  $(document).on('input', '.print-ac-input', function () {
    var $inp  = $(this);
    var key   = $inp.data('ac-key');
    var query = $inp.val().trim();
    if (query.length === 0) { hideAllDropdowns(); return; }
    fetchSuggestions(key, query, function (items) { showDropdown($inp, items); });
  });

  // Keyboard navigation + Enter to save
  $(document).on('keydown', '.print-ac-input', function (e) {
    var $inp  = $(this);
    var $drop = $inp.siblings('.print-ac-dropdown');
    var $items = $drop.find('.print-ac-item');

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      var $active = $items.filter('.active');
      if ($active.length) { $active.removeClass('active').next().addClass('active'); }
      else { $items.first().addClass('active'); }
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      var $active2 = $items.filter('.active');
      if ($active2.length) { $active2.removeClass('active').prev().addClass('active'); }
    } else if (e.key === 'Enter') {
      e.preventDefault();
      var $active3 = $items.filter('.active');
      if ($active3.length) {
        $inp.val($active3.text());
        $drop.hide().empty();
      }
      // Always save on Enter regardless of dropdown state
      var $tr    = $inp.closest('tr');
      var itemId = $inp.data('item-id');
      var orderId = $tr.closest('.order-detail-card').find('[data-order-id]').first().data('order-id');
      savePrintSettings($tr, itemId, orderId);
    } else if (e.key === 'Escape') {
      $drop.hide().empty();
    }
  });

  // Close dropdown on outside click
  $(document).on('click', function (e) {
    if (!$(e.target).closest('.print-setting-field').length) {
      hideAllDropdowns();
    }
  });

  // ── Modal: show Printing Settings block ─────────────────────────────────
  $(document).on('click', '.btn-view-options', function () {
    var $btn       = $(this);
    var isGraphics = $btn.data('is-graphics') === 1 || $btn.data('is-graphics') === '1';
    var printer    = $.trim($btn.data('print-printer')  || '');
    var material   = $.trim($btn.data('print-material') || '');
    var finish     = $.trim($btn.data('print-finish')   || '');

    // Remove any previous block
    $('#printingSettingsModalBlock').remove();

    var $modal = $('#optionsModal');

    if (isGraphics) {
      var hasSomething = printer !== '' || material !== '' || finish !== '';
      var $block = $('<div id="printingSettingsModalBlock" class="printing-settings-block mb-3">');
      $block.append('<h6 class="text-info mb-3"><i class="fas fa-print mr-2"></i>Printing Settings</h6>');

      var $row = $('<div class="row">');

      function psCol(label, val) {
        var $col = $('<div class="col-sm-4 mb-2">');
        $col.append('<div class="ps-label">' + label + '</div>');
        $col.append('<div class="ps-value">' + (val !== '' ? $('<span>').text(val).html() : '<span class="text-muted">—</span>') + '</div>');
        return $col;
      }

      $row.append(psCol('🖨️ Printer', printer));
      $row.append(psCol('🧱 Material', material));
      $row.append(psCol('✨ Finish', finish));
      $block.append($row);

      if (!hasSomething) {
        $block.append('<p class="text-muted small mb-0">Žiadne print nastavenia ešte neboli vyplnené.</p>');
      }

      // Prepend before the imported options section inside modal body
      $modal.find('.modal-body').prepend($block);
    }
  });

})();
</script>
<?php
$html = ob_get_clean();
out(200, ['ok' => true, 'html' => $html]);
?>