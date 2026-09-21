<?php
declare(strict_types=1);
ob_start();
register_shutdown_function(function () {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    while (ob_get_level() > 0)
      ob_end_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'PHP Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']]);
  }
});
// pozor, odskoky v textarea sposobuje <div class="g-opt-note-display"> Ak dám formatovať dokument
// DOČASNE — zmažať po diagnostike
file_put_contents(
  __DIR__ . '/debug.txt',
  '__DIR__=' . __DIR__ . "\n" .
  'base=' . dirname(__DIR__, 2) . "\n" .
  'connFile=' . dirname(__DIR__, 2) . '/includes/conn.php' . "\n" .
  'exists=' . (is_file(dirname(__DIR__, 2) . '/includes/conn.php') ? 'YES' : 'NO') . "\n"
);
session_start();
header('Content-Type: application/json; charset=utf-8');
function seatCoverOptionIsFilled($value): bool
{
  if (is_array($value) || is_object($value)) {
    return false;
  }

  $value = trim((string) $value);
  if ($value === '') {
    return false;
  }

  $negativeValues = ['no', 'nie', 'nein', 'non', 'false', '0', 'n/a', '-', 'x'];
  return !in_array(mb_strtolower($value), $negativeValues, true);
}

function orderDetailMoneyValue($value): ?float
{
  if ($value === null || is_array($value) || is_object($value)) {
    return null;
  }

  $value = trim(str_replace(["\u{00A0}", ' '], '', (string) $value));
  $value = preg_replace('/[^\d.,\-]/', '', $value) ?? '';
  if ($value === '') {
    return null;
  }

  if (substr_count($value, ',') === 1 && substr_count($value, '.') === 0) {
    $value = str_replace(',', '.', $value);
  } elseif (substr_count($value, ',') > 0 && substr_count($value, '.') === 1) {
    $value = str_replace(',', '', $value);
  }

  return is_numeric($value) ? (float) $value : null;
}

function orderDetailTableColumns(mysqli $conn, string $tableName): array
{
  static $cache = [];
  if (isset($cache[$tableName])) {
    return $cache[$tableName];
  }

  $columns = [];
  $stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  if (!$stmt) {
    $cache[$tableName] = $columns;
    return $columns;
  }

  $stmt->bind_param('s', $tableName);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $columns[] = (string) ($row['COLUMN_NAME'] ?? '');
  }
  $stmt->close();

  $cache[$tableName] = $columns;
  return $columns;
}

/**
 * odhadované rozdelenie nákladov pre jednotlivé dpt....
 * Hodnoty sú v percentách a mali by sa sčítať na 100 pre každý zdroj.
 */
/**
 * Percentuálne rozdelenie hodnoty objednávky podľa zdroja a kombinácie typov.
 *
 * Štruktúra: [ 'ZDROJ' => [ 'TYPY' => [ 'kategoria' => %, ... ], ... ], ... ]
 *
 * TYPY sú vždy zoradené ako G, F, P, S (napr. GFP, GPS, GFPS).
 * Percentá v každom riadku sa MUSIA sčítať na 100.
 * Kategórie s 0% vynechať — nezobrazujú sa v breakdowne.
 * Shipping je vždy prítomný (okrem prípadov kde je reálne 0).
 *
 * Dostupné kľúče: graphics | plastics | seat_covers | fitting | shipping | other
 */
function orderDetailPercentageBreakdownBySource(): array
{
  $ebay = [
    // ── Iba grafika ──────────────────────────────────────── súčet = 100
    'G' => [
      'graphics' => 90,
      'shipping' => 10,
    ],
    // ── Grafika + Plasty ─────────────────────────────────── súčet = 100
    'GP' => [
      'graphics' => 45,
      'plastics' => 45,
      'shipping' => 10,
    ],
    // ── Grafika + Fitting + Plasty ───────────────────────── súčet = 100
    'GFP' => [
      'graphics' => 40,
      'fitting'  => 10,
      'plastics' => 40,
      'shipping' => 10,
    ],
    // ── Grafika + Fitting + Plasty + Seat Cover ──────────── súčet = 100
    'GFPS' => [
      'graphics'    => 35,
      'fitting'     => 10,
      'plastics'    => 35,
      'seat_covers' => 10,
      'shipping'    => 10,
    ],
    // ── Grafika + Plasty + Seat Cover ────────────────────── súčet = 100
    'GPS' => [
      'graphics'    => 40,
      'plastics'    => 40,
      'seat_covers' => 10,
      'shipping'    => 10,
    ],
    // ── Iba Plasty ───────────────────────────────────────── súčet = 100
    'P' => [
      'plastics' => 90,
      'shipping' => 10,
    ],
    // ── Iba Seat Cover ───────────────────────────────────── súčet = 100
    'S' => [
      'seat_covers' => 90,
      'shipping'    => 10,
    ],
  ];

  // MX Locker — upraviť podľa potreby (zatiaľ rovnaké ako eBay)
  $mxLocker = $ebay;

  return [
    'EBAY'      => $ebay,
    'MX_LOCKER' => $mxLocker,
  ];
}

function productSpecDepartmentForItemType(string $itemTypeCode): string
{
  $itemTypeCode = strtoupper(trim($itemTypeCode));
  if ($itemTypeCode === 'M') {
    $itemTypeCode = 'G';
  }
  if ($itemTypeCode === 'T') {
    $itemTypeCode = 'P';
  }

  return in_array($itemTypeCode, ['G', 'S', 'P', 'F'], true) ? $itemTypeCode : '';
}

function productSpecDepartmentForItem(array $item): string
{
  $department = productSpecDepartmentForItemType((string) ($item['item_type_code'] ?? ''));
  if ($department !== '') {
    return $department;
  }

  if (function_exists('dept_get_departments')) {
    $departments = dept_get_departments(
      (string) ($item['custom_label'] ?? ''),
      (string) ($item['sku'] ?? '')
    );

    if (count($departments) === 1) {
      $resolvedDepartment = strtoupper(trim((string) $departments[0]));
      if (in_array($resolvedDepartment, ['G', 'S', 'P', 'F'], true)) {
        return $resolvedDepartment;
      }
    }
  }

  return '';
}

function productSpecValueIsFilled($value): bool
{
  if (is_array($value) || is_object($value) || $value === null) {
    return false;
  }

  return trim((string) $value) !== '';
}

function productSpecNormalizeKey(string $key): string
{
  $key = trim(mb_strtolower($key, 'UTF-8'));
  $key = preg_replace('/[^a-z0-9]+/u', '-', $key) ?? $key;
  $key = trim($key, '-');
  return preg_replace('/-+/', '-', $key) ?? $key;
}

function productSpecNormalizedValueMap(array $data): array
{
  $normalized = [];
  foreach ($data as $rawKey => $rawValue) {
    if (!is_scalar($rawValue) && $rawValue !== null) {
      continue;
    }

    $normalizedKey = productSpecNormalizeKey((string) $rawKey);
    if ($normalizedKey === '') {
      continue;
    }

    $normalized[$normalizedKey] = $rawValue;
  }

  return $normalized;
}

function productSpecValueFromKeys(array $data, array $keys): string
{
  $normalizedMap = productSpecNormalizedValueMap($data);
  foreach ($keys as $key) {
    if (!array_key_exists($key, $data)) {
      $normalizedKey = productSpecNormalizeKey((string) $key);
      if ($normalizedKey === '' || !array_key_exists($normalizedKey, $normalizedMap)) {
        continue;
      }
      $value = $normalizedMap[$normalizedKey];
      if (is_array($value) || is_object($value) || $value === null) {
        continue;
      }

      $value = trim((string) $value);
      if ($value !== '') {
        return $value;
      }
      continue;
    }

    $value = $data[$key];
    if (is_array($value) || is_object($value) || $value === null) {
      continue;
    }

    $value = trim((string) $value);
    if ($value !== '') {
      return $value;
    }
  }

  return '';
}

function productSpecFieldRoleFromParts(string $specKey, string $sourceKey, string $label): string
{
  $candidates = array_values(array_filter([
    productSpecNormalizeKey($specKey),
    productSpecNormalizeKey($sourceKey),
    productSpecNormalizeKey($label),
  ]));

  foreach ($candidates as $candidate) {
    if (
      in_array($candidate, ['material', 'base-material', 'graphics-material'], true)
      || preg_match('/(?:^|-)base-material$/', $candidate)
      || preg_match('/(?:^|-)graphics-material$/', $candidate)
    ) {
      return 'material';
    }

    if (
      in_array($candidate, ['finish', 'graphics-finish'], true)
      || preg_match('/(?:^|-)graphics-finish$/', $candidate)
    ) {
      return 'finish';
    }

    if ($candidate === 'grip' || preg_match('/(?:^|-)grip$/', $candidate)) {
      return 'grip';
    }

    if (
      in_array($candidate, ['tr-swingarms', 'swingarms'], true)
      || preg_match('/(?:^|-)tr-swingarms$/', $candidate)
    ) {
      return 'tr_swingarms';
    }

    if ($candidate === 'printer' || preg_match('/(?:^|-)printer$/', $candidate)) {
      return 'printer';
    }
  }

  return '';
}

function productSpecFieldRole(array $meta): string
{
  return productSpecFieldRoleFromParts(
    (string) ($meta['spec_key'] ?? ''),
    (string) ($meta['source_key'] ?? ''),
    (string) ($meta['label'] ?? '')
  );
}

function productSpecAddControlClass(array &$meta, string $class): void
{
  $classes = preg_split('/\s+/', trim((string) ($meta['control_class'] ?? ''))) ?: [];
  if (!in_array($class, $classes, true)) {
    $classes[] = $class;
  }
  $meta['control_class'] = trim(implode(' ', array_filter($classes)));
}

function productSpecFieldMeta(array $definition): array
{
  $specKey = (string) ($definition['spec_key'] ?? '');
  $department = (string) ($definition['department'] ?? '');
  $sourceKey = (string) ($definition['source_key'] ?? '');
  $fieldType = (string) ($definition['field_type'] ?? 'dropdown');
  $label = (string) ($definition['label'] ?? $specKey);

  $meta = [
    'spec_key' => $specKey,
    'department' => $department,
    'field_type' => $fieldType,
    'label' => $label,
    'apply_to_subcategories' => (int) (($definition['apply_to_subcategories'] ?? 0) ? 1 : 0),
    'field_sort_order' => (int) ($definition['field_sort_order'] ?? 999),
    'source_key' => $sourceKey,
    'source_keys' => array_values(array_unique(array_filter([
      $sourceKey,
      str_replace('-', '_', $sourceKey),
    ]))),
    'internal_key' => '_' . $specKey,
    'render' => $fieldType === 'text' ? 'input' : 'select',
    'wrapper_class' => 'print-setting-field product-spec-label',
    'control_class' => 'item-print-generic item-product-spec-field',
    'placeholder' => '',
    'empty_label' => 'Select...',
    'fallback_options' => [],
    'autocomplete_key' => '',
    'write_source_key' => true,
  ];
  $isNoteLikeField = (
  preg_match('/(?:^|_)(note|buyer_note|my_item_note)$/i', $specKey)
  || in_array(productSpecNormalizeKey($sourceKey), ['note', 'buyer-note', 'my-item-note'], true)
  || in_array(productSpecNormalizeKey($label), ['note', 'buyer-note', 'my-item-note'], true)
);

  switch ($specKey) {
    case 'graphics_material':
      $meta['internal_key'] = '_print_material';
      $meta['source_keys'] = ['base-material', 'base_material', 'material', 'graphics-material', 'graphics_material'];
      $meta['control_class'] = 'item-print-material item-product-spec-field';
      break;
    case 'graphics_finish':
      $meta['internal_key'] = '_print_finish';
      $meta['source_keys'] = ['graphics-finish', 'graphics_finish', 'finish'];
      $meta['control_class'] = 'item-print-finish item-product-spec-field';
      break;
    case 'graphics_grip':
      $meta['internal_key'] = '_print_grip';
      $meta['source_keys'] = ['grip'];
      $meta['control_class'] = 'item-print-grip item-product-spec-field';
      $meta['wrapper_class'] .= ' print-setting-field-grip';
      break;
    case 'graphics_tr_swingarms':
      $meta['internal_key'] = '_print_tr_swingarms';
      $meta['source_keys'] = ['tr-swingarms', 'tr_swingarms'];
      $meta['control_class'] = 'item-print-tr-swingarms item-product-spec-field';
      $meta['wrapper_class'] .= ' print-setting-field-swingarms';
      break;
    case 'graphics_printer':
      $meta['internal_key'] = '_printer';
      $meta['source_keys'] = [];
      $meta['control_class'] = 'item-print-printer item-product-spec-field';
      break;
    case 'graphics_name':
      $meta['internal_key'] = '_graphics_name';
      $meta['source_keys'] = ['name', 'rider-name', 'rider_name', 'custom-name'];
      $meta['render'] = 'autocomplete';
      break;
    case 'graphics_number':
      $meta['internal_key'] = '_graphics_number';
      $meta['source_keys'] = ['number', 'race-number', 'race_number', 'rider-number'];
      $meta['render'] = 'autocomplete';
      break;
    case 'graphics_note':
      $meta['internal_key'] = '_graphics_note';
      $meta['source_keys'] = ['note'];
      $meta['source_key'] = 'note';
      $meta['render'] = 'textarea';
      $meta['control_class'] = 'item-print-generic item-product-spec-field g-opt-note-textarea';
      $meta['write_source_key'] = true;
      $meta['placeholder'] = 'Poznámka...';
      break;
    case 'seat_patch_applied':
      $meta['internal_key'] = '_seat_patch_applied';
      $meta['source_keys'] = ['patch-style'];
      $meta['fallback_options'] = ['0' => '✗', '1' => '✓'];
      break;
  }

  $fieldRole = productSpecFieldRoleFromParts($specKey, $sourceKey, $label);
  if ($fieldRole === 'material') {
    productSpecAddControlClass($meta, 'item-print-material');
    productSpecAddControlClass($meta, 'item-print-generic');
  } elseif ($fieldRole === 'finish') {
    productSpecAddControlClass($meta, 'item-print-finish');
    productSpecAddControlClass($meta, 'item-print-generic');
  } elseif ($fieldRole === 'grip') {
    productSpecAddControlClass($meta, 'item-print-grip');
  } elseif ($fieldRole === 'tr_swingarms') {
    productSpecAddControlClass($meta, 'item-print-tr-swingarms');
  } elseif ($fieldRole === 'printer') {
    productSpecAddControlClass($meta, 'item-print-printer');
  }

  if ($isNoteLikeField) {
    $meta['render'] = 'textarea';
    $meta['control_class'] = 'item-print-generic item-product-spec-field g-opt-note-textarea';
    $meta['placeholder'] = 'Poznámka...';
    $meta['wrapper_class'] = 'product-spec-label g-opt-note-field';
  }

  if ($specKey === 'graphics_name') {
    $meta['wrapper_class'] = 'print-setting-field product-spec-label';
    $meta['autocomplete_key'] = 'graphics_name';
    $meta['placeholder'] = 'Rider name';
  } elseif ($specKey === 'graphics_number') {
    $meta['wrapper_class'] = 'print-setting-field product-spec-label';
    $meta['autocomplete_key'] = 'graphics_number';
    $meta['placeholder'] = 'Race #';
  }

  if ($meta['source_key'] === '' && !empty($meta['source_keys'][0])) {
    $meta['source_key'] = (string) $meta['source_keys'][0];
  }

  return $meta;
}

function productSpecFieldCurrentValue(array $meta, array $extOptArr, array $internalOptArr, string $sourceCode = ''): string
{
  $internalKey = (string) ($meta['internal_key'] ?? '');
  if ($internalKey !== '' && array_key_exists($internalKey, $internalOptArr)) {
    $internalValue = $internalOptArr[$internalKey];
    if (!is_array($internalValue) && !is_object($internalValue) && $internalValue !== null) {
      $internalValue = trim((string) $internalValue);
      if ($internalValue !== '') {
        return $internalValue;
      }
    } else {
      return '';
    }
  }

  if ((string) ($meta['spec_key'] ?? '') === 'graphics_note') {
    $parts = [];
    $seenNormalizedKeys = [];
    $seenValues = [];

    foreach ((array) ($meta['source_keys'] ?? []) as $key) {
      $normalizedKey = productSpecNormalizeKey((string) $key);

      if ($normalizedKey === '' || isset($seenNormalizedKeys[$normalizedKey])) {
        continue;
      }

      $seenNormalizedKeys[$normalizedKey] = true;

      $value = productSpecValueFromKeys($extOptArr, [(string) $key]);
      $value = trim((string) $value);

      if ($value === '') {
        continue;
      }

      $valueHash = md5($value);
      if (isset($seenValues[$valueHash])) {
        continue;
      }

      $seenValues[$valueHash] = true;

      if ($normalizedKey === 'note') {
        $parts[] = "Note:\n" . $value;
        continue;
      }

      if ($normalizedKey === 'buyer-note') {
        $parts[] = "Buyer note:\n" . $value;
        continue;
      }

      $parts[] = $value;
    }

    if (!empty($parts)) {
      return trim(implode("\n\n", $parts));
    }
  }

  $currentValue = productSpecValueFromKeys($extOptArr, (array) ($meta['source_keys'] ?? []));
  if ($currentValue !== '') {
    return $currentValue;
  }

  $sourceCode = strtoupper(trim($sourceCode));
  $sourceKeyNormalized = productSpecNormalizeKey((string) ($meta['source_key'] ?? ''));
  $labelNormalized = productSpecNormalizeKey((string) ($meta['label'] ?? ''));
  $specKeyNormalized = productSpecNormalizeKey((string) ($meta['spec_key'] ?? ''));

  if (
    strpos($sourceCode, 'SHOPTET') !== false
    && (
      $sourceKeyNormalized === 'buyer-note'
      || $labelNormalized === 'buyer-note'
      || preg_match('/(?:^|-)buyer-note$/', $specKeyNormalized)
    )
  ) {
    return productSpecValueFromKeys($extOptArr, ['note']);
  }

  return '';
}

function productSpecFieldHasAnyValue(array $meta, array $extOptArr, array $internalOptArr): bool
{
  $internalKey = (string) ($meta['internal_key'] ?? '');
  if ($internalKey !== '' && array_key_exists($internalKey, $internalOptArr) && productSpecValueIsFilled($internalOptArr[$internalKey])) {
    return true;
  }

  $normalizedMap = productSpecNormalizedValueMap($extOptArr);
  foreach ((array) ($meta['source_keys'] ?? []) as $key) {
    if (array_key_exists($key, $extOptArr) && productSpecValueIsFilled($extOptArr[$key])) {
      return true;
    }

    $normalizedKey = productSpecNormalizeKey((string) $key);
    if ($normalizedKey !== '' && array_key_exists($normalizedKey, $normalizedMap) && productSpecValueIsFilled($normalizedMap[$normalizedKey])) {
      return true;
    }
  }

  return false;
}

function productSpecNormalizeGraphicsSubcategory(?string $subcat): string
{
  $subcat = strtoupper(trim((string) $subcat));
  return defined('GRAPHICS_SUBCAT_LABELS') && isset(GRAPHICS_SUBCAT_LABELS[$subcat]) ? $subcat : '';
}

function productSpecGraphicsSubcategoryFromSpecKey(string $specKey, string $department): string
{
  if (strtoupper(trim($department)) !== 'G') {
    return '';
  }

  static $slugMap = null;
  if ($slugMap === null) {
    $slugMap = [];
    if (defined('GRAPHICS_SUBCAT_LABELS')) {
      foreach (GRAPHICS_SUBCAT_LABELS as $subCategoryCode => $_label) {
        $slugMap[(string) $subCategoryCode] = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', (string) $subCategoryCode));
      }
    }
  }

  $normalizedSpecKey = strtolower(trim($specKey));
  foreach ($slugMap as $subCategoryCode => $subCategorySlug) {
    $prefix = 'graphics_' . $subCategorySlug . '_';
    if (strpos($normalizedSpecKey, $prefix) === 0) {
      return productSpecNormalizeGraphicsSubcategory((string) $subCategoryCode);
    }
  }

  return '';
}

function productSpecGraphicsSubcategoryFromItemData(?string $storedSubcat, ?string $customLabel, ?string $sku): string
{
  $storedSubcat = productSpecNormalizeGraphicsSubcategory($storedSubcat);
  if ($storedSubcat !== '') {
    return $storedSubcat;
  }

  if (!defined('GRAPHICS_SUBCAT_PREFIX_MAP')) {
    return '';
  }

  foreach ([$customLabel, $sku] as $candidate) {
    $candidate = strtoupper(trim((string) $candidate));
    if ($candidate === '') {
      continue;
    }

    $candidate = explode('|', $candidate)[0];
    foreach (GRAPHICS_SUBCAT_PREFIX_MAP as $prefix => $subCategoryCode) {
      $prefix = strtoupper((string) $prefix);
      if ($prefix === '') {
        continue;
      }

      // Prefixy ako G_RT, G_MF, G_MC... sú pevné a za nimi môže nasledovať
      // čokoľvek: čísla, pomlčky, lomítka, písmená atď.
      // Preto tu používame čistý startsWith match namiesto očakávania '_'.
      if (strpos($candidate, $prefix) === 0) {
        return productSpecNormalizeGraphicsSubcategory((string) $subCategoryCode);
      }
    }
  }

  return '';
}

function patchOptionsForModal(array $options): array
{
  $allowed = [
    'patch-style',
    'name',
    'name-color',
    'name-font',
    'number',
    'number-color',
    'number-font',
  ];

  $filtered = [];
  foreach ($allowed as $key) {
    if (!array_key_exists($key, $options)) {
      continue;
    }

    $value = $options[$key];
    if ($value === null || $value === '' || is_array($value) || is_object($value)) {
      continue;
    }

    $filtered[$key] = $value;
  }

  return $filtered;
}

function out(int $code, array $payload): void
{
  while (ob_get_level() > 0)
    ob_end_clean();  // ← zmaž oba buffery
  http_response_code($code);
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  echo $json !== false ? $json : '{"ok":false,"error":"JSON encode failed"}';
  exit;
}

function jsonEncodeForModal($data): string
{
  $json = json_encode(
    $data,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
  );

  return $json !== false ? $json : '{}';
}

