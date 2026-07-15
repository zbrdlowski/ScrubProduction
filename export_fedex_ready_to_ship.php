<?php
declare(strict_types=1);

/**
 * Export FedEx Stratus CSV pre objednavky v stave READY_TO_SHIP.
 *
 * Pouzitie:
 *  - polozit tento subor do rovnakeho adresara ako orders.php (aby fungoval require_once __DIR__.'/conn.php')
 *  - otvorit v prehliadaci: export_fedex_ready_to_ship.php
 *    -> stiahne sa CSV subor pripraveny na import do FedEx Stratus
 *
 * DOPLNIT / SKONTROLOVAT PRED PRVYM POUZITIM:
 *  1) $WEIGHT_MAP nizsie - mam len par hodnot z tvojho priloheneho priesladu (GFP=6, TFP=5, P=5).
 *     Ostatne kombinacie (S, G, F, GFS, GPS, FPS, GFPS, TFPS, M, ...) su TODO - doplnit realnu hmotnost v kg.
 *  2) Stlpec "kod statu US+CA" (US-state, napr. KY, MI, WI) - v order_addresses som nenasiel
 *     samostatny stlpec pre stat/region. Necham prazdne (viz funkcia resolveUsState nizsie) -
 *     ak mate tento udaj niekde inde v DB, treba doplnit tu.
 *  3) "servis" je fixne "economy" - ak sa niekedy meni, treba doplnit logiku (napr. podla shipping_method).
 */

require_once __DIR__ . '/includes/conn.php';

if (!isset($conn) || !$conn instanceof mysqli) {
  http_response_code(500);
  die('Database connection error.');
}

// ---------------------------------------------------------------------
// 1) Mapovanie item_types (normalizovane G,F,P,S poradie + T,M na konci)
//    -> Material composition text (podla tvojej SWITCH funkcie v Sheets)
// ---------------------------------------------------------------------
$MATERIAL_MAP = [
  'G'    => 'Polepy / Graphics kit (Stickers) for motorcycles made of vinyl film',
  'S'    => 'Potah sedadla / Seat cover for motorcycles produced of rubberized vinyl',
  'P'    => 'Plasty / Plastics kit (set of plastic parts) for motorcycle produced of Polypropylene (PP)',
  'GFP'  => 'GFP / PP Plastics kit with applied Stickers for motorcycles',
  'TFP'  => 'TFP / PP Plastics kit with applied Stickers for motorcycles',
  'GFPS' => 'GFPS / PP Plastics kit with applied Stickers for motorcycles with seat cover in the same design',
  'TFPS' => 'GFPS / PP Plastics kit with applied Stickers for motorcycles with seat cover in the same design',
  'M'    => 'Koberec / Carpet - motorcycle mat 100% Nylon - Velours',
];
// Fallback pre kombinacie, ktore nie su vyssie (podla tvojho SWITCH defaultu)
$MATERIAL_DEFAULT = 'Plasty / Plastics kit (set of plastic parts) for motorcycle produced of Polypropylene (PP)';

// ---------------------------------------------------------------------
// 2) Hmotnost (kg) podla item_types - TODO: doplnit chybajuce hodnoty
// ---------------------------------------------------------------------
$WEIGHT_MAP = [
  'GFP'  => 6,
  'TFP'  => 5,
  'P'    => 5,
  'G'    => 1, // TODO
  'F'    => 0, // TODO
  'S'    => 0.5, // TODO
  'M'    => 6, // TODO
  'GFS'  => 3, // TODO
  'GPS'  => 6, // TODO
  'FPS'  => 6, // TODO
  'GFPS' => 6, // TODO
  'TFPS' => 6, // TODO
];
$WEIGHT_DEFAULT = 5; // pouzije sa, ak kombinacia chyba alebo je null - uprav podla potreby

// ---------------------------------------------------------------------
// 3) HS Code - konstantny pre vsetok tovar (podla priloheneho priesladu)
// ---------------------------------------------------------------------
const HS_CODE = '8714109000';

// ---------------------------------------------------------------------
// 4) Poistna hodnota - replika Google Sheets schodovej funkcie
// ---------------------------------------------------------------------
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

// ---------------------------------------------------------------------
// 5) Normalizacia poradia item_types (rovnaka logika ako v orders.php)
// ---------------------------------------------------------------------
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

// ---------------------------------------------------------------------
// 6) US / CA - kod statu/provincie
//    US cast je 1:1 skopirovana z get_order_detail.php (funkcia usStateFromZip).
//    CA cast (podla prveho pismena postal code) som doplnil ja, kedze v projekte
//    som ziadnu existujucu funkciu pre Kanadu nenasiel - over si prosim spravnost.
// ---------------------------------------------------------------------
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

