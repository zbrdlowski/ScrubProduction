<?php
declare(strict_types=1);

// Tento skript bezi mimo index.php routera (kvoli CSV/http headers),
// takze si musi session a auth nastartovat sam - rovnaky pattern ako
// napr. v get_order_detail.php.
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!isset($_SESSION['permission'])) {
  http_response_code(403);
  die('Not logged in.');
}

require_once __DIR__ . '/includes/conn.php';

if (!isset($conn) || !$conn instanceof mysqli) {
  http_response_code(500);
  die('Database connection error.');
}

// ---------------------------------------------------------------------
// Department ACL - 1:1 skopirovane z orders.php, aby export ukazoval
// presne tie objednavky, ktore by dany pouzivatel videl aj v zozname.
//
// dept=-1  => "All Orders" - vedome obchadza ACL pre kohokolvek
//             (napr. telefonat od zakaznika mimo vlastneho oddelenia,
//             alebo hromadny export pre cely tim).
// dept=0   => Auto, podla vlastneho oddelenia pouzivatela
// dept=N   => konkretne oddelenie - respektuje sa pre kohokolvek (rovnako ako
//             v orders.php), kedze detail objednavky uz tiez nie je blokovany
//             podla oddelenia.
// ---------------------------------------------------------------------
$dpt = (int) ($_SESSION['dpt'] ?? 0);
$allAccess = in_array($dpt, [1, 3, 4, 5, 7], true);

if (isset($_GET['dept'])) {
  // Explicitny parameter (najspolahlivejsi zdroj) - toto by mal posielat JS modal.
  $fDept = (int) $_GET['dept'];
} else {
  // FALLBACK: ?dept= nebolo poslane (JS modal ho zatial neposiela).
  // Skusime ho vytiahnut z Referer hlavicky - teda z URL zoznamu objednavok,
  // odkial sa export otvoril (napr. .../index.php?page=orders&status=READY_TO_SHIP&dept=-1).
  // Pozor: Referer nemusi byt vzdy dostupny (blokovany prehliadacom/rozsirenim),
  // takze toto je len docasna berlicka - spravne riesenie je poslat dept explicitne z JS.
  $fDept = 0;
  $referer = $_SERVER['HTTP_REFERER'] ?? '';
  if ($referer !== '') {
    $refQuery = parse_url($referer, PHP_URL_QUERY);
    if ($refQuery) {
      parse_str($refQuery, $refParams);
      if (isset($refParams['dept'])) {
        $fDept = (int) $refParams['dept'];
      }
    }
  }
}

$deptFilter = [
  2 => ['GRAPHICS'],
  6 => ['PLASTICS'],
  8 => ['SEATCOVER'],
];
$deptTypeFilter = [
  9 => ['F'],
];

$effectiveDept = ($fDept !== 0) ? $fDept : $dpt;

if ($fDept === -1) {
  $aclCats = [];
  $aclTypes = [];
} elseif ($fDept > 0) {
  $aclCats = $deptFilter[$fDept] ?? [];
  $aclTypes = $deptTypeFilter[$fDept] ?? [];
} else {
  $aclCats = $deptFilter[$dpt] ?? [];
  $aclTypes = $deptTypeFilter[$dpt] ?? [];
}

// Debug: otvor export_fedex_ready_to_ship.php?debug_acl=1 (pripadne aj &dept=-1)
// - vypise, co presne skript vyhodnotil, bez generovania CSV. Uzitocne na overenie,
// ci sa dept parameter spravne prenasa z JS modalu / Refereru.
// Pristupne len pre superadmina (permission === 900).
if (isset($_GET['debug_acl'])) {
  if ((int) ($_SESSION['permission'] ?? 0) !== 900) {
    http_response_code(403);
    die('Forbidden.');
  }
  header('Content-Type: text/plain; charset=utf-8');
  echo "session dpt: $dpt\n";
  echo "allAccess: " . ($allAccess ? 'true' : 'false') . "\n";
  echo "GET dept: " . (isset($_GET['dept']) ? $_GET['dept'] : '(nenastavene)') . "\n";
  echo "Referer: " . ($_SERVER['HTTP_REFERER'] ?? '(ziadny)') . "\n";
  echo "resolved fDept: $fDept\n";
  echo "effectiveDept: $effectiveDept\n";
  echo "aclCats: " . json_encode($aclCats) . "\n";
  echo "aclTypes: " . json_encode($aclTypes) . "\n";
  $conn->close();
  exit;
}