function jsonDecodeAssocSafe(string $json): array
{
  if ($json === '') {
    return [];
  }

  $data = json_decode($json, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
  return is_array($data) ? $data : [];
}

function orderDetailCustomItemFallbackKey(int $lineNo, string $itemTypeCode = ''): string
{
  return $lineNo . '|' . strtoupper(trim($itemTypeCode));
}

function orderDetailLoadCustomItemOptionFallbacks(mysqli $conn, array $sourceMeta): array
{
  $customOrderId = (int) ($sourceMeta['custom_order_id'] ?? 0);
  if ($customOrderId <= 0) {
    return [];
  }

  $stmt = $conn->prepare('
    SELECT line_no, item_type_code, options_json, internal_options_json
    FROM custom_order_items
    WHERE custom_order_id = ?
    ORDER BY COALESCE(line_no, 999999), id
  ');
  if (!$stmt) {
    return [];
  }

  $stmt->bind_param('i', $customOrderId);
  $stmt->execute();
  $res = $stmt->get_result();
  $fallbacks = [];
  while ($row = $res->fetch_assoc()) {
    $lineNo = (int) ($row['line_no'] ?? 0);
    if ($lineNo <= 0) {
      continue;
    }

    $fallback = [
      'options' => jsonDecodeAssocSafe((string) ($row['options_json'] ?? '{}')),
      'internal' => jsonDecodeAssocSafe((string) ($row['internal_options_json'] ?? '{}')),
    ];
    $fallbacks[orderDetailCustomItemFallbackKey($lineNo, (string) ($row['item_type_code'] ?? ''))] = $fallback;
    $fallbacks[orderDetailCustomItemFallbackKey($lineNo)] = $fallback;
  }
  $stmt->close();

  return $fallbacks;
}

function orderDetailNormalizeCustomFinancialBreakdown(array $breakdown): array
{
  $keys = [
    'total',
    'graphics',
    'plastics',
    'seat_covers',
    'fitting',
    'accessories',
    'other',
    'shipping',
    'deposits',
    'paid_net',
    'balance_due',
  ];
  $normalized = [];
  foreach ($keys as $key) {
    $value = orderDetailMoneyValue($breakdown[$key] ?? null);
    if ($value !== null) {
      $normalized[$key] = $value;
    }
  }
  $currency = strtoupper(trim((string) ($breakdown['currency'] ?? '')));
  if ($currency !== '') {
    $normalized['currency'] = $currency;
  }

  return $normalized;
}

function orderDetailLoadCustomFinancialBreakdownFallback(mysqli $conn, array $sourceMeta): array
{
  $customOrderId = (int) ($sourceMeta['custom_order_id'] ?? 0);
  if ($customOrderId <= 0) {
    return [];
  }

  $stmt = $conn->prepare("
    SELECT
      co.currency,
      COALESCE(co.shipping_price, 0) AS shipping,
      COALESCE(item_stats.graphics, 0) AS graphics,
      COALESCE(item_stats.plastics, 0) AS plastics,
      COALESCE(item_stats.seat_covers, 0) AS seat_covers,
      COALESCE(item_stats.fitting, 0) AS fitting,
      COALESCE(item_stats.accessories, 0) AS accessories,
      COALESCE(item_stats.other_total, 0) AS other_total,
      COALESCE(payment_stats.deposits, 0) AS deposits,
      COALESCE(payment_stats.paid_net, 0) AS paid_net
    FROM custom_orders co
    LEFT JOIN (
      SELECT
        custom_order_id,
        SUM(CASE WHEN UPPER(TRIM(COALESCE(item_type_code, 'M'))) = 'G' THEN qty * unit_price ELSE 0 END) AS graphics,
        SUM(CASE WHEN UPPER(TRIM(COALESCE(item_type_code, 'M'))) = 'P' THEN qty * unit_price ELSE 0 END) AS plastics,
        SUM(CASE WHEN UPPER(TRIM(COALESCE(item_type_code, 'M'))) = 'S' THEN qty * unit_price ELSE 0 END) AS seat_covers,
        SUM(CASE WHEN UPPER(TRIM(COALESCE(item_type_code, 'M'))) = 'F' THEN qty * unit_price ELSE 0 END) AS fitting,
        SUM(CASE WHEN UPPER(TRIM(COALESCE(item_type_code, 'M'))) = 'T' THEN qty * unit_price ELSE 0 END) AS accessories,
        SUM(CASE WHEN UPPER(TRIM(COALESCE(item_type_code, 'M'))) NOT IN ('G', 'P', 'S', 'F', 'T') THEN qty * unit_price ELSE 0 END) AS other_total
      FROM custom_order_items
      WHERE custom_order_id = ?
      GROUP BY custom_order_id
    ) item_stats ON item_stats.custom_order_id = co.id
    LEFT JOIN (
      SELECT
        custom_order_id,
        SUM(CASE WHEN UPPER(TRIM(payment_kind)) IN ('DEPOSIT', 'EXTRA_DEPOSIT') THEN amount ELSE 0 END) AS deposits,
        SUM(CASE WHEN UPPER(TRIM(payment_kind)) = 'REFUND' THEN -amount ELSE amount END) AS paid_net
      FROM custom_order_payments
      WHERE custom_order_id = ?
      GROUP BY custom_order_id
    ) payment_stats ON payment_stats.custom_order_id = co.id
    WHERE co.id = ?
    LIMIT 1
  ");
  if (!$stmt) {
    return [];
  }

  $stmt->bind_param('iii', $customOrderId, $customOrderId, $customOrderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) {
    return [];
  }

  $total = (float) ($row['graphics'] ?? 0)
    + (float) ($row['plastics'] ?? 0)
    + (float) ($row['seat_covers'] ?? 0)
    + (float) ($row['fitting'] ?? 0)
    + (float) ($row['accessories'] ?? 0)
    + (float) ($row['other_total'] ?? 0)
    + (float) ($row['shipping'] ?? 0);
  $paidNet = (float) ($row['paid_net'] ?? 0);

  return [
    'currency' => strtoupper(trim((string) ($row['currency'] ?? ''))),
    'total' => $total,
    'graphics' => (float) ($row['graphics'] ?? 0),
    'plastics' => (float) ($row['plastics'] ?? 0),
    'seat_covers' => (float) ($row['seat_covers'] ?? 0),
    'fitting' => (float) ($row['fitting'] ?? 0),
    'accessories' => (float) ($row['accessories'] ?? 0),
    'other' => (float) ($row['other_total'] ?? 0),
    'shipping' => (float) ($row['shipping'] ?? 0),
    'deposits' => (float) ($row['deposits'] ?? 0),
    'paid_net' => $paidNet,
    'balance_due' => $total - $paidNet,
  ];
}

function orderDetailCustomItemFallbackForItem(array $fallbacks, array $item): array
{
  $lineNo = (int) ($item['line_no'] ?? 0);
  if ($lineNo <= 0) {
    return [];
  }

  $typedKey = orderDetailCustomItemFallbackKey($lineNo, (string) ($item['item_type_code'] ?? ''));
  if (isset($fallbacks[$typedKey]) && is_array($fallbacks[$typedKey])) {
    return $fallbacks[$typedKey];
  }

  $lineKey = orderDetailCustomItemFallbackKey($lineNo);
  return isset($fallbacks[$lineKey]) && is_array($fallbacks[$lineKey]) ? $fallbacks[$lineKey] : [];
}

function orderDetailMergeCustomPrintFallbackOptions(array $extOptArr, array $internalOptArr, array $fallback): array
{
  $fallbackOptions = is_array($fallback['options'] ?? null) ? $fallback['options'] : [];
  $fallbackInternal = is_array($fallback['internal'] ?? null) ? $fallback['internal'] : [];

  $material = productSpecValueFromKeys($internalOptArr, ['_print_material']);
  if ($material === '') {
    $material = productSpecValueFromKeys($extOptArr, ['base-material', 'base_material', 'material', 'graphics-material', 'graphics_material']);
  }
  if ($material === '') {
    $material = productSpecValueFromKeys($fallbackInternal, ['_print_material']);
  }
  if ($material === '') {
    $material = productSpecValueFromKeys($fallbackOptions, ['base-material', 'base_material', 'material', 'graphics-material', 'graphics_material']);
  }
  if ($material !== '') {
    $extOptArr['base-material'] = $material;
    $extOptArr['base_material'] = $material;
    $extOptArr['material'] = $material;
  }

  $finish = productSpecValueFromKeys($internalOptArr, ['_print_finish']);
  if ($finish === '') {
    $finish = productSpecValueFromKeys($extOptArr, ['graphics-finish', 'graphics_finish', 'finish']);
  }
  if ($finish === '') {
    $finish = productSpecValueFromKeys($fallbackInternal, ['_print_finish']);
  }
  if ($finish === '') {
    $finish = productSpecValueFromKeys($fallbackOptions, ['graphics-finish', 'graphics_finish', 'finish']);
  }
  if ($finish !== '') {
    $extOptArr['graphics-finish'] = $finish;
    $extOptArr['graphics_finish'] = $finish;
    $extOptArr['finish'] = $finish;
  }

  return $extOptArr;
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
require_once $base . '/includes/orders_status_helpers.php';
require_once $base . '/includes/orders_customs_helpers.php';
require_once $base . '/includes/get_order_detail_product_spec_selects.php';
require_once __DIR__ . '/department_config.php';
require_once __DIR__ . '/manual_item_builder_helper.php';
require_once __DIR__ . '/financial_helpers.php';

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

// ── Label script path (relative to the web root, adjust if needed) ───────────
define('LABEL_BASE_PATH', 'scripts/labels/');

function item_type_category_badge(array $item, array $order, array $addr, string $orderCountry): string
{
  static $labelMap = [
  'G' => ['G', 'Graphics', 'label_rtp.php'],
  'P' => ['P', 'Plastics', 'label_index.php'],
  'F' => ['F', 'Fitting', 'label_index.php'],
  'T' => ['P', 'Plastics', 'label_index.php'],
  'M' => ['P', 'Plastics', 'label_index.php'],
  'S' => ['S', 'Seat Cover', 'label_seat.php'],
  ];

  $type = strtoupper(trim((string) ($item['item_type_code'] ?? '')));
  [$shortLabel, $fullLabel, $script] = $labelMap[$type] ?? ['?', 'Unknown', ''];

  if ($script === '') {
    return '<span class="badge badge-product-type">' . h($shortLabel) . '</span>';
  }

  // ── Zostavenie parametrov štítku ─────────────────────────────────────────
  $orderNum = (string) ($order['order_number'] ?? $order['external_order_id'] ?? '');
  $customer = trim((string) ($order['customer_name'] ?? $order['customer_email'] ?? ''));
  $ship = trim((string) ($order['shipping_method'] ?? $order['shipping_code'] ?? ''));
  $rawDate = trim((string) ($order['created_at'] ?? ''));
  $date = $rawDate !== '' ? date('d.m.Y', strtotime($rawDate)) : '';
  $prodNote = trim((string) ($order['production_note'] ?? ''));
  $sourceCode = trim((string) ($order['source_code'] ?? ''));

  // options_json
  $opts = jsonDecodeAssocSafe((string) ($item['options_json'] ?? '{}'));
  $intOpts = jsonDecodeAssocSafe((string) ($item['internal_options_json'] ?? '{}'));

  $basematerial = productSpecValueFromKeys($intOpts, ['_print_material']);
  if ($basematerial === '') {
    $basematerial = productSpecValueFromKeys($opts, ['base-material', 'base_material', 'material', 'graphics-material', 'graphics_material']);
  }
  $finish = productSpecValueFromKeys($intOpts, ['_print_finish']);
  if ($finish === '') {
    $finish = productSpecValueFromKeys($opts, ['graphics-finish', 'graphics_finish', 'finish']);
  }
  $printer = (string) ($intOpts['_printer'] ?? '');
  $itemTitle = trim((string) ($item['custom_label'] ?? $item['title'] ?? ''));

  // Seat-specific
  $seatMaterial = (string) ($opts['material'] ?? $opts['seat-material'] ?? '');
  $bike = (string) ($opts['bike'] ?? $opts['bike-brand'] ?? $opts['brand'] ?? '');
  $version = (string) ($opts['version'] ?? $opts['seat-version'] ?? '');
  $extra = (string) ($opts['extra'] ?? '');

  // label_rtp.php si načíta všetko z DB podľa item_id
  if ($type === 'G') {
    $url = LABEL_BASE_PATH . $script . '?item_id=' . (int) ($item['id'] ?? 0);
  } else {
    $params = [
      'order'       => $orderNum,
      'name'        => $customer,
      'country'     => $orderCountry,
      'gfp'         => $type,
      'item'        => $itemTitle,
      'ship'        => $ship,
      'date'        => $date,
      'note'        => $prodNote,
      'extra'       => $extra,
      'basematerial'=> $basematerial,
      'finish'      => $finish,
      'printer'     => $printer,
      'material'    => $seatMaterial,
      'bike'        => $bike,
      'version'     => $version,
    ];
    $url = LABEL_BASE_PATH . $script . '?' . http_build_query($params);
  }

  return '<a href="' . h($url) . '" target="_blank" rel="noopener"'
    . ' class="badge badge-product-type"'
    . ' style="cursor:pointer; text-decoration:none;"'
    . ' title="Vytlacit stitok - ' . h($fullLabel) . '">'
    . h($shortLabel)
    . '</a>';
}

function trafficTypesStringFromOrder(array $order, array $items = []): string
{
  $summary = json_decode((string) ($order['traffic_summary_json'] ?? ''), true);

  $orderTypes = ['G', 'F', 'P', 'S'];
  $out = '';

  if (is_array($summary)) {
    foreach ($orderTypes as $type) {
      if (array_key_exists($type, $summary)) {
        $out .= $type;
      }
    }
  }

  if ($out !== '') {
    return $out;
  }

  foreach ($items as $item) {
    $type = strtoupper(trim((string) ($item['item_type_code'] ?? '')));

    if ($type === 'T' || $type === 'M') {
      $type = 'P';
    }

    if (in_array($type, $orderTypes, true) && strpos($out, $type) === false) {
      $out .= $type;
    }
  }

  return $out;
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
  out(500, ['ok' => false, 'error' => 'SQL prepare failed: ' . mysqli_error($conn)]);
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- production note thread ---
$stmt = $conn->prepare("
  SELECT
    n.id,
    n.note,
    n.created_at,
    e.firstname,
    e.lastname,
    e.photo
  FROM order_production_notes n
  LEFT JOIN employees e ON e.id = n.user_id
  WHERE n.order_id = ?
  ORDER BY n.created_at ASC, n.id ASC
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$productionNotes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// item_type_category_badge() and the RTP label params below still read
// $order['production_note'] directly (they print the latest note on labels),
// so keep it pointed at the most recent thread entry instead of the stale
// orders.production_note column.
$order['production_note'] = !empty($productionNotes)
  ? (string) (end($productionNotes)['note'] ?? '')
  : '';

if (!$order)
  out(404, ['ok' => false, 'error' => 'Order not found']);

$sourceMeta = json_decode((string) ($order['source_meta'] ?? ''), true);
if (!is_array($sourceMeta)) {
  $sourceMeta = [];
}

$isCustomOrder = strtoupper(trim((string) ($order['source_code'] ?? ''))) === 'CUSTOM';
$customFinancialBreakdown = [];
if ($isCustomOrder) {
  if (is_array($sourceMeta['financial_breakdown'] ?? null)) {
    $customFinancialBreakdown = orderDetailNormalizeCustomFinancialBreakdown($sourceMeta['financial_breakdown']);
  }
  if (empty($customFinancialBreakdown)) {
    $customFinancialBreakdown = orderDetailLoadCustomFinancialBreakdownFallback($conn, $sourceMeta);
  }
  if (!isset($sourceMeta['shipping_price']) && isset($customFinancialBreakdown['shipping'])) {
    $sourceMeta['shipping_price'] = $customFinancialBreakdown['shipping'];
  }
}

$financialInfo = order_financial_effective_totals($conn, $order, $sourceMeta);
$financialAdjustmentRows = order_financial_fetch_adjustments($conn, $orderId);
$financialBaseTotal = (float) $financialInfo['base_total'];
$financialAdjustmentsTotal = (float) $financialInfo['adjustments_total'];
$financialCalculatedTotal = (float) $financialInfo['calculated_total'];
$financialTotalOverrideActive = (bool) $financialInfo['override_active'];
$financialEffectiveTotal = (float) $financialInfo['effective_total'];
$financialSourceCurrency = (string) $financialInfo['source_currency'];
$financialEffectiveCurrency = (string) $financialInfo['effective_currency'];
$financialOverrideCurrency = (string) $financialInfo['override_currency'];
$financialCanEdit = (int) ($_SESSION['permission'] ?? 0) >= 400;

$followupMeta = is_array($sourceMeta['_followup'] ?? null) ? $sourceMeta['_followup'] : [];
$followupTypeLabels = [
  'REPEAT' => 'Repeat Order',
  'WARRANTY' => 'Warranty Claim',
  'CRASH' => 'Crash Replacement',
  'SPLIT' => 'Order Split',
];
$followupTypeCode = strtoupper(trim((string) ($followupMeta['type'] ?? '')));
$followupLabel = $followupTypeLabels[$followupTypeCode] ?? trim((string) ($followupMeta['label'] ?? ''));
$followupParentOrderId = (int) ($followupMeta['parent_order_id'] ?? 0);
$followupParentOrderNumber = trim((string) ($followupMeta['parent_order_number'] ?? ''));
$followupReason = trim((string) ($followupMeta['reason'] ?? ''));
$followupDoNotInvoice = !empty($followupMeta['do_not_invoice']);

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
$orderHasPlasticsCategory = in_array('PLASTICS', array_map('strtoupper', $cats), true);

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
$customsIdentifier = trim((string) ($order['customs_identifier'] ?? ''));
$customsIdentifierMissing = ordersIsCustomsIdentifierMissing($orderCountry, $customsIdentifier);
$customsIdentifierLabel = ordersCustomsIdentifierLabel($orderCountry);
$customsCountryLabels = ordersCustomsIdentifierCountryLabels();
$displayCustomerPhone = '';

if (!empty($addr['SHIPPING']['phone'])) {
  $displayCustomerPhone = (string) $addr['SHIPPING']['phone'];
} elseif (!empty($addr['BILLING']['phone'])) {
  $displayCustomerPhone = (string) $addr['BILLING']['phone'];
} else {
  $displayCustomerPhone = (string) ($order['customer_phone'] ?? '');
}

$deliveryContactPhone = trim((string) ($addr['SHIPPING']['phone'] ?? ''));
if ($deliveryContactPhone === '') {
  $deliveryContactPhone = trim($displayCustomerPhone);
}
$deliveryEmail = trim((string) ($addr['SHIPPING']['email'] ?? ''));
if ($deliveryEmail === '') {
  $deliveryEmail = trim((string) ($order['customer_email'] ?? ($addr['BILLING']['email'] ?? '')));
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
              AND oa.role IN (
                CONCAT('PRIMARY_', CASE
                  WHEN order_items.item_type_code = 'G' THEN 'GRAPHICS'
                  WHEN order_items.item_type_code IN ('P', 'T', 'M') THEN 'PLASTICS'
                  WHEN order_items.item_type_code = 'S' THEN 'SEATCOVER'
                  WHEN order_items.item_type_code = 'F' THEN 'FITTING'
                  ELSE ''
                END),
                CONCAT('COLLAB_', CASE
                  WHEN order_items.item_type_code = 'G' THEN 'GRAPHICS'
                  WHEN order_items.item_type_code IN ('P', 'T', 'M') THEN 'PLASTICS'
                  WHEN order_items.item_type_code = 'S' THEN 'SEATCOVER'
                  WHEN order_items.item_type_code = 'F' THEN 'FITTING'
                  ELSE ''
                END)
              )
              AND oa.removed_at IS NULL
            ORDER BY
              CASE
                WHEN oa.role LIKE 'PRIMARY_%' THEN 1
                ELSE 2
              END,
              oa.id
            LIMIT 1
          ), 0), '|',
          oia.id, '|',
          COALESCE(oia.assignment_role, 'WORKER')
        )
        ORDER BY
          CASE COALESCE(oia.assignment_role, 'WORKER')
            WHEN 'PREPARED' THEN 1
            WHEN 'CHECKED' THEN 2
            ELSE 3
          END,
          e.firstname,
          e.lastname
        SEPARATOR ';;'
      )
      FROM order_item_assignments oia
      JOIN employees e ON e.id = oia.employee_id
      WHERE oia.item_id = order_items.id
        AND oia.removed_at IS NULL
        AND (
          oia.assignment_role IN ('PREPARED', 'CHECKED')
          OR NOT EXISTS (
            SELECT 1
            FROM order_item_assignments oia_role
            WHERE oia_role.item_id = order_items.id
              AND oia_role.assignment_role IN ('PREPARED', 'CHECKED')
              AND oia_role.removed_at IS NULL
          )
        )
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
while ($it = $r->fetch_assoc()) {
  $it['item_assigned_users_raw'] = (string) ($it['item_assigned_users'] ?? '');
  $items[] = $it;
}
$stmt->close();
$customItemOptionFallbacks = orderDetailLoadCustomItemOptionFallbacks($conn, $sourceMeta);

$removedPreparedByItem = [];
if ($items) {
  $itemIdsForRemovedPrepared = [];
  foreach ($items as $itemForRemovedPrepared) {
    $itemIdForRemovedPrepared = (int) ($itemForRemovedPrepared['id'] ?? 0);
    if ($itemIdForRemovedPrepared > 0) {
      $itemIdsForRemovedPrepared[] = $itemIdForRemovedPrepared;
    }
  }
  $itemIdsForRemovedPrepared = array_values(array_unique($itemIdsForRemovedPrepared));

  if ($itemIdsForRemovedPrepared) {
    $removedPlaceholders = implode(',', array_fill(0, count($itemIdsForRemovedPrepared), '?'));
    $removedTypes = 'i' . str_repeat('i', count($itemIdsForRemovedPrepared));
    $removedParams = array_merge([$orderId], $itemIdsForRemovedPrepared);
    $removedPreparedStmt = $conn->prepare("
      SELECT item_id, employee_id, MAX(assigned_at) AS assigned_at
      FROM order_item_assignments
      WHERE order_id = ?
        AND item_id IN ($removedPlaceholders)
        AND assignment_role = 'PREPARED'
        AND removed_at IS NOT NULL
      GROUP BY item_id, employee_id
    ");

    if ($removedPreparedStmt) {
      $removedPreparedStmt->bind_param($removedTypes, ...$removedParams);
      $removedPreparedStmt->execute();
      $removedPreparedResult = $removedPreparedStmt->get_result();
      while ($removedPreparedRow = $removedPreparedResult->fetch_assoc()) {
        $removedItemId = (int) ($removedPreparedRow['item_id'] ?? 0);
        $removedEmployeeId = (int) ($removedPreparedRow['employee_id'] ?? 0);
        if ($removedItemId > 0 && $removedEmployeeId > 0) {
          $removedPreparedByItem[$removedItemId][$removedEmployeeId] = (string) ($removedPreparedRow['assigned_at'] ?? '');
        }
      }
      $removedPreparedStmt->close();
    }
  }
}

// Doplní avatar človeka, ktorý prevzal objednávku cez TAKE.
// TAKE zapisuje department-level assignment do order_assignments,
// zatiaľ čo pôvodná bunka Assigned čítala iba order_item_assignments.
$deptAssignmentRows = [];
$deptAssignmentStmt = $conn->prepare("
  SELECT
    oa.id AS assignment_id,
    oa.employee_id,
    oa.role,
    oa.assigned_at,
    TRIM(CONCAT(e.firstname, ' ', e.lastname)) AS employee_name,
    COALESCE(e.photo, '') AS photo
  FROM order_assignments oa
  JOIN employees e ON e.id = oa.employee_id
  WHERE oa.order_id = ?
    AND oa.removed_at IS NULL
    AND oa.role IN (
      'PRIMARY_GRAPHICS',
      'PRIMARY_PLASTICS',
      'PRIMARY_SEATCOVER',
      'PRIMARY_FITTING'
    )
  ORDER BY e.firstname, e.lastname
");

if ($deptAssignmentStmt) {
  $deptAssignmentStmt->bind_param('i', $orderId);
  $deptAssignmentStmt->execute();
  $deptAssignmentResult = $deptAssignmentStmt->get_result();
  while ($deptAssignment = $deptAssignmentResult->fetch_assoc()) {
    $deptAssignmentRows[] = $deptAssignment;
  }
  $deptAssignmentStmt->close();
}

if ($deptAssignmentRows) {
  $roleTypeMap = [
    'PRIMARY_GRAPHICS' => ['G'],
    'PRIMARY_PLASTICS' => ['P', 'T', 'M'],
    'PRIMARY_SEATCOVER' => ['S'],
    'PRIMARY_FITTING' => ['F'],
  ];

  foreach ($items as &$itemForDeptAssignment) {
    $itemIdForDeptAssignment = (int) ($itemForDeptAssignment['id'] ?? 0);
    $itemTypeForDeptAssignment = strtoupper(trim((string) ($itemForDeptAssignment['item_type_code'] ?? '')));
    $existingAssignedRaw = trim((string) ($itemForDeptAssignment['item_assigned_users'] ?? ''));
    $existingEmployeeIds = [];
    $hasPreparedAssignment = false;

    if ($existingAssignedRaw !== '') {
      foreach (explode(';;', $existingAssignedRaw) as $existingAssignmentPart) {
        $existingAssignmentBits = explode('|', $existingAssignmentPart);
        if (!empty($existingAssignmentBits[0])) {
          $existingEmployeeIds[(int) $existingAssignmentBits[0]] = true;
        }
        if (strtoupper((string) ($existingAssignmentBits[5] ?? '')) === 'PREPARED') {
          $hasPreparedAssignment = true;
        }
      }
    }

    $assignmentPartsToAdd = [];
    foreach ($deptAssignmentRows as $deptAssignment) {
      $role = (string) ($deptAssignment['role'] ?? '');
      if (!in_array($itemTypeForDeptAssignment, $roleTypeMap[$role] ?? [], true)) {
        continue;
      }
      if ($hasPreparedAssignment) {
        continue;
      }

      $employeeId = (int) ($deptAssignment['employee_id'] ?? 0);
      if ($employeeId <= 0 || isset($existingEmployeeIds[$employeeId])) {
        continue;
      }
      $removedPreparedAssignedAt = $removedPreparedByItem[$itemIdForDeptAssignment][$employeeId] ?? '';
      $orderAssignmentAssignedAt = (string) ($deptAssignment['assigned_at'] ?? '');
      if ($removedPreparedAssignedAt !== '' && ($orderAssignmentAssignedAt === '' || strcmp($removedPreparedAssignedAt, $orderAssignmentAssignedAt) >= 0)) {
        continue;
      }

      $assignmentPartsToAdd[] = implode('|', [
        $employeeId,
        (string) ($deptAssignment['employee_name'] ?? ''),
        (string) ($deptAssignment['photo'] ?? ''),
        (int) ($deptAssignment['assignment_id'] ?? 0),
        0,
        'PREPARED',
      ]);
      $existingEmployeeIds[$employeeId] = true;
    }

    if ($assignmentPartsToAdd) {
      $itemForDeptAssignment['item_assigned_users'] = trim(
        $existingAssignedRaw . ($existingAssignedRaw !== '' ? ';;' : '') . implode(';;', $assignmentPartsToAdd),
        ';'
      );
    }
  }
  unset($itemForDeptAssignment);
}

// Zoradiť položky podľa departmentu: G → P → F → ostatné
$deptOrder = ['G' => 1, 'P' => 2, 'T' => 2, 'M' => 2, 'S' => 2, 'F' => 3];
usort($items, function (array $a, array $b) use ($deptOrder): int {
  $ta = strtoupper(trim((string) ($a['item_type_code'] ?? '')));
  $tb = strtoupper(trim((string) ($b['item_type_code'] ?? '')));
  $wa = $deptOrder[$ta] ?? 99;
  $wb = $deptOrder[$tb] ?? 99;
  if ($wa !== $wb)
    return $wa <=> $wb;
  // V rámci rovnakého departmentu zachovaj pôvodné poradie (line_no, id)
  $la = (int) ($a['line_no'] ?? 999999);
  $lb = (int) ($b['line_no'] ?? 999999);
  if ($la !== $lb)
    return $la <=> $lb;
  return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
});

// ── Zoskupenie Seat Cover položky s jej auto-generovaným Patch (aby boli v tabuľke hneď pri sebe) ──
// Nemáme priamy prepojovací kľúč medzi Patch položkou a jej Seat Cover položkou,
// preto párujeme podľa poradia výskytu (v praxi je v objednávke jeden Seat Cover a jeden Patch).
$seatCoverPatchGroups = []; // seatItemId => patchItemId
{
  $seatItemIdsForGrouping = [];
  $patchItemIdsForGrouping = [];
  foreach ($items as $groupCandidateItem) {
    $groupCandidateType = strtoupper(trim((string) ($groupCandidateItem['item_type_code'] ?? '')));
    if ($groupCandidateType === 'S') {
      $seatItemIdsForGrouping[] = (int) ($groupCandidateItem['id'] ?? 0);
      continue;
    }
    $groupCandidateOpts = jsonDecodeAssocSafe((string) ($groupCandidateItem['options_json'] ?? '{}'));
    if (($groupCandidateOpts['_auto_generated'] ?? '') === 'SEAT_PATCH_AUTO_GRAPHICS') {
      $patchItemIdsForGrouping[] = (int) ($groupCandidateItem['id'] ?? 0);
    }
  }

  $seatPatchPairCount = min(count($seatItemIdsForGrouping), count($patchItemIdsForGrouping));
  for ($seatPatchPairIndex = 0; $seatPatchPairIndex < $seatPatchPairCount; $seatPatchPairIndex++) {
    $seatCoverPatchGroups[$seatItemIdsForGrouping[$seatPatchPairIndex]] = $patchItemIdsForGrouping[$seatPatchPairIndex];
  }
}
$patchToSeatCoverGroup = array_flip($seatCoverPatchGroups); // patchItemId => seatItemId

if ($seatCoverPatchGroups) {
  $itemsReorderedForGrouping = [];
  $pendingPatchItemsById = [];

  foreach ($items as $orderedGroupItem) {
    $orderedGroupItemId = (int) ($orderedGroupItem['id'] ?? 0);

    // Patch položku odložíme nabok a vložíme ju hneď za jej párovú Seat Cover položku.
    if (isset($patchToSeatCoverGroup[$orderedGroupItemId])) {
      $pendingPatchItemsById[$orderedGroupItemId] = $orderedGroupItem;
      continue;
    }

    $itemsReorderedForGrouping[] = $orderedGroupItem;

    if (
      isset($seatCoverPatchGroups[$orderedGroupItemId])
      && isset($pendingPatchItemsById[$seatCoverPatchGroups[$orderedGroupItemId]])
    ) {
      $itemsReorderedForGrouping[] = $pendingPatchItemsById[$seatCoverPatchGroups[$orderedGroupItemId]];
      unset($pendingPatchItemsById[$seatCoverPatchGroups[$orderedGroupItemId]]);
    }
  }

  // Poistka: ak by Patch položka predsa len predbehla svoju Seat Cover položku
  // (za bežných okolností sa nestane, dept order dáva G pred S), doplníme na koniec.
  foreach ($pendingPatchItemsById as $leftoverPatchItem) {
    $itemsReorderedForGrouping[] = $leftoverPatchItem;
  }

  $items = $itemsReorderedForGrouping;
}

$orderValueBreakdown = [
  'graphics' => 0.0,
  'plastics' => 0.0,
  'seat_covers' => 0.0,
  'fitting' => 0.0,
  'accessories' => 0.0,
  'other' => 0.0,
];
foreach ($items as $breakdownItem) {
  $lineValue = (float) ($breakdownItem['unit_price'] ?? 0) * max(1, (int) ($breakdownItem['qty'] ?? 1));
  $itemDepartment = productSpecDepartmentForItemType((string) ($breakdownItem['item_type_code'] ?? ''));
  if ($itemDepartment === 'G') {
    $orderValueBreakdown['graphics'] += $lineValue;
  } elseif ($itemDepartment === 'P') {
    $orderValueBreakdown['plastics'] += $lineValue;
  } elseif ($itemDepartment === 'S') {
    $orderValueBreakdown['seat_covers'] += $lineValue;
  } elseif ($itemDepartment === 'F') {
    $orderValueBreakdown['fitting'] += $lineValue;
  } else {
    $orderValueBreakdown['other'] += $lineValue;
  }
}
$orderValueBreakdown['shipping'] = orderDetailMoneyValue($sourceMeta['shipping_price'] ?? null) ?? 0.0;
$orderValueBreakdown['total'] = $financialEffectiveTotal;

if (!empty($customFinancialBreakdown)) {
  foreach (['graphics', 'plastics', 'seat_covers', 'fitting', 'accessories', 'other', 'shipping'] as $breakdownKey) {
    $orderValueBreakdown[$breakdownKey] = (float) ($customFinancialBreakdown[$breakdownKey] ?? 0.0);
  }
}

$percentageBreakdownConfig = orderDetailPercentageBreakdownBySource();
$breakdownSourceCode = strtoupper(trim((string) ($order['source_code'] ?? '')));

// ── SHOPTET: breakdown podľa skutočných položiek s podkategóriami grafiky ──
$isShoptetOrder = (strpos($breakdownSourceCode, 'SHOPTET') !== false);
$shoptetBreakdown = []; // [['label' => string, 'value' => float], ...]

if ($isShoptetOrder) {
  // Fitting fee je vždy zahrnutý v cene hlavnej grafickej položky (G_).
  // Na Shoptete ide o checkbox "+39.90 €" pri grafike — cena fitting sa nikdy
  // neviaže na podkategórie (G_RT, G_MF...), iba na hlavné G položky.
  // Zistime, či objednávka obsahuje fitting, priamo z item_type_code položiek.
  $shoptetFittingFee = 39.90;

  $shoptetGraphicsSubcats = [];
  $shoptetMainGCount      = 0;   // počet hlavných G položiek (nie podkategórie)
  $shoptetHasFitting      = false;
  $shoptetPlastics        = 0.0;
  $shoptetSeatCovers      = 0.0;
  $shoptetOther           = 0.0;

  foreach ($items as $shoptetItem) {
    $lineValue   = (float) ($shoptetItem['unit_price'] ?? 0) * max(1, (int) ($shoptetItem['qty'] ?? 1));
    $rawTypeCode = strtoupper(trim((string) ($shoptetItem['item_type_code'] ?? '')));
    $itemDept    = productSpecDepartmentForItemType($rawTypeCode);

    if ($rawTypeCode === 'F') {
      // F položka = fitting service — cena je fyzicky v grafike, tu ju ignorujeme
      $shoptetHasFitting = true;
      continue;
    }

    if ($itemDept === 'G') {
      $subcat    = productSpecGraphicsSubcategoryFromItemData(
        $shoptetItem['graphics_subcat'] ?? null,
        $shoptetItem['custom_label']    ?? null,
        $shoptetItem['sku']             ?? null
      );

      if ($subcat === '') {
        // Hlavná G položka (nie podkategória) — fitting sa viaže práve k nej
        $shoptetMainGCount++;
        $subcatKey = 'G_MAIN';
      } else {
        $subcatKey = $subcat;
      }

      $shoptetGraphicsSubcats[$subcatKey] = ($shoptetGraphicsSubcats[$subcatKey] ?? 0.0) + $lineValue;
    } elseif ($itemDept === 'P') {
      $shoptetPlastics += $lineValue;
    } elseif ($itemDept === 'S') {
      $shoptetSeatCovers += $lineValue;
    } else {
      $shoptetOther += $lineValue;
    }
  }

  // Fitting: vypočítame sumu a odrátime ju od hlavných G položiek
  $shoptetFittingTotal = 0.0;
  if ($shoptetHasFitting && $shoptetMainGCount > 0) {
    $shoptetFittingTotal = $shoptetFittingFee * $shoptetMainGCount;
    // Odrátime fitting z hlavných G položiek (hodnota nesmie ísť pod 0)
    $shoptetGraphicsSubcats['G_MAIN'] = max(
      0.0,
      ($shoptetGraphicsSubcats['G_MAIN'] ?? 0.0) - $shoptetFittingTotal
    );
  }

  // Grafické položky — výpis (G_MAIN ako "Graphics", podkategórie s vlastným labelom)
  foreach ($shoptetGraphicsSubcats as $subcatCode => $subcatValue) {
    if ($subcatValue <= 0.0) continue;
    if ($subcatCode === 'G_MAIN') {
      $subcatLabel = 'Graphics';
    } elseif (defined('GRAPHICS_SUBCAT_LABELS') && isset(GRAPHICS_SUBCAT_LABELS[$subcatCode])) {
      $subcatLabel = GRAPHICS_SUBCAT_LABELS[$subcatCode];
    } else {
      $subcatLabel = $subcatCode;
    }
    $shoptetBreakdown[] = ['label' => $subcatLabel, 'value' => $subcatValue];
  }

  // Fitting — len ak existuje
  if ($shoptetFittingTotal > 0.0) {
    $shoptetBreakdown[] = ['label' => 'Fitting', 'value' => $shoptetFittingTotal];
  }

  // Ostatné departmenty — len ak nenulové
  if ($shoptetPlastics   > 0.0) $shoptetBreakdown[] = ['label' => 'Plastics',    'value' => $shoptetPlastics];
  if ($shoptetSeatCovers > 0.0) $shoptetBreakdown[] = ['label' => 'Seat Covers', 'value' => $shoptetSeatCovers];

  $shoptetShipping = orderDetailMoneyValue($sourceMeta['shipping_price'] ?? null) ?? 0.0;
  if ($shoptetShipping   > 0.0) $shoptetBreakdown[] = ['label' => 'Shipping',    'value' => $shoptetShipping];
  if ($shoptetOther      > 0.0) $shoptetBreakdown[] = ['label' => 'Other',        'value' => $shoptetOther];
}
// ── koniec SHOPTET breakdown ──

// Typ objednávky potrebujeme aj pre lookup percentuálneho breakdownu (EBAY/MX_LOCKER).
// trafficTypesStringFromOrder() vracia zoradený reťazec napr. "GFP", "GPS", "G"...
// Definujeme ho tu skôr, aby bol dostupný pre percentage lookup nižšie.
$orderTrafficTypes = trafficTypesStringFromOrder($order, $items);

// ── EBAY / MX_LOCKER: percentuálny breakdown podľa zdroja + kombinácie typov ──
// Lookup: $percentageBreakdownConfig['EBAY']['GFP'] = ['graphics' => 35, ...]
// Typy normalizujeme do kanonického poradia G→F→P→S (rovnako ako normalizeTypesOrder()
// v orders.php), pretože trafficTypesStringFromOrder() vracia poradie podľa položiek.
$activePercentageBreakdown = null;
if (!$isShoptetOrder && isset($percentageBreakdownConfig[$breakdownSourceCode])) {
  $sourceTypeMap = $percentageBreakdownConfig[$breakdownSourceCode];

  // Zoradíme znaky podľa kanonického poradia G=1, F=2, P=3, S=4
  $typeWeights  = ['G' => 1, 'F' => 2, 'P' => 3, 'S' => 4];
  $typeChars    = str_split(strtoupper($orderTrafficTypes));
  usort($typeChars, static fn($a, $b) => ($typeWeights[$a] ?? 99) <=> ($typeWeights[$b] ?? 99));
  $orderTypeKey = implode('', $typeChars); // napr. "GFPS", "GFP", "G"...

  if (isset($sourceTypeMap[$orderTypeKey])) {
    $activePercentageBreakdown = $sourceTypeMap[$orderTypeKey];
  }
}

if ($activePercentageBreakdown !== null) {
  $pricedItemsTotal = $orderValueBreakdown['graphics']
    + $orderValueBreakdown['plastics']
    + $orderValueBreakdown['seat_covers']
    + $orderValueBreakdown['fitting']
    + $orderValueBreakdown['accessories']
    + $orderValueBreakdown['other'];
  $percentageTotal = $financialEffectiveTotal > 0
    ? $financialEffectiveTotal
    : ($pricedItemsTotal > 0 ? $pricedItemsTotal : $orderValueBreakdown['total']);
  $totalCents       = (int) round($percentageTotal * 100);
  $allocatedCents   = 0;

  $nonZeroPercentageKeys = array_keys(array_filter(
    $activePercentageBreakdown,
    static fn($pct) => (float) $pct > 0
  ));
  $lastPercentageKey = end($nonZeroPercentageKeys);

  // Vynulujeme všetky breakdown kľúče — zobrazíme len tie, čo sú v konfigu
  foreach (['graphics', 'plastics', 'seat_covers', 'fitting', 'accessories', 'shipping', 'other'] as $resetKey) {
    $orderValueBreakdown[$resetKey] = 0.0;
  }

  foreach ($activePercentageBreakdown as $breakdownKey => $percentage) {
    $valueCents = $breakdownKey === $lastPercentageKey
      ? $totalCents - $allocatedCents
      : (int) round($totalCents * ((float) $percentage / 100));
    $orderValueBreakdown[$breakdownKey] = $valueCents / 100;
    $allocatedCents += $valueCents;
  }
  $orderValueBreakdown['total'] = $totalCents / 100;
}

$orderValueBreakdown['total'] = $financialEffectiveTotal;
$financialBreakdownAdjustment = 0.0;

if ($activePercentageBreakdown === null) {
  if ($isShoptetOrder && !empty($shoptetBreakdown)) {
    $listedBreakdownTotal = array_sum(array_map(
      static fn($row) => (float) ($row['value'] ?? 0.0),
      $shoptetBreakdown
    ));
  } else {
    $listedBreakdownTotal = $orderValueBreakdown['graphics']
      + $orderValueBreakdown['plastics']
      + $orderValueBreakdown['seat_covers']
      + $orderValueBreakdown['fitting']
      + $orderValueBreakdown['accessories']
      + $orderValueBreakdown['shipping']
      + $orderValueBreakdown['other'];
  }

  $financialBreakdownAdjustment = round($financialEffectiveTotal - $listedBreakdownTotal, 2);
}

if ($isShoptetOrder && abs($financialBreakdownAdjustment) >= 0.005) {
  $shoptetBreakdown[] = [
    'label' => $financialBreakdownAdjustment >= 0 ? 'Financial adjustment' : 'Financial refund / adjustment',
    'value' => $financialBreakdownAdjustment,
  ];
  $financialBreakdownAdjustment = 0.0;
}

$paymentReceivedAmount = orderDetailMoneyValue($order['payment_received_amount'] ?? null);
$paymentDifference = $paymentReceivedAmount !== null
  ? round($paymentReceivedAmount - $orderValueBreakdown['total'], 2)
  : null;

$orderCurrency = $financialEffectiveCurrency;
$orderCurrencySuffix = order_financial_currency_suffix($orderCurrency);
$financialSourceCurrencySuffix = order_financial_currency_suffix($financialSourceCurrency);
$financialEffectiveCurrencySuffix = order_financial_currency_suffix($financialEffectiveCurrency);

$status = (string) ($order['status'] ?? '');
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

$statusLabels = ordersGetOrderStatusLabels($conn, true);
$statusOptions = array_keys($statusLabels);

$currentStatus = strtoupper(trim((string) ($order['status'] ?? 'NEW')));
if ($currentStatus === '') {
  $currentStatus = 'NEW';
}
if (!in_array($currentStatus, $statusOptions, true)) {
  $statusOptions[] = $currentStatus;
}

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
  $data = jsonDecodeAssocSafe($json ?: '{}');

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

  return jsonEncodeForModal($data);
}

function prepareEditableOptionsJsonForModal(string $json): string
{
  $data = jsonDecodeAssocSafe($json ?: '{}');
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

  return jsonEncodeForModal($editable);
}

function optionLabelMapForModal(
  mysqli $conn,
  array $data,
  string $itemTypeCode = '',
  string $graphicsSubcategory = ''
): array
{
  $department = productSpecDepartmentForItemType($itemTypeCode);
  $labels = [];

  foreach ($data as $key => $value) {
    if ($value === null || $value === '' || is_array($value) || is_object($value)) {
      continue;
    }

    $stringKey = (string) $key;
    if ($stringKey === '' || strpos($stringKey, '_') === 0) {
      continue;
    }

    $labels[$stringKey] = productSpecDisplayLabelForOptionKey(
      $conn,
      $stringKey,
      $department,
      $graphicsSubcategory
    );
  }

  return $labels;
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

function orderDetailCategoryFieldsFromOptions(array $data): array
{
  $categoryInfo = optionValue($data, ['category_info', 'Category Info', 'category-info', 'category info', 'category', 'Category']);
  $brand = optionValue($data, ['category_brand', 'brand', 'Brand', 'bike-brand', 'manufacturer', 'Manufacturer']);
  $model = optionValue($data, ['category_model', 'model', 'Model', 'bike-model', 'Bike', 'bike']);
  $year = optionValue($data, ['category_year_range', 'year', 'Year', 'bike-year', 'model-year', 'Year Range']);
  $modelCode = optionValue($data, ['category_modelcode', 'modelcode', 'design_code', 'design-code', 'category_code', 'model_code', 'sku-code']);

  if ($categoryInfo !== '') {
    $parts = array_values(array_filter(array_map('trim', explode('|', $categoryInfo)), static function (string $value): bool {
      return $value !== '';
    }));
    if ($brand === '' && isset($parts[0])) {
      $brand = $parts[0];
    }
    if ($model === '' && isset($parts[1])) {
      $model = $parts[1];
    }
    if ($year === '' && isset($parts[2])) {
      $year = $parts[2];
    }
    if ($modelCode === '' && isset($parts[3])) {
      $modelCode = $parts[3];
    }
  }

  if ($categoryInfo === '') {
    $parts = array_values(array_filter([$brand, $model, $year], static function (string $value): bool {
      return $value !== '';
    }));
    if ($parts) {
      $categoryInfo = implode(' | ', $parts);
      if ($modelCode !== '') {
        $categoryInfo .= ' | ' . $modelCode;
      }
    }
  }

  return [
    'category_info' => $categoryInfo,
    'category_brand' => $brand,
    'category_model' => $model,
    'category_year_range' => $year,
    'category_modelcode' => $modelCode,
  ];
}

function ebayItemNumberForItem(array $item): string
{
  $data = jsonDecodeAssocSafe((string) ($item['options_json'] ?? ''));

  $itemNumber = optionValue($data, [
    'item_number',
    'Item number',
    'item_id',
    'Item ID',
    'ebay_item_id',
    'legacy_item_id'
  ]);

  if ($itemNumber !== '') {
    return $itemNumber;
  }

  foreach (['sku', 'custom_label', 'title'] as $field) {
    if (preg_match('/\b([13][0-9]{8,15})\b/', (string) ($item[$field] ?? ''), $m)) {
      return $m[1];
    }
  }

  return '';
}

// Generuje URL produktu podľa zdroja objednávky (SHOPTET, EBAY, atď.)
function itemProductUrl(array $order, array $item, bool $forPlasticsDepartment = false): string
{
  // Extrahuje zdroj, SKU a manuálnu URL z údajov
  $source = strtoupper((string) ($order['source_code'] ?? ''));
  $sku = trim((string) ($item['sku'] ?? ''));
  $manualUrl = trim((string) ($item['product_url'] ?? ''));
  $isEbayOrder = strpos($source, 'EBAY') !== false;
  $itemNumber = '';

  if ($isEbayOrder) {
    $itemNumber = ebayItemNumberForItem($item);

    if ($forPlasticsDepartment && $itemNumber !== '') {
      $orderNumber = trim((string) ($order['order_number'] ?? $order['external_order_id'] ?? ''));

      if ($orderNumber !== '') {
        // Plastics need the seller order detail; everyone else keeps the item listing.
        if (strpos($itemNumber, '3') === 0) {
          return 'https://www.ebay.com/sh/ord/details?orderid=' . rawurlencode($orderNumber);
        }

        if (strpos($itemNumber, '1') === 0) {
          return 'https://www.ebay.de/mesh/ord/details?orderid=' . rawurlencode($orderNumber);
        }
      }
    }
  }

  // Ak je zadaná manuálna URL, použije sa
  if ($manualUrl !== '') {
    return $manualUrl;
  }

  // Pre SHOPTET objednávky vracia vyhľadávací link s SKU
  if (strpos($source, 'SHOPTET') !== false && $sku !== '') {
    return 'https://www.scrubdesignz.com/search/?string=' . rawurlencode($sku);
  }

  // Pre EBAY objednávky vytvorí link na základe čísla položky
  if ($isEbayOrder) {
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
// --- order photos ---
$orderPhotos = [];
$photoTableExists = false;
$photoTableCheck = $conn->query("SHOW TABLES LIKE 'order_photos'");
if ($photoTableCheck && $photoTableCheck->num_rows > 0) {
  $photoTableExists = true;
  $photoStmt = $conn->prepare("
    SELECT id, file_name, original_name, file_path, mime_type, file_size, width, height, created_at
    FROM order_photos
    WHERE order_id = ? AND deleted_at IS NULL
    ORDER BY id DESC
  ");
  if ($photoStmt) {
    $photoStmt->bind_param('i', $orderId);
    $photoStmt->execute();
    $photoRes = $photoStmt->get_result();
    while ($photo = $photoRes->fetch_assoc()) {
      $orderPhotos[] = $photo;
    }
    $photoStmt->close();
  }
}

// --- build HTML ---
ob_start();
?>
<style>
  /* Detail objednávky – väčší komfort čítania */
  .order-detail-table {
    border-collapse: separate;
    border-spacing: 0 0;
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

  .order-detail-table tbody tr.item-repeat-header-row th {
    background-color: #343a40;
    color: #fff;
    font-weight: 600;
    position: sticky;
    top: 0;
    z-index: 1;
  }

  /* ── Item block separácia ───────────────────────────────────────
     AdminLTE používa border-collapse:collapse — border-radius na tr
     nefunguje. Riešenie: box-shadow na td simuluje outline celého bloku.
     Accent prúžok = inset left box-shadow.
  ─────────────────────────────────────────────────────────────── */

  /* Info riadok — horná časť bloku */
  tr.item-info-row>td {
    background: rgba(255, 255, 255, .028) !important;
    border-top: none !important;
    border-bottom: none !important;
    border-left: none !important;
    border-right: none !important;
    /* bez pravých vertikálnych borders — len top + accent vľavo */
    box-shadow:
      0 -2px 0 0 rgba(255, 255, 255, .15),
      /* top border */
      inset 3px 0 0 0 var(--item-accent, #555);
    /* accent prúžok vľavo */
  }

  tr.item-info-row>td:first-child {
    box-shadow:
      0 -2px 0 0 rgba(255, 255, 255, .15),
      inset 3px 0 0 0 var(--item-accent, #555),
      -2px 0 0 0 var(--item-accent, #555);
    /* ľavý border = accent farba */
  }

  tr.item-info-row>td:last-child {
    box-shadow:
      0 -2px 0 0 rgba(255, 255, 255, .15),
      inset 3px 0 0 0 var(--item-accent, #555),
      -2px 0 0 0 var(--item-accent, #555);
    /* ľavý border = accent farba */
  }

  /* Spodný riadok bloku — item bez options (P, S, F...) */
  tr.item-info-row.item-no-options>td {
    box-shadow:
      0 -2px 0 0 rgba(255, 255, 255, .15),
      0 3px 0 0 rgba(255, 255, 255, .15),
      /* bottom border */
      inset 3px 0 0 0 var(--item-accent, #555);
  }

  tr.item-info-row.item-no-options>td:first-child {
    box-shadow:
      0 -2px 0 0 rgba(255, 255, 255, .15),
      -2px 0 0 0 var(--item-accent, #555),
      0 3px 0 0 rgba(255, 255, 255, .15),
      inset 3px 0 0 0 var(--item-accent, #555);
  }

  tr.item-info-row.item-no-options>td:last-child {
    box-shadow:
      0 -2px 0 0 rgba(255, 255, 255, .15),
      0 3px 0 0 rgba(255, 255, 255, .15);
  }

  /* Options row (G-item) — spodná časť bloku */
  tr.g-item-options-row>td {
    border-top: none !important;
    border-bottom: none !important;
    border-left: none !important;
    border-right: none !important;
    box-shadow:
      -2px 0 0 0 var(--item-accent, #555),
      /* ľavý border = accent */
      0 3px 0 0 rgba(255, 255, 255, .15),
      /* bottom border */
      inset 3px 0 0 0 var(--item-accent, #555);
    /* accent prúžok vľavo — žiadny pravý border */
  }

  /* Opakujúca sa hlavička pred každou položkou */
  tr.item-group-header>th {
    background-color: #343a40 !important;
    font-weight: 600;
    font-size: 0.78rem;
    color: #adb5bd;
    padding: 0.35rem 0.75rem !important;
    border-top: 2px solid rgba(255, 255, 255, 0.15) !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
    border-left: none !important;
    border-right: none !important;
  }


  tr.item-spacer-row>td {
    height: 8px !important;
    padding: 0 !important;
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
  }

  /* Farebné akcenty podľa typu */
  tr.item-type-G {
    --item-accent: #28a745;
  }

  tr.item-type-P {
    --item-accent: #17a2b8;
  }

  tr.item-type-S {
    --item-accent: #ebd618;
  }

  tr.item-type-F {
    --item-accent: #fd7e14;
  }

  tr.item-type-T {
    --item-accent: #ffc107;
  }

  tr.item-type-M {
    --item-accent: #ffc107;
  }

  /* Jemný tónovaný background podľa typu — info riadok aj options riadok rovnaká farba */
  tr.item-type-G.item-info-row>td,
  tr.item-type-G.g-item-options-row>td {
    background: rgba(23, 163, 184, 0.2) !important;
  }

  tr.item-type-P.item-info-row>td,
  tr.item-type-P.g-item-options-row>td {
    background: rgba(76, 142, 247, .05) !important;
  }

  tr.item-type-S.item-info-row>td,
  tr.item-type-S.g-item-options-row>td {
    background: rgba(40, 167, 69, .05) !important;
  }

  tr.item-type-F.item-info-row>td,
  tr.item-type-F.g-item-options-row>td {
    background: rgba(253, 126, 20, .05) !important;
  }

  /* ── Seat Cover + auto-generovaný Patch — banner nad skupinou ── */
  .seat-patch-group-label>td {
    background: rgba(232, 62, 140, 0.14) !important;
    color: #e83e8c;
    font-weight: 700;
    font-size: 11px;
    letter-spacing: .02em;
    padding: 3px 8px !important;
    border: none !important;
  }

  .order-detail-table tbody tr.qty-warning-row>td {
    background: rgba(255, 193, 7, 0.22) !important;
    box-shadow: inset 4px 0 0 #ffc107;
  }

  .badge-product-type {
    min-width: 28px;
    padding: 0.35rem 0.5rem;
    background-color: #6c757d;
    color: #fff;
    font-weight: 700;
    text-align: center;
  }

  .badge-product-type:hover,
  .badge-product-type:focus {
    background-color: #5a6268;
    color: #fff;
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
    outline: none !important;
  }

  /* FINAL: outline/border na KAŽDOM riadku položky.
     Dôležité: nepoužívaj box-shadow reset na td, lebo zruší item outline.
     Každý item-info-row aj g-item-options-row má vlastný svetlý border,
     takže G grafika má jasne oddelený horný aj spodný riadok. */
  .order-detail-table tbody tr.item-info-row>td,
  .order-detail-table tbody tr.g-item-options-row>td {
    border-top: 1px solid rgba(255, 255, 255, .24) !important;
    border-bottom: 1px solid rgba(255, 255, 255, .24) !important;
    background-clip: padding-box;
  }

  .order-detail-table tbody tr.item-info-row>td:first-child,
  .order-detail-table tbody tr.g-item-options-row>td:first-child {
    border-left: 3px solid var(--item-accent, #8a8f98) !important;
  }

  .order-detail-table tbody tr.item-info-row>td:last-child,
  .order-detail-table tbody tr.g-item-options-row>td:last-child {
    border-right: 1px solid rgba(255, 255, 255, .24) !important;
  }

  /* G options row je jeden colspan td, preto musí dostať aj pravý border na ten istý td. */
  .order-detail-table tbody tr.g-item-options-row>td[colspan] {
    border-left: 3px solid var(--item-accent, #17a2b8) !important;
    border-right: 1px solid rgba(255, 255, 255, .24) !important;
  }

  /* Viditeľný predel medzi 1. a 2. riadkom grafiky */
  .order-detail-table tbody tr.g-item-options-row>td {
    border-top: 1px solid rgba(255, 255, 255, .32) !important;
    background: rgba(23, 162, 184, .045) !important;
  }

  /* Medzera medzi samostatnými položkami */
  .order-detail-table tbody tr.item-spacer-row>td {
    height: 8px !important;
    padding: 0 !important;
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
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
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
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

  .order-detail-header-selects .status-override-indicator {
    align-self: center;
    white-space: nowrap;
  }

  .order-detail-header-selects .btn-confirm-order-payment,
  .order-detail-header-selects .btn-resume-order-workflow {
    white-space: nowrap;
  }

  .order-detail-header .form-control {
    background-color: rgba(0, 0, 0, 0.18) !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
    color: #f8f9fa !important;
  }

  .order-detail-header select.form-control,
  .order-detail-header-selects select.form-control,
  .order-detail-header .order-status-select,
  .order-detail-header .order-types-select {
    background-color: #2b3035 !important;
    color: #f8f9fa !important;
  }

  .order-detail-header select.form-control option,
  .order-detail-header-selects select.form-control option,
  .order-detail-header .order-status-select option,
  .order-detail-header .order-types-select option {
    background-color: #2b3035 !important;
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

  .custom-item-builder-shell {
    border: 1px solid rgba(60, 141, 188, .28);
    border-radius: 12px;
    background: rgba(60, 141, 188, .06);
    padding: 12px;
  }

  .custom-item-builder-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
  }

  .custom-item-builder-title {
    font-size: 12px;
    font-weight: 700;
    color: #cfd6dc;
    letter-spacing: .05em;
    text-transform: uppercase;
  }

  .custom-builder-picker {
    display: flex;
    justify-content: flex-start;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
    flex-wrap: wrap;
  }

  .custom-builder-picker-label {
    flex: 0 0 220px;
    max-width: 220px;
  }

  .custom-builder-placeholder {
    margin-top: 8px;
    color: #8f9ba7;
    font-size: 13px;
  }

  .custom-builder-order-shell {
    border: 1px solid rgba(255, 255, 255, .1);
    border-radius: 10px;
    background: rgba(255, 255, 255, .015);
    overflow: hidden;
  }

  .custom-builder-order-shell>.table-responsive {
    overflow-y: hidden;
  }

  @media (min-width: 1500px) {
    .custom-builder-order-shell>.table-responsive {
      overflow-x: hidden;
      overflow-y: hidden;
    }
  }

  .custom-builder-order-shell[hidden],
  .custom-builder-placeholder[hidden],
  .custom-item-spec-group[hidden],
  [data-builder-body][hidden] {
    display: none !important;
  }

  .custom-builder-subtitle {
    padding: 12px 14px 8px;
    color: #f4f6f8;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
  }

  .custom-builder-order-table {
    margin-bottom: 0;
  }

  .custom-builder-order-table td,
  .custom-builder-order-table th {
    outline: none !important;
    vertical-align: middle;
  }

  .custom-builder-order-table tr.item-repeat-header-row>th {
    background-color: #343a40 !important;
    font-weight: 600;
    font-size: .78rem;
    color: #f0f3f6;
    padding: .35rem .6rem !important;
    border-top: 2px solid rgba(255, 255, 255, .15) !important;
    border-bottom: 1px solid rgba(255, 255, 255, .1) !important;
    border-left: 1px solid rgba(255, 255, 255, .14) !important;
    border-right: 1px solid rgba(255, 255, 255, .14) !important;
    white-space: nowrap;
  }

  .custom-builder-order-table tr.item-type-G {
    --item-accent: #28a745;
    --item-bg: rgba(40, 167, 69, .16);
  }

  .custom-builder-order-table tr.item-type-P {
    --item-accent: #17a2b8;
    --item-bg: rgba(23, 162, 184, .14);
  }

  .custom-builder-order-table tr.item-type-S {
    --item-accent: #ebd618;
    --item-bg: rgba(235, 214, 24, .12);
  }

  .custom-builder-order-table tr.item-type-F {
    --item-accent: #fd7e14;
    --item-bg: rgba(253, 126, 20, .13);
  }

  .custom-builder-order-table tr.item-type-T,
  .custom-builder-order-table tr.item-type-M {
    --item-accent: #ffc107;
    --item-bg: rgba(255, 193, 7, .13);
  }

  .custom-builder-order-table tbody tr.item-info-row>td,
  .custom-builder-order-table tbody tr.g-item-options-row>td {
    box-shadow: none !important;
    border-top: 1px solid rgba(255, 255, 255, .18) !important;
    border-bottom: 1px solid rgba(255, 255, 255, .18) !important;
    border-left: 1px solid rgba(255, 255, 255, .18) !important;
    border-right: 0 !important;
    background: var(--item-bg, rgba(255, 255, 255, .035)) !important;
    background-clip: padding-box !important;
  }

  .custom-builder-order-table tbody tr.item-info-row>td:last-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td:last-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td[colspan] {
    border-right: 1px solid rgba(255, 255, 255, .18) !important;
  }

  .custom-builder-order-table tbody tr.item-info-row>td:first-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td:first-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td[colspan] {
    border-left: 10px solid var(--item-accent, #8a8f98) !important;
  }

  .custom-builder-order-table tbody tr.g-item-options-row>td {
    border-top: 1px solid rgba(255, 255, 255, .26) !important;
    padding: 5px 8px 7px !important;
  }

  .custom-builder-type-badge {
    min-width: 28px;
    height: 28px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: #6c757d;
    color: #fff;
    font-weight: 700;
    text-align: center;
  }

  .custom-builder-assigned-placeholder {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, .18);
    background: rgba(255, 255, 255, .04);
    color: #cdd6df;
    font-size: 11px;
    font-weight: 700;
  }

  .custom-existing-item-meta-edit {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 4px;
  }

  .manual-add-item-form .custom-builder-mini-btn,
  .manual-add-item-form .custom-builder-link-btn {
    white-space: nowrap;
  }

  .manual-add-item-form .custom-builder-order-table tbody tr.item-info-row>td {
    padding: 7px 8px !important;
  }

  .manual-add-item-form .custom-builder-order-table .form-control,
  .manual-add-item-form .custom-builder-order-table .custom-category-info-trigger {
    min-height: 32px;
    padding-top: .34rem;
    padding-bottom: .34rem;
  }

  .manual-add-item-form .manual-item-title {
    margin-bottom: 6px !important;
  }

  .manual-add-item-form .custom-existing-item-meta-edit {
    gap: 6px;
  }
  .manual-add-item-form .manual-item-category-info {
    min-width: 190px;
  }
  .custom-category-info-trigger {
    width: 100%;
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    min-height: 31px;
    text-align: left;
  }

  .custom-category-info-trigger.is-empty {
    color: #b9c3cd;
    border-style: dashed;
  }

  .order-item-category-info-form {
    width: 150px;
    margin: 0 auto;
  }

  .order-item-category-info-form .custom-category-info-trigger {
    min-height: 28px;
    padding: .18rem .4rem;
    white-space: normal;
    line-height: 1.15;
  }

  .order-item-category-info-form .custom-category-info-trigger:not(.is-empty) {
    width: auto;
    min-width: 28px;
    justify-content: center;
  }

  .custom-category-info-text {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .custom-category-picker-modal .modal-content {
    background: #2b3239;
    color: #f8f9fa;
    border: 1px solid rgba(255, 255, 255, .18);
  }

  .custom-category-picker-steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    align-items: stretch;
  }

  .custom-category-picker-step {
    min-width: 0;
    padding: 10px;
    border: 1px solid rgba(255, 255, 255, .16);
    border-radius: 8px;
    background: rgba(255, 255, 255, .035);
  }

  .custom-category-picker-step label {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 7px;
    font-size: 12px;
    font-weight: 700;
    color: #d7dee7;
  }

  .custom-category-picker-step-number {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 20px;
    background: rgba(23, 162, 184, .25);
    border: 1px solid rgba(23, 162, 184, .65);
    color: #e7fbff;
    font-size: 11px;
    font-weight: 800;
  }

  .custom-category-picker-preview {
    margin-top: 12px;
    padding: 9px 10px;
    border-radius: 8px;
    border: 1px solid rgba(23, 162, 184, .28);
    background: rgba(23, 162, 184, .08);
    color: #d7eef5;
    font-size: 12px;
    line-height: 1.35;
  }

  @media (max-width: 900px) {
    .custom-category-picker-steps {
      grid-template-columns: 1fr;
    }
  }
  .manual-add-item-form .custom-builder-subtitle {
    display: none;
  }

  .manual-add-item-form .custom-builder-order-shell {
    border-radius: 0;
  }
  .manual-add-item-form .manual-item-main-spec-row .g-options-bar {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    gap: 6px;
    width: 100%;
  }

  .manual-add-item-form .manual-item-note-spec-row .g-options-bar {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    align-items: stretch;
    width: 100%;
  }

  .manual-add-item-form .manual-item-spec-group .product-spec-label {
    width: 100%;
    max-width: none;
    min-width: 0 !important;
    margin: 0 !important;
  }

  .manual-add-item-form .manual-item-main-spec-row .product-spec-label {
    flex: 1 1 0 !important;
  }

  .manual-add-item-form .manual-item-spec-group .product-spec-label-title {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .manual-add-item-form .manual-item-spec-group .form-control {
    width: 100%;
    min-width: 0;
    padding-left: .42rem;
    padding-right: .42rem;
  }

  @media (max-width: 767.98px) {
    .manual-add-item-form .manual-item-note-spec-row .g-options-bar {
      grid-template-columns: 1fr;
    }
  }
  .order-detail-table tbody tr.item-info-row:focus-within>td,
  .order-detail-table tbody tr.g-item-options-row:focus-within>td {
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
    filter: brightness(1.05);
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
    left: 0;
    right: 0;
    top: 100%;
    background: #1e2530;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 4px;
    z-index: 9999;
    max-height: 160px;
    overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0, 0, 0, .45);
  }

  .print-ac-item {
    padding: 5px 10px;
    cursor: pointer;
    font-size: 12px;
    color: #e2e8f0;
    border-bottom: 1px solid rgba(255, 255, 255, .06);
  }

  .print-ac-item:hover,
  .print-ac-item.active {
    background: rgba(63, 158, 255, .22);
    color: #fff;
  }

  .print-settings-cell .form-control-sm {
    font-size: 11px;
    padding: 2px 6px;
    height: auto;
  }

  .print-settings-row {
    display: flex;
    align-items: stretch;
    gap: 6px;
    flex-wrap: nowrap;
  }

  .print-settings-row .print-setting-field {
    flex: 0 0 120px;
    min-width: 120px;
    margin: 0 !important;
  }

  .product-spec-label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 120px;
    padding: 6px;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 6px;
    background: rgba(255, 255, 255, .025);
  }

  .product-spec-label-title {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .04em;
    color: #d7dee7;
    line-height: 1.1;
  }


  .seat-op-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .seat-op-field {
    flex: 0 0 120px;
    min-width: 120px;
    margin: 0 !important;
  }

  .seat-op-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .04em;
    color: #d7dee7;
    line-height: 1.1;
  }

  .print-setting-field-grip.product-spec-state-yes {
    border-color: rgba(40, 167, 69, .42);
    background: linear-gradient(180deg, rgba(40, 167, 69, .18) 0%, rgba(40, 167, 69, .08) 100%);
    box-shadow: inset 0 0 0 1px rgba(40, 167, 69, .08);
  }

  .print-setting-field-grip.product-spec-state-yes .product-spec-label-title {
    color: #7ee2a8;
  }

  .print-setting-field-grip.product-spec-state-yes .item-print-grip {
    border-color: rgba(40, 167, 69, .55);
    background-color: rgba(33, 37, 41, .92);
    color: #dff7e8;
  }

  .print-setting-field-grip.product-spec-state-yes .item-print-grip:focus {
    border-color: #4fd38a;
    box-shadow: 0 0 0 .2rem rgba(40, 167, 69, .18);
  }

  .print-setting-field-grip.product-spec-state-no {
    border-color: rgba(220, 53, 69, .4);
    background: linear-gradient(180deg, rgba(220, 53, 69, .16) 0%, rgba(220, 53, 69, .07) 100%);
    box-shadow: inset 0 0 0 1px rgba(220, 53, 69, .08);
  }

  .print-setting-field-grip.product-spec-state-no .product-spec-label-title {
    color: #ff9aa5;
  }

  .print-setting-field-grip.product-spec-state-no .item-print-grip {
    border-color: rgba(220, 53, 69, .5);
    background-color: rgba(33, 37, 41, .92);
    color: #ffe3e6;
  }

  .print-setting-field-grip.product-spec-state-no .item-print-grip:focus {
    border-color: #ff7b88;
    box-shadow: 0 0 0 .2rem rgba(220, 53, 69, .16);
  }

  .print-setting-field-swingarms.product-spec-state-yes {
    border-color: rgba(40, 167, 69, .42);
    background: linear-gradient(180deg, rgba(40, 167, 69, .18) 0%, rgba(40, 167, 69, .08) 100%);
    box-shadow: inset 0 0 0 1px rgba(40, 167, 69, .08);
  }

  .print-setting-field-swingarms.product-spec-state-yes .product-spec-label-title {
    color: #7ee2a8;
  }

  .print-setting-field-swingarms.product-spec-state-yes .item-print-tr-swingarms {
    border-color: rgba(40, 167, 69, .55);
    background-color: rgba(33, 37, 41, .92);
    color: #dff7e8;
  }

  .print-setting-field-swingarms.product-spec-state-yes .item-print-tr-swingarms:focus {
    border-color: #4fd38a;
    box-shadow: 0 0 0 .2rem rgba(40, 167, 69, .18);
  }

  .print-setting-field-swingarms.product-spec-state-no {
    border-color: rgba(220, 53, 69, .4);
    background: linear-gradient(180deg, rgba(220, 53, 69, .16) 0%, rgba(220, 53, 69, .07) 100%);
    box-shadow: inset 0 0 0 1px rgba(220, 53, 69, .08);
  }

  .print-setting-field-swingarms.product-spec-state-no .product-spec-label-title {
    color: #ff9aa5;
  }

  .print-setting-field-swingarms.product-spec-state-no .item-print-tr-swingarms {
    border-color: rgba(220, 53, 69, .5);
    background-color: rgba(33, 37, 41, .92);
    color: #ffe3e6;
  }

  .print-setting-field-swingarms.product-spec-state-no .item-print-tr-swingarms:focus {
    border-color: #ff7b88;
    box-shadow: 0 0 0 .2rem rgba(220, 53, 69, .16);
  }

  .seat-op-code {
    line-height: 1;
  }

  /* ── Graphics item — dvojriadkový layout ────────────────────── */
  .g-item-options-row>td {
    background: rgba(23, 162, 184, 0.04) !important;
    border-top: none !important;
    padding: 5px 8px 7px !important;
  }

  .g-options-bar {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    gap: 6px;
    width: 100%;
  }

  /* Každý formulárový riadok sa vždy roztiahne na celú šírku:
     1 prvok = 100 %, viac prvkov = rovnomerne rozdelená šírka. */
  .g-options-bar .print-setting-field,
  .g-options-bar .product-spec-label {
    flex: 1 1 0 !important;
    min-width: 0 !important;
    margin: 0 !important;
  }

  .g-options-bar .position-relative {
    width: 100%;
  }

  /* Note field — flex filler */
  .g-opt-note-field {
    flex: 1 1 auto !important;
    min-width: 130px !important;
  }

  .g-opt-note-textarea {
    resize: vertical !important;
    flex: 0 0 auto !important;
    align-self: stretch;
    min-height: 31px;
    height: auto;
    overflow: auto;
  }

  .g-opt-text-display,
  .g-opt-note-display {
    flex: 1;
    min-height: 31px;
    padding: .25rem .5rem;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: .2rem;
    background-color: transparent;
    color: inherit;
    line-height: 1.5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  /* Note wrapper — rovnaká výška ako susedné labely (stretch) */
  .g-options-bar .g-opt-note-field {
    height: auto !important;
    align-self: stretch;
    display: flex;
    flex-direction: column;
    padding-top: 3px;
    padding-bottom: 3px;
  }

  /* product-spec-label vo vnútri g-options-bar — flex column, height: 100% */
  .g-options-bar .product-spec-label {
    display: flex;
    flex-direction: column;
    height: 100%;
    justify-content: flex-start;
  }

  .g-options-bar .product-spec-label select,
  .g-options-bar .product-spec-label input {
    flex: 1;
  }

  /* Category Info cell */
  .g-cat-info {
    font-size: 11px;
    line-height: 1.45;
    white-space: nowrap;
  }

  .g-cat-info .g-cat-main {
    color: #d4dde6;
    display: block;
  }

  .g-cat-info .g-cat-code a {
    font-size: 11px;
    font-weight: 700;
    color: #17a2b8;
    text-decoration: none;
    border-bottom: 1px dashed rgba(23, 162, 184, .4);
  }

  .g-cat-info .g-cat-code a:hover {
    color: #5bcfdf;
    border-bottom-color: #5bcfdf;
  }

  /* Number color preview dot */
  .g-numcolor-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, .25);
    margin-right: 3px;
    vertical-align: middle;
  }

  /* ── Printing Settings block in modal ───────────────────────── */
  .printing-settings-block {
    background: rgba(63, 158, 255, .08);
    border: 1px solid rgba(63, 158, 255, .25);
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

  .seat-op-inactive {
    opacity: 0.38;
  }

  .seat-op-inactive select {
    pointer-events: none;
  }

  /* ── FINAL OVERRIDE: jednotné farbenie dvojriadkovej položky + iba jeden ľavý accent ── */
  .order-detail-table {
    border-collapse: separate !important;
    border-spacing: 0 !important;
  }

  .order-detail-table tbody tr.item-info-row>td,
  .order-detail-table tbody tr.g-item-options-row>td {
    box-shadow: none !important;
    border-top: 1px solid rgba(255, 255, 255, .18) !important;
    border-bottom: 1px solid rgba(255, 255, 255, .18) !important;
    border-left: 1px solid rgba(255, 255, 255, .18) !important;
    border-right: 0 !important;
    background: var(--item-bg, rgba(255, 255, 255, .035)) !important;
    background-clip: padding-box !important;
  }

  .order-detail-table tbody tr.item-info-row>td:last-child,
  .order-detail-table tbody tr.g-item-options-row>td:last-child,
  .order-detail-table tbody tr.g-item-options-row>td[colspan] {
    border-right: 1px solid rgba(255, 255, 255, .18) !important;
  }

  /* Jediný department pásik: iba úplne prvá bunka prvého riadku položky */
  .order-detail-table tbody tr.item-info-row>td:first-child {
    border-left: 10px solid var(--item-accent, #8a8f98) !important;
  }

  /* Druhý/dropdown riadok už nemá farebný pásik, iba sivé orámovanie */
  .order-detail-table tbody tr.g-item-options-row>td:first-child,
  .order-detail-table tbody tr.g-item-options-row>td[colspan] {
    border-left: 10px solid var(--item-accent, #555) !important;
  }

  /* Jemný predel medzi horným a spodným riadkom toho istého itemu */
  .order-detail-table tbody tr.g-item-options-row>td {
    border-top: 1px solid rgba(255, 255, 255, .26) !important;
  }

  /* Rovnaká farba horného aj spodného riadku podľa typu/depu */
  tr.item-type-G {
    --item-accent: #28a745;
    --item-bg: rgba(40, 167, 69, .16);
  }

  tr.item-type-P {
    --item-accent: #17a2b8;
    --item-bg: rgba(23, 162, 184, .14);
  }

  tr.item-type-S {
    --item-accent: #ebd618;
    --item-bg: rgba(235, 214, 24, .12);
  }

  tr.item-type-F {
    --item-accent: #fd7e14;
    --item-bg: rgba(253, 126, 20, .13);
  }

  tr.item-type-T,
  tr.item-type-M {
    --item-accent: #ffc107;
    --item-bg: rgba(255, 193, 7, .13);
  }

  /* Ak je qty warning, nech je vidieť silná žltá navyše nad department pozadím, ale nech nezabije ľavý department pásik */
  .order-detail-table tbody tr.qty-warning-row>td {
    background: linear-gradient(rgba(255, 193, 7, .28), rgba(255, 193, 7, .28)), var(--item-bg, rgba(255, 193, 7, .16)) !important;
    box-shadow: inset 4px 0 0 rgba(255, 193, 7, .9) !important;
  }

  .order-detail-table tbody tr.qty-warning-row>td:first-child {
    border-left: 6px solid var(--item-accent, #ffc107) !important;
  }

  .order-detail-table tbody tr.item-spacer-row>td {
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
  }



  /* ── Order photos block ───────────────────────────────────── */
  .order-photos-card {
    border: 1px dashed rgba(23, 162, 184, .45);
    border-radius: 10px;
    background: rgba(23, 162, 184, .06);
    padding: 10px;
  }

  .order-photo-dropzone {
    border: 2px dashed rgba(255, 255, 255, .25);
    border-radius: 10px;
    min-height: 82px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    cursor: pointer;
    background: rgba(0, 0, 0, .16);
    transition: border-color .15s ease, background .15s ease;
  }

  .order-photo-dropzone.is-dragover {
    border-color: #17a2b8;
    background: rgba(23, 162, 184, .18);
  }

  .order-photo-thumb-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .order-photo-thumb-wrap {
    position: relative;
    width: 72px;
    height: 72px;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, .18);
    background: rgba(0, 0, 0, .25);
  }

  .order-photo-thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
    cursor: zoom-in;
  }

  .btn-delete-order-photo {
    position: absolute;
    top: 3px;
    right: 3px;
    width: 20px;
    height: 20px;
    padding: 0;
    line-height: 18px;
    border-radius: 50%;
    font-size: 13px;
    font-weight: 700;
  }

  .order-photo-upload-progress {
    display: none;
    height: 4px;
    border-radius: 999px;
    background: rgba(255, 255, 255, .12);
    overflow: hidden;
  }

  .order-photo-upload-progress>span {
    display: block;
    height: 100%;
    width: 0;
    background: #17a2b8;
  }

  .order-photo-lightbox {
    position: fixed;
    inset: 0;
    z-index: 20000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition:
      opacity .22s ease,
      background-color .22s ease,
      visibility 0s linear .22s;
  }

  .order-photo-lightbox.is-open {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    background: rgba(0, 0, 0, .84);
    transition:
      opacity .22s ease,
      background-color .22s ease,
      visibility 0s;
  }

  .order-photo-lightbox img {
    max-width: 96vw;
    max-height: 92vh;
    border-radius: 10px;
    box-shadow: 0 16px 50px rgba(0, 0, 0, .65);
    opacity: 0;
    transform: scale(.86) translateY(14px);
    transition:
      transform .26s cubic-bezier(.2, .8, .2, 1),
      opacity .18s ease;
    will-change: transform, opacity;
  }

  .order-photo-lightbox.is-open img {
    opacity: 1;
    transform: scale(1) translateY(0);
  }

  .order-photo-lightbox-close {
    position: fixed;
    top: 14px;
    right: 18px;
    z-index: 20001;
    opacity: 0;
    transform: scale(.9);
    transition: opacity .18s ease .08s, transform .18s ease .08s;
  }

  .order-photo-lightbox-nav {
    position: fixed;
    top: 50%;
    transform: translateY(-50%);
    z-index: 20001;
    width: 46px;
    height: 64px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, .25);
    background: rgba(0, 0, 0, .35);
    color: #fff;
    font-size: 34px;
    line-height: 58px;
    text-align: center;
    cursor: pointer;
    opacity: .75;
  }

  .order-photo-lightbox-nav:hover {
    opacity: 1;
    background: rgba(23, 162, 184, .45);
  }

  .order-photo-lightbox-prev {
    left: 22px;
  }

  .order-photo-lightbox-next {
    right: 22px;
  }

  .order-photo-lightbox-counter {
    position: fixed;
    bottom: 18px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 20001;
    color: #d7dee7;
    background: rgba(0, 0, 0, .45);
    border-radius: 999px;
    padding: 4px 12px;
    font-size: 12px;
  }

  .order-photo-lightbox.is-open .order-photo-lightbox-close {
    opacity: 1;
    transform: scale(1);
  }

  .order-header-main-row {
    align-items: stretch;
  }

  .order-header-left-stack {
    min-width: 0;
  }

  .order-header-split-line {
    border-top-color: rgba(255, 255, 255, .10);
  }

  .order-header-extra-row {
    min-height: 0;
  }

  .order-header-summary {
    min-width: 0;
  }

  .order-summary-meta {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 10px;
  }

  .order-summary-meta-item,
  .order-summary-card,
  .order-header-operations-card {
    border: 1px solid rgba(255, 255, 255, .10);
    border-radius: 10px;
    background: rgba(255, 255, 255, .035);
  }

  .order-summary-meta-item {
    padding: 8px 10px;
    min-width: 0;
  }

  .order-summary-label {
    display: block;
    margin-bottom: 3px;
    color: rgba(255, 255, 255, .52);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
  }

  .order-summary-value {
    color: #f2f5f8;
    overflow-wrap: anywhere;
  }

  .order-summary-address-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .order-summary-card {
    position: relative;
    min-width: 0;
    padding: 12px;
    overflow: hidden;
  }

  .order-summary-card::before {
    content: "";
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 3px;
    background: rgba(23, 162, 184, .75);
  }

  .order-summary-card-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 9px;
    padding-left: 3px;
    color: #f8f9fa;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .025em;
  }

  .order-summary-card-title .btn-copy-inline {
    flex: 0 0 auto;
    color: #8fd7e6 !important;
    border: 1px solid rgba(23, 162, 184, .38);
    border-radius: 6px;
    background: rgba(23, 162, 184, .08);
    padding: 2px 7px;
  }

  .order-summary-primary {
    margin-bottom: 4px;
    color: #fff;
    font-weight: 700;
  }

  .order-summary-line {
    min-height: 18px;
    color: rgba(255, 255, 255, .70);
    overflow-wrap: anywhere;
  }

  .order-summary-line .btn-copy-inline {
    padding: 0 3px;
    color: rgba(255, 255, 255, .62) !important;
  }

  .order-summary-country {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 5px;
    color: rgba(255, 255, 255, .82);
  }

  .custom-country-select-wrap {
    position: relative;
    width: 100%;
  }

  .custom-country-select-wrap .custom-country-select {
    padding-left: 35px;
  }

  .custom-country-select-wrap.no-flag .custom-country-select {
    padding-left: .5rem;
  }

  .custom-country-flag {
    position: absolute;
    left: 10px;
    top: 50%;
    z-index: 3;
    width: 18px;
    height: 13px;
    transform: translateY(-50%);
    border-radius: 2px;
    background-position: 50%;
    background-repeat: no-repeat;
    background-size: cover;
    box-shadow: 0 0 0 1px rgba(255, 255, 255, .18);
    pointer-events: none;
  }

  .custom-country-flag.is-empty {
    display: none;
  }

  .customs-identifier-alert {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    padding: 11px 13px;
    border: 1px solid rgba(255, 79, 79, .82);
    border-left: 6px solid #ff3b3b;
    border-radius: 8px;
    background: linear-gradient(90deg, rgba(220, 53, 69, .25), rgba(255, 193, 7, .10));
    color: #fff;
    font-weight: 700;
  }

  .customs-identifier-field {
    margin-top: 7px;
    padding: 8px;
    border: 1px solid rgba(255, 193, 7, .62);
    border-radius: 7px;
    background: rgba(255, 193, 7, .09);
  }

  .customs-identifier-field.is-missing {
    border-color: rgba(255, 79, 79, .92);
    background: rgba(220, 53, 69, .15);
    box-shadow: 0 0 0 2px rgba(220, 53, 69, .08);
  }

  .customs-identifier-field label {
    margin-bottom: 4px;
    color: #ffe8a1;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .035em;
    text-transform: uppercase;
  }

  .order-header-edit .card {
    border-radius: 10px;
    overflow: hidden;
  }

  .order-header-operations {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 10px;
  }

          .order-header-operations-card {
            padding: 10px 12px;
  }

  .order-header-operations-title {
    margin-bottom: 8px;
    color: rgba(255, 255, 255, .62);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
  }

  .order-header-copy-row {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
  }

  .order-header-copy-value {
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .tracking-copy-row {
    display: grid;
    grid-template-columns: minmax(108px, max-content) minmax(88px, 1fr) max-content max-content max-content;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
  }

  .tracking-copy-main {
    display: inline-flex;
    align-items: center;
    min-width: 0;
    gap: 4px;
  }

  .tracking-copy-main .order-header-copy-value {
    white-space: nowrap;
    overflow-wrap: normal;
  }

  .tracking-carrier-label {
    min-width: 0;
    overflow: hidden;
    color: rgba(255, 255, 255, .58);
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .tracking-date-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    max-width: 130px;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 10.5px;
    line-height: 1.1;
    white-space: nowrap;
  }

  .tracking-copy-row .btn-delete-tracking {
    justify-self: end;
  }

  .order-header-copy-empty {
    color: rgba(255, 255, 255, .48);
    font-size: 12px;
  }

  .order-photos-span-col {
    min-height: 0;
  }

  .order-photos-panel,
  .order-photos-card {
    min-height: 0;
  }

  .order-photos-panel {
    display: flex;
    flex-direction: column;
  }

  .order-detail-secondary-row>[class*="col-"] {
    display: flex;
    flex-direction: column;
  }

  .order-detail-secondary-row .production-note-box,
  .order-detail-secondary-row .order-followup-panel,
  .order-detail-secondary-row .order-followup-panel>.card,
  .order-detail-secondary-row .order-photos-panel,
  .order-detail-secondary-row .order-photos-card {
    flex: 1 1 auto;
  }

  .order-detail-secondary-row .order-followup-panel {
    display: flex;
    flex-direction: column;
  }

  .order-detail-secondary-row .production-note-box,
  .order-detail-secondary-row .order-followup-panel,
  .order-detail-secondary-row .order-photos-panel {
    width: 100%;
    height: 100%;
  }

  .order-detail-secondary-row .production-note-box,
  .order-detail-secondary-row .order-followup-panel>.card {
    margin-bottom: 0;
  }

  .order-photos-card {
    display: flex;
    flex-direction: column;
  }

  .order-photos-card-admin {
    flex: 1 1 auto;
    height: 100%;
  }

  .order-photos-card-user {
    min-height: 120px;
  }

  .order-photo-thumb-grid {
    align-content: flex-start;
    padding-right: 2px;
  }

  .order-value-breakdown-card {
    width: 100%;
    border: 1px solid rgba(23, 162, 184, .45);
    border-radius: 10px;
    background: rgba(23, 162, 184, .06);
    padding: 14px;
  }

  .order-value-breakdown-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 4px 0;
  }

  .order-value-breakdown-total {
    margin-bottom: 6px;
    padding-bottom: 9px;
    border-bottom: 1px solid rgba(255, 255, 255, .14);
    font-weight: 700;
  }

  .order-value-breakdown-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 10px;
    font-weight: 700;
  }

  .order-financial-total-editor {
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(255, 255, 255, .14);
  }

  .order-financial-total-editor label {
    display: block;
    margin-bottom: 4px;
    font-size: .78rem;
    text-transform: uppercase;
    color: #9ecfe0;
  }

  .order-financial-total-input {
    text-align: right;
    font-weight: 700;
  }

  .order-financial-meta,
  .order-financial-adjustment-meta {
    font-size: .78rem;
    color: #b8c3ca;
  }

  .order-financial-adjustment-list {
    margin: 8px 0 10px;
    padding: 8px 0;
    border-top: 1px solid rgba(255, 255, 255, .1);
    border-bottom: 1px solid rgba(255, 255, 255, .1);
  }

  .order-financial-adjustment-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    padding: 3px 0;
  }

  .order-financial-adjustment-main {
    min-width: 0;
  }

  .order-financial-adjustment-purpose {
    font-weight: 600;
  }

  .order-financial-adjustment-amount {
    white-space: nowrap;
    font-weight: 700;
  }

  @media (max-width: 991.98px) {
    .order-summary-meta,
    .order-summary-address-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .order-detail-secondary-row>[class*="col-"]:not(:last-child) {
      margin-bottom: 1rem;
    }

    .order-photos-card-admin {
      min-height: 220px;
    }

    .order-photos-card-user {
      min-height: 120px;
    }
  }

  @media (max-width: 575.98px) {
    .order-summary-meta,
    .order-summary-address-grid,
    .order-header-operations {
      grid-template-columns: 1fr;
    }

  }

  /* Tracking number / carrier / add-button row: flexible inputs, button always one line */
  .tracking-add-row {
    display: flex;
    flex-wrap: wrap;
    align-items: stretch;
    gap: 6px;
  }

  .tracking-add-row .tracking-number,
  .tracking-add-row .tracking-carrier {
    flex: 1 1 80px;
    min-width: 60px;
    width: auto;
  }

  .tracking-add-row .tracking-carrier {
    flex: 0.7 1 60px;
  }

  .tracking-add-row .btn-add-tracking {
    flex: 0 0 auto;
    white-space: nowrap;
  }

  /* ── Seat Cover + Patch — spoločný ružový pásik (rovnaký mechanizmus ako
     department farebný pásik vyššie, len prepísaná farba). Selektor je
     zámerne plne kvalifikovaný, aby mal istú prioritu nad "FINAL OVERRIDE"
     blokom vyššie bez ohľadu na poradie. */
  .order-detail-table tbody tr.seat-patch-group-row {
    --item-accent: #e83e8c;
  }

  .order-detail-table tbody tr.item-repeat-header-row.seat-patch-group-row th:first-child {
    border-left: 10px solid #e83e8c !important;
  }
</style>
<div class="p-3">
  <div class="card card-dark order-detail-card mb-0"
    style="--order-detail-accent: <?php echo h($detailAccentColor); ?>; border-radius:14px; overflow:hidden;">
    <div class="order-detail-header">
      <div class="d-flex justify-content-between align-items-start flex-wrap">
        <div class="order-detail-header-title">
          <b class="btn-copy-inline"
            data-copy="<?php echo h($order['order_number'] ?? $order['external_order_id'] ?? $orderId); ?>"
            title="Click to copy order number"
            style="cursor:pointer;">#<?php echo h($order['order_number'] ?? $order['external_order_id'] ?? $orderId); ?></b>
          <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
            <button type="button" class="btn btn-sm btn-light btn-edit-order-header"
              data-order-id="<?php echo (int) $orderId; ?>" data-mode="edit">
              ✏️ Edit header
            </button>
          <?php endif; ?>
        </div>
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

          $statusLabels = ordersGetOrderStatusLabels($conn, true);
          $statusOptions = array_keys($statusLabels);

          $currentStatus = strtoupper(trim((string) ($order['status'] ?? 'NEW')));
          if ($currentStatus === '') {
            $currentStatus = 'NEW';
          }
          // Ak je objednávka v stave ktorý nie je v zozname, pridaj ho
          if (!in_array($currentStatus, $statusOptions, true)) {
            $statusOptions[] = $currentStatus;
          }
          $isPendingStatus = $currentStatus === 'PENDING';
          $hasStatusOverride = (int) ($order['status_override'] ?? 0) === 1;
          $isFinalStatus = in_array($currentStatus, ['SHIPPED', 'CANCELLED', 'DELIVERED'], true);

          ?>



          <div class="order-detail-header-selects">
            <select class="form-control form-control-sm order-status-select"
              data-order-id="<?php echo (int) $orderId; ?>" data-original-status="<?php echo h($currentStatus); ?>">

              <?php foreach ($statusOptions as $st): ?>
                <?php $pendingOptionDisabled = $isPendingStatus && !in_array($st, ['PENDING', 'CANCELLED'], true); ?>
                <option value="<?php echo h($st); ?>"
                  <?php echo ($currentStatus === $st ? 'selected' : ''); ?>
                  <?php echo ($pendingOptionDisabled ? 'disabled' : ''); ?>>
                  <?php echo h($statusLabels[$st] ?? str_replace('_', ' ', $st)); ?>
                </option>
              <?php endforeach; ?>

            </select>

            <?php if ($isPendingStatus && (int) ($_SESSION['permission'] ?? 0) >= 400): ?>
              <button type="button" class="btn btn-sm btn-success btn-confirm-order-payment"
                data-order-id="<?php echo (int) $orderId; ?>"
                data-expected-amount="<?php echo h(number_format($orderValueBreakdown['total'], 2, '.', '')); ?>"
                data-currency="<?php echo h((string) ($order['currency'] ?? '')); ?>">
                <i class="fas fa-money-check-alt mr-1"></i>Payment confirmed
              </button>
            <?php endif; ?>

            <?php if ($hasStatusOverride): ?>
              <span class="badge badge-warning status-override-indicator"
                title="Item status changes are still saved, but they cannot change the overall order status until automatic workflow is resumed.">
                <i class="fas fa-lock mr-1"></i>Manual status – workflow paused
              </span>
              <?php if (!$isFinalStatus && !$isPendingStatus && (int) ($_SESSION['permission'] ?? 0) >= 400): ?>
                <button type="button" class="btn btn-sm btn-outline-warning btn-resume-order-workflow"
                  data-order-id="<?php echo (int) $orderId; ?>">
                  <i class="fas fa-unlock-alt mr-1"></i>Resume automatic workflow
                </button>
              <?php endif; ?>
            <?php endif; ?>
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

        </div>
      </div>
    </div>



    <div class="card-body">

      <div class="row order-header-main-row align-items-stretch">
        <div class="col-lg-8 order-header-left-stack d-flex flex-column">

          <div class="order-header-summary order-summary-meta">
            <?php $customerDisplayName = $order['customer_name'] ?: $order['customer_email'] ?: '-'; ?>
            <div class="order-summary-meta-item">
              <span class="order-summary-label">Customer</span>
              <div class="order-summary-value font-weight-bold">
                <?php echo h($customerDisplayName); ?>
                <button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($customerDisplayName); ?>"
                  title="Copy customer name">📋</button>
              </div>
              <?php if ($deliveryEmail !== ''): ?>
                <div class="order-summary-line mt-1">
                  <i class="fas fa-envelope mr-1"></i><?php echo h($deliveryEmail); ?>
                  <button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($deliveryEmail); ?>"
                    title="Copy customer email">📋</button>
                </div>
              <?php endif; ?>
              <?php if ($deliveryContactPhone !== ''): ?>
                <div class="order-summary-line">
                  <i class="fas fa-phone-alt mr-1"></i><?php echo h($deliveryContactPhone); ?>
                  <button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($deliveryContactPhone); ?>"
                    title="Copy customer phone">📋</button>
                </div>
              <?php endif; ?>
            </div>

            <div class="order-summary-meta-item">
              <span class="order-summary-label">Shipping</span>
              <div class="order-summary-value"><?php echo h($order['shipping_method'] ?? '-'); ?></div>
            </div>

            <div class="order-summary-meta-item">
              <span class="order-summary-label">Payment</span>
              <div class="order-summary-value"><?php echo h($order['payment_method'] ?? '-'); ?></div>
              <?php if ($followupLabel !== ''): ?>
                <div class="mt-1">
                  <span class="badge badge-info"><?php echo h($followupLabel); ?></span>
                  <?php if ($followupDoNotInvoice): ?>
                    <span class="badge badge-danger">Do not invoice</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="order-summary-meta-item">
              <span class="order-summary-label">Order timeline</span>
              <div class="order-summary-line small">
                <i class="fas fa-calendar-alt mr-1 text-muted"></i><b>Order:</b> <?php echo h($order['order_date'] ?? '-'); ?>
              </div>
              <div class="order-summary-line small">
                <i class="fas fa-upload mr-1 text-muted"></i><b>Import:</b> <?php echo h($order['imported_at'] ?? '-'); ?>
              </div>
              <div class="order-summary-line small">
                <i class="fas fa-check-circle mr-1 <?php echo !empty($order['delivered_at']) ? 'text-success' : 'text-muted'; ?>"></i><b>Delivered:</b> <?php echo h($order['delivered_at'] ?? '-'); ?>
              </div>
              <?php if (!empty($order['production_started_at'])): ?>
                <div class="order-summary-line small">
                  <i class="fas fa-cogs mr-1 text-muted"></i><b>Production:</b> <?php echo h($order['production_started_at']); ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($followupLabel !== ''): ?>
            <div class="order-header-summary small text-muted mb-2">
              Parent:
              <?php echo h($followupParentOrderNumber !== '' ? $followupParentOrderNumber : ('#' . $followupParentOrderId)); ?>
              <?php if ($followupReason !== ''): ?>
                <span class="ml-2">Reason: <?php echo h($followupReason); ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($customsIdentifierMissing): ?>
            <div class="customs-identifier-alert" role="alert">
              <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
              <span>Customs clearance data missing: enter the customer's <?php echo h($customsIdentifierLabel); ?> in Edit order header.</span>
            </div>
          <?php endif; ?>

          <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
            <div class="order-header-edit mt-3" style="display:none;"
              data-customs-country-labels="<?php echo h((string) json_encode($customsCountryLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
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
                      <div class="mb-1 custom-country-select-wrap no-flag">
                        <span class="custom-country-flag is-empty" data-country-flag aria-hidden="true"></span>
                        <select class="form-control form-control-sm edit-billing-country custom-country-select"
                          data-order-detail-country-select data-country-placeholder="Country">
                          <option value="<?php echo h((string) ($b['country'] ?? '')); ?>" selected><?php echo h((string) ($b['country'] ?? '')); ?></option>
                        </select>
                      </div>
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
                      <div class="mb-1 custom-country-select-wrap no-flag">
                        <span class="custom-country-flag is-empty" data-country-flag aria-hidden="true"></span>
                        <select class="form-control form-control-sm edit-shipping-country custom-country-select"
                          data-order-detail-country-select data-country-placeholder="Country">
                          <option value="<?php echo h((string) ($s['country'] ?? '')); ?>" selected><?php echo h((string) ($s['country'] ?? '')); ?></option>
                        </select>
                      </div>
                      <div class="customs-identifier-field<?php echo $customsIdentifierMissing ? ' is-missing' : ''; ?>"
                        data-customs-identifier-field<?php echo ordersRequiresCustomsIdentifier($orderCountry) ? '' : ' hidden'; ?>>
                        <label><i class="fas fa-passport mr-1" aria-hidden="true"></i><span data-customs-identifier-label><?php echo h($customsIdentifierLabel); ?></span></label>
                        <input class="form-control form-control-sm edit-customs-identifier" maxlength="128"
                          placeholder="Enter customer customs / tax ID"
                          value="<?php echo h($customsIdentifier); ?>">
                        <small class="text-muted">Required for customs clearance in the selected destination country.</small>
                      </div>
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

          <?php
          $b = $addr['BILLING'] ?? [];
          $s = $addr['SHIPPING'] ?? [];
          $billingState = strtoupper((string) ($b['country'] ?? '')) === 'US'
            ? usStateFromZip(normalizeUsZipFromAddress($b))
            : '';
          $shippingState = strtoupper((string) ($s['country'] ?? '')) === 'US'
            ? usStateFromZip(normalizeUsZipFromAddress($s))
            : '';
          $fullBilling = $b ? addressCopyText($b, $billingState) . (!empty($b['country']) ? "\n" . strtoupper((string) $b['country']) : '') : '';
          $fullShipping = $s ? trim(
            addressCopyText($s, $shippingState) .
            (!empty($s['country']) ? "\n" . strtoupper((string) $s['country']) : '') .
            ($customsIdentifier !== '' ? "\n" . $customsIdentifierLabel . ': ' . $customsIdentifier : '') .
            ($deliveryEmail !== '' ? "\nEmail: " . $deliveryEmail : '') .
            ($deliveryContactPhone !== '' ? "\nPhone: " . $deliveryContactPhone : '')
          ) : '';
          $billingPhone = trim((string) ($b['phone'] ?? ''));
          ?>

          <div class="order-header-summary order-summary-address-grid">
            <section class="order-summary-card">
              <div class="order-summary-card-title">
                <span><i class="fas fa-file-invoice mr-1"></i>Billing address</span>
                <?php if ($fullBilling !== ''): ?>
                  <button class="btn btn-xs btn-copy-inline" data-copy="<?php echo h($fullBilling); ?>">📋 Copy</button>
                <?php endif; ?>
              </div>
              <?php if ($b): ?>
                <div class="order-summary-primary"><?php echo h($b['name'] ?? '-'); ?></div>
                <?php if (!empty($b['company'])): ?>
                  <div class="order-summary-line"><?php echo h($b['company']); ?><?php echo !empty($b['company_id']) ? ' [' . h($b['company_id']) . ']' : ''; ?></div>
                <?php elseif (!empty($b['company_id'])): ?>
                  <div class="order-summary-line">Company ID: <?php echo h($b['company_id']); ?></div>
                <?php endif; ?>
                <div class="order-summary-line"><?php echo h($b['street'] ?? ''); ?></div>
                <div class="order-summary-line"><?php echo h(trim(($b['city'] ?? '') . ' ' . ($b['zip'] ?? ''))); ?></div>
                <?php if ($billingState !== ''): ?><div class="order-summary-line">State: <b><?php echo h($billingState); ?></b></div><?php endif; ?>
                <?php if (!empty($b['country'])): ?>
                  <div class="order-summary-country"><?php echo countryFlag($b['country']); ?> <?php echo h(strtoupper((string) $b['country'])); ?></div>
                <?php endif; ?>
                <?php if ($billingPhone !== '' && $billingPhone !== $deliveryContactPhone): ?>
                  <div class="order-summary-line mt-1"><i class="fas fa-phone-alt mr-1"></i><?php echo h($billingPhone); ?><button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($billingPhone); ?>">📋</button></div>
                <?php endif; ?>
              <?php else: ?>
                <div class="text-muted">No billing address</div>
              <?php endif; ?>
            </section>

            <section class="order-summary-card">
              <div class="order-summary-card-title">
                <span><i class="fas fa-shipping-fast mr-1"></i>Delivery address</span>
                <?php if ($fullShipping !== ''): ?>
                  <button class="btn btn-xs btn-copy-inline" data-copy="<?php echo h($fullShipping); ?>">📋 Copy</button>
                <?php endif; ?>
              </div>
              <?php if ($s): ?>
                <div class="order-summary-primary"><?php echo h($s['name'] ?? '-'); ?></div>
                <?php if (!empty($s['company'])): ?>
                  <div class="order-summary-line"><?php echo h($s['company']); ?><?php echo !empty($s['company_id']) ? ' [' . h($s['company_id']) . ']' : ''; ?></div>
                <?php elseif (!empty($s['company_id'])): ?>
                  <div class="order-summary-line">Company ID: <?php echo h($s['company_id']); ?></div>
                <?php endif; ?>
                <div class="order-summary-line"><?php echo h($s['street'] ?? ''); ?></div>
                <div class="order-summary-line"><?php echo h(trim(($s['city'] ?? '') . ' ' . ($s['zip'] ?? ''))); ?></div>
                <?php if ($shippingState !== ''): ?><div class="order-summary-line">State: <b><?php echo h($shippingState); ?></b></div><?php endif; ?>
                <div class="order-summary-country">
                  <?php if (!empty($s['country'])): ?><?php echo countryFlag($s['country']); ?><?php endif; ?>
                  <span class="order-country-display"><?php echo h($orderCountry ?: '-'); ?></span>
                  <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                    <button type="button" class="btn btn-xs btn-outline-warning btn-edit-country ml-1"
                      data-order-id="<?php echo (int) $orderId; ?>" data-country="<?php echo h($orderCountry); ?>">Edit</button>
                  <?php endif; ?>
                </div>
                <?php if ($customsIdentifier !== ''): ?>
                  <div class="order-summary-line mt-2">
                    <i class="fas fa-passport mr-1" aria-hidden="true"></i><b><?php echo h($customsIdentifierLabel); ?>:</b>
                    <?php echo h($customsIdentifier); ?>
                    <button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($customsIdentifier); ?>">📋</button>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <div class="text-muted">No delivery address</div>
              <?php endif; ?>
            </section>

          </div>

          <?php $orderOperationsCanEdit = (int) ($_SESSION['permission'] ?? 0) >= 300; ?>
          <div class="order-header-summary order-header-operations">
            <div class="order-header-operations-card">
              <div class="order-header-operations-title">Invoices</div>
              <?php
              $invoiceRows = [];
              $invStmt = $conn->prepare("SELECT id, invoice_number FROM order_invoices WHERE order_id = ? AND deleted_at IS NULL ORDER BY id DESC");
              if ($invStmt) {
                $invStmt->bind_param('i', $orderId);
                $invStmt->execute();
                $invRes = $invStmt->get_result();
                while ($inv = $invRes->fetch_assoc()) {
                  $invoiceRows[] = $inv;
                }
                $invStmt->close();
              }
              ?>
              <?php if ($invoiceRows): ?>
                <?php foreach ($invoiceRows as $inv): ?>
                  <?php $invoiceNumber = trim((string) ($inv['invoice_number'] ?? '')); ?>
                  <div class="small mb-1 order-header-copy-row">
                    <b class="order-header-copy-value"><?php echo h($invoiceNumber); ?></b>
                    <button type="button" class="btn btn-xs btn-copy-inline" data-copy="<?php echo h($invoiceNumber); ?>" title="Copy invoice number">📋</button>
                    <?php if ($orderOperationsCanEdit): ?>
                      <button type="button" class="btn btn-xs btn-outline-danger ml-1 py-0 px-2 btn-delete-invoice" data-id="<?php echo (int) $inv['id']; ?>" data-order-id="<?php echo (int) $orderId; ?>">×</button>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="order-header-copy-empty">No invoice yet.</div>
              <?php endif; ?>
              <?php if ($orderOperationsCanEdit): ?>
                <div class="form-row mt-2 invoice-add-row">
                  <div class="col-md-8"><input class="form-control form-control-sm invoice-number" placeholder="Invoice number"></div>
                  <div class="col-md-4"><button type="button" class="btn btn-sm btn-info btn-block btn-add-invoice" data-order-id="<?php echo (int) $orderId; ?>">Add Invoice</button></div>
                </div>
              <?php endif; ?>
            </div>

            <div class="order-header-operations-card">
              <div class="order-header-operations-title">Tracking</div>
              <?php
              $trackingRows = [];
              $trackingColumns = orderDetailTableColumns($conn, 'order_tracking_numbers');
              $trackingDeliveredSelect = in_array('delivered_at', $trackingColumns, true) ? 'delivered_at' : 'NULL AS delivered_at';
              $trackingFedexStatusSelect = in_array('fedex_status_detail', $trackingColumns, true) ? 'fedex_status_detail' : 'NULL AS fedex_status_detail';
              $trackingStmt = $conn->prepare("SELECT id, tracking_number, carrier, created_at, $trackingDeliveredSelect, $trackingFedexStatusSelect FROM order_tracking_numbers WHERE order_id = ? AND deleted_at IS NULL ORDER BY id DESC");
              if ($trackingStmt) {
                $trackingStmt->bind_param('i', $orderId);
                $trackingStmt->execute();
                $trackingRes = $trackingStmt->get_result();
                while ($t = $trackingRes->fetch_assoc()) {
                  $trackingRows[] = $t;
                }
                $trackingStmt->close();
              }
              ?>
              <?php if ($trackingRows): ?>
                <?php foreach ($trackingRows as $t): ?>
                  <?php
                  $trackingNumber = trim((string) ($t['tracking_number'] ?? ''));
                  $trackingCarrier = trim((string) ($t['carrier'] ?? ''));
                  $trackingShippedTs = !empty($t['created_at']) ? strtotime((string) $t['created_at']) : false;
                  $trackingDeliveredTs = !empty($t['delivered_at']) ? strtotime((string) $t['delivered_at']) : false;
                  ?>
                  <div class="small mb-1 tracking-copy-row">
                    <span class="tracking-copy-main">
                      <b class="order-header-copy-value"><?php echo h($trackingNumber); ?></b>
                      <button type="button" class="btn btn-xs btn-copy-inline" data-copy="<?php echo h($trackingNumber); ?>" title="Copy tracking number">📋</button>
                    </span>
                    <span class="tracking-carrier-label" title="<?php echo h($trackingCarrier); ?>"><?php echo h($trackingCarrier !== '' ? $trackingCarrier : '-'); ?></span>
                    <?php if ($trackingShippedTs !== false): ?>
                      <span class="tracking-date-chip text-muted" title="Shipped: <?php echo h(date('d.m.Y H:i', $trackingShippedTs)); ?>">
                        <i class="fas fa-truck"></i><?php echo h(date('d.m.y H:i', $trackingShippedTs)); ?>
                      </span>
                    <?php else: ?>
                      <span></span>
                    <?php endif; ?>
                    <?php if ($trackingDeliveredTs !== false): ?>
                      <span class="tracking-date-chip text-success" title="Delivered: <?php echo h(date('d.m.Y H:i', $trackingDeliveredTs)); ?>">
                        <i class="fas fa-check-circle"></i><?php echo h(date('d.m.y H:i', $trackingDeliveredTs)); ?>
                      </span>
                    <?php elseif (!empty($t['fedex_status_detail'])): ?>
                      <span class="tracking-date-chip text-muted" title="FedEx: <?php echo h((string) $t['fedex_status_detail']); ?>">
                        <i class="fas fa-info-circle"></i><?php echo h((string) $t['fedex_status_detail']); ?>
                      </span>
                    <?php else: ?>
                      <span></span>
                    <?php endif; ?>
                    <?php if ($orderOperationsCanEdit): ?>
                      <button type="button" class="btn btn-xs btn-outline-danger ml-1 py-0 px-2 btn-delete-tracking" data-id="<?php echo (int) $t['id']; ?>" data-order-id="<?php echo (int) $orderId; ?>">×</button>
                    <?php else: ?>
                      <span></span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="order-header-copy-empty">No tracking yet.</div>
              <?php endif; ?>
              <?php if ($orderOperationsCanEdit): ?>
                <div class="form-row tracking-add-row mt-2">
                  <input class="form-control form-control-sm tracking-number" placeholder="Tracking number">
                  <input class="form-control form-control-sm tracking-carrier" placeholder="Carrier">
                  <button type="button" class="btn btn-sm btn-info btn-add-tracking" data-order-id="<?php echo (int) $orderId; ?>">Add Tracking</button>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-lg-4 mt-3 mt-lg-0 d-flex">
          <div class="order-value-breakdown-card" data-order-financial-card data-order-id="<?php echo (int) $orderId; ?>">
            <div class="order-value-breakdown-heading">
              <span>Financial breakdown</span>
              <?php if ($financialCanEdit): ?>
                <button type="button" class="btn btn-xs btn-outline-info btn-add-financial-adjustment"
                  data-order-id="<?php echo (int) $orderId; ?>">
                  <i class="fas fa-plus mr-1"></i>Payment / refund
                </button>
              <?php endif; ?>
            </div>

            <div class="order-financial-total-editor">
              <label>Total value</label>
              <?php if ($financialCanEdit): ?>
                <div class="input-group input-group-sm">
                  <input type="text" class="form-control bg-dark text-light border-info order-financial-total-input"
                    value="<?php echo h(number_format($financialEffectiveTotal, 2, '.', '')); ?>"
                    data-original-value="<?php echo h(number_format($financialEffectiveTotal, 2, '.', '')); ?>">
                  <div class="input-group-append">
                    <span class="input-group-text bg-info border-info text-white">EUR</span>
                    <button type="button" class="btn btn-info btn-save-financial-total"
                      data-order-id="<?php echo (int) $orderId; ?>">
                      Save
                    </button>
                    <?php if ($financialTotalOverrideActive): ?>
                      <button type="button" class="btn btn-outline-secondary btn-reset-financial-total"
                        data-order-id="<?php echo (int) $orderId; ?>">
                        Reset
                      </button>
                    <?php endif; ?>
                  </div>
                </div>
              <?php else: ?>
                <div class="order-value-breakdown-row order-value-breakdown-total mb-1">
                  <span>Total Order Value:</span>
                  <span><?php echo number_format($orderValueBreakdown['total'], 2, '.', ''); ?><?php echo h($orderCurrencySuffix); ?></span>
                </div>
              <?php endif; ?>
              <div class="order-financial-meta mt-1">
                Imported: <?php echo number_format($financialBaseTotal, 2, '.', ''); ?><?php echo h($financialSourceCurrencySuffix); ?>
                <?php if (abs($financialAdjustmentsTotal) >= 0.005): ?>
                  · Movements: <?php echo ($financialAdjustmentsTotal > 0 ? '+' : ''); ?><?php echo number_format($financialAdjustmentsTotal, 2, '.', ''); ?> €
                  · Calculated: <?php echo number_format($financialCalculatedTotal, 2, '.', ''); ?> €
                <?php endif; ?>
                <?php if ($financialTotalOverrideActive): ?>
                  · Manual total
                <?php endif; ?>
              </div>
            </div>

            <?php if (!empty($financialAdjustmentRows)): ?>
              <div class="order-financial-adjustment-list">
                <?php foreach ($financialAdjustmentRows as $financialAdjustment): ?>
                  <?php
                  $financialAdjustmentAmount = (float) ($financialAdjustment['amount'] ?? 0);
                  $financialAdjustmentClass = $financialAdjustmentAmount < 0 ? 'text-warning' : 'text-info';
                  $financialAdjustmentReference = trim((string) ($financialAdjustment['reference'] ?? ''));
                  $financialAdjustmentPurpose = trim((string) ($financialAdjustment['purpose'] ?? ''));
                  ?>
                  <div class="order-financial-adjustment-row">
                    <div class="order-financial-adjustment-main">
                      <div class="order-financial-adjustment-purpose <?php echo h($financialAdjustmentClass); ?>">
                        <?php echo h($financialAdjustmentPurpose !== '' ? $financialAdjustmentPurpose : ($financialAdjustmentAmount < 0 ? 'Refund' : 'Payment')); ?>
                      </div>
                      <div class="order-financial-adjustment-meta">
                        <?php echo h($financialAdjustmentReference !== '' ? $financialAdjustmentReference : 'No reference'); ?>
                        <?php if (!empty($financialAdjustment['created_at'])): ?>
                          · <?php echo h(date('d.m.Y H:i', strtotime((string) $financialAdjustment['created_at']))); ?>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="d-flex align-items-center">
                      <span class="order-financial-adjustment-amount <?php echo h($financialAdjustmentClass); ?>">
                        <?php echo ($financialAdjustmentAmount > 0 ? '+' : ''); ?><?php echo number_format($financialAdjustmentAmount, 2, '.', ''); ?> €
                      </span>
                      <?php if ($financialCanEdit): ?>
                        <button type="button" class="btn btn-xs btn-outline-danger ml-2 btn-delete-financial-adjustment"
                          data-id="<?php echo (int) ($financialAdjustment['id'] ?? 0); ?>">
                          ×
                        </button>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($paymentReceivedAmount !== null && empty($customFinancialBreakdown)): ?>
              <div class="order-value-breakdown-row text-success">
                <span>Payment received:</span>
                <span><?php echo number_format($paymentReceivedAmount, 2, '.', ''); ?><?php echo h($orderCurrencySuffix); ?></span>
              </div>
              <div class="order-value-breakdown-row <?php echo abs((float) $paymentDifference) < 0.005 ? 'text-muted' : ((float) $paymentDifference > 0 ? 'text-info' : 'text-warning'); ?>">
                <span>Payment difference:</span>
                <span><?php echo ((float) $paymentDifference > 0 ? '+' : ''); ?><?php echo number_format((float) $paymentDifference, 2, '.', ''); ?><?php echo h($orderCurrencySuffix); ?></span>
              </div>
            <?php endif; ?>
            <?php if ($isShoptetOrder && !empty($shoptetBreakdown)): ?>
              <?php foreach ($shoptetBreakdown as $shoptetRow): ?>
                <div class="order-value-breakdown-row">
                  <span><?php echo h($shoptetRow['label']); ?>:</span>
                  <span><?php echo number_format((float) $shoptetRow['value'], 2, '.', ''); ?><?php echo h($orderCurrencySuffix); ?></span>
                </div>
              <?php endforeach; ?>
            <?php elseif ($activePercentageBreakdown !== null): ?>
              <?php foreach ([
                'graphics'    => 'Graphics',
                'plastics'    => 'Plastics',
                'seat_covers' => 'Seat Covers',
                'fitting'     => 'Fitting',
                'accessories' => 'Accessories',
                'shipping'    => 'Shipping',
                'other'       => 'Other',
              ] as $breakdownKey => $breakdownLabel): ?>
                <?php if (($orderValueBreakdown[$breakdownKey] ?? 0.0) > 0.0): ?>
                  <div class="order-value-breakdown-row">
                    <span><?php echo h($breakdownLabel); ?>:</span>
                    <span><?php echo number_format($orderValueBreakdown[$breakdownKey], 2, '.', ''); ?><?php echo h($orderCurrencySuffix); ?></span>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php else: ?>
              <?php foreach ([
                'graphics'    => 'Graphics',
                'plastics'    => 'Plastics',
                'seat_covers' => 'Seat Covers',
                'fitting'     => 'Fitting',
                'accessories' => 'Accessories',
                'shipping'    => 'Shipping',
                'other'       => 'Other',
              ] as $breakdownKey => $breakdownLabel): ?>
                <?php
                $breakdownValue = (float) ($orderValueBreakdown[$breakdownKey] ?? 0.0);
                if (!empty($customFinancialBreakdown) && $breakdownKey !== 'shipping' && $breakdownValue <= 0.0) {
                  continue;
                }
                ?>
                <div class="order-value-breakdown-row">
                  <span><?php echo h($breakdownLabel); ?>:</span>
                  <span><?php echo number_format($breakdownValue, 2, '.', ''); ?><?php echo h($orderCurrencySuffix); ?></span>
                </div>
              <?php endforeach; ?>
              <?php if (abs($financialBreakdownAdjustment) >= 0.005): ?>
                <div class="order-value-breakdown-row <?php echo $financialBreakdownAdjustment < 0 ? 'text-warning' : 'text-info'; ?>">
                  <span><?php echo $financialBreakdownAdjustment < 0 ? 'Financial refund / adjustment' : 'Financial adjustment'; ?>:</span>
                  <span><?php echo ($financialBreakdownAdjustment > 0 ? '+' : ''); ?><?php echo number_format($financialBreakdownAdjustment, 2, '.', ''); ?><?php echo h($orderCurrencySuffix); ?></span>
                </div>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($customFinancialBreakdown)): ?>
              <?php $customPaidNet = (float) ($customFinancialBreakdown['paid_net'] ?? 0.0); ?>
              <hr style="border-color:rgba(255,255,255,.14);">
              <div class="order-value-breakdown-row">
                <span>Deposits:</span>
                <span><?php echo number_format((float) ($customFinancialBreakdown['deposits'] ?? 0.0), 2, '.', ''); ?><?php echo h($orderCurrencySuffix); ?></span>
              </div>
              <div class="order-value-breakdown-row">
                <span>Paid net:</span>
                <span><?php echo number_format($customPaidNet, 2, '.', ''); ?><?php echo h($orderCurrencySuffix); ?></span>
              </div>
              <div class="order-value-breakdown-row font-weight-bold">
                <span>Balance due:</span>
                <span><?php echo number_format((float) $orderValueBreakdown['total'] - $customPaidNet, 2, '.', ''); ?><?php echo h($orderCurrencySuffix); ?></span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <hr />
      <?php
      $showFollowupPanel = (int) ($_SESSION['permission'] ?? 0) >= 300 && !empty($items);
      $productionNoteColClass = $showFollowupPanel ? 'col-lg-4' : 'col-lg-8';
      ?>

      <div class="row order-detail-secondary-row align-items-stretch mb-3">
        <div class="<?php echo $productionNoteColClass; ?>">
          <div class="card bg-dark border-info production-note-box">
            <div class="card-header d-flex align-items-start justify-content-between">
              <b>Production Note</b>
              <button type="button" class="btn btn-sm btn-outline-info ml-auto btn-edit-production-note">
                Add note
              </button>
            </div>

            <div class="card-body">
              <div class="production-note-thread" style="max-height:260px; overflow-y:auto;">
                <?php if (empty($productionNotes)): ?>
                  <span class="text-muted production-note-empty">No production notes yet.</span>
                <?php else: ?>
                  <?php foreach ($productionNotes as $noteRow): ?>
                    <?php
                    $noteAuthor = trim((string) ($noteRow['firstname'] ?? '') . ' ' . (string) ($noteRow['lastname'] ?? ''));
                    $notePhoto = trim((string) ($noteRow['photo'] ?? ''));
                    $noteAt = trim((string) ($noteRow['created_at'] ?? ''));
                    ?>
                    <div class="production-note-entry mb-2 pb-2 border-bottom border-secondary">
                      <div class="d-flex align-items-center mb-1 text-muted">
                        <?php if ($notePhoto !== ''): ?>
                          <img src="images/<?= h($notePhoto) ?>" class="img-circle mr-2"
                            style="width:20px; height:20px; object-fit:cover;" alt="<?= h($noteAuthor) ?>">
                        <?php else: ?>
                          <i class="fas fa-user-circle mr-2"></i>
                        <?php endif; ?>

                        <small>
                          <b><?= h($noteAuthor !== '' ? $noteAuthor : 'Unknown') ?></b>
                          <?php if ($noteAt !== ''): ?>
                            · <?= h($noteAt) ?>
                          <?php endif; ?>
                        </small>
                      </div>
                      <div class="production-note-display text-light" style="white-space:pre-wrap;"><?php echo h($noteRow['note'] ?? ''); ?></div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <div class="production-note-editor mt-2" style="display:none;"><textarea
                  class="form-control form-control-sm production-note-input production-note-textarea" rows="2"
                  placeholder="Customer changes / production instructions..."></textarea>

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
          </div>
        </div>

        <?php if ($showFollowupPanel): ?>
        <div class="col-lg-4">
            <div class="order-followup-panel">
              <div class="card bg-dark border-info">
            <div class="card-header d-flex align-items-start justify-content-between">
              <div>
                <b>Create Follow-up Order</b>
                <div class="small text-muted">Repeat, warranty claim, crash replacement or split from selected items.</div>
              </div>
              <button type="button" class="btn btn-sm btn-outline-info ml-auto btn-toggle-followup-panel">
                Open
              </button>
            </div>
            <div class="card-body order-followup-form" style="display:none;">
              <input type="hidden" class="followup-order-id" value="<?php echo (int) $orderId; ?>">

              <div class="form-row">
                <div class="form-group col-md-4">
                  <label>Type</label>
                  <select class="form-control form-control-sm followup-type-select">
                    <option value="REPEAT">Repeat Order</option>
                    <option value="WARRANTY">Warranty Claim</option>
                    <option value="CRASH">Crash Replacement</option>
                    <option value="SPLIT">Order Split</option>
                  </select>
                </div>
                <div class="form-group col-md-4">
                  <label>Billing</label>
                  <div class="form-control form-control-sm bg-secondary followup-invoice-state">Standard invoicing</div>
                </div>
                <div class="form-group col-md-4">
                  <label>&nbsp;</label>
                  <div class="form-check mt-1">
                    <input class="form-check-input followup-do-not-invoice" type="checkbox" value="1" id="followup-do-not-invoice-<?php echo (int) $orderId; ?>">
                    <label class="form-check-label" for="followup-do-not-invoice-<?php echo (int) $orderId; ?>">
                      Do not invoice
                    </label>
                  </div>
                </div>
              </div>

              <div class="form-group">
                <label>Reason / note</label>
                <textarea class="form-control form-control-sm followup-reason" rows="2" placeholder="Why are we creating this follow-up order?"></textarea>
              </div>

              <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="small text-muted">Select items and quantities for the new order.</div>
                <button type="button" class="btn btn-xs btn-outline-light btn-followup-select-all">Select all</button>
              </div>

              <div class="table-responsive">
                <table class="table table-sm table-bordered table-dark mb-0">
                  <thead>
                    <tr>
                      <th style="width:50px;">Use</th>
                      <th style="width:70px;">Type</th>
                      <th>Item</th>
                      <th style="width:90px;">Orig. Qty</th>
                      <th style="width:90px;">New Qty</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($items as $followupItem): ?>
                      <tr>
                        <td class="text-center">
                          <input type="checkbox" class="followup-item-check" data-item-id="<?php echo (int) $followupItem['id']; ?>" checked>
                        </td>
                        <td><?php echo h((string) ($followupItem['item_type_code'] ?? '')); ?></td>
                        <td>
                          <?php echo h((string) ($followupItem['title'] ?? '')); ?>
                          <?php if (!empty($followupItem['sku']) || !empty($followupItem['custom_label'])): ?>
                            <div class="small text-muted">
                              <?php echo h(trim((string) ($followupItem['sku'] ?? ''))); ?>
                              <?php if (!empty($followupItem['custom_label'])): ?>
                                <span class="ml-1"><?php echo h((string) $followupItem['custom_label']); ?></span>
                              <?php endif; ?>
                            </div>
                          <?php endif; ?>
                        </td>
                        <td><?php echo (int) ($followupItem['qty'] ?? 0); ?></td>
                        <td>
                          <input type="number" min="1" max="<?php echo (int) ($followupItem['qty'] ?? 1); ?>"
                            value="<?php echo (int) ($followupItem['qty'] ?? 1); ?>"
                            class="form-control form-control-sm followup-item-qty"
                            data-item-id="<?php echo (int) $followupItem['id']; ?>">
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <div class="mt-3 d-flex align-items-center justify-content-between">
                <div class="small text-muted followup-hint">Repeat order will copy selected items into a new production order.</div>
                <button type="button" class="btn btn-success btn-sm btn-create-followup-order">Create Follow-up</button>
              </div>
            </div>
              </div>
          </div>
        </div>
        <?php endif; ?>

        <div class="col-lg-4">
          <div class="order-photos-panel w-100">
            <div
              class="order-photos-card <?php echo ((int) ($_SESSION['permission'] ?? 0) > 300) ? 'order-photos-card-admin' : 'order-photos-card-user'; ?>"
              data-order-id="<?php echo (int) $orderId; ?>">
          <?php if ((int) ($_SESSION['permission'] ?? 0) > 300): ?>
            <div class="order-photo-dropzone" data-order-id="<?php echo (int) $orderId; ?>">
              <input type="file" class="order-photo-input d-none" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
              <div>
                <i class="fas fa-cloud-upload-alt d-block mb-1"></i>
                <b>Drag & drop photos</b>
                <div class="small text-muted">alebo klikni pre výber · resize na max 1500 px</div>
              </div>
            </div>
            <div class="order-photo-upload-progress mt-2"><span></span></div>
          <?php endif; ?>

              <div class="order-photo-thumb-grid mt-2">
            <?php if (!empty($orderPhotos)): ?>
              <?php foreach ($orderPhotos as $photo): ?>
                <?php $photoUrl = (string) ($photo['file_path'] ?? ''); ?>
                <div class="order-photo-thumb-wrap" data-photo-id="<?php echo (int) $photo['id']; ?>">
                  <img src="<?php echo h($photoUrl); ?>" class="order-photo-thumb"
                    data-full-src="<?php echo h($photoUrl); ?>"
                    alt="<?php echo h($photo['original_name'] ?? 'Order photo'); ?>">
                  <?php if ((int) ($_SESSION['permission'] ?? 0) > 300): ?>
                    <button type="button" class="btn btn-xs btn-danger btn-delete-order-photo"
                      data-photo-id="<?php echo (int) $photo['id']; ?>" title="Delete photo">×</button>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="text-muted small order-photo-empty">Žiadne fotky.</div>
            <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0 order-detail-table">
          <tbody>

            <?php foreach ($items as $it): ?>
              <?php
              $seatPatchGroupItemId = (int) ($it['id'] ?? 0);
              $isSeatPatchGroupStart = isset($seatCoverPatchGroups[$seatPatchGroupItemId]);
              $isSeatPatchGroupEnd = isset($patchToSeatCoverGroup[$seatPatchGroupItemId]);
              $seatPatchGroupClass = ($isSeatPatchGroupStart || $isSeatPatchGroupEnd) ? 'seat-patch-group-row' : '';
              if ($isSeatPatchGroupStart)
                $seatPatchGroupClass .= ' seat-patch-group-first';
              if ($isSeatPatchGroupEnd)
                $seatPatchGroupClass .= ' seat-patch-group-last';
              ?>
              <?php if ($isSeatPatchGroupStart || $isSeatPatchGroupEnd): ?>
                <tr class="seat-patch-group-label" aria-hidden="true">
                  <td colspan="99">🪑📎 Seat Cover + Patch</td>
                </tr>
              <?php endif; ?>
              <tr class="item-repeat-header-row <?= $seatPatchGroupClass ?>">
                <th class="text-center">Assigned</th>
                <th>Type</th>
                <th class="text-center">Názov</th>
                <th>Qty</th>
                <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                  <th>Price</th>
                <?php endif; ?>

                <th title="Category / model info">Category Info</th>
                <th title="Product specification" style="display:none;">📋 Product Specification</th>
                <th>Link</th>
                <th class="text-center">Detail</th>
                <th>Action</th>
                <th>Waiting</th>
                <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                  <th class="text-center">Save</th>
                  <th class="text-center">Delete</th>
                <?php endif; ?>
              </tr>
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
              <?php
              $itemTypeClass = 'item-type-' . strtoupper(trim((string) ($it['item_type_code'] ?? 'X')));
              $noOptionsClass = (strtoupper(trim((string) ($it['item_type_code'] ?? ''))) === 'G') ? '' : 'item-no-options';
              ?>
              <tr
                class="<?php echo ((int) $it['qty'] > 1 ? 'qty-warning-row' : ''); ?> item-info-row <?= $itemTypeClass ?> <?= $noOptionsClass ?> <?= $seatPatchGroupClass ?>"
                data-item-type="<?php echo h($it['item_type_code'] ?? ''); ?>">
                <td class="text-center" style="width:50px;">
                  <?php
                  $assignedRaw = trim((string) ($it['item_assigned_users'] ?? ''));
                  $itemAssignedRaw = trim((string) ($it['item_assigned_users_raw'] ?? ''));
                  $itemAssigned = [];
                  $realItemAssigned = [];

                  if ($assignedRaw !== '') {
                    foreach (explode(';;', $assignedRaw) as $part) {
                      $bits = explode('|', $part);
                      if (count($bits) >= 3) {
                        $itemAssigned[] = [
                          'id' => (int) $bits[0],
                          'name' => $bits[1],
                          'photo' => $bits[2],
                          'assignment_id' => (int) ($bits[3] ?? 0),
                          'item_assignment_id' => (int) ($bits[4] ?? 0),
                          'assignment_role' => strtoupper((string) ($bits[5] ?? 'WORKER')),
                        ];
                      }
                    }
                  }

                  if ($itemAssignedRaw !== '') {
                    foreach (explode(';;', $itemAssignedRaw) as $part) {
                      $bits = explode('|', $part);
                      if (count($bits) >= 3) {
                        $realItemAssigned[] = [
                          'id' => (int) $bits[0],
                          'name' => $bits[1],
                          'photo' => $bits[2],
                          'assignment_id' => (int) ($bits[3] ?? 0),
                          'item_assignment_id' => (int) ($bits[4] ?? 0),
                          'assignment_role' => strtoupper((string) ($bits[5] ?? 'WORKER')),
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
                  $itemTypePrimaryRoleMap = [
                    'G' => 'PRIMARY_GRAPHICS',
                    'P' => 'PRIMARY_PLASTICS',
                    'T' => 'PRIMARY_PLASTICS',
                    'M' => 'PRIMARY_PLASTICS',
                    'S' => 'PRIMARY_SEATCOVER',
                    'F' => 'PRIMARY_FITTING',
                  ];
                  $itemTypeDeptCodeMap = [
                    'G' => 'GRAPHICS',
                    'P' => 'PLASTICS',
                    'T' => 'PLASTICS',
                    'M' => 'PLASTICS',
                    'S' => 'SEATCOVER',
                    'F' => 'FITTING',
                  ];

                  $currentDeptPrimaryRole = $itemTypePrimaryRoleMap[$itemType] ?? '';
                  $currentDeptCode = $itemTypeDeptCodeMap[$itemType] ?? '';
                  $currentUserCanPersonalOrders = $currentUserHasPersonalOrders;

                  $canTakeOrderFromDetail = (
                    $currentDeptPrimaryRole !== ''
                    && (
                      ((int) ($_SESSION['permission'] ?? 0) >= 400)
                      || ($itemType === 'F' && $currentUserCanPersonalOrders)
                      || (isset($dptItemMap[$userDpt]) && $dptItemMap[$userDpt] === $itemType)
                    )
                  );
                  ?>

                  <div class="d-flex justify-content-center align-items-center flex-wrap" style="gap:4px;">
                    <?php foreach ($itemAssigned as $a): ?>
                      <?php
                      $name = trim((string) $a['name']);
                      $photo = trim((string) $a['photo']);
                      $assignmentRole = strtoupper((string) ($a['assignment_role'] ?? 'WORKER'));
                      $assignmentRoleLabel = $assignmentRole === 'PREPARED'
                        ? 'Scanned Out / Prepared'
                        : ($assignmentRole === 'CHECKED' ? 'Ready / Checked' : 'Assigned');
                      $assignmentRoleMark = $assignmentRole === 'PREPARED'
                        ? 'S'
                        : ($assignmentRole === 'CHECKED' ? 'R' : '');

                      $initials = '';
                      foreach (preg_split('/\s+/', $name) as $p) {
                        if ($p !== '') {
                          $initials .= mb_strtoupper(mb_substr($p, 0, 1));
                        }
                      }
                      $initials = mb_substr($initials, 0, 2);

                      $removeAssignmentKind = 'item';
                      $removeAssignmentId = (int) ($a['item_assignment_id'] ?? 0);
                      $removeOrderAssignmentId = $removeAssignmentId > 0 ? 0 : (int) ($a['assignment_id'] ?? 0);
                      $canRemoveThisAssignment = (
                        ($removeAssignmentId > 0 || $removeOrderAssignmentId > 0)
                        && (
                          (int) ($_SESSION['permission'] ?? 0) >= 300
                          || (int) $a['id'] === $currentUserId
                        )
                      );
                      ?>

                      <span class="assigned-avatar-wrap">

                        <?php if ($photo !== ''): ?>
                          <img src="images/<?= h($photo) ?>" class="img-circle elevation-2"
                            style="width:28px; height:28px; object-fit:cover;" title="<?= h($name . ' — ' . $assignmentRoleLabel) ?>">
                        <?php else: ?>
                          <span class="badge badge-secondary"
                            style="width:28px; height:28px; line-height:28px; border-radius:50%;" title="<?= h($name . ' — ' . $assignmentRoleLabel) ?>">
                            <?= h($initials ?: '?') ?>
                          </span>
                        <?php endif; ?>

                        <?php if ($assignmentRoleMark !== ''): ?>
                          <span title="<?= h($assignmentRoleLabel) ?>"
                            style="position:absolute; right:-4px; bottom:-5px; min-width:14px; height:14px; padding:0 3px; border-radius:7px; background:<?= $assignmentRole === 'CHECKED' ? '#28a745' : '#17a2b8' ?>; color:#fff; border:1px solid #25313d; font-size:8px; font-weight:800; line-height:12px; text-align:center;">
                            <?= h($assignmentRoleMark) ?>
                          </span>
                        <?php endif; ?>

                        <?php if ($canRemoveThisAssignment): ?>
                          <button type="button" class="btn-remove-assignment btn-remove-item-assignment"
                            data-assignment-id="<?= $removeAssignmentId ?>"
                            data-assignment-kind="<?= h($removeAssignmentKind) ?>"
                            data-order-assignment-id="<?= $removeOrderAssignmentId ?>"
                            data-item-id="<?= (int) $it['id'] ?>"
                            title="<?= ((int) $a['id'] === $currentUserId ? 'Remove my assignment' : 'Remove assignment') ?>">
                            ×
                          </button>
                        <?php endif; ?>

                      </span>
                    <?php endforeach; ?>

                    <?php if ($canTakeOrderFromDetail && empty($realItemAssigned)): ?>
                      <button type="button" class="btn btn-sm btn-warning btn-take-order px-2 py-1"
                        style="font-size:11px; font-weight:700; padding:2px 8px; border-radius:8px; letter-spacing:.3px;"
                        data-order-id="<?= (int) $orderId ?>" data-dept-code="<?= h($currentDeptCode) ?>"
                        data-item-id="<?= (int) $it['id'] ?>"
                        title="Take this item for my department">
                        TAKE
                      </button>
                    <?php endif; ?>
                  </div>
                </td>

                <td class="text-center" style="width:40px;">
                  <?php echo item_type_category_badge($it, $order, $addr, $orderCountry); ?>
                </td>

                <td style="min-width:180px;">
                  <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                    <input class="form-control form-control-sm item-title mb-1"
                      value="<?php echo h($it['title'] ?? ''); ?>">
                  <?php else: ?>
                    <?php echo h($it['title'] ?? ''); ?>
                  <?php endif; ?>
                  <input type="hidden" class="item-sku" value="<?php echo h($it['sku'] ?? ''); ?>">
                  <input type="hidden" class="item-label" value="<?php echo h($it['custom_label'] ?? ''); ?>">
                  <?php
                  $displaySku = trim((string) ($it['sku'] ?? ''));
                  $displayLabel = trim((string) ($it['custom_label'] ?? ''));
                  ?>
                  <?php if ($displaySku !== '' || $displayLabel !== ''): ?>
                    <div class="small text-muted">
                      <?= h($displaySku); ?>
                      <?php if ($displaySku !== '' && $displayLabel !== '' && strcasecmp($displaySku, $displayLabel) !== 0): ?>
                        | <?= h($displayLabel); ?><?php endif; ?>
                      <?php if ($displaySku === '' && $displayLabel !== ''): ?>       <?= h($displayLabel); ?>     <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>


                <td style="width:80px;">
                  <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                    <input type="number" class="form-control form-control-sm item-qty"
                      value="<?php echo (int) $it['qty']; ?>" min="1">
                  <?php else: ?>
                    <?php echo (int) $it['qty']; ?>
                  <?php endif; ?>
                </td>

                <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                  <td style="width:90px;<?= ((int) ($_SESSION['permission'] ?? 0) >= 300) ? '' : ' display:none;' ?>">
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
                <?php endif; ?>

                <td style="min-width:220px; display:none;">
                  <input type="text" class="form-control form-control-sm item-waiting-note"
                    data-item-id="<?= (int) $it['id'] ?>" value="<?= h($it['waiting_note'] ?? '') ?>"
                    placeholder="Na čo čakáme?">


                  <input type="date" class="form-control form-control-sm mt-1 item-expected-date"
                    data-item-id="<?= (int) $it['id'] ?>" value="<?= h($it['expected_date'] ?? '') ?>">
                </td>

                <td style="display:none;">
                  <?php
                  $type = strtoupper((string) ($it['item_type_code'] ?? ''));

                  $statusLabels = ordersGetItemStatusLabelsForItem($conn, $it, true);
                  $statuses = array_keys($statusLabels);

                  // Prazdny/legacy 'NEW' status uz nie je definovany v controlls.php.
                  // Namiesto pevneho stringu 'NEW' pouzijeme prvy (najnizsi sort_order)
                  // aktivny status pre dany department ako default.
                  $defaultStatus = $statuses[0] ?? 'NEW';
                  $rawStatus = strtoupper(trim((string) ($it['item_status'] ?? '')));
                  $currentStatus = ($rawStatus !== '' && $rawStatus !== 'NEW') ? $rawStatus : $defaultStatus;

                  if (!in_array($currentStatus, $statuses, true)) {
                    $statuses[] = $currentStatus;
                  }
                  ?>

                  <select class="form-control form-control-sm item-status-select" data-item-id="<?= (int) $it['id'] ?>">
                    <?php foreach ($statuses as $s): ?>
                      <option value="<?= h($s) ?>" <?= ($currentStatus === $s ? 'selected' : '') ?>>
                        <?= h($statusLabels[$s] ?? str_replace('_', ' ', $s)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <?php
                $productUrl = itemProductUrl($order, $it, $dpt === 6 && $orderHasPlasticsCategory);
                // --- Printing settings (stored in internal_options_json) ---
                $internalOptRaw = (string) ($it['internal_options_json'] ?? '{}');
                if (trim($internalOptRaw) === '')
                  $internalOptRaw = '{}';
                $internalOptArr = json_decode($internalOptRaw, true);
                if (!is_array($internalOptArr))
                  $internalOptArr = [];
                // Also read base-material / graphics-finish from options_json
                $extOptArr = jsonDecodeAssocSafe((string) ($it['options_json'] ?? '{}'));
                $customItemFallback = orderDetailCustomItemFallbackForItem($customItemOptionFallbacks, $it);
                if ($customItemFallback) {
                  $extOptArr = orderDetailMergeCustomPrintFallbackOptions($extOptArr, $internalOptArr, $customItemFallback);
                }
                $orderSourceCodeUpper = strtoupper(trim((string) ($order['source_code'] ?? '')));
                $canSetMissingCategoryInfo = in_array($orderSourceCodeUpper, ['CUSTOM', 'EBAY', 'MX_LOCKER', 'MXLOCKER'], true)
                  || strpos($orderSourceCodeUpper, 'EBAY') !== false;

                $printPrinter = (string) ($internalOptArr['_printer'] ?? '');
                $printMaterial = productSpecValueFromKeys($internalOptArr, ['_print_material']);
                if ($printMaterial === '') {
                  $printMaterial = productSpecValueFromKeys($extOptArr, ['base-material', 'base_material', 'material', 'graphics-material', 'graphics_material']);
                }
                $printFinish = productSpecValueFromKeys($internalOptArr, ['_print_finish']);
                if ($printFinish === '') {
                  $printFinish = productSpecValueFromKeys($extOptArr, ['graphics-finish', 'graphics_finish', 'finish']);
                }
                $printGrip = (string) ($internalOptArr['_print_grip'] ?? ($extOptArr['grip'] ?? ''));
                $printTrSwingarms = (string) ($internalOptArr['_print_tr_swingarms'] ?? ($extOptArr['tr-swingarms'] ?? $extOptArr['tr_swingarms'] ?? ''));
                $isGraphicsItem = (strtoupper(trim((string) ($it['item_type_code'] ?? ''))) === 'G');
                $isSeatCoverItem = (strtoupper(trim((string) ($it['item_type_code'] ?? ''))) === 'S');
                $isPatchItem = (($extOptArr['_auto_generated'] ?? '') === 'SEAT_PATCH_AUTO_GRAPHICS');
                // Upsellové auto-generated položky nemajú options formulár
                $hasOptionsForm = dept_has_options_form($it['options_json'] ?? null);
                // Subcategory z internal_options_json (nastavená pri importe)
                $itemSubcat = productSpecGraphicsSubcategoryFromItemData(
                  (string) ($internalOptArr['_subcat'] ?? ''),
                  (string) ($it['custom_label'] ?? ''),
                  (string) ($it['sku'] ?? '')
                );
                if ($itemSubcat === '' && strtoupper(trim((string) ($it['item_type_code'] ?? ''))) === 'M') {
                  $itemSubcat = 'MOTO_CARPET';
                }
                // Patch je auto-generated grafika zo Seat Cover — nedá sa poznať
                // podľa custom_label prefixu, preto sa priraďuje natvrdo podľa tagu.
                if ($isPatchItem) {
                  $itemSubcat = 'SEAT_PATCH';
                }
                // editaciu môže urobiť ktokoľvek z grafiky alebo admin, aby sa dali nastaviť tlačiarne aj pre iné oddelenia.
                $canEditPrint = ((int) ($_SESSION['permission'] ?? 0) >= 0);
                $itemSpecDepartment = productSpecDepartmentForItem($it);
                $itemProductSpecFields = [];
                $showItemProductSpecRow = false;
                $shouldRenderProductSpecFields = $itemSpecDepartment !== '' && ($hasOptionsForm || $itemSpecDepartment === 'F');
                if ($shouldRenderProductSpecFields) {
                  foreach (productSpecFieldDefinitions($conn, $itemSpecDepartment) as $productSpecDefinition) {
                    $fieldMeta = productSpecFieldMeta($productSpecDefinition);

                    $isEbayOrder = strpos(strtoupper((string) ($order['source_code'] ?? '')), 'EBAY') !== false;

                    $fieldSourceKey = productSpecNormalizeKey((string) ($fieldMeta['source_key'] ?? ''));

                    // Žiadne source-specific filtrovanie.
                    // Zobraz iba to, čo je aktívne v controlls.php / product_spec_options.

                    $fieldSubcategory = productSpecGraphicsSubcategoryFromSpecKey(
                      (string) ($fieldMeta['spec_key'] ?? ''),
                      (string) ($fieldMeta['department'] ?? $itemSpecDepartment)
                    );
                    $fieldAppliesToSubcategories = (int) ($fieldMeta['apply_to_subcategories'] ?? 0) === 1;

                    if ($itemSpecDepartment === 'G' && $itemSubcat !== '') {
                      if ($fieldSubcategory === '' && !$fieldAppliesToSubcategories) {
                        continue;
                      }
                    }

                    if ($fieldSubcategory !== '' && $fieldSubcategory !== $itemSubcat) {
                      continue;
                    }

                    $fieldMeta['current_value'] = productSpecFieldCurrentValue($fieldMeta, $extOptArr, $internalOptArr, (string) ($order['source_code'] ?? ''));
                    $fieldMeta['has_any_value'] = productSpecFieldHasAnyValue($fieldMeta, $extOptArr, $internalOptArr);

                    $fieldRole = productSpecFieldRole($fieldMeta);
                    if ($fieldMeta['current_value'] !== '') {
                      if ($fieldRole === 'material') {
                        $printMaterial = $fieldMeta['current_value'];
                      } elseif ($fieldRole === 'finish') {
                        $printFinish = $fieldMeta['current_value'];
                      } elseif ($fieldRole === 'grip') {
                        $printGrip = $fieldMeta['current_value'];
                      } elseif ($fieldRole === 'tr_swingarms') {
                        $printTrSwingarms = $fieldMeta['current_value'];
                      } elseif ($fieldRole === 'printer') {
                        $printPrinter = $fieldMeta['current_value'];
                      }
                    }

                    if ($fieldMeta['has_any_value']) {
                      $showItemProductSpecRow = true;
                    }

                    $itemProductSpecFields[] = $fieldMeta;
                  }
                  usort($itemProductSpecFields, function (array $a, array $b): int {
                    $ao = (int) ($a['field_sort_order'] ?? 999);
                    $bo = (int) ($b['field_sort_order'] ?? 999);

                    if ($ao !== $bo) {
                      return $ao <=> $bo;
                    }

                    return strcmp((string) ($a['spec_key'] ?? ''), (string) ($b['spec_key'] ?? ''));
                  });
                  // Druhý riadok zobraz vždy, keď pre department existuje aspoň
                  // jeden definovaný formulárový prvok — aj keď ešte nemá hodnotu.
                  if (!empty($itemProductSpecFields)) {
                    $showItemProductSpecRow = true;
                  }
                }

                // ── Category Info (Shoptet) ─────────────────
                // options_json môže mať kľúč "category" vo formáte "Suzuki | DR-Z400 | 1999-2024 | CPM8"
                // alebo kombináciu polí brand/model/year + design_code
                // Platí pre všetky departmenty/typy položiek, nielen Graphics (G).
                $gCategoryRaw = '';
                $gCategoryMain = '';
                $gCategoryBrand = '';
                $gCategoryModelYear = '';
                $gCategoryCode = '';
                $gCategoryEditBrand = trim((string) optionValue($extOptArr, ['category_brand', 'brand', 'Brand', 'bike-brand', 'manufacturer', 'Manufacturer']));
                $gCategoryEditModel = trim((string) optionValue($extOptArr, ['category_model', 'model', 'Model', 'bike-model', 'Bike', 'bike']));
                $gCategoryEditYear = trim((string) optionValue($extOptArr, ['category_year_range', 'year', 'Year', 'bike-year', 'model-year', 'Year Range']));
                $gCategoryEditCode = trim((string) optionValue($extOptArr, ['category_modelcode', 'modelcode', 'design_code', 'design-code', 'category_code', 'model_code', 'sku-code']));
                $gCategoryEditInfo = ''; {
                  // Hľadáme category v rôznych kľúčoch options_json — Shoptet používa rôzne konvencie
                  $catCandidates = [
                    'Category Info',
                    'category_info',
                    'category-info',
                    'category info',
                    'category',
                    'Category',
                    'bike-category',
                    'bike_category',
                    'model-category',
                    'model_category',
                    'product-category',
                    'variant',
                    'Variant',
                    'Varianta',
                    'varianta',
                    'bike',
                    'Bike',
                    'model',
                    'Model',
                  ];
                  foreach ($catCandidates as $ck) {
                    if (isset($extOptArr[$ck]) && is_string($extOptArr[$ck]) && trim($extOptArr[$ck]) !== '') {
                      $val = trim($extOptArr[$ck]);
                      // Chceme hodnoty ktoré obsahujú | separátor alebo vyzerajú ako kategória
                      if (strpos($val, '|') !== false) {
                        $gCategoryRaw = $val;
                        break;
                      }
                      // Inak ako fallback (ak nenájdeme s |, použijeme prvý neprázdny)
                      if ($gCategoryRaw === '') {
                        $gCategoryRaw = $val;
                      }
                    }
                  }

                  // Ak stále nič, skúsime poskladať z brand + model + year
                  if ($gCategoryRaw === '' || strpos($gCategoryRaw, '|') === false) {
                    $brandVal = $gCategoryEditBrand;
                    $modelVal = $gCategoryEditModel;
                    $yearVal = $gCategoryEditYear;
                    $codeVal = $gCategoryEditCode;
                    $parts = array_filter([$brandVal, $modelVal, $yearVal]);
                    if ($parts) {
                      $gCategoryRaw = implode(' | ', $parts) . ($codeVal !== '' ? ' | ' . $codeVal : '');
                    }
                  }

                  $gCategoryEditInfo = $gCategoryRaw;
                  if ($gCategoryRaw !== '') {
                    $editParts = array_values(array_filter(array_map('trim', explode('|', $gCategoryRaw)), function ($p) {
                      return $p !== '';
                    }));
                    if ($gCategoryEditBrand === '' && isset($editParts[0])) {
                      $gCategoryEditBrand = $editParts[0];
                    }
                    if ($gCategoryEditModel === '' && isset($editParts[1])) {
                      $gCategoryEditModel = $editParts[1];
                    }
                    if ($gCategoryEditYear === '' && isset($editParts[2])) {
                      $gCategoryEditYear = $editParts[2];
                    }
                    if ($gCategoryEditCode === '' && isset($editParts[3])) {
                      $gCategoryEditCode = $editParts[3];
                    }
                  }

                  // Rozdeliť podľa "|"
                  if ($gCategoryRaw !== '' && strpos($gCategoryRaw, '|') !== false) {
                    $catParts = array_map('trim', explode('|', $gCategoryRaw));
                    // Posledný segment = kód modelu
                    $lastPart = array_pop($catParts);
                    // Ak posledný segment vyzerá ako kód (max 10 znakov, alfanum, bez medzier)
                    if ($lastPart !== '' && preg_match('/^[A-Z0-9]{2,10}$/i', $lastPart)) {
                      $gCategoryCode = strtoupper($lastPart);
                    } else {
                      // Nie je kód — vráť späť a zobraz ako súčasť hlavných častí
                      array_push($catParts, $lastPart);
                      $gCategoryCode = '';
                    }

                    $catParts = array_values(array_filter($catParts, function ($p) {
                      return $p !== '';
                    }));
                    // 1. riadok = brand (prvá časť), 2. riadok = zvyšok (model + roky...)
                    $gCategoryBrand = $catParts[0] ?? '';
                    $gCategoryModelYear = implode('  ', array_slice($catParts, 1));
                    $gCategoryMain = implode('  ', $catParts);
                  } elseif ($gCategoryRaw !== '') {
                    // Jednoduchý string bez separátora — necháme na jednom riadku
                    $gCategoryBrand = $gCategoryRaw;
                    $gCategoryModelYear = '';
                    $gCategoryMain = $gCategoryRaw;
                  }
                }
                ?>

                <?php
                // ── Category Info stĺpec (Shoptet) ─────────────
                // Pre všetky typy položiek: zobraz dáta ak existujú, inak prázdnu bunku.
                ?>
                <td class="text-center g-cat-td" style="min-width:120px;max-width:50px; white-space:nowrap;">
                  <?php $hasCategoryDisplay = ($gCategoryBrand !== '' || $gCategoryModelYear !== '' || $gCategoryCode !== ''); ?>
                  <form class="order-item-category-info-form mb-0" data-order-id="<?= (int) $orderId ?>" data-item-id="<?= (int) $it['id'] ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $orderId ?>">
                    <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                    <input type="hidden" name="category_info" value="<?= h($gCategoryEditInfo) ?>">
                    <input type="hidden" name="category_brand" value="<?= h($gCategoryEditBrand) ?>">
                    <input type="hidden" name="category_model" value="<?= h($gCategoryEditModel) ?>">
                    <input type="hidden" name="category_year_range" value="<?= h($gCategoryEditYear) ?>">
                    <input type="hidden" name="category_modelcode" value="<?= h($gCategoryEditCode) ?>">
                    <?php if ($hasCategoryDisplay): ?>
                      <div class="g-cat-info">
                        <?php if ($gCategoryBrand !== ''): ?>
                          <span class="g-cat-main"><?= h($gCategoryBrand) ?></span>
                        <?php endif; ?>
                        <?php if ($gCategoryModelYear !== ''): ?>
                          <span class="g-cat-main"><?= h($gCategoryModelYear) ?></span>
                        <?php endif; ?>
                        <?php if ($gCategoryCode !== ''): ?>
                          <span class="g-cat-code"><a href="#"
                              title="Model kód: <?= h($gCategoryCode) ?>"><?= h($gCategoryCode) ?></a></span>
                        <?php endif; ?>
                      </div>
                      <button type="button" class="btn btn-xs btn-outline-info custom-category-info-trigger order-item-category-info-trigger mt-1" title="Change Brand, Model, Year range and Model Code">
                        <i class="fas fa-pencil-alt" aria-hidden="true"></i>
                      </button>
                    <?php else: ?>
                      <button type="button" class="btn btn-xs btn-outline-info custom-category-info-trigger order-item-category-info-trigger is-empty" title="Select Brand, Model, Year range and Model Code">
                        <span class="custom-category-info-text">Set Category</span>
                        <i class="fas fa-chevron-right ml-1" aria-hidden="true"></i>
                      </button>
                    <?php endif; ?>
                    <span class="small text-muted d-block mt-1 order-item-category-save-state" hidden></span>
                  </form>
                </td>

                <td style="display:none;"></td>

                <td class="text-center">
                  <?php if ($productUrl !== ''): ?>
                    <a href="<?= h($productUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info"
                      title="<?= h($productUrl) ?>">
                      <i class="fas fa-external-link-alt mr-1"></i>
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
                $modalOptionsRaw = $extOptArr;
                if ($isPatchItem) {
                  $modalOptionsRaw = patchOptionsForModal($extOptArr);
                }
                $formattedOptions = jsonEncodeForModal($modalOptionsRaw);
                $formattedOptions = prepareOptionsJsonForModal($conn, $formattedOptions);
                $editableOptions = prepareEditableOptionsJsonForModal(jsonEncodeForModal($modalOptionsRaw));
                $optionLabels = jsonEncodeForModal(optionLabelMapForModal(
                  $conn,
                  $modalOptionsRaw,
                  (string) ($it['item_type_code'] ?? ''),
                  $itemSubcat
                ));
                // Strip _printer/_print_material/_print_finish from modal display — they are shown separately
                $internalOptForModal = $internalOptArr;
                unset($internalOptForModal['_printer'], $internalOptForModal['_print_material'], $internalOptForModal['_print_finish'], $internalOptForModal['_print_grip'], $internalOptForModal['_print_tr_swingarms'], $internalOptForModal['_seat_cover_ops_confirmed']);
                $internalOptions = jsonEncodeForModal($internalOptForModal);
                $isManualItem = !empty($extOptArr['_manual']);
                $manualItemReason = $isManualItem ? trim((string) ($extOptArr['reason'] ?? '')) : '';
                ?>
                <td class="text-center">
                  <button type="button" class="btn btn-xs btn-outline-info btn-view-options"
                    data-item-id="<?= (int) $it['id'] ?>" data-options="<?= h($formattedOptions) ?>"
                    data-options-raw="<?= h($editableOptions) ?>"
                    data-option-labels="<?= h($optionLabels) ?>"
                    data-can-edit-options="<?= ((int) ($_SESSION['permission'] ?? 0) >= 300 ? '1' : '0') ?>"
                    data-internal-options="<?= h($internalOptions) ?>"
                    data-detail-title="<?= h($isPatchItem ? 'Patch Detail' : 'Product Detail') ?>"
                    data-source-code="<?= h((string) ($order['source_code'] ?? '')) ?>"
                    data-is-manual-item="<?= $isManualItem ? '1' : '0' ?>"
                    data-manual-reason="<?= h($manualItemReason) ?>"
                    data-is-graphics="<?= $isGraphicsItem ? '1' : '0' ?>" data-print-printer="<?= h($printPrinter) ?>"
                    data-print-material="<?= h($printMaterial) ?>" data-print-finish="<?= h($printFinish) ?>"
                    data-print-grip="<?= h($printGrip) ?>" data-print-tr-swingarms="<?= h($printTrSwingarms) ?>">
                    Detail
                  </button>
                </td>

                <td>
                  <?php
                  $type = strtoupper((string) ($it['item_type_code'] ?? ''));

                  $statusLabels = ordersGetItemStatusLabelsForItem($conn, $it, true);
                  $statuses = array_keys($statusLabels);

                  // Prazdny/legacy 'NEW' status uz nie je definovany v controlls.php.
                  // Namiesto pevneho stringu 'NEW' pouzijeme prvy (najnizsi sort_order)
                  // aktivny status pre dany department ako default.
                  $defaultStatus = $statuses[0] ?? 'NEW';
                  $rawStatus = strtoupper(trim((string) ($it['item_status'] ?? '')));
                  $currentStatus = ($rawStatus !== '' && $rawStatus !== 'NEW') ? $rawStatus : $defaultStatus;

                  if (!in_array($currentStatus, $statuses, true)) {
                    $statuses[] = $currentStatus;
                  }
                  ?>

                  <select class="form-control form-control-sm item-status-select" data-item-id="<?= (int) $it['id'] ?>">
                    <?php foreach ($statuses as $s): ?>
                      <option value="<?= h($s) ?>" <?= ($currentStatus === $s ? 'selected' : '') ?>>
                        <?= h($statusLabels[$s] ?? str_replace('_', ' ', $s)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>

                <td style="min-width:120px;">
                  <div class="input-group input-group-sm mb-1">
                    <input type="text" class="form-control form-control-sm item-waiting-note"
                      data-item-id="<?= (int) $it['id'] ?>" value="<?= h($it['waiting_note'] ?? '') ?>"
                      placeholder="Na čo čakáme?">

                    <div class="input-group-append">
                      <button type="button" class="btn btn-outline-success btn-save-waiting"
                        data-item-id="<?= (int) $it['id'] ?>" title="Uložiť waiting">
                        <i class="fas fa-save"></i>
                      </button>
                    </div>
                  </div>

                  <input type="date" class="form-control form-control-sm item-expected-date"
                    data-item-id="<?= (int) $it['id'] ?>" value="<?= h($it['expected_date'] ?? '') ?>">
                </td>

                <td class="text-center" style="display:none;">
                  <?php
                  $itTypeRtp = strtoupper(trim((string) ($it['item_type_code'] ?? '')));
                  if ($itTypeRtp === 'G'):
                    // label_rtp.php načíta všetko z DB — stačí item_id
                    $rtpUrl = LABEL_BASE_PATH . 'label_rtp.php?item_id=' . (int) $it['id'];
                    ?>
                    <a href="<?= h($rtpUrl) ?>" target="_blank" rel="noopener" class="btn btn-xs btn-outline-warning"
                      title="RTP info prúžok pre grafika">RTP</a>
                  <?php else: ?>
                    <span class="text-muted" style="font-size:11px;">—</span>
                  <?php endif; ?>
                </td>

                <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
                  <td class="text-center" style="width:40px;">
                    <button type="button" class="btn btn-xs btn-outline-success btn-save-item"
                      data-id="<?php echo (int) $it['id']; ?>" data-order-id="<?php echo (int) $orderId; ?>">
                      Save
                    </button>
                  </td>

                  <td class="text-center" style="width:40px;">
                    <button type="button" class="btn btn-xs btn-outline-danger btn-delete-order-item"
                      data-item-id="<?php echo (int) $it['id']; ?>" data-order-id="<?php echo (int) $orderId; ?>">
                      Delete
                    </button>
                  </td>
                <?php endif; ?>
              </tr>

              <?php if ($showItemProductSpecRow && !empty($itemProductSpecFields)): ?>
                <?php
                $itemProductSpecMainFields = [];
                $itemProductSpecTextFields = [];

                foreach ($itemProductSpecFields as $itemSpecField) {

                  $isTextareaField = (
                      (string) ($itemSpecField['render'] ?? '') === 'textarea'
                  );

                  if ($isTextareaField) {
                      $itemProductSpecTextFields[] = $itemSpecField;
                  } else {
                      $itemProductSpecMainFields[] = $itemSpecField;
                  }
                }

                $renderProductSpecFieldsRow = function (array $fieldsForRow) use ($conn, $it): void {
                  foreach ($fieldsForRow as $itemSpecField): ?>
                    <label class="<?= h($itemSpecField['wrapper_class']) ?>">
                      <span class="product-spec-label-title"><?= h($itemSpecField['label']) ?></span>
                      <?php
                      $fieldSourceKeyNormalized = productSpecNormalizeKey((string) ($itemSpecField['source_key'] ?? ''));

                      $isUserEditableTextField = (
                        $itemSpecField['field_type'] === 'text'
                        && in_array($fieldSourceKeyNormalized, ['note'], true)
                      );

                      $isAdminTextEditor = (
                        $itemSpecField['field_type'] === 'text'
                        && ((int) ($_SESSION['permission'] ?? 0) >= 300 || $isUserEditableTextField)
                      );

                      $isUserTextBlock = (
                        $itemSpecField['field_type'] === 'text'
                        && !$isAdminTextEditor
                      );

                      $sharedFieldAttrs = ' data-item-id="' . (int) $it['id'] . '"'
                        . (!empty($itemSpecField['write_source_key']) && $itemSpecField['source_key'] !== '' ? ' data-source-key="' . h($itemSpecField['source_key']) . '"' : '')
                        . ' data-field-type="' . h($itemSpecField['field_type']) . '"'
                        . ($itemSpecField['internal_key'] !== '' ? ' data-internal-key="' . h($itemSpecField['internal_key']) . '"' : '');
                      ?>
                      <?php if ($itemSpecField['render'] === 'autocomplete'): ?>
                        <div class="position-relative">
                          <input type="text" class="form-control form-control-sm print-ac-input item-product-spec-field"
                            <?= $sharedFieldAttrs ?> data-ac-key="<?= h($itemSpecField['autocomplete_key']) ?>"
                            value="<?= h($itemSpecField['current_value']) ?>"
                            placeholder="<?= h($itemSpecField['placeholder']) ?>">
                          <div class="print-ac-dropdown" style="display:none;"></div>
                        </div>
                      <?php elseif ($isUserTextBlock): ?>
                        <?php $userTextValue = trim((string) ($itemSpecField['current_value'] ?? '')); ?>
                        <div class="g-opt-note-display"><?= $userTextValue !== '' ? h($userTextValue) : '<span class="text-muted">&mdash;</span>' ?></div>
                      <?php elseif ($itemSpecField['render'] === 'textarea' || $isAdminTextEditor): ?>          <?php $textareaValue = trim((string) ($itemSpecField['current_value'] ?? '')); ?><textarea
                          class="form-control form-control-sm <?= h($itemSpecField['control_class']) ?>" <?= $sharedFieldAttrs ?>
                          placeholder="<?= h($itemSpecField['placeholder']) ?>"
                          rows="1"><?= h($textareaValue) ?></textarea><?php else: ?>
                        <?=
                          renderProductSpecField(
                            $conn,
                            $itemSpecField['spec_key'],
                            (string) $itemSpecField['current_value'],
                            $itemSpecField['fallback_options'],
                            $itemSpecField['control_class'],
                            $sharedFieldAttrs,
                            (string) $itemSpecField['empty_label']
                          );
                        ?>
                      <?php endif; ?>
                    </label>
                  <?php endforeach;
                };
                ?>

                <?php if (!empty($itemProductSpecMainFields)): ?>
                  <tr class="g-item-options-row <?= $itemTypeClass ?> <?= $seatPatchGroupClass ?>" data-item-id="<?= (int) $it['id'] ?>">
                    <td colspan="99" style="padding:5px 8px 7px; border-top:none !important;">
                      <div class="g-options-bar">
                        <?php $renderProductSpecFieldsRow($itemProductSpecMainFields); ?>
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>

                <?php if (!empty($itemProductSpecTextFields)): ?>
                  <tr class="g-item-options-row g-item-text-options-row <?= $itemTypeClass ?> <?= $seatPatchGroupClass ?>"
                    data-item-id="<?= (int) $it['id'] ?>">
                    <td colspan="99" style="padding:5px 8px 7px; border-top:none !important;">
                      <div class="g-options-bar g-text-options-bar">
                        <?php $renderProductSpecFieldsRow($itemProductSpecTextFields); ?>
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>
              <?php endif; ?>
              <tr class="item-spacer-row" aria-hidden="true">
                <td colspan="99"
                  style="height:6px; padding:0 !important; border:none !important; background:transparent !important;">
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
          <h6 class="text-muted mb-2 mt-3">Položky</h6>
          <?php
          $manualAllowedTypes = [
            'G' => 'Graphics',
            'P' => 'Plastics',
            'S' => 'Seat Cover',
            'F' => 'Fitting',
            'T' => 'Accessories',
            'M' => 'Misc / Upsell',
          ];
          $manualGraphicsSubcategoryLabels = manualItemGraphicsSubcategoryLabels();
          $manualBuilderStatusMap = [];
          foreach ($manualAllowedTypes as $manualTypeCode => $manualTypeLabel) {
            $manualStatusScopes = $manualTypeCode === 'G' ? array_merge([''], array_keys($manualGraphicsSubcategoryLabels)) : [''];
            foreach ($manualStatusScopes as $manualStatusSubcategory) {
              $manualStatusItem = [
                'item_type_code' => $manualTypeCode,
                'sku' => 'MANUAL',
                'custom_label' => '',
                'options_json' => '{}',
                'internal_options_json' => json_encode(['_subcat' => (string) $manualStatusSubcategory], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
              ];
              $manualStatusMapKey = $manualTypeCode . '|' . strtoupper((string) $manualStatusSubcategory);
              foreach (ordersGetItemStatusDefinitionsForItem($conn, $manualStatusItem, true) as $manualStatusCode => $manualStatusMeta) {
                $manualBuilderStatusMap[$manualStatusMapKey][$manualStatusCode] = [
                  'label' => (string) ($manualStatusMeta['label'] ?? $manualStatusCode),
                  'color' => (string) ($manualStatusMeta['color'] ?? ''),
                ];
              }
            }
          }
          $manualBuilderDepartments = ['G' => 'G', 'P' => 'P', 'S' => 'S', 'F' => 'F'];
          $manualCategoryDefaults = [
            'category_info' => '',
            'category_brand' => '',
            'category_model' => '',
            'category_year_range' => '',
            'category_modelcode' => '',
          ];
          foreach ($items as $manualCategorySourceItem) {
            $candidateCategory = orderDetailCategoryFieldsFromOptions(jsonDecodeAssocSafe((string) ($manualCategorySourceItem['options_json'] ?? '{}')));
            if (trim((string) ($candidateCategory['category_info'] ?? '')) !== '') {
              $manualCategoryDefaults = $candidateCategory;
              break;
            }
          }
          $manualCategoryDefaultInfo = trim((string) ($manualCategoryDefaults['category_info'] ?? ''));
          ?>
          <form class="manual-add-item-form mb-3" data-order-id="<?php echo (int) $orderId; ?>">
            <input type="hidden" name="order_id" value="<?php echo (int) $orderId; ?>">
            <script type="application/json" class="manual-builder-status-map"><?php echo json_encode($manualBuilderStatusMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
            <div class="custom-item-builder-shell manual-item-box manual-item-type-neutral">
              <div class="custom-builder-picker">
                <div class="custom-builder-picker-label">
                  <label>Product Type</label>
                  <select name="item_type_code" class="form-control form-control-sm custom-item-type-select manual-item-type">
                    <option value="">Select product type...</option>
                    <?php foreach ($manualAllowedTypes as $manualTypeCode => $manualTypeLabel): ?><option value="<?= h($manualTypeCode) ?>"><?= h($manualTypeLabel) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="custom-builder-picker-label" data-graphics-subcategory-wrap hidden>
                  <label>Graphics subcategory</label>
                  <select name="graphics_subcategory" class="form-control form-control-sm custom-graphics-subcategory-select manual-graphics-subcategory-select">
                    <option value="">Graphics Kit</option>
                    <?php foreach ($manualGraphicsSubcategoryLabels as $manualSubcatCode => $manualSubcatLabel): ?>
                      <option value="<?= h((string) $manualSubcatCode) ?>"><?= h((string) $manualSubcatLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="custom-builder-order-shell" data-builder-body hidden>
                <div class="custom-builder-subtitle">Core Item Row</div>
                <div class="table-responsive">
                  <table class="table table-sm table-bordered mb-0 custom-builder-order-table">
                    <tbody>
                      <tr class="item-repeat-header-row">
                        <th class="text-center">Assigned</th>
                        <th>Type</th>
                        <th class="text-center">Nazov</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Category Info</th>
                        <th>Link</th>
                        <th class="text-center">Detail</th>
                        <th>Action</th>
                        <th>Waiting</th>
                        <th class="text-center">Save</th>
                        <th class="text-center">Delete</th>
                      </tr>
                      <tr class="item-info-row" data-builder-row>
                        <td class="text-center" style="width:56px;">
                          <span class="custom-builder-assigned-placeholder" title="Manual item">+</span>
                        </td>
                        <td class="text-center" style="width:46px;">
                          <span class="custom-builder-type-badge" data-builder-type-badge>?</span>
                        </td>
                        <td style="min-width:280px;">
                          <input type="text" name="title" class="form-control form-control-sm mb-1 manual-item-title" placeholder="Product name" required>
                          <div class="custom-existing-item-meta-edit">
                            <input type="text" name="sku" class="form-control form-control-sm manual-item-sku" value="MANUAL" placeholder="SKU / MANUAL">
                            <input type="text" name="custom_label" class="form-control form-control-sm manual-item-custom-label" placeholder="Custom label">
                          </div>
                        </td>
                        <td style="width:72px;">
                          <input type="number" min="1" name="qty" class="form-control form-control-sm manual-item-qty" value="1">
                        </td>
                        <td style="width:92px;">
                          <input type="number" step="0.01" name="unit_price" class="form-control form-control-sm manual-item-unit-price" value="0">
                        </td>
                        <td style="min-width:220px;">
                          <input type="hidden" name="category_info" value="<?= h($manualCategoryDefaultInfo) ?>">
                          <input type="hidden" name="category_brand" value="<?= h($manualCategoryDefaults['category_brand'] ?? '') ?>">
                          <input type="hidden" name="category_model" value="<?= h($manualCategoryDefaults['category_model'] ?? '') ?>">
                          <input type="hidden" name="category_year_range" value="<?= h($manualCategoryDefaults['category_year_range'] ?? '') ?>">
                          <input type="hidden" name="category_modelcode" value="<?= h($manualCategoryDefaults['category_modelcode'] ?? '') ?>">
                          <button type="button" class="btn btn-sm btn-outline-info custom-category-info-trigger manual-item-category-info<?= $manualCategoryDefaultInfo === '' ? ' is-empty' : '' ?>" title="Select Brand, Model, Year range and Model Code">
                            <span class="custom-category-info-text"><?= h($manualCategoryDefaultInfo !== '' ? $manualCategoryDefaultInfo : 'Select Brand / Model / Year / Model Code') ?></span>
                            <i class="fas fa-chevron-right" aria-hidden="true"></i>
                          </button>
                        </td>
                        <td class="text-center" style="width:76px;">
                          <button type="button" class="btn btn-sm btn-outline-info custom-builder-link-btn" disabled><i class="fas fa-external-link-alt"></i></button>
                        </td>
                        <td class="text-center" style="width:92px;">
                          <button type="button" class="btn btn-xs btn-outline-info custom-builder-mini-btn" disabled>Detail</button>
                        </td>
                        <td style="min-width:140px;">
                          <select name="item_status" class="form-control form-control-sm custom-item-status-select manual-item-status-select" data-status-dynamic="1"></select>
                        </td>
                        <td style="min-width:170px;">
                          <div class="input-group input-group-sm mb-1">
                            <input type="text" class="form-control form-control-sm" placeholder="Na co cakame?" disabled>
                            <div class="input-group-append">
                              <button type="button" class="btn btn-outline-success" disabled><i class="fas fa-save"></i></button>
                            </div>
                          </div>
                          <input type="date" class="form-control form-control-sm" disabled>
                        </td>
                        <td class="text-center" style="width:52px;">
                          <button type="submit" class="btn btn-xs btn-outline-success custom-builder-mini-btn btn-add-manual-item" data-order-id="<?php echo (int) $orderId; ?>">Save</button>
                        </td>
                        <td class="text-center" style="width:58px;">
                          <button type="button" class="btn btn-xs btn-outline-danger custom-builder-mini-btn" disabled>Delete</button>
                        </td>
                      </tr>

                      <?php foreach ($manualBuilderDepartments as $manualDepartmentCode => $manualDefinitionType): ?>
                        <?php
                        $manualSubcatScopes = [''];
                        if ($manualDepartmentCode === 'G') {
                          $manualSubcatScopes = array_merge($manualSubcatScopes, array_keys($manualGraphicsSubcategoryLabels));
                        }
                        ?>
                        <?php foreach ($manualSubcatScopes as $manualSubcatScope): ?>
                          <?php
                          $manualDefinitions = manualItemFieldDefinitions($conn, $manualDefinitionType, (string) $manualSubcatScope);
                          $manualMainFields = [];
                          $manualTextFields = [];
                          foreach ($manualDefinitions as $manualDefinition) {
                            $manualSourceKey = trim((string) ($manualDefinition['source_key'] ?? ''));
                            $manualFieldType = trim((string) ($manualDefinition['field_type'] ?? 'dropdown'));
                            if (in_array($manualSourceKey, ['note', 'my-item-note', 'buyer-note'], true)) {
                              $manualTextFields[] = $manualDefinition;
                            } else {
                              $manualMainFields[] = $manualDefinition;
                            }
                          }
                          ?>

                          <?php if (!empty($manualMainFields)): ?>
                            <tr class="g-item-options-row custom-item-spec-group manual-item-spec-group manual-item-main-spec-row" data-builder-row data-department="<?= h($manualDepartmentCode) ?>" data-subcategory="<?= h((string) $manualSubcatScope) ?>" hidden>
                              <td colspan="99">
                                <div class="g-options-bar">
                                  <?php foreach ($manualMainFields as $manualDefinition): ?>
                                    <label class="product-spec-label">
                                      <span class="product-spec-label-title"><?= h(manualItemBuilderSpecLabel($manualDepartmentCode, $manualDefinition)) ?></span>
                                      <?= manualItemRenderSpecFieldInput($conn, $manualDefinition) ?>
                                    </label>
                                  <?php endforeach; ?>
                                </div>
                              </td>
                            </tr>
                          <?php endif; ?>

                          <?php if (!empty($manualTextFields)): ?>
                            <tr class="g-item-options-row custom-item-spec-group manual-item-spec-group manual-item-note-spec-row" data-builder-row data-department="<?= h($manualDepartmentCode) ?>" data-subcategory="<?= h((string) $manualSubcatScope) ?>" hidden>
                              <td colspan="99">
                                <div class="g-options-bar">
                                  <?php foreach ($manualTextFields as $manualDefinition): ?>
                                    <label class="product-spec-label">
                                      <span class="product-spec-label-title"><?= h(manualItemBuilderSpecLabel($manualDepartmentCode, $manualDefinition)) ?></span>
                                      <?= manualItemRenderSpecFieldInput($conn, $manualDefinition) ?>
                                    </label>
                                  <?php endforeach; ?>
                                </div>
                              </td>
                            </tr>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      <?php endforeach; ?>

                      <tr class="item-spacer-row" aria-hidden="true">
                        <td colspan="99"></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </form>

          <div class="modal fade custom-category-picker-modal" tabindex="-1" role="dialog" aria-hidden="true" data-category-picker-modal>
            <div class="modal-dialog modal-lg" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <div>
                    <h5 class="modal-title mb-1">Select Category Info</h5>
                    <div class="small text-muted">Choose compatibility in the same order as in Product Chart.</div>
                  </div>
                  <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                  <div class="custom-category-picker-steps">
                    <div class="custom-category-picker-step">
                      <label><span class="custom-category-picker-step-number">1</span> Brand</label>
                      <select class="form-control form-control-sm" data-category-brand>
                        <option value="">Loading brands...</option>
                      </select>
                    </div>
                    <div class="custom-category-picker-step">
                      <label><span class="custom-category-picker-step-number">2</span> Model</label>
                      <select class="form-control form-control-sm" data-category-model disabled>
                        <option value="">Select brand first</option>
                      </select>
                    </div>
                    <div class="custom-category-picker-step">
                      <label><span class="custom-category-picker-step-number">3</span> Year range / Model Code</label>
                      <select class="form-control form-control-sm" data-category-year disabled>
                        <option value="">Select model first</option>
                      </select>
                    </div>
                  </div>
                  <div class="custom-category-picker-preview" data-category-preview>Select Brand, Model and Year range to load Model Code.</div>
                  <div class="small text-danger mt-2" data-category-error hidden></div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-sm btn-outline-danger mr-auto" data-category-clear>Clear Category Info</button>
                  <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                  <button type="button" class="btn btn-sm btn-success" data-category-apply disabled>Apply</button>
                </div>
              </div>
            </div>
          </div>
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
    // Zruš staré handlery pri opätovnom otvorení detailu
    $(document).off('.printSettings');

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

    function findProductSpecContext($tr, itemId) {
      itemId = parseInt(itemId, 10) || parseInt($tr.data('item-id'), 10) || parseInt($tr.find('.btn-view-options').data('item-id'), 10) || 0;

      var $infoRow = $tr.hasClass('item-info-row') ? $tr : $tr.prevAll('tr.item-info-row').filter(function () {
        var rowItemId = parseInt($(this).find('.btn-view-options').data('item-id'), 10) || 0;
        return !itemId || rowItemId === itemId;
      }).first();

      if (!$infoRow.length) {
        $infoRow = $tr.prevAll('tr.item-info-row').first();
      }

      var $detailBtn = $infoRow.find('.btn-view-options').first();
      if (!itemId) {
        itemId = parseInt($detailBtn.data('item-id'), 10) || 0;
      }

      var $specRows = $();
      if ($infoRow.length) {
        $specRows = $infoRow.nextUntil('tr.item-info-row, tr.item-repeat-header-row').filter('tr.g-item-options-row').filter(function () {
          var rowItemId = parseInt($(this).data('item-id'), 10) || 0;
          return !itemId || !rowItemId || rowItemId === itemId;
        });
      }

      if (!$specRows.length && $tr.hasClass('g-item-options-row')) {
        $specRows = $tr;
      }

      return {
        itemId: itemId,
        infoRow: $infoRow,
        detailBtn: $detailBtn,
        specRows: $specRows,
        searchScope: $specRows.length ? $specRows : $tr
      };
    }

    function savePrintSettings($tr, itemId, orderId) {
      var deferred = $.Deferred();
      var context = findProductSpecContext($tr, itemId);
      var $infoRow = context.infoRow;
      var $detailBtn = context.detailBtn;
      var $searchScope = context.searchScope;
      itemId = context.itemId;

      if (!itemId || !$detailBtn.length) {
        deferred.resolve({ skipped: true });
        return deferred.promise();
      }

      function scopedValue(selector) {
        var $field = $searchScope.find(selector).first();
        if (!$field.length) {
          $field = $infoRow.find(selector).first();
        }
        return $field.length ? ($field.val() || '') : '';
      }

      var printer = scopedValue('.item-print-printer');
      var material = scopedValue('.item-print-material');
      var finish = scopedValue('.item-print-finish');
      var grip = scopedValue('.item-print-grip');
      var trSwingarms = scopedValue('.item-print-tr-swingarms');

      var existing = {};
      try {
        existing = JSON.parse($detailBtn.attr('data-internal-options') || '{}');
      } catch (e) {
        existing = {};
      }

      if (!existing || Array.isArray(existing) || typeof existing !== 'object') {
        existing = {};
      }

      var existingOptions = {};
      try {
        existingOptions = JSON.parse($detailBtn.attr('data-options-raw') || '{}');
      } catch (e) {
        existingOptions = {};
      }
      if (!existingOptions || Array.isArray(existingOptions) || typeof existingOptions !== 'object') {
        existingOptions = {};
      }

      existing['_printer'] = printer;
      existing['_print_material'] = material;
      existing['_print_finish'] = finish;
      existing['_print_grip'] = grip;
      existing['_print_tr_swingarms'] = trSwingarms;

      $searchScope.find('.item-print-generic[data-internal-key], .print-ac-input[data-internal-key], textarea[data-internal-key]').each(function () {
        var key = $(this).data('internal-key');
        if (key) existing[key] = $(this).val() || '';
      });

      $searchScope.find('.item-product-spec-field[data-source-key]').each(function () {
        var $field = $(this);
        var sourceKey = String($field.data('source-key') || '');
        if (!sourceKey) {
          return;
        }
        existingOptions[sourceKey] = $field.val() || '';
      });

      var newJson = JSON.stringify(existing);
      var newOptionsJson = JSON.stringify(existingOptions);

      function finishSave() {
        $detailBtn.attr('data-internal-options', newJson);
        $detailBtn.attr('data-options-raw', newOptionsJson);
        $detailBtn.attr('data-options', newOptionsJson);
        $detailBtn.attr('data-print-printer', printer);
        $detailBtn.attr('data-print-material', material);
        $detailBtn.attr('data-print-finish', finish);
        $detailBtn.attr('data-print-grip', grip);
        $detailBtn.attr('data-print-tr-swingarms', trSwingarms);

        var $flashTargets = $searchScope.find('select, input, textarea').add($infoRow.find('.print-settings-cell input, .print-settings-cell select'));
        $flashTargets.css('border-color', '#28a745');
        setTimeout(function () {
          $flashTargets.css('border-color', '');
        }, 1000);

        deferred.resolve({ ok: true });
      }

      function failSave(message, xhr) {
        alert(message);
        deferred.reject(xhr || message);
      }

      function saveInternalOptions() {
        $.post('scripts/orders/update_item_internal_options.php', {
          item_id: itemId,
          internal_options_json: newJson
        }, function (res) {
          if (!res || !res.ok) {
            failSave(res && res.error ? res.error : 'Save failed', res);
            return;
          }
          finishSave();
        }, 'json').fail(function (xhr) {
          failSave('Update request failed:\n' + xhr.status + '\n' + xhr.responseText, xhr);
        });
      }

      $.post('scripts/orders/update_item_options.php', {
        item_id: itemId,
        options_json: newOptionsJson
      }, function (res) {
        if (!res || !res.ok) {
          failSave(res && res.error ? res.error : 'Save failed', res);
          return;
        }
        saveInternalOptions();
      }, 'json').fail(function (xhr) {
        failSave('Update request failed:\n' + xhr.status + '\n' + xhr.responseText, xhr);
      });

      return deferred.promise();
    }

    function reloadAfterUnifiedItemSave(orderId) {
      orderId = parseInt(orderId, 10) || 0;
      if (orderId && typeof reloadOrderDetail === 'function') {
        reloadOrderDetail(orderId);
        return;
      }
      window.location.reload();
    }

    $('.order-detail-card .btn-save-item').off('click.productSpecUnifiedSave').on('click.productSpecUnifiedSave', function (e) {
      var $btn = $(this);
      var $infoRow = $btn.closest('tr.item-info-row');
      var itemId = parseInt($btn.data('id'), 10) || 0;
      var context = findProductSpecContext($infoRow, itemId);

      if (!context.specRows.length) {
        return;
      }

      e.preventDefault();
      e.stopImmediatePropagation();

      var orderId = parseInt($btn.data('order-id'), 10) || parseInt($btn.closest('.order-detail-card').data('order-id'), 10) || 0;
      var originalHtml = $btn.html();
      var title = $infoRow.find('.item-title').val() || $.trim($infoRow.find('.item-title').text()) || '';
      var type = $infoRow.find('.item-type').val() || $infoRow.data('item-type') || '';
      var qty = $infoRow.find('.item-qty').val() || 1;
      var sku = $infoRow.find('.item-sku').val() || '';
      var label = $infoRow.find('.item-label').val() || '';
      var unitPrice = $infoRow.find('.item-unit-price').val();

      if (!itemId || !title || !type) {
        alert('Invalid item data');
        return;
      }

      $btn.prop('disabled', true).text('Saving...');

      savePrintSettings($infoRow, itemId, orderId).done(function () {
        var payload = {
          item_id: itemId,
          title: title,
          type: type,
          qty: qty,
          sku: sku,
          custom_label: label
        };

        if (unitPrice !== undefined) {
          payload.unit_price = unitPrice;
        }

        $.post('scripts/orders/update_order_item.php', payload, function (res) {
          if (!res || !res.ok) {
            alert(res && res.error ? res.error : 'Update failed');
            $btn.prop('disabled', false).html(originalHtml);
            return;
          }

          reloadAfterUnifiedItemSave(orderId);
        }, 'json').fail(function () {
          alert('Update request failed');
          $btn.prop('disabled', false).html(originalHtml);
        });
      }).fail(function () {
        $btn.prop('disabled', false).html(originalHtml);
      });
    });
    // Input events — autocomplete
    function getBinaryProductSpecState($select) {
      var value = $.trim(String($select.val() || '')).toLowerCase();
      var text = $.trim(String($select.find('option:selected').text() || '')).toLowerCase();

      if (text.indexOf('✓') !== -1 || value === '1' || value === 'yes' || value === 'true') {
        return 'yes';
      }

      if (text.indexOf('✗') !== -1 || value === '0' || value === 'no' || value === 'false') {
        return 'no';
      }

      return '';
    }

    function applyGripState($select) {
      var $label = $select.closest('.print-setting-field-grip');
      var state = getBinaryProductSpecState($select);

      if (!$label.length) {
        return;
      }

      $label.removeClass('product-spec-state-yes product-spec-state-no');

      if (state === 'yes') {
        $label.addClass('product-spec-state-yes');
      } else if (state === 'no') {
        $label.addClass('product-spec-state-no');
      }
    }

    function applySwingarmsState($select) {
      var $label = $select.closest('.print-setting-field-swingarms');
      var state = getBinaryProductSpecState($select);

      if (!$label.length) {
        return;
      }

      $label.removeClass('product-spec-state-yes product-spec-state-no');

      if (state === 'yes') {
        $label.addClass('product-spec-state-yes');
      } else if (state === 'no') {
        $label.addClass('product-spec-state-no');
      }
    }

    $(document).on('input.printSettings', '.print-ac-input', function () {
      var $inp = $(this);
      var key = $inp.data('ac-key');
      var query = $inp.val().trim();
      if (query.length === 0) { hideAllDropdowns(); return; }
      fetchSuggestions(key, query, function (items) { showDropdown($inp, items); });
    });

    // Keyboard navigation + Enter to save
    $(document).on('keydown.printSettings', '.print-ac-input', function (e) {
      var $inp = $(this);
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
        var $tr = $inp.closest('tr');
        var itemId = $inp.data('item-id');
        var orderId = $tr.closest('.order-detail-card').find('[data-order-id]').first().data('order-id');
        savePrintSettings($tr, itemId, orderId);
      } else if (e.key === 'Escape') {
        $drop.hide().empty();
      }
    });

    $(document).on('change.printSettings', '.item-print-printer, .item-print-material, .item-print-finish, .item-print-grip, .item-print-tr-swingarms, .item-print-generic', function () {
      var $field = $(this);
      var $tr = $field.closest('tr');
      var itemId = parseInt($field.data('item-id'), 10) || parseInt($tr.find('.btn-view-options').data('item-id'), 10) || 0;
      var orderId = $tr.closest('.order-detail-card').find('[data-order-id]').first().data('order-id');
      if (!itemId) {
        // g-item-options-row — hľadaj cez data-item-id na samotnom tr
        itemId = parseInt($tr.data('item-id'), 10) || 0;
        if (!itemId) return;
        orderId = $tr.closest('.order-detail-card').find('[data-order-id]').first().data('order-id');
      }
      if ($field.is('.item-print-grip')) {
        applyGripState($field);
      }
      if ($field.is('.item-print-tr-swingarms')) {
        applySwingarmsState($field);
      }
      savePrintSettings($tr, itemId, orderId);
    });

    // Ukladanie dynamických product-spec textarea polí (debounce 600ms)
    var gNoteTimer = null;
    $(document).on('input.printSettings', 'textarea.item-print-generic[data-internal-key]', function () {
      var $ta = $(this);
      clearTimeout(gNoteTimer);
      gNoteTimer = setTimeout(function () {
        var $tr = $ta.closest('tr');
        var itemId = parseInt($ta.data('item-id'), 10) || parseInt($tr.data('item-id'), 10) || 0;
        if (!itemId) return;
        var orderId = $tr.closest('.order-detail-card').find('[data-order-id]').first().data('order-id');
        savePrintSettings($tr, itemId, orderId);
      }, 600);
    });

    // Close dropdown on outside click
    $(document).on('click', function (e) {
      if (!$(e.target).closest('.print-setting-field').length) {
        hideAllDropdowns();
      }
    });

    $('.item-print-grip').each(function () {
      applyGripState($(this));
    });

    $('.item-print-tr-swingarms').each(function () {
      applySwingarmsState($(this));
    });

    // ── Modal: show Printing Settings block ─────────────────────────────────
    $(document).on('click.printSettings', '.btn-view-options', function () {
      var $btn = $(this);
      var isGraphics = $btn.data('is-graphics') === 1 || $btn.data('is-graphics') === '1';
      var printer = $.trim($btn.data('print-printer') || '');
      var material = $.trim($btn.data('print-material') || '');
      var finish = $.trim($btn.data('print-finish') || '');
      var grip = $.trim($btn.data('print-grip') || '');
      var trSwingarms = $.trim($btn.data('print-tr-swingarms') || '');

      // Remove any previous block
      $('#printingSettingsModalBlock').remove();

      var $modal = $('#optionsModal');

      if (isGraphics) {
        var hasSomething = printer !== '' || material !== '' || finish !== '' || grip !== '' || trSwingarms !== '';
        var $block = $('<div id="printingSettingsModalBlock" class="printing-settings-block mb-3">');
        $block.append('<h6 class="text-info mb-3"><i class="fas fa-print mr-2"></i>Printing Settings</h6>');

        var $row = $('<div class="row">');

        function psCol(label, val) {
          var $col = $('<div class="col-sm-6 col-lg-4 mb-2">');
          $col.append('<div class="ps-label">' + label + '</div>');
          $col.append('<div class="ps-value">' + (val !== '' ? $('<span>').text(val).html() : '<span class="text-muted">—</span>') + '</div>');
          return $col;
        }

        $row.append(psCol('🧱 Material', material));
        $row.append(psCol('✨ Finish', finish));
        $row.append(psCol('Grip', grip));
        $row.append(psCol('Tr. Swingarms', trSwingarms));
        $row.append(psCol('🖨️ Printer', printer));
        $block.append($row);

        if (!hasSomething) {
          $block.append('<p class="text-muted small mb-0">Žiadne print nastavenia ešte neboli vyplnené.</p>');
        }

        // Prepend before the imported options section inside modal body
        $modal.find('.modal-body').prepend($block);
      }
    });

  })();


  /* ── Order photos: drag/drop upload + smooth lightbox + navigation ───────── */
  (function () {
    'use strict';

    $(document).off('.orderPhotos');

    var orderPhotoLightboxItems = [];
    var orderPhotoLightboxIndex = 0;

    function ensureLightbox() {
      var $box = $('#orderPhotoLightbox');
      if ($box.length) return $box;

      $box = $('<div id="orderPhotoLightbox" class="order-photo-lightbox">' +
        '<button type="button" class="btn btn-sm btn-light order-photo-lightbox-close">×</button>' +
        '<button type="button" class="order-photo-lightbox-nav order-photo-lightbox-prev" title="Previous">‹</button>' +
        '<button type="button" class="order-photo-lightbox-nav order-photo-lightbox-next" title="Next">›</button>' +
        '<img src="" alt="Order photo">' +
        '<div class="order-photo-lightbox-counter"></div>' +
        '</div>');

      $('body').append($box);
      return $box;
    }

    function reloadDetail($card) {
      var orderId = parseInt($card.data('order-id'), 10) || 0;
      if (!orderId) return;

      $.post('scripts/orders/get_order_detail.php', {
        order_id: orderId
      }, function (res) {
        if (!res || !res.ok) {
          alert(res && res.error ? res.error : 'Detail reload failed');
          return;
        }

        var $wrap = $('#detail-' + orderId);

        if ($wrap.length) {
          $wrap.html(res.html)
            .data('loaded', true)
            .stop(true, true)
            .show();

          $('#ordersTable .order-row').removeClass('order-row-open');
          $('.order-row[data-order-id="' + orderId + '"]').addClass('order-row-open');
          $('#ordersTable').addClass('table-has-open');
          return;
        }

        location.reload();
      }, 'json');
    }

    function uploadPhotos($dropzone, files) {
      files = Array.prototype.slice.call(files || []).filter(function (file) {
        return file && /^image\//i.test(file.type || '');
      });
      if (!files.length) return;

      var orderId = parseInt($dropzone.data('order-id'), 10) || 0;
      if (!orderId) return;

      var $card = $dropzone.closest('.order-photos-card');
      var $progress = $card.find('.order-photo-upload-progress');
      var $bar = $progress.find('span');
      var fd = new FormData();

      fd.append('order_id', orderId);
      files.forEach(function (file) { fd.append('photos[]', file); });

      $progress.show();
      $bar.css('width', '0%');

      $.ajax({
        url: 'scripts/orders/upload_order_photos.php',
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
        xhr: function () {
          var xhr = $.ajaxSettings.xhr();
          if (xhr.upload) {
            xhr.upload.addEventListener('progress', function (e) {
              if (e.lengthComputable) {
                $bar.css('width', Math.round((e.loaded / e.total) * 100) + '%');
              }
            });
          }
          return xhr;
        }
      }).done(function (res) {
        if (!res || !res.ok) {
          alert(res && res.error ? res.error : 'Upload failed');
          return;
        }
        reloadDetail($card);
      }).fail(function (xhr) {
        alert('Upload failed:\n' + xhr.status + '\n' + xhr.responseText);
      }).always(function () {
        setTimeout(function () { $progress.hide(); $bar.css('width', '0%'); }, 400);
      });
    }

    function collectLightboxItems($clickedThumb) {
      orderPhotoLightboxItems = [];

      var $grid = $clickedThumb.closest('.order-photo-thumb-grid');
      $grid.find('.order-photo-thumb').each(function () {
        var $thumb = $(this);
        orderPhotoLightboxItems.push({
          src: $thumb.data('full-src') || $thumb.attr('src'),
          alt: $thumb.attr('alt') || 'Order photo'
        });
      });

      orderPhotoLightboxIndex = $grid.find('.order-photo-thumb').index($clickedThumb);
      if (orderPhotoLightboxIndex < 0) orderPhotoLightboxIndex = 0;
    }

    function showLightboxPhoto(index) {
      if (!orderPhotoLightboxItems.length) return;

      if (index < 0) index = orderPhotoLightboxItems.length - 1;
      if (index >= orderPhotoLightboxItems.length) index = 0;
      orderPhotoLightboxIndex = index;

      var item = orderPhotoLightboxItems[orderPhotoLightboxIndex];
      var $box = ensureLightbox();
      var $img = $box.find('img');

      $img.css({ opacity: 0, transform: 'scale(.94) translateY(8px)' });

      setTimeout(function () {
        $img.attr('src', item.src).attr('alt', item.alt);
        $box.find('.order-photo-lightbox-counter').text(
          (orderPhotoLightboxIndex + 1) + ' / ' + orderPhotoLightboxItems.length
        );
        $box.find('.order-photo-lightbox-nav, .order-photo-lightbox-counter')
          .toggle(orderPhotoLightboxItems.length > 1);

        window.requestAnimationFrame(function () {
          $img.css({ opacity: 1, transform: 'scale(1) translateY(0)' });
        });
      }, 90);
    }

    function openLightbox($thumb) {
      collectLightboxItems($thumb);

      var $box = ensureLightbox();
      $box.addClass('is-open');

      window.requestAnimationFrame(function () {
        showLightboxPhoto(orderPhotoLightboxIndex);
      });
    }

    function closeLightbox() {
      var $box = $('#orderPhotoLightbox');
      if (!$box.length) return;

      $box.removeClass('is-open');
      window.setTimeout(function () {
        if (!$box.hasClass('is-open')) {
          $box.find('img').attr('src', '').removeAttr('style');
        }
      }, 260);
    }

    $(document).on('click.orderPhotos', '.order-photo-dropzone', function (e) {
      if ($(e.target).is('input')) return;
      $(this).find('.order-photo-input').trigger('click');
    });

    $(document).on('change.orderPhotos', '.order-photo-input', function () {
      uploadPhotos($(this).closest('.order-photo-dropzone'), this.files);
      this.value = '';
    });

    $(document).on('dragenter.orderPhotos dragover.orderPhotos', '.order-photo-dropzone', function (e) {
      e.preventDefault();
      e.stopPropagation();
      $(this).addClass('is-dragover');
    });

    $(document).on('dragleave.orderPhotos drop.orderPhotos', '.order-photo-dropzone', function (e) {
      e.preventDefault();
      e.stopPropagation();
      $(this).removeClass('is-dragover');
      if (e.type === 'drop') {
        uploadPhotos($(this), e.originalEvent.dataTransfer.files);
      }
    });

    $(document).on('click.orderPhotos', '.order-photo-thumb', function () {
      openLightbox($(this));
    });

    $(document).on('click.orderPhotos', '.order-photo-lightbox-prev', function (e) {
      e.preventDefault();
      e.stopPropagation();
      showLightboxPhoto(orderPhotoLightboxIndex - 1);
    });

    $(document).on('click.orderPhotos', '.order-photo-lightbox-next', function (e) {
      e.preventDefault();
      e.stopPropagation();
      showLightboxPhoto(orderPhotoLightboxIndex + 1);
    });

    $(document).on('click.orderPhotos', '#orderPhotoLightbox, .order-photo-lightbox-close', function (e) {
      if ($(e.target).is('img, .order-photo-lightbox-nav')) return;
      closeLightbox();
    });

    $(document).on('keydown.orderPhotos', function (e) {
      var $box = $('#orderPhotoLightbox');
      if (!$box.hasClass('is-open')) return;

      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        showLightboxPhoto(orderPhotoLightboxIndex - 1);
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        showLightboxPhoto(orderPhotoLightboxIndex + 1);
      } else if (e.key === 'Escape') {
        e.preventDefault();
        closeLightbox();
      }
    });

    $(document).on('click.orderPhotos', '.btn-delete-order-photo', function () {
      if (!confirm('Zmazať fotku z objednávky?')) return;

      var $btn = $(this);
      var $card = $btn.closest('.order-photos-card');

      $.post('scripts/orders/delete_order_photo.php', {
        photo_id: $btn.data('photo-id'),
        order_id: $card.data('order-id')
      }, function (res) {
        if (!res || !res.ok) {
          alert(res && res.error ? res.error : 'Delete failed');
          return;
        }

        $btn.closest('.order-photo-thumb-wrap').remove();
        if (!$card.find('.order-photo-thumb-wrap').length) {
          $card.find('.order-photo-thumb-grid').html('<div class="text-muted small order-photo-empty">Žiadne fotky.</div>');
        }
      }, 'json').fail(function (xhr) {
        alert('Delete failed:\n' + xhr.status + '\n' + xhr.responseText);
      });
    });
  })();

</script>
<?php
$html = ob_get_clean();
out(200, ['ok' => true, 'html' => $html]);
?>
