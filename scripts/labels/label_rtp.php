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

function labelOptionValue(array $data, array $keys): string
{
  $normalized = [];
  foreach ($data as $rawKey => $rawValue) {
    $key = strtolower(trim((string)$rawKey));
    $key = preg_replace('/[^a-z0-9]+/', '-', $key) ?? $key;
    $key = trim($key, '-');
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

    $normalizedKey = strtolower(trim((string)$key));
    $normalizedKey = preg_replace('/[^a-z0-9]+/', '-', $normalizedKey) ?? $normalizedKey;
    $normalizedKey = trim($normalizedKey, '-');
    if ($normalizedKey !== '' && array_key_exists($normalizedKey, $normalized)) {
      $value = labelValueToString($normalized[$normalizedKey]);
      if ($value !== '') return $value;
    }
  }

  return '';
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
      oi.item_type_code,
      oi.options_json,
      oi.internal_options_json,
      oi.order_id,
      o.order_number,
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
          DISTINCT CONCAT(e.firstname,' ',e.lastname)
          ORDER BY e.firstname, e.lastname
          SEPARATOR ', '
        )
        FROM order_item_assignments oia
        JOIN employees e ON e.id = oia.employee_id
        WHERE oia.item_id = oi.id AND oia.removed_at IS NULL
      ) AS assigned_names,
      (
        SELECT GROUP_CONCAT(
          DISTINCT CONCAT(e.firstname,' ',e.lastname)
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
      oi.item_type_code,
      oi.options_json,
      oi.internal_options_json,
      oi.order_id,
      o.order_number,
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
$material     = (string)($intOpts['_print_material']    ?? $opts['base-material']     ?? '');
$finish       = (string)($intOpts['_print_finish']      ?? $opts['graphics-finish']   ?? '');
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
  $row2Cells[] = ['type'=>'spec','label'=>$sd['label'],'value'=>trim((string)$sd['value']),'f'=>false];
}

$rows = [$row1Cells, $row2Cells];
$W        = 1800;
$padH     = 8;    // horizontalny padding
$padV     = 4;    // vertikalny padding nad/pod kazdy riadok v bunke

// Velkosti fontov (pt/px pre imagettfbbox)
$szLabel  = 7.5;   // label riadok (sivy, mensi)
$szValue  = 9.5;   // value riadok (cierny, vacsi)

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