// Rovnaka FITTING detekcia ako v orders.php (typ F cez viacero moznych zdrojov)
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

function buildAclWhere(mysqli $conn, array $aclCats, array $aclTypes, string $fitWhere): array
{
  $clauses = [];
  $types = '';
  $params = [];

  if (!empty($aclCats)) {
    $ph = implode(',', array_fill(0, count($aclCats), '?'));
    $clauses[] = "EXISTS (
      SELECT 1
      FROM order_categories ocx
      JOIN categories cx ON cx.id = ocx.category_id
      WHERE ocx.order_id = o.id AND cx.code IN ($ph)
    )";
    $types .= str_repeat('s', count($aclCats));
    foreach ($aclCats as $c) {
      $params[] = $c;
    }
  }

  if (!empty($aclTypes) && in_array('F', $aclTypes, true)) {
    $clauses[] = $fitWhere;
  }

  return [$clauses, $types, $params];
}

$MATERIAL_MAP = [
  'G' => 'Polepy / Graphics kit (Stickers) for motorcycles made of vinyl film',
  'S' => 'Potah sedadla / Seat cover for motorcycles produced of rubberized vinyl',
  'P' => 'Plasty / Plastics kit (set of plastic parts) for motorcycle produced of Polypropylene (PP)',
  'GFP' => 'GFP / PP Plastics kit with applied Stickers for motorcycles',
  'TFP' => 'TFP / PP Plastics kit with applied Stickers for motorcycles',
  'GFPS' => 'GFPS / PP Plastics kit with applied Stickers for motorcycles with seat cover in the same design',
  'TFPS' => 'GFPS / PP Plastics kit with applied Stickers for motorcycles with seat cover in the same design',
  'M' => 'Koberec / Carpet - motorcycle mat 100% Nylon - Velours',
];
$MATERIAL_DEFAULT = 'Plasty / Plastics kit (set of plastic parts) for motorcycle produced of Polypropylene (PP)';

$WEIGHT_MAP = [
  'GFP' => 6,
  'TFP' => 5,
  'P' => 5,
  'G' => 1,
  'F' => 0,
  'S' => 0.5,
  'M' => 6,
  'GFS' => 3,
  'GPS' => 6,
  'FPS' => 6,
  'GFPS' => 6,
  'TFPS' => 6,
];
$WEIGHT_DEFAULT = 5;

const HS_CODE = '8714109000';

function calcInsuredValue(float $value): float
{
  $breakpoints = [164, 246, 328, 410, 492, 574, 656, 738, 820, 902, 984];

  if ($value <= $breakpoints[0]) {
    return round($value, 2);
  }

  $insured = $breakpoints[0];
  foreach ($breakpoints as $bp) {
    if ($value > $bp) {
      $insured = $bp;
    } else {
      break;
    }
  }

  return (float) $insured;
}

function normalizeTypesOrder(string $types): string
{
  $weights = ['G' => 1, 'F' => 2, 'P' => 3, 'S' => 4];
  $typesArr = str_split(strtoupper($types));

  usort($typesArr, function ($a, $b) use ($weights) {
    $wa = $weights[$a] ?? 99;
    $wb = $weights[$b] ?? 99;
    return $wa <=> $wb;
  });

  return implode('', $typesArr);
}