// TODO: over spravnost - v projekte som ekvivalent pre Kanadu nenasiel,
// toto je standardna mapa podla prveho pismena postal code.
function caProvinceFromPostal(string $postal): string
{
  $postal = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $postal));
  if ($postal === '') {
    return '';
  }
  $firstLetter = substr($postal, 0, 1);

  $map = [
    'A' => 'NL', 'B' => 'NS', 'C' => 'PE', 'E' => 'NB',
    'G' => 'QC', 'H' => 'QC', 'J' => 'QC',
    'K' => 'ON', 'L' => 'ON', 'M' => 'ON', 'N' => 'ON', 'P' => 'ON',
    'R' => 'MB', 'S' => 'SK', 'T' => 'AB', 'V' => 'BC',
    'X' => 'NT', 'Y' => 'YT',
  ];

  return $map[$firstLetter] ?? '';
}

function resolveUsState(array $row): string
{
  $country = strtoupper(trim((string) ($row['ship_country'] ?? '')));
  $zip     = (string) ($row['ship_zip'] ?? '');

  if ($country === 'US' || $country === 'USA') {
    return usStateFromZip($zip);
  }
  if ($country === 'CA' || $country === 'CAN') {
    return caProvinceFromPostal($zip);
  }
  return '';
}

// ---------------------------------------------------------------------
// Query: vsetky objednavky READY_TO_SHIP s dorucovacou adresou
// ---------------------------------------------------------------------
$sql = "SELECT
  o.id,
  o.order_number,
  o.external_order_id,
  o.total AS order_total,

  cu.name  AS customer_name,
  cu.email AS customer_email,

  COALESCE(oa_ship.name, cu.name)       AS ship_name,
  COALESCE(oa_ship.company, '')         AS ship_company,
  COALESCE(oa_ship.street, '')          AS ship_street,
  COALESCE(oa_ship.city, '')            AS ship_city,
  COALESCE(oa_ship.zip, '')             AS ship_zip,
  COALESCE(oa_ship.country, '')         AS ship_country,
  COALESCE(oa_ship.phone, '')           AS ship_phone,
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
WHERE o.status = 'READY_TO_SHIP'
ORDER BY o.id ASC";

$res = $conn->query($sql);
if (!$res) {
  http_response_code(500);
  die('Query error: ' . $conn->error);
}

// ---------------------------------------------------------------------
// CSV output
// ---------------------------------------------------------------------
$filename = 'fedex_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

fputcsv($out, [
  'nazov firmy', 'meno', 'adresa', 'mesto', 'psc', 'stat', 'kod statu US+CA',
  'tel', 'e-mail', 'referencne_cislo', 'nazov faktury', 'colna hodnota',
  'servis', 'platca cla', 'poistit', 'HS Code', 'Material composition', 'Weight',
]);

while ($row = $res->fetch_assoc()) {
  $typesRaw   = (string) ($row['item_types'] ?? '');
  $typesNorm  = normalizeTypesOrder($typesRaw);
  $orderTotal = (float) ($row['order_total'] ?? 0);

  $refNumber = trim($typesNorm . ' - ' . ((string) ($row['order_number'] ?? '') !== ''
    ? $row['order_number']
    : $row['external_order_id']));

  $invoiceName = trim((string) ($row['invoice_number'] ?? ''));
  if ($invoiceName === '') {
    $invoiceName = 'Invoice';
  }

  $material = $MATERIAL_MAP[$typesNorm] ?? $MATERIAL_DEFAULT;
  $weight   = $WEIGHT_MAP[$typesNorm] ?? $WEIGHT_DEFAULT;
  if ($weight === null) {
    $weight = $WEIGHT_DEFAULT;
  }

  fputcsv($out, [
    $row['ship_company'],                 // nazov firmy
    $row['ship_name'],                    // meno
    $row['ship_street'],                  // adresa
    $row['ship_city'],                    // mesto
    $row['ship_zip'],                     // psc
    $row['ship_country'],                 // stat
    resolveUsState($row),                 // kod statu US+CA (TODO)
    $row['ship_phone'],                   // tel
    $row['ship_email'],                   // e-mail
    $refNumber,                           // referencne_cislo
    $invoiceName,                         // nazov faktury
    number_format($orderTotal, 2, '.', ''), // colna hodnota
    'economy',                            // servis
    2,                                    // platca cla (2 = prijemca)
    number_format(calcInsuredValue($orderTotal), 2, '.', ''), // poistit
    HS_CODE,                              // HS Code
    $material,                            // Material composition
    $weight,                              // Weight
  ]);
}

fclose($out);
$conn->close();
