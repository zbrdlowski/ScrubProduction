<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('html_errors', '0');
$labelRtpBaseObLevel = ob_get_level();
ob_start();
register_shutdown_function(function () use ($labelRtpBaseObLevel): void {
  $err = error_get_last();
  if (!$err || !in_array((int)($err['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    return;
  }
  while (ob_get_level() > $labelRtpBaseObLevel) {
    @ob_end_clean();
  }
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
  }
  echo 'Label generation failed';
});

// ── Bootstrap ─────────────────────────────────────────────────────────────────
session_start();
$base = dirname(__DIR__, 2);               // rovnaká logika ako get_order_detail.php
require_once $base . '/includes/conn.php'; // $conn

function gi(string $k): int  { return isset($_GET[$k]) ? (int)$_GET[$k] : 0; }
function gs(string $k): string { return isset($_GET[$k]) ? trim((string)$_GET[$k]) : ''; }

function jsonArr(string $raw): array {
  $d = json_decode($raw !== '' ? $raw : '{}', true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
  return is_array($d) ? $d : [];
}
function labelValueToString($value): string
{
  if (is_array($value)) {
    $parts = [];
    array_walk_recursive($value, static function ($part) use (&$parts): void {
      if (!is_scalar($part)) return;
      $part = trim((string)$part);
      if ($part !== '' && !in_array($part, $parts, true)) {
        $parts[] = $part;
      }
    });
    return implode(' | ', $parts);
  }

  if (is_object($value) || $value === null) {
    return '';
  }

  return trim((string)$value);
}

function labelNormalizeKey(string $key): string
{
  $key = strtolower(trim($key));
  $key = preg_replace('/[^a-z0-9]+/', '-', $key) ?? $key;
  $key = trim($key, '-');
  return preg_replace('/-+/', '-', $key) ?? $key;
}

function labelUnderscoreKey(string $key): string
{
  return str_replace('-', '_', labelNormalizeKey($key));
}

function labelOptionValue(array $data, array $keys): string
{
  $normalized = [];
  foreach ($data as $rawKey => $rawValue) {
    $key = labelNormalizeKey((string)$rawKey);
    if ($key !== '' && (!array_key_exists($key, $normalized) || labelValueToString($normalized[$key]) === '')) {
      $normalized[$key] = $rawValue;
    }
  }

  foreach ($keys as $key) {
    $variants = array_values(array_unique([
      (string)$key,
      str_replace('-', '_', (string)$key),
      str_replace('_', '-', (string)$key),
    ]));

    foreach ($variants as $variant) {
      if (!array_key_exists($variant, $data)) continue;
      $value = labelValueToString($data[$variant]);
      if ($value !== '') return $value;
    }

    $normalizedKey = labelNormalizeKey((string)$key);
    if ($normalizedKey !== '' && array_key_exists($normalizedKey, $normalized)) {
      $value = labelValueToString($normalized[$normalizedKey]);
      if ($value !== '') return $value;
    }
  }

  return '';
}

function labelFirstFilledValue(array $data, array $keys): string
{
  return labelOptionValue($data, $keys);
}

function labelNormalizedKeyEndsWith(string $key, string $suffix): bool
{
  if ($key === '' || $suffix === '') {
    return false;
  }

  return $key === $suffix || substr($key, -strlen('-' . $suffix)) === '-' . $suffix;
}

function labelInternalOptionValue(array $data, array $exactKeys, array $suffixKeys, string $subcategory = ''): string
{
  $keys = $exactKeys;
  $subcategorySlug = labelUnderscoreKey($subcategory);
  foreach ($suffixKeys as $suffixKey) {
    $suffixSlug = labelUnderscoreKey((string)$suffixKey);
    if ($suffixSlug === '') {
      continue;
    }
    if ($subcategorySlug !== '') {
      $keys[] = '_graphics_' . $subcategorySlug . '_' . $suffixSlug;
    }
  }

  $value = labelFirstFilledValue($data, array_values(array_unique($keys)));
  if ($value !== '') {
    return $value;
  }

  $subcategoryNormalized = labelNormalizeKey($subcategory);
  $suffixes = [];
  foreach ($suffixKeys as $suffixKey) {
    $suffix = labelNormalizeKey((string)$suffixKey);
    if ($suffix !== '') {
      $suffixes[$suffix] = true;
    }
  }

  if (!$suffixes) {
    return '';
  }

  foreach ($data as $rawKey => $rawValue) {
    $value = labelValueToString($rawValue);
    if ($value === '') {
      continue;
    }

    $normalizedKey = labelNormalizeKey((string)$rawKey);
    if ($normalizedKey === '') {
      continue;
    }

    if (
      $subcategoryNormalized !== ''
      && strpos($normalizedKey, 'graphics-' . $subcategoryNormalized . '-') !== 0
    ) {
      continue;
    }

    foreach (array_keys($suffixes) as $suffix) {
      if (labelNormalizedKeyEndsWith($normalizedKey, $suffix)) {
        return $value;
      }
    }
  }

  return '';
}

function labelPrintSpecValues(array $opts, array $intOpts): array
{
  $subcategory = labelFirstFilledValue($intOpts, ['_subcat', 'subcat']);

  $material = labelInternalOptionValue($intOpts, ['_print_material'], [
    'base-material',
    'graphics-material',
    'material',
  ], $subcategory);
  if ($material === '') {
    $material = labelFirstFilledValue($opts, [
      'base-material',
      'base_material',
      'material',
      'graphics-material',
      'graphics_material',
    ]);
  }

  $finish = labelInternalOptionValue($intOpts, ['_print_finish'], [
    'graphics-finish',
    'finish',
  ], $subcategory);
  if ($finish === '') {
    $finish = labelFirstFilledValue($opts, [
      'graphics-finish',
      'graphics_finish',
      'finish',
    ]);
  }

  $color = labelInternalOptionValue($intOpts, [], [
    'mid-forks-color',
    'rim-tapes-color',
    'spoke-coats-color',
    'stickers-gear-color',
    'color',
  ], $subcategory);
  if ($color === '') {
    $color = labelFirstFilledValue($opts, [
      'mid-forks-color',
      'mid_forks_color',
      'rim-tapes-color',
      'rim_tapes_color',
      'spoke-coats-color',
      'spoke_coats_color',
      'stickers-gear-color',
      'stickers_gear_color',
      'color',
    ]);
  }

  $size = labelInternalOptionValue($intOpts, [], [
    'mid-forks-size',
    '4pcs-midfork-size',
    'rim-tapes-size-2',
    'size',
  ], $subcategory);
  if ($size === '') {
    $size = labelFirstFilledValue($opts, [
      'mid-forks-size',
      'mid_forks_size',
      '4pcs-midfork-size',
      '4pcs_midfork_size',
      'rim-tapes-size-2',
      'rim_tapes_size_2',
      'size',
    ]);
  }

  return [
    'material' => $material,
    'finish' => $finish,
    'color' => $color,
    'size' => $size,
  ];
}

function labelCategoryModel(array $opts): string
{
  $catCandidates = [
    'Category Info', 'category info', 'category_info', 'category-info',
    'category', 'Category', 'bike-category', 'bike_category',
    'model-category', 'model_category', 'product-category',
    'variant', 'Variant', 'Varianta', 'varianta',
    'bike', 'Bike', 'model', 'Model',
  ];

  $raw = '';
  foreach ($catCandidates as $key) {
    $value = labelOptionValue($opts, [$key]);
    if ($value === '') continue;
    if (strpos($value, '|') !== false) {
      $raw = $value;
      break;
    }
    if ($raw === '') {
      $raw = $value;
    }
  }

  if ($raw === '' || strpos($raw, '|') === false) {
    $brand = labelOptionValue($opts, ['brand', 'Brand', 'bike-brand', 'bike_brand', 'manufacturer', 'Manufacturer', 'make', 'Make']);
    $model = labelOptionValue($opts, ['model', 'Model', 'bike-model', 'bike_model', 'Bike', 'bike']);
    $year  = labelOptionValue($opts, ['year', 'Year', 'years', 'Years', 'bike-year', 'bike_year', 'model-year', 'model_year', 'Year Range']);
    $code  = labelOptionValue($opts, ['design_code', 'design-code', 'category_code', 'category-code', 'model_code', 'model-code', 'sku-code', 'sku_code', 'code', 'Code']);
    $parts = array_values(array_filter([$brand, $model, $year], static function ($v) { return trim((string)$v) !== ''; }));
    if ($parts) {
      $raw = implode(' | ', $parts) . ($code !== '' ? ' | ' . $code : '');
    }
  }

  if ($raw === '') return '';
  $parts = array_values(array_filter(array_map('trim', explode('|', $raw)), static function ($v) { return $v !== ''; }));
  return $parts ? implode(' | ', $parts) : trim($raw);
}

function labelFindOrderCategoryModel(mysqli $conn, int $orderId, int $currentItemId): string
{
  if ($orderId <= 0) return '';

  $stmt = $conn->prepare("
    SELECT id, options_json
    FROM order_items
    WHERE order_id = ?
      AND deleted_at IS NULL
    ORDER BY
      CASE WHEN id = ? THEN 0 ELSE 1 END,
      CASE WHEN item_type_code = 'G' THEN 0 ELSE 1 END,
      COALESCE(line_no, 999999),
      id
  ");
  if (!$stmt) return '';

  $stmt->bind_param('ii', $orderId, $currentItemId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($candidate = $res->fetch_assoc()) {
    $model = labelCategoryModel(jsonArr((string)($candidate['options_json'] ?? '')));
    if ($model !== '') {
      $stmt->close();
      return $model;
    }
  }
  $stmt->close();

  return '';
}

function labelFindCustomOrderItemPrintOptions(mysqli $conn, array $row): array
{
  $sourceMeta = json_decode((string) ($row['source_meta'] ?? ''), true);
  if (!is_array($sourceMeta)) {
    return [];
  }

  $customOrderId = (int) ($sourceMeta['custom_order_id'] ?? 0);
  $lineNo = (int) ($row['line_no'] ?? 0);
  if ($customOrderId <= 0 || $lineNo <= 0) {
    return [];
  }

  $stmt = $conn->prepare("
    SELECT options_json, internal_options_json
    FROM custom_order_items
    WHERE custom_order_id = ?
      AND line_no = ?
    ORDER BY
      CASE WHEN UPPER(item_type_code) = ? THEN 0 ELSE 1 END,
      id
    LIMIT 1
  ");
  if (!$stmt) {
    return [];
  }

  $itemType = strtoupper(trim((string) ($row['item_type_code'] ?? '')));
  $stmt->bind_param('iis', $customOrderId, $lineNo, $itemType);
  $stmt->execute();
  $customRow = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$customRow) {
    return [];
  }

  $opts = jsonArr((string) ($customRow['options_json'] ?? '{}'));
  $intOpts = jsonArr((string) ($customRow['internal_options_json'] ?? '{}'));

  return labelPrintSpecValues($opts, $intOpts);
}
// ── Parametre z URL ───────────────────────────────────────────────────────────
$itemId  = gi('item_id');   // POVINNÉ — ID riadku z order_items
$orderId = gi('order_id');  // fallback ak nemáme item_id (badge link)

if ($itemId <= 0 && $orderId <= 0) {
  http_response_code(400);
  exit('Missing item_id or order_id');
}

// ── Načítanie z DB ────────────────────────────────────────────────────────────
// Ak máme item_id, načítame priamo ten konkrétny item.
// Ak máme len order_id, berieme prvý G-item objednávky.

if ($itemId > 0) {
  $stmt = $conn->prepare("
    SELECT
      oi.id,
      oi.line_no,
      oi.item_type_code,
      oi.options_json,
      oi.internal_options_json,
      oi.order_id,
      o.order_number,
      o.source_meta,
      os.code AS source_code,
      COALESCE(cu.name, cu.email, '') AS customer_name,
      (
        SELECT COALESCE(a.country, '')
        FROM order_addresses a
        WHERE a.order_id = o.id AND a.type = 'SHIPPING'
        LIMIT 1
      ) AS shipping_country,
      (
        SELECT GROUP_CONCAT(
          CONCAT(e.firstname,' ',e.lastname)
          ORDER BY e.firstname, e.lastname
          SEPARATOR ', '
        )
        FROM order_item_assignments oia
        JOIN employees e ON e.id = oia.employee_id
        WHERE oia.item_id = oi.id AND oia.removed_at IS NULL
      ) AS assigned_names,
      (
        SELECT GROUP_CONCAT(
          CONCAT(e.firstname,' ',e.lastname)
          ORDER BY
            CASE WHEN oa.role = 'PRIMARY_GRAPHICS' THEN 0 ELSE 1 END,
            e.firstname, e.lastname
          SEPARATOR ', '
        )
        FROM order_assignments oa
        JOIN employees e ON e.id = oa.employee_id
        WHERE oa.order_id = oi.order_id
          AND oa.removed_at IS NULL
          AND oa.role IN ('PRIMARY_GRAPHICS', 'COLLAB_GRAPHICS')
      ) AS order_graphic_names,
      (
        SELECT n.note
        FROM order_production_notes n
        WHERE n.order_id = oi.order_id
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT 1
      ) AS production_note
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN order_sources os ON os.id = o.source_id
    LEFT JOIN customers cu ON cu.id = o.customer_id
    WHERE oi.id = ?
    LIMIT 1
  ");
  $stmt->bind_param('i', $itemId);
} else {
  $stmt = $conn->prepare("
    SELECT
      oi.id,
      oi.line_no,
      oi.item_type_code,
      oi.options_json,
      oi.internal_options_json,
      oi.order_id,
      o.order_number,
      o.source_meta,
      os.code AS source_code,
      COALESCE(cu.name, cu.email, '') AS customer_name,
      (
        SELECT COALESCE(a.country, '')
        FROM order_addresses a
        WHERE a.order_id = o.id AND a.type = 'SHIPPING'
        LIMIT 1
      ) AS shipping_country,
      (
        SELECT GROUP_CONCAT(
          CONCAT(e.firstname,' ',e.lastname)
          ORDER BY e.firstname, e.lastname
          SEPARATOR ', '
        )
        FROM order_item_assignments oia
        JOIN employees e ON e.id = oia.employee_id
        WHERE oia.item_id = oi.id AND oia.removed_at IS NULL
      ) AS assigned_names,
      (
        SELECT GROUP_CONCAT(
          CONCAT(e.firstname,' ',e.lastname)
          ORDER BY
            CASE WHEN oa.role = 'PRIMARY_GRAPHICS' THEN 0 ELSE 1 END,
            e.firstname, e.lastname
          SEPARATOR ', '
        )
        FROM order_assignments oa
        JOIN employees e ON e.id = oa.employee_id
        WHERE oa.order_id = oi.order_id
          AND oa.removed_at IS NULL
          AND oa.role IN ('PRIMARY_GRAPHICS', 'COLLAB_GRAPHICS')
      ) AS order_graphic_names,
      (
        SELECT n.note
        FROM order_production_notes n
        WHERE n.order_id = oi.order_id
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT 1
      ) AS production_note
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN order_sources os ON os.id = o.source_id
    LEFT JOIN customers cu ON cu.id = o.customer_id
    WHERE oi.order_id = ?
      AND oi.item_type_code = 'G'
      AND oi.deleted_at IS NULL
    ORDER BY COALESCE(oi.line_no, 999999), oi.id
    LIMIT 1
  ");
  $stmt->bind_param('i', $orderId);
}

if (!$stmt) {
  http_response_code(500);
  exit('SQL prepare failed: ' . $conn->error);
}
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  http_response_code(404);
  exit('Item not found');
}

// ── Rozbalenie dát ────────────────────────────────────────────────────────────
$opts    = jsonArr((string)($row['options_json'] ?? ''));
$intOpts = jsonArr((string)($row['internal_options_json'] ?? ''));

$orderNum   = (string)($row['order_number'] ?? '');
$customer   = trim((string)($row['customer_name'] ?? $row['customer_email'] ?? ''));
$country    = (string)($row['shipping_country'] ?? '');
$sourceCode = (string)($row['source_code'] ?? 'SO');
$model      = labelCategoryModel($opts);
if ($model === '') {
  $model = labelFindOrderCategoryModel($conn, (int)($row['order_id'] ?? 0), (int)($row['id'] ?? 0));
}
$graphic    = trim((string)($row['assigned_names'] ?? ''));
if ($graphic === '') {
  $graphic = trim((string)($row['order_graphic_names'] ?? ''));
}

// GFP — z traffic_summary_json objednávky (ak nestačí, doplníme len G)
$gfp = '';
$gfpSummary = null;
$gfpStmt = $conn->prepare("SELECT traffic_summary_json FROM orders WHERE id = ? LIMIT 1");
if ($gfpStmt) {
  $gfpOrderId = (int)($row['order_id'] ?? 0);
  $gfpStmt->bind_param('i', $gfpOrderId);
  $gfpStmt->execute();
  $gfpRow = $gfpStmt->get_result()->fetch_assoc();
  $gfpStmt->close();
  $gfpSummary = json_decode((string)($gfpRow['traffic_summary_json'] ?? ''), true);
}
foreach (['G','F','P','S'] as $t) {
  if (is_array($gfpSummary) && array_key_exists($t, $gfpSummary)) $gfp .= $t;
}
if ($gfp === '') $gfp = (string)($row['item_type_code'] ?? 'G');

// ── Spec hodnoty ──────────────────────────────────────────────────────────────
$customPrintFallback = labelFindCustomOrderItemPrintOptions($conn, $row);
$printSpecs = labelPrintSpecValues($opts, $intOpts);
$material = (string) ($printSpecs['material'] ?? '');
if ($material === '') {
  $material = (string) ($customPrintFallback['material'] ?? '');
}
$finish = (string) ($printSpecs['finish'] ?? '');
if ($finish === '') {
  $finish = (string) ($customPrintFallback['finish'] ?? '');
}
$color = (string) ($printSpecs['color'] ?? '');
if ($color === '') {
  $color = (string) ($customPrintFallback['color'] ?? '');
}
$size = (string) ($printSpecs['size'] ?? '');
if ($size === '') {
  $size = (string) ($customPrintFallback['size'] ?? '');
}
$design       = trim((string)($opts['design'] ?? $opts['design-name'] ?? ''));
$riderName    = (string)($intOpts['_graphics_name']     ?? $opts['name']              ?? $opts['rider-name']   ?? '');
$nameFont     = (string)($opts['name-font']  ?? '');
$riderNumber  = (string)($intOpts['_graphics_number']   ?? $opts['number']            ?? $opts['race-number']  ?? '');
$numberFont   = (string)($opts['number-font'] ?? '');
$numberColor  = (string)($opts['number-color'] ?? '');
$plateColor   = (string)($opts['number-plate-color'] ?? $opts['numberplate-color'] ?? '');
$trSwingarms  = (string)($intOpts['_print_tr_swingarms'] ?? $opts['tr-swingarms'] ?? $opts['tr_swingarms'] ?? '');
$grip         = (string)($intOpts['_print_grip']        ?? $opts['grip']              ?? '');
$printer      = (string)($intOpts['_printer'] ?? '');
$itemNote     = (string)($intOpts['_graphics_note']     ?? $opts['note']              ?? '');

// ── Font (TTF) ────────────────────────────────────────────────────────────────
// Ak server nema TTF font, kreslenie nizsie automaticky pouzije interny GD font.
function firstUsableFont(array $paths): string {
  foreach ($paths as $fp) {
    if (is_string($fp) && $fp !== '' && is_file($fp) && is_readable($fp)) {
      return $fp;
    }
  }
  return '';
}

$fontRegular = firstUsableFont([
  $base . '/assets/fonts/DejaVuSans.ttf',
  $base . '/assets/fonts/LiberationSans-Regular.ttf',
  $base . '/fonts/DejaVuSans.ttf',
  $base . '/fonts/LiberationSans-Regular.ttf',
  '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
  '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
  '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
  '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
  '/usr/share/fonts/dejavu/DejaVuSans.ttf',
  '/usr/share/fonts/TTF/DejaVuSans.ttf',
  '/usr/local/share/fonts/DejaVuSans.ttf',
  '/usr/local/share/fonts/Arial.ttf',
  '/usr/share/fonts/TTF/arial.ttf',
  '/usr/share/fonts/truetype/msttcorefonts/Arial.ttf',
  '/usr/share/fonts/truetype/msttcorefonts/arial.ttf',
  '/usr/syno/share/fonts/DejaVuSans.ttf',
  '/usr/syno/share/fonts/Arial.ttf',
  'C:/Windows/Fonts/arial.ttf',
  'C:/Windows/Fonts/segoeui.ttf',
]);

$fontBold = firstUsableFont([
  $base . '/assets/fonts/DejaVuSans-Bold.ttf',
  $base . '/assets/fonts/LiberationSans-Bold.ttf',
  $base . '/fonts/DejaVuSans-Bold.ttf',
  $base . '/fonts/LiberationSans-Bold.ttf',
  '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
  '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
  '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
  '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
  '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
  '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
  '/usr/local/share/fonts/DejaVuSans-Bold.ttf',
  '/usr/local/share/fonts/Arial_Bold.ttf',
  '/usr/share/fonts/TTF/arialbd.ttf',
  '/usr/share/fonts/truetype/msttcorefonts/Arial_Bold.ttf',
  '/usr/share/fonts/truetype/msttcorefonts/arialbd.ttf',
  '/usr/syno/share/fonts/DejaVuSans-Bold.ttf',
  '/usr/syno/share/fonts/Arial_Bold.ttf',
  'C:/Windows/Fonts/arialbd.ttf',
  'C:/Windows/Fonts/segoeuib.ttf',
]);
if ($fontBold === '') $fontBold = $fontRegular;
// ── Definícia buniek ──────────────────────────────────────────────────────────
// type 'single'  — 1 riadok, len value (order, name, type, country, grafik, fitting)
// type 'spec'    — 2 riadky: label (menší/sivý) + value (väčší/čierny)
// Farby: 'f' = fitting (červený), normálne = biely bg

$row1Cells = [];
$row2Cells = [];

// 1. riadok: pevne polia objednavky
if ($orderNum !== '')   $row1Cells[] = ['type'=>'single','label'=>'',       'value'=>$orderNum,   'f'=>false];
if ($customer !== '')   $row1Cells[] = ['type'=>'single','label'=>'',       'value'=>$customer,   'f'=>false];
if ($sourceCode !== '') $row1Cells[] = ['type'=>'single','label'=>'',       'value'=>$sourceCode, 'f'=>false];
if ($country !== '')    $row1Cells[] = ['type'=>'single','label'=>'Country',       'value'=>$country,    'f'=>false];
if ($gfp !== '')        $row1Cells[] = ['type'=>'spec',  'label'=>'Types',  'value'=>$gfp,        'f'=>false];
if ($graphic !== '')    $row1Cells[] = ['type'=>'spec',  'label'=>'Grafik', 'value'=>$graphic,    'f'=>false, 'min'=>130];
$row1Cells[] = ['type'=>'spec', 'label'=>'Model', 'value'=>$model, 'f'=>false, 'min'=>120, 'max'=>360];


// 2. riadok: technicke/spec polia
$specDefs = [
  ['label'=>'Material',     'value'=>$material],
  ['label'=>'Finish',       'value'=>$finish],
  ['label'=>'Color',        'value'=>$color, 'optional'=>true],
  ['label'=>'Size',         'value'=>$size, 'optional'=>true],
//  ['label'=>'Design',       'value'=>$design],
  ['label'=>'Name',         'value'=>$riderName],
  ['label'=>'Name Font',    'value'=>$nameFont],
  ['label'=>'Number',       'value'=>$riderNumber],
  ['label'=>'Number Font',  'value'=>$numberFont],
  ['label'=>'Num. Color',   'value'=>$numberColor],
  ['label'=>'Plate Color',  'value'=>$plateColor],
  ['label'=>'Swingarms',    'value'=>$trSwingarms],
  ['label'=>'Grip',         'value'=>$grip],
  ['label'=>'Printer',      'value'=>$printer],
];
foreach ($specDefs as $sd) {
  $value = trim((string)$sd['value']);
  if (!empty($sd['optional']) && $value === '') {
    continue;
  }
  $row2Cells[] = ['type'=>'spec','label'=>$sd['label'],'value'=>$value,'f'=>false];
}

$rows = [$row1Cells, $row2Cells];
$W        = 1800;
$padH     = 8;    // horizontalny padding
$padV     = 4;    // vertikalny padding nad/pod kazdy riadok v bunke

// Velkosti fontov (pt/px pre imagettfbbox)
$szLabel  = 7.5;   // label riadok (sivy, mensi)
$szValue  = 9.5;   // value riadok (cierny, vacsi)

function labelHasGdRenderer(): bool {
  foreach ([
    'imagecreatetruecolor',
    'imagecolorallocate',
    'imagefilledrectangle',
    'imageline',
    'imagepng',
    'imagedestroy',
    'imagefontheight',
    'imagefontwidth',
    'imagestring',
  ] as $fn) {
    if (!function_exists($fn)) {
      return false;
    }
  }
  return true;
}

function labelSvgEsc(string $text): string {
  return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function labelSvgTextLen(string $text): int {
  return function_exists('mb_strlen') ? (int)mb_strlen($text, 'UTF-8') : strlen($text);
}

function labelSvgTextSlice(string $text, int $start, int $length): string {
  return function_exists('mb_substr') ? (string)mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
}

function labelSvgTextWidth(string $text, float $size, bool $bold = false): int {
  if ($text === '') return 0;

  $len = labelSvgTextLen($text);
  $wide = preg_match_all('/[MW@#%&0-9]/u', $text, $m) ?: 0;
  $narrow = preg_match_all('/[il.,:;|!]/u', $text, $m) ?: 0;
  $normal = max(0, $len - $wide - $narrow);
  $factor = ($normal * 0.56) + ($wide * 0.76) + ($narrow * 0.32);
  if ($bold) {
    $factor *= 1.06;
  }

  return (int)ceil($factor * $size);
}

function labelSvgFitText(string $text, float $size, int $maxWidth, bool $bold = false): string {
  if ($text === '' || $maxWidth <= 0) return '';
  if (labelSvgTextWidth($text, $size, $bold) <= $maxWidth) return $text;

  $suffix = '...';
  if (labelSvgTextWidth($suffix, $size, $bold) > $maxWidth) return '';

  $lo = 0;
  $hi = labelSvgTextLen($text);
  $best = '';
  while ($lo <= $hi) {
    $mid = intdiv($lo + $hi, 2);
    $candidate = labelSvgTextSlice($text, 0, $mid) . $suffix;
    if (labelSvgTextWidth($candidate, $size, $bold) <= $maxWidth) {
      $best = $candidate;
      $lo = $mid + 1;
    } else {
      $hi = $mid - 1;
    }
  }

  return $best;
}

function labelSvgCellNatWidth(array $cell, float $szL, float $szV, int $padH): int {
  if (($cell['type'] ?? '') === 'single') {
    $tw = labelSvgTextWidth((string)($cell['value'] ?? ''), $szV, false);
  } else {
    $tw = max(
      labelSvgTextWidth((string)($cell['label'] ?? ''), $szL, true),
      labelSvgTextWidth((string)(($cell['value'] ?? '') !== '' ? $cell['value'] : ' '), $szV, false)
    );
  }

  $minW = isset($cell['min']) ? max(1, (int)$cell['min']) : 55;
  $maxW = isset($cell['max']) ? max($minW, (int)$cell['max']) : 0;
  $width = max($minW, $tw + $padH * 2);
  return $maxW > 0 ? min($width, $maxW) : $width;
}

function labelSvgFitCellWidths(array $natural, int $availableW): array {
  $count = count($natural);
  if ($count === 0) return [];

  $naturalSum = array_sum($natural);
  if ($naturalSum <= 0) {
    $base = (int)floor($availableW / $count);
    $widths = array_fill(0, $count, $base);
    $widths[$count - 1] += $availableW - array_sum($widths);
    return $widths;
  }

  $widths = $natural;
  $extraPx = $availableW - $naturalSum;

  if ($extraPx > 0) {
    $assigned = 0;
    foreach ($natural as $i => $nw) {
      if ($i === $count - 1) {
        $widths[$i] = $nw + ($extraPx - $assigned);
      } else {
        $add = (int)floor($extraPx * ($nw / $naturalSum));
        $widths[$i] = $nw + $add;
        $assigned += $add;
      }
    }
  } elseif ($extraPx < 0 && $availableW > 0) {
    $scale = $availableW / $naturalSum;
    $assigned = 0;
    foreach ($natural as $i => $nw) {
      if ($i === $count - 1) {
        $widths[$i] = max(1, $availableW - $assigned);
      } else {
        $w = max(1, (int)floor($nw * $scale));
        $widths[$i] = $w;
        $assigned += $w;
      }
    }
  }

  while (array_sum($widths) > $availableW) {
    $maxIdx = array_keys($widths, max($widths), true)[0];
    if ($widths[$maxIdx] <= 1) break;
    $widths[$maxIdx]--;
  }
  while (array_sum($widths) < $availableW) {
    $maxIdx = array_keys($widths, max($widths), true)[0];
    $widths[$maxIdx]++;
  }

  return $widths;
}

function labelShellCommand(array $parts): string {
  return implode(' ', array_map(static function ($part): string {
    return escapeshellarg((string)$part);
  }, $parts));
}

function labelRunCommand(array $parts, ?string &$stdout = null, ?string &$stderr = null): int {
  $stdout = '';
  $stderr = '';
  if (!function_exists('proc_open') || !function_exists('escapeshellarg')) {
    return 127;
  }

  $proc = @proc_open(labelShellCommand($parts), [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ], $pipes);
  if (!is_resource($proc)) {
    return 127;
  }

  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[2]);

  return (int)proc_close($proc);
}

function labelFindImageMagickCommand(): string {
  $candidates = [];
  foreach (['IMAGEMAGICK_BINARY', 'MAGICK_BINARY'] as $envKey) {
    $envValue = getenv($envKey);
    if (is_string($envValue) && trim($envValue) !== '') {
      $candidates[] = trim($envValue);
    }
  }

  foreach ([
    'magick',
    'convert',
    '/usr/bin/magick',
    '/usr/bin/convert',
    '/usr/local/bin/magick',
    '/usr/local/bin/convert',
  ] as $candidate) {
    $candidates[] = $candidate;
  }

  foreach (array_values(array_unique($candidates)) as $candidate) {
    $out = '';
    $err = '';
    $code = labelRunCommand([$candidate, '-version'], $out, $err);
    if ($code === 0 && stripos($out . $err, 'ImageMagick') !== false) {
      return $candidate;
    }
  }

  return '';
}

function labelRenderSvgFallback(array $rows, int $W, int $padH, int $padV, float $szLabel, float $szValue, string $orderNum, int $baseObLevel): void {
  $command = labelFindImageMagickCommand();
  if ($command === '') {
    while (ob_get_level() > $baseObLevel) {
      @ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Label generation failed: PHP GD extension is missing and ImageMagick CLI is not available.';
    exit;
  }

  $hLabel = (int)ceil($szLabel * 1.25);
  $hValue = (int)ceil($szValue * 1.35);
  $rowH = $padV + $hLabel + $padV + $hValue + $padV;
  $H = $rowH * count($rows);

  $rowWidths = [];
  foreach ($rows as $rowIdx => $rowCells) {
    $natural = [];
    foreach ($rowCells as $cell) {
      $natural[] = labelSvgCellNatWidth($cell, $szLabel, $szValue, $padH);
    }
    $rowWidths[$rowIdx] = labelSvgFitCellWidths($natural, $W);
  }

  $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H . '">' . "\n";
  $svg .= '<rect x="0" y="0" width="' . $W . '" height="' . $H . '" fill="#ffffff"/>' . "\n";

  foreach ($rows as $rowIdx => $rowCells) {
    $y = $rowIdx * $rowH;
    $x = 0;
    $boundaries = [0];

    foreach ($rowCells as $i => $cell) {
      $cellW = $rowWidths[$rowIdx][$i] ?? 0;
      if ($cellW <= 0) continue;

      $isFitting = !empty($cell['f']);
      $bg = $isFitting ? '#c80000' : '#ffffff';
      $fgValue = $isFitting ? '#ffffff' : '#000000';
      $fgLabel = $isFitting ? '#ffffff' : '#828282';
      $centerX = $x + ($cellW / 2);

      $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $cellW . '" height="' . $rowH . '" fill="' . $bg . '"/>' . "\n";

      if (($cell['type'] ?? '') === 'single') {
        $text = labelSvgFitText((string)($cell['value'] ?? ''), $szValue, max(0, $cellW - $padH * 2), false);
        $svg .= '<text x="' . $centerX . '" y="' . ($y + ($rowH / 2) + 1) . '" text-anchor="middle" dominant-baseline="middle" font-family="DejaVu Sans, Arial, sans-serif" font-size="' . $szValue . '" fill="' . $fgValue . '">' . labelSvgEsc($text) . '</text>' . "\n";
      } else {
        $labelText = labelSvgFitText((string)($cell['label'] ?? ''), $szLabel, max(0, $cellW - $padH * 2), true);
        $valueText = labelSvgFitText((string)($cell['value'] ?? ''), $szValue, max(0, $cellW - $padH * 2), false);
        $svg .= '<text x="' . $centerX . '" y="' . ($y + $padV + ($hLabel / 2) + 1) . '" text-anchor="middle" dominant-baseline="middle" font-family="DejaVu Sans, Arial, sans-serif" font-size="' . $szLabel . '" font-weight="700" fill="' . $fgLabel . '">' . labelSvgEsc($labelText) . '</text>' . "\n";
        $svg .= '<text x="' . $centerX . '" y="' . ($y + $padV + $hLabel + $padV + ($hValue / 2) + 1) . '" text-anchor="middle" dominant-baseline="middle" font-family="DejaVu Sans, Arial, sans-serif" font-size="' . $szValue . '" fill="' . $fgValue . '">' . labelSvgEsc($valueText) . '</text>' . "\n";
      }

      $x += $cellW;
      $boundaries[] = $x;
      if ($x >= $W) break;
    }

    foreach (array_unique($boundaries) as $boundaryX) {
      $lineX = min($W - 1, max(0, (int)$boundaryX));
      $svg .= '<line x1="' . $lineX . '" y1="' . $y . '" x2="' . $lineX . '" y2="' . ($y + $rowH - 1) . '" stroke="#000000" stroke-width="1"/>' . "\n";
    }
    $svg .= '<line x1="' . ($W - 1) . '" y1="' . $y . '" x2="' . ($W - 1) . '" y2="' . ($y + $rowH - 1) . '" stroke="#000000" stroke-width="1"/>' . "\n";
  }

  for ($rowLine = 0; $rowLine <= count($rows); $rowLine++) {
    $lineY = ($rowLine === count($rows)) ? $H - 1 : $rowLine * $rowH;
    $svg .= '<line x1="0" y1="' . $lineY . '" x2="' . ($W - 1) . '" y2="' . $lineY . '" stroke="#000000" stroke-width="1"/>' . "\n";
  }
  $svg .= '</svg>' . "\n";

  $tmpBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'label_rtp_' . preg_replace('/[^A-Za-z0-9_]+/', '_', uniqid('', true));
  $svgFile = $tmpBase . '.svg';
  $pngFile = $tmpBase . '.png';

  $ok = @file_put_contents($svgFile, $svg) !== false;
  if ($ok) {
    $out = '';
    $err = '';
    $ok = labelRunCommand([$command, '-background', 'none', $svgFile, 'png32:' . $pngFile], $out, $err) === 0
      && is_file($pngFile)
      && filesize($pngFile) > 0;
  }

  if (!$ok) {
    @unlink($svgFile);
    @unlink($pngFile);
    while (ob_get_level() > $baseObLevel) {
      @ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Label generation failed: ImageMagick could not render the PNG fallback.';
    exit;
  }

  while (ob_get_level() > $baseObLevel) {
    @ob_end_clean();
  }
  header('Content-Type: image/png');
  header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $orderNum ?: 'rtp') . '_rtp.png"');
  readfile($pngFile);
  @unlink($svgFile);
  @unlink($pngFile);
  exit;
}

if (!labelHasGdRenderer()) {
  labelRenderSvgFallback($rows, $W, $padH, $padV, $szLabel, $szValue, $orderNum, $labelRtpBaseObLevel);
}

// Výška bunky — label + value + 3× vertikálny padding
function labelCanUseTtf(string $font): bool {
  static $cache = [];
  if ($font === '' || !function_exists('imagettfbbox') || !function_exists('imagettftext')) {
    return false;
  }
  if (array_key_exists($font, $cache)) {
    return $cache[$font];
  }
  if (!is_file($font) || !is_readable($font)) {
    $cache[$font] = false;
    return false;
  }
  $box = @imagettfbbox(8, 0, $font, 'Ag');
  $cache[$font] = is_array($box);
  return $cache[$font];
}

function labelGdFont(float $sz): int {
  return ($sz <= 8.0) ? 3 : 5;
}

function labelGdText(string $text): string {
  if ($text === '') return '';
  if (function_exists('iconv')) {
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false && $converted !== '') {
      return $converted;
    }
  }
  $clean = preg_replace('/[^\x20-\x7E]/', '?', $text);
  return is_string($clean) ? $clean : $text;
}

function ttfH(float $sz, string $font): int {
  if (labelCanUseTtf($font)) {
    $b = @imagettfbbox($sz, 0, $font, 'Ag');
    if (is_array($b)) {
      return abs($b[7] - $b[1]);
    }
  }
  return imagefontheight(labelGdFont($sz));
}
$hLabel  = ttfH($szLabel, $fontBold);
$hValue  = ttfH($szValue, $fontRegular);
$rowH    = $padV + $hLabel + $padV + $hValue + $padV;
$H       = $rowH * count($rows);

// ── Šírky buniek ─────────────────────────────────────────────────────────────
function ttfW(string $text, float $sz, string $font): int {
  if ($text === '') return 0;
  if (labelCanUseTtf($font)) {
    $b = @imagettfbbox($sz, 0, $font, $text);
    if (is_array($b)) {
      return abs($b[4] - $b[0]);
    }
  }
  return imagefontwidth(labelGdFont($sz)) * strlen(labelGdText($text));
}

function drawLabelText($img, float $sz, int $x, int $baselineY, int $color, string $font, string $text): void {
  if ($text === '') return;
  if (labelCanUseTtf($font)) {
    $ok = @imagettftext($img, $sz, 0, $x, $baselineY, $color, $font, $text);
    if ($ok !== false) {
      return;
    }
  }

  $gdFont = labelGdFont($sz);
  imagestring(
    $img,
    $gdFont,
    $x,
    max(0, $baselineY - imagefontheight($gdFont)),
    labelGdText($text),
    $color
  );
}

function labelTextLen(string $text): int {
  return function_exists('mb_strlen') ? (int)mb_strlen($text, 'UTF-8') : strlen($text);
}

function labelTextSlice(string $text, int $start, int $length): string {
  return function_exists('mb_substr') ? (string)mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
}

function fitLabelText(string $text, float $sz, string $font, int $maxWidth): string {
  if ($text === '' || $maxWidth <= 0) return '';
  if (ttfW($text, $sz, $font) <= $maxWidth) return $text;

  $suffix = '...';
  if (ttfW($suffix, $sz, $font) > $maxWidth) return '';

  $lo = 0;
  $hi = labelTextLen($text);
  $best = '';
  while ($lo <= $hi) {
    $mid = intdiv($lo + $hi, 2);
    $candidate = labelTextSlice($text, 0, $mid) . $suffix;
    if (ttfW($candidate, $sz, $font) <= $maxWidth) {
      $best = $candidate;
      $lo = $mid + 1;
    } else {
      $hi = $mid - 1;
    }
  }

  return $best;
}

function cellNatWidth(array $cell, float $szL, float $szV, string $fBold, string $fReg, int $padH): int {
  if ($cell['type'] === 'single') {
    $tw = ttfW($cell['value'], $szV, $fReg);
  } else {
    $tw = max(
      ttfW($cell['label'], $szL, $fBold),
      ttfW($cell['value'] !== '' ? $cell['value'] : ' ', $szV, $fReg)
    );
  }
  $minW = isset($cell['min']) ? max(1, (int)$cell['min']) : 55;
  $maxW = isset($cell['max']) ? max($minW, (int)$cell['max']) : 0;
  $width = max($minW, $tw + $padH * 2);
  return $maxW > 0 ? min($width, $maxW) : $width;
}

function fitCellWidths(array $natural, int $availableW): array {
  $count = count($natural);
  if ($count === 0) return [];

  $naturalSum = array_sum($natural);
  if ($naturalSum <= 0) {
    $base = (int)floor($availableW / $count);
    $widths = array_fill(0, $count, $base);
    $widths[$count - 1] += $availableW - array_sum($widths);
    return $widths;
  }

  $widths = $natural;
  $extraPx = $availableW - $naturalSum;

  if ($extraPx > 0) {
    $assigned = 0;
    foreach ($natural as $i => $nw) {
      if ($i === $count - 1) {
        $widths[$i] = $nw + ($extraPx - $assigned);
      } else {
        $add = (int)floor($extraPx * ($nw / $naturalSum));
        $widths[$i] = $nw + $add;
        $assigned += $add;
      }
    }
  } elseif ($extraPx < 0 && $availableW > 0) {
    $scale = $availableW / $naturalSum;
    $assigned = 0;
    foreach ($natural as $i => $nw) {
      if ($i === $count - 1) {
        $widths[$i] = max(1, $availableW - $assigned);
      } else {
        $w = max(1, (int)floor($nw * $scale));
        $widths[$i] = $w;
        $assigned += $w;
      }
    }
  }

  while (array_sum($widths) > $availableW) {
    $maxIdx = array_keys($widths, max($widths), true)[0];
    if ($widths[$maxIdx] <= 1) break;
    $widths[$maxIdx]--;
  }
  while (array_sum($widths) < $availableW) {
    $maxIdx = array_keys($widths, max($widths), true)[0];
    $widths[$maxIdx]++;
  }

  return $widths;
}

$rowWidths = [];
foreach ($rows as $rowIdx => $rowCells) {
  $natural = [];
  foreach ($rowCells as $cell) {
    $natural[] = cellNatWidth($cell, $szLabel, $szValue, $fontBold, $fontRegular, $padH);
  }
  $rowWidths[$rowIdx] = fitCellWidths($natural, $W);
}
$img   = imagecreatetruecolor($W, $H);
$white = imagecolorallocate($img, 255, 255, 255);
$black = imagecolorallocate($img, 0,   0,   0);
$red   = imagecolorallocate($img, 200, 0,   0);
$gray  = imagecolorallocate($img, 130, 130, 130);
$lgray = imagecolorallocate($img, 220, 220, 220);

imagefilledrectangle($img, 0, 0, $W - 1, $H - 1, $white);

foreach ($rows as $rowIdx => $rowCells) {
  $y = $rowIdx * $rowH;
  $x = 0;
  $boundaries = [0];

  foreach ($rowCells as $i => $cell) {
    $cellW = $rowWidths[$rowIdx][$i] ?? 0;
    if ($cellW <= 0) continue;

    $bg       = $cell['f'] ? $red   : $white;
    $fgValue  = $cell['f'] ? $white : $black;
    $fgLabel  = $cell['f'] ? $white : $gray;

    imagefilledrectangle($img, $x, $y, min($W - 1, $x + $cellW - 1), $y + $rowH - 1, $bg);

    if ($cell['type'] === 'single') {
      $text = fitLabelText($cell['value'], $szValue, $fontRegular, max(0, $cellW - $padH * 2));
      $tw   = ttfW($text, $szValue, $fontRegular);
      $tx   = $x + (int)(($cellW - $tw) / 2);
      $ty   = $y + (int)(($rowH + $hValue) / 2) - (int)($hValue * 0.15);
      drawLabelText($img, $szValue, $tx, $ty, $fgValue, $fontRegular, $text);
    } else {
      $labelText = fitLabelText($cell['label'], $szLabel, $fontBold, max(0, $cellW - $padH * 2));
      $valueText = fitLabelText($cell['value'], $szValue, $fontRegular, max(0, $cellW - $padH * 2));

      $twL = ttfW($labelText, $szLabel, $fontBold);
      $txL = $x + (int)(($cellW - $twL) / 2);
      $tyL = $y + $padV + $hLabel;
      drawLabelText($img, $szLabel, $txL, $tyL, $fgLabel, $fontBold, $labelText);

      $twV = ttfW($valueText, $szValue, $fontRegular);
      $txV = $x + (int)(($cellW - $twV) / 2);
      $tyV = $y + $padV + $hLabel + $padV + $hValue;
      drawLabelText($img, $szValue, $txV, $tyV, $fgValue, $fontRegular, $valueText);
    }

    $x += $cellW;
    $boundaries[] = $x;
    if ($x >= $W) break;
  }

  foreach (array_unique($boundaries) as $boundaryX) {
    $lineX = min($W - 1, max(0, (int)$boundaryX));
    imageline($img, $lineX, $y, $lineX, $y + $rowH - 1, $black);
  }
  imageline($img, $W - 1, $y, $W - 1, $y + $rowH - 1, $black);
}

for ($rowLine = 0; $rowLine <= count($rows); $rowLine++) {
  $lineY = ($rowLine === count($rows)) ? $H - 1 : $rowLine * $rowH;
  imageline($img, 0, $lineY, $W - 1, $lineY, $black);
}
while (ob_get_level() > $labelRtpBaseObLevel) {
  @ob_end_clean();
}
header('Content-Type: image/png');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $orderNum ?: 'rtp') . '_rtp.png"');
imagepng($img);
imagedestroy($img);