function usStateFromZip(string $zip): string
{
  $zip = preg_replace('/\D+/', '', $zip);
  if (strlen($zip) < 5) {
    return '';
  }
  $n = (int) substr($zip, 0, 5);

  $ranges = [
    'AL' => [[35000, 36999]], 'AK' => [[99500, 99999]], 'AZ' => [[85000, 86999]],
    'AR' => [[71600, 72999]], 'CA' => [[90000, 96699]], 'CO' => [[80000, 81999]],
    'CT' => [[6000, 6999]], 'DE' => [[19700, 19999]],
    'DC' => [[20000, 20099], [20200, 20599], [56900, 56999]],
    'FL' => [[32000, 34999]], 'GA' => [[30000, 31999], [39800, 39999]],
    'HI' => [[96700, 96899]], 'ID' => [[83200, 83999]], 'IL' => [[60000, 62999]],
    'IN' => [[46000, 47999]], 'IA' => [[50000, 52999]], 'KS' => [[66000, 67999]],
    'KY' => [[40000, 42999]], 'LA' => [[70000, 71599]], 'ME' => [[3900, 4999]],
    'MD' => [[20600, 21999]], 'MA' => [[1000, 2799], [5500, 5599]],
    'MI' => [[48000, 49999]], 'MN' => [[55000, 56799]], 'MS' => [[38600, 39799]],
    'MO' => [[63000, 65999]], 'MT' => [[59000, 59999]], 'NE' => [[68000, 69999]],
    'NV' => [[88900, 89999]], 'NH' => [[3000, 3899]], 'NJ' => [[7000, 8999]],
    'NM' => [[87000, 88499]], 'NY' => [[10000, 14999], [500, 599], [6390, 6390]],
    'NC' => [[27000, 28999]], 'ND' => [[58000, 58999]], 'OH' => [[43000, 45999]],
    'OK' => [[73000, 74999]], 'OR' => [[97000, 97999]], 'PA' => [[15000, 19699]],
    'RI' => [[2800, 2999]], 'SC' => [[29000, 29999]], 'SD' => [[57000, 57999]],
    'TN' => [[37000, 38599]], 'TX' => [[75000, 79999], [88500, 88599]],
    'UT' => [[84000, 84999]], 'VT' => [[5000, 5999]], 'VA' => [[20100, 24699]],
    'WA' => [[98000, 99499]], 'WV' => [[24700, 26999]], 'WI' => [[53000, 54999]],
    'WY' => [[82000, 83199]],
  ];

  foreach ($ranges as $state => $rs) {
    foreach ($rs as $r) {
      if ($n >= $r[0] && $n <= $r[1]) {
        return $state;
      }
    }
  }

  return '';
}

function caProvinceFromPostal(string $postal): string
{
  $postal = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $postal));
  if ($postal === '') {
    return '';
  }

  $map = [
    'A' => 'NL', 'B' => 'NS', 'C' => 'PE', 'E' => 'NB',
    'G' => 'QC', 'H' => 'QC', 'J' => 'QC',
    'K' => 'ON', 'L' => 'ON', 'M' => 'ON', 'N' => 'ON', 'P' => 'ON',
    'R' => 'MB', 'S' => 'SK', 'T' => 'AB', 'V' => 'BC',
    'X' => 'NT', 'Y' => 'YT',
  ];

  return $map[substr($postal, 0, 1)] ?? '';
}

function resolveUsState(array $row): string
{
  $country = strtoupper(trim((string) ($row['ship_country'] ?? '')));
  $zip = (string) ($row['ship_zip'] ?? '');

  if ($country === 'US' || $country === 'USA') {
    return usStateFromZip($zip);
  }
  if ($country === 'CA' || $country === 'CAN') {
    return caProvinceFromPostal($zip);
  }

  return '';
}

function fetchReadyToShipOrders(mysqli $conn, array $aclCats, array $aclTypes, string $fitWhere): array
{
  [$aclClauses, $aclTypesStr, $aclParams] = buildAclWhere($conn, $aclCats, $aclTypes, $fitWhere);

  $where = ["o.status = 'READY_TO_SHIP'"];
  foreach ($aclClauses as $c) {
    $where[] = $c;
  }
  $whereSql = implode(' AND ', $where);

  $sql = "SELECT
    o.id,
    o.order_number,
    o.external_order_id,
    o.total AS order_total,

    cu.name AS customer_name,
    cu.email AS customer_email,

    COALESCE(oa_ship.name, cu.name) AS ship_name,
    COALESCE(oa_ship.company, '') AS ship_company,
    COALESCE(oa_ship.street, '') AS ship_street,
    COALESCE(oa_ship.city, '') AS ship_city,
    COALESCE(oa_ship.zip, '') AS ship_zip,
    COALESCE(oa_ship.country, '') AS ship_country,
    COALESCE(oa_ship.phone, '') AS ship_phone,
    COALESCE(oa_ship.email, cu.email, '') AS ship_email,

    (
      SELECT GROUP_CONCAT(DISTINCT oi.item_type_code ORDER BY oi.item_type_code SEPARATOR '')
      FROM order_items oi
      WHERE oi.order_id = o.id
        AND oi.item_type_code IS NOT NULL
        AND oi.item_type_code <> ''
    ) AS item_types,

    (
      SELECT oinv.invoice_number
      FROM order_invoices oinv
      WHERE oinv.order_id = o.id
      ORDER BY oinv.id DESC
      LIMIT 1
    ) AS invoice_number

  FROM orders o
  LEFT JOIN customers cu ON cu.id = o.customer_id
  LEFT JOIN order_addresses oa_ship
    ON oa_ship.order_id = o.id AND UPPER(oa_ship.type) = 'SHIPPING'
  WHERE $whereSql
  ORDER BY o.id ASC";

  if (empty($aclParams)) {
    $res = $conn->query($sql);
    if (!$res) {
      http_response_code(500);
      die('Query error: ' . $conn->error);
    }
  } else {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      http_response_code(500);
      die('Prepare error: ' . $conn->error);
    }
    $stmt->bind_param($aclTypesStr, ...$aclParams);
    $stmt->execute();
    $res = $stmt->get_result();
  }

  $rows = [];
  while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
  }

  return $rows;
}

function buildExportRows(array $orders, array $materialMap, string $materialDefault, array $weightMap, float $weightDefault): array
{
  $exportRows = [];

  foreach ($orders as $row) {
    $typesRaw = (string) ($row['item_types'] ?? '');
    $typesNorm = normalizeTypesOrder($typesRaw);
    $orderTotal = (float) ($row['order_total'] ?? 0);

    $orderNumber = trim((string) ($row['order_number'] ?? ''));
    $externalOrderId = trim((string) ($row['external_order_id'] ?? ''));
    $refNumber = trim($typesNorm . ' - ' . ($orderNumber !== '' ? $orderNumber : $externalOrderId), ' -');

    $invoiceName = trim((string) ($row['invoice_number'] ?? ''));
    if ($invoiceName === '') {
      $invoiceName = 'Invoice';
    }

    $material = $materialMap[$typesNorm] ?? $materialDefault;
    $weight = $weightMap[$typesNorm] ?? $weightDefault;
    if ($weight === null || $weight === '') {
      $weight = $weightDefault;
    }

    $exportRows[(int) $row['id']] = [
      'order_id' => (int) $row['id'],
      'order_number' => $orderNumber,
      'external_order_id' => $externalOrderId,
      'types' => $typesNorm,
      'company' => (string) ($row['ship_company'] ?? ''),
      'name' => (string) ($row['ship_name'] ?? ''),
      'address' => (string) ($row['ship_street'] ?? ''),
      'city' => (string) ($row['ship_city'] ?? ''),
      'zip' => (string) ($row['ship_zip'] ?? ''),
      'country' => (string) ($row['ship_country'] ?? ''),
      'state_code' => resolveUsState($row),
      'phone' => (string) ($row['ship_phone'] ?? ''),
      'email' => (string) ($row['ship_email'] ?? ''),
      'ref_number' => $refNumber,
      'invoice_name' => $invoiceName,
      'customs_value' => number_format($orderTotal, 2, '.', ''),
      'service' => 'economy',
      'duty_payer' => '2',
      'insured_value' => number_format(calcInsuredValue($orderTotal), 2, '.', ''),
      'hs_code' => HS_CODE,
      'material' => $material,
      'weight' => is_numeric((string) $weight) ? number_format((float) $weight, 2, '.', '') : (string) $weight,
    ];
  }

  return $exportRows;
}

function applyOverrides(array $defaultRows, array $submittedRows): array
{
  $allowedFields = [
    'company', 'name', 'address', 'city', 'zip', 'country', 'state_code', 'phone', 'email',
    'ref_number', 'invoice_name', 'customs_value', 'service', 'duty_payer', 'insured_value',
    'hs_code', 'material', 'weight',
  ];

  $rows = [];
  foreach ($defaultRows as $orderId => $row) {
    $overrides = $submittedRows[$orderId] ?? [];
    if (!is_array($overrides)) {
      $overrides = [];
    }

    foreach ($allowedFields as $field) {
      if (array_key_exists($field, $overrides)) {
        $row[$field] = trim((string) $overrides[$field]);
      }
    }

    $rows[$orderId] = $row;
  }

  return $rows;
}

function renderPreviewRows(array $rows): void
{
  if (!$rows) {
    echo '<div class="alert alert-warning mb-0">No READY_TO_SHIP orders found.</div>';
    return;
  }

  echo '<div class="alert alert-info py-2 px-3 mb-3">';
  echo 'Uprav hodnoty podla potreby a potom klikni na <strong>Generate CSV</strong>.';
  echo '</div>';
  echo '<div class="table-responsive">';
  echo '<table class="table table-dark table-sm table-bordered fedex-preview-table mb-0">';
  echo '<thead><tr>';
  echo '<th style="min-width:110px;">Order</th>';
  echo '<th style="min-width:90px;">Types</th>';
  echo '<th style="min-width:150px;">Company</th>';
  echo '<th style="min-width:150px;">Name</th>';
  echo '<th style="min-width:180px;">Address</th>';
  echo '<th style="min-width:140px;">City</th>';
  echo '<th style="min-width:100px;">ZIP</th>';
  echo '<th style="min-width:90px;">Country</th>';
  echo '<th style="min-width:90px;">State</th>';
  echo '<th style="min-width:130px;">Phone</th>';
  echo '<th style="min-width:170px;">E-mail</th>';
  echo '<th style="min-width:160px;">Reference</th>';
  echo '<th style="min-width:140px;">Invoice</th>';
  echo '<th style="min-width:110px;">Customs</th>';
  echo '<th style="min-width:100px;">Service</th>';
  echo '<th style="min-width:95px;">Duty Payer</th>';
  echo '<th style="min-width:110px;">Insurance</th>';
  echo '<th style="min-width:120px;">HS Code</th>';
  echo '<th style="min-width:280px;">Material</th>';
  echo '<th style="min-width:90px;">Weight</th>';
  echo '</tr></thead><tbody>';

  foreach ($rows as $row) {
    $orderId = (int) $row['order_id'];
    $orderLabel = $row['order_number'] !== '' ? $row['order_number'] : $row['external_order_id'];
    echo '<tr>';
    echo '<td><strong>' . htmlspecialchars($orderLabel) . '</strong><br><small class="text-muted">#' . $orderId . '</small></td>';
    echo '<td>' . htmlspecialchars((string) $row['types']) . '</td>';

    foreach ([
      'company', 'name', 'address', 'city', 'zip', 'country', 'state_code', 'phone', 'email',
      'ref_number', 'invoice_name', 'customs_value', 'service', 'duty_payer', 'insured_value',
      'hs_code', 'material', 'weight',
    ] as $field) {
      echo '<td>';
      echo '<input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" name="rows[' . $orderId . '][' . $field . ']" value="' . htmlspecialchars((string) $row[$field]) . '">';
      echo '</td>';
    }

    echo '</tr>';
  }

  echo '</tbody></table></div>';
}

$orders = fetchReadyToShipOrders($conn, $aclCats, $aclTypes, $fitWhere);
$defaultRows = buildExportRows($orders, $MATERIAL_MAP, $MATERIAL_DEFAULT, $WEIGHT_MAP, $WEIGHT_DEFAULT);

if (isset($_GET['preview'])) {
  renderPreviewRows($defaultRows);
  $conn->close();
  exit;
}

$submittedRows = isset($_POST['rows']) && is_array($_POST['rows']) ? $_POST['rows'] : [];
$exportRows = applyOverrides($defaultRows, $submittedRows);

// FedEx Stratus vie robit problemy s diakritikou/cudzimi znakmi v UTF-8,
// preto vystup konvertujeme do Windows-1252 ("ANSI"). //TRANSLIT skusi znak
// nahradit najblizsim ekvivalentom (napr. e -> e), //IGNORE zahodi znaky,
// ktore sa vobec nedaju previest (namiesto padu skriptu).
function toAnsi(string $value): string
{
  $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
  return $converted === false ? $value : $converted;
}

function ansiRow(array $fields): array
{
  return array_map('toAnsi', $fields);
}

$filename = 'fedex_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=windows-1252');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ansiRow([
  'nazov firmy', 'meno', 'adresa', 'mesto', 'psc', 'stat', 'kod statu US+CA',
  'tel', 'e-mail', 'referencne_cislo', 'nazov faktury', 'colna hodnota',
  'servis', 'platca cla', 'poistit', 'HS Code', 'Material composition', 'Weight',
]));

foreach ($exportRows as $row) {
  fputcsv($out, ansiRow([
    $row['company'],
    $row['name'],
    $row['address'],
    $row['city'],
    $row['zip'],
    $row['country'],
    $row['state_code'],
    $row['phone'],
    $row['email'],
    $row['ref_number'],
    $row['invoice_name'],
    $row['customs_value'],
    $row['service'],
    $row['duty_payer'],
    $row['insured_value'],
    $row['hs_code'],
    $row['material'],
    $row['weight'],
  ]));
}

fclose($out);
$conn->close();