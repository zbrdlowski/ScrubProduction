<?php
declare(strict_types=1);

/**
 * Product spec helpers pre get_order_detail.php
 * Tento súbor obsahuje VÝLUČNE funkcie súvisiace s product spec options.
 * Všetky ostatné helper funkcie žijú v get_order_detail.php.
 */

/**
 * Načíta aktívne options pre daný spec_key z DB.
 * Vracia ['items' => [...], 'field_type' => 'dropdown|text|checkbox|radio']
 */
function productSpecColumnExists(mysqli $conn, string $column): bool
{
  static $cache = [];

  $column = trim($column);
  if ($column === '') {
    return false;
  }

  if (array_key_exists($column, $cache)) {
    return $cache[$column];
  }

  $stmt = $conn->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'product_spec_options'
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  if (!$stmt) {
    $cache[$column] = false;
    return false;
  }

  $stmt->bind_param('s', $column);
  $exists = false;
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_assoc() !== null;
  }
  $stmt->close();

  $cache[$column] = $exists;
  return $exists;
}

function productSpecFieldLabelColumnExists(mysqli $conn): bool
{
  return productSpecColumnExists($conn, 'field_label');
}

function productSpecOptions(mysqli $conn, string $specKey, array $fallbackOptions): array
{
  static $cache = [];

  if (isset($cache[$specKey])) {
    return $cache[$specKey];
  }

  $items = [];
  $fieldType = 'dropdown';

  $stmt = $conn->prepare("
    SELECT label, value, field_type
    FROM product_spec_options
    WHERE spec_key = ? AND active = 1
    ORDER BY sort_order ASC, id ASC
  ");
  if ($stmt) {
    $stmt->bind_param('s', $specKey);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $value = trim((string) ($row['value'] ?? ''));
        $label = trim((string) ($row['label'] ?? ''));
        $ft    = trim((string) ($row['field_type'] ?? 'dropdown'));
        if ($value === '' || $label === '') continue;
        if ($ft !== 'dropdown') $fieldType = $ft;
        $items[] = ['value' => $value, 'label' => $label, 'field_type' => $ft];
      }
    }
    $stmt->close();
  }

  if (!$items) {
  $cache[$specKey] = ['items' => [], 'field_type' => $fieldType];
  return $cache[$specKey];
}

  $cache[$specKey] = ['items' => $items, 'field_type' => $fieldType];
  return $cache[$specKey];
}

/**
 * Renderuje správny HTML formulárový prvok podľa field_type.
 *
 * dropdown / radio / checkbox → <select>
 * text                        → <input type="text">
 */
function renderProductSpecField(
  mysqli $conn,
  string $specKey,
  string $currentValue,
  array  $fallbackOptions,
  string $cssClass,
  string $dataAttr,
  string $emptyLabel = 'Select...'
): string {
  $spec      = productSpecOptions($conn, $specKey, $fallbackOptions);
  $items     = $spec['items'];
  $fieldType = $spec['field_type'];

  if ($fieldType === 'text') {
    return '<input type="text"'
      . ' class="form-control form-control-sm ' . htmlspecialchars($cssClass, ENT_QUOTES, 'UTF-8') . '"'
      . ' ' . $dataAttr
      . ' value="' . htmlspecialchars($currentValue, ENT_QUOTES, 'UTF-8') . '"'
      . ' placeholder="">';
  }

  $extra = ($fieldType === 'radio') ? ' data-field-type="radio"' : '';
  $html  = '<select class="form-control form-control-sm ' . htmlspecialchars($cssClass, ENT_QUOTES, 'UTF-8') . '" ' . $dataAttr . $extra . '>';
  $html .= '<option value=""' . ($currentValue === '' ? ' selected' : '') . '>' . htmlspecialchars($emptyLabel, ENT_QUOTES, 'UTF-8') . '</option>';

  $hasCurrent = ($currentValue === '');
  foreach ($items as $option) {
    $val   = (string) $option['value'];
    $label = (string) $option['label'];
    if ($val === $currentValue) $hasCurrent = true;
    $html .= '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"' . ($val === $currentValue ? ' selected' : '') . '>'
           . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
  }

  if (!$hasCurrent && $currentValue !== '') {
    $html .= '<option value="' . htmlspecialchars($currentValue, ENT_QUOTES, 'UTF-8') . '" selected>'
           . htmlspecialchars($currentValue, ENT_QUOTES, 'UTF-8') . '</option>';
  }

  $html .= '</select>';
  return $html;
}

/**
 * Zachovaná pre spätnú kompatibilitu — renderuje <option> tagy (volajúci obalí <select>).
 */
function renderProductSpecOptions(mysqli $conn, string $specKey, string $currentValue, array $fallbackOptions, string $emptyLabel = 'Select...'): string
{
  $spec  = productSpecOptions($conn, $specKey, $fallbackOptions);
  $items = $spec['items'];

  $html = '<option value=""' . ($currentValue === '' ? ' selected' : '') . '>' . htmlspecialchars($emptyLabel, ENT_QUOTES, 'UTF-8') . '</option>';
  $hasCurrent = ($currentValue === '');

  foreach ($items as $option) {
    $value = (string) $option['value'];
    $label = (string) $option['label'];
    if ($value === $currentValue) $hasCurrent = true;
    $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . ($value === $currentValue ? ' selected' : '') . '>'
           . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
  }

  if (!$hasCurrent && $currentValue !== '') {
    $html .= '<option value="' . htmlspecialchars($currentValue, ENT_QUOTES, 'UTF-8') . '" selected>'
           . htmlspecialchars($currentValue, ENT_QUOTES, 'UTF-8') . '</option>';
  }

  return $html;
}

function productSpecDepartmentPrefix(string $department): string
{
  static $prefixes = [
    'G' => 'graphics',
    'S' => 'seat',
    'P' => 'plastics',
    'F' => 'fitting',
  ];

  $department = strtoupper(trim($department));
  return $prefixes[$department] ?? '';
}

function productSpecLabelFromKey(string $specKey, string $department): string
{
  static $defaults = [
    'graphics_material'       => 'Material',
    'graphics_finish'         => 'Finish',
    'graphics_grip'           => 'Grip',
    'graphics_tr_swingarms'   => 'Tr. Swingarms',
    'graphics_printer'        => 'Printer',
    'seat_waterproof_seams'   => 'Waterproof Seams',
    'seat_enduro_pocket'      => 'Enduro Pocket',
    'seat_side_brand_patches' => 'Side Brand Patches',
    'seat_patch_applied'      => 'Patch Applied',
  ];

  if (isset($defaults[$specKey])) {
    return $defaults[$specKey];
  }

  $label = trim($specKey);
  $prefix = productSpecDepartmentPrefix($department);
  if ($prefix !== '') {
    $prefix .= '_';
    if (strpos($label, $prefix) === 0) {
      $label = substr($label, strlen($prefix));
    }
  }

  $label = str_replace(['_', '-'], ' ', $label);
  $label = preg_replace('/\s+/', ' ', $label) ?? $label;
  $label = trim($label);

  return $label !== '' ? mb_convert_case($label, MB_CASE_TITLE, 'UTF-8') : $specKey;
}

function productSpecSourceKeyFromSpecKey(string $specKey, string $department): string
{
  $source = trim($specKey);
  $prefix = productSpecDepartmentPrefix($department);
  if ($prefix !== '') {
    $prefix .= '_';
    if (strpos($source, $prefix) === 0) {
      $source = substr($source, strlen($prefix));
    }
  }

  return str_replace('_', '-', $source);
}

function productSpecNormalizeSourceKey(string $key): string
{
  $key = trim(mb_strtolower($key, 'UTF-8'));
  $key = str_replace('_', '-', $key);
  $key = preg_replace('/[^a-z0-9-]+/u', '-', $key) ?? $key;
  $key = trim($key, '-');
  return preg_replace('/-+/', '-', $key) ?? $key;
}

function productSpecHumanizeOptionKey(string $key): string
{
  static $overrides = [
    'base-material' => 'Material',
    'graphics-finish' => 'Finish',
    'grip' => 'Grip',
    'tr-swingarms' => 'Tr. Swingarms',
    'printer' => 'Printer',
    'name' => 'Rider Name',
    'number' => 'Rider Number',
    'patch-style' => 'Patch Style',
    'waterproof-seams' => 'Waterproof Seams',
    'enduro-pocket' => 'Enduro Pocket',
    'side-brand-patches' => 'Side Brand Patches',
    'my-item-note' => 'My Item Note',
    'buyer-note' => 'Buyer Note',
    'category-info' => 'Category Info',
  ];

  $normalized = productSpecNormalizeSourceKey($key);
  if ($normalized !== '' && isset($overrides[$normalized])) {
    return $overrides[$normalized];
  }

  $label = str_replace(['_', '-'], ' ', trim($key));
  $label = preg_replace('/\s+/', ' ', $label) ?? $label;
  $label = trim($label);

  return $label !== '' ? mb_convert_case($label, MB_CASE_TITLE, 'UTF-8') : $key;
}

function productSpecDisplayLabelForOptionKey(mysqli $conn, string $optionKey, string $department = ''): string
{
  static $cache = [];

  $normalizedOptionKey = productSpecNormalizeSourceKey($optionKey);
  if ($normalizedOptionKey === '') {
    return trim($optionKey);
  }

  $department = strtoupper(trim($department));
  $cacheKey = $department . '|' . $normalizedOptionKey;
  if (isset($cache[$cacheKey])) {
    return $cache[$cacheKey];
  }

  $departmentsToCheck = [];
  if ($department !== '') {
    $departmentsToCheck[] = $department;
  }
  foreach (['G', 'S', 'P', 'F'] as $departmentCode) {
    if (!in_array($departmentCode, $departmentsToCheck, true)) {
      $departmentsToCheck[] = $departmentCode;
    }
  }

  foreach ($departmentsToCheck as $departmentCode) {
    foreach (productSpecFieldDefinitions($conn, $departmentCode) as $definition) {
      $sourceKey = productSpecNormalizeSourceKey((string) ($definition['source_key'] ?? ''));
      if ($sourceKey === '' || $sourceKey !== $normalizedOptionKey) {
        continue;
      }

      $label = trim((string) ($definition['label'] ?? ''));
      if ($label !== '') {
        $cache[$cacheKey] = $label;
        return $label;
      }
    }
  }

  $cache[$cacheKey] = productSpecHumanizeOptionKey($optionKey);
  return $cache[$cacheKey];
}
/*
function productSpecDefaultFieldDefinitions(string $department): array
{
  $department = strtoupper(trim($department));

  $definitionsByDepartment = [
    'G' => [
      ['spec_key' => 'graphics_material', 'field_type' => 'dropdown', 'label' => 'Material', 'source_key' => 'base-material', 'apply_to_subcategories' => 0, 'field_sort_order' => 40],
      ['spec_key' => 'graphics_finish', 'field_type' => 'dropdown', 'label' => 'Finish', 'source_key' => 'graphics-finish', 'apply_to_subcategories' => 0, 'field_sort_order' => 20],
      ['spec_key' => 'graphics_grip', 'field_type' => 'dropdown', 'label' => 'Grip', 'source_key' => 'grip', 'apply_to_subcategories' => 0, 'field_sort_order' => 30],
      ['spec_key' => 'graphics_tr_swingarms', 'field_type' => 'dropdown', 'label' => 'Tr. Swingarms', 'source_key' => 'tr-swingarms', 'apply_to_subcategories' => 0, 'field_sort_order' => 70],
      ['spec_key' => 'graphics_printer', 'field_type' => 'dropdown', 'label' => 'Printer', 'source_key' => 'printer', 'apply_to_subcategories' => 1, 'field_sort_order' => 60],
      ['spec_key' => 'graphics_name', 'field_type' => 'text', 'label' => 'Name', 'source_key' => 'name', 'apply_to_subcategories' => 0, 'field_sort_order' => 110],
      ['spec_key' => 'graphics_number', 'field_type' => 'text', 'label' => 'Number', 'source_key' => 'number', 'apply_to_subcategories' => 0, 'field_sort_order' => 120],
      ['spec_key' => 'graphics_note', 'field_type' => 'text', 'label' => 'Note', 'source_key' => 'note', 'apply_to_subcategories' => 1, 'field_sort_order' => 90],
    ],
    'S' => [
      ['spec_key' => 'seat_enduro_pocket', 'field_type' => 'dropdown', 'label' => 'Enduro Pocket', 'source_key' => 'enduro-pocket', 'apply_to_subcategories' => 0, 'field_sort_order' => 10],
      ['spec_key' => 'seat_waterproof_seams', 'field_type' => 'dropdown', 'label' => 'Waterproof Seams', 'source_key' => 'waterproof-seams', 'apply_to_subcategories' => 0, 'field_sort_order' => 20],
      ['spec_key' => 'seat_side_brand_patches', 'field_type' => 'dropdown', 'label' => 'Side Brand Patches', 'source_key' => 'side-brand-patches', 'apply_to_subcategories' => 0, 'field_sort_order' => 30],
      ['spec_key' => 'seat_patch_applied', 'field_type' => 'dropdown', 'label' => 'Patch Applied', 'source_key' => 'patch-style', 'apply_to_subcategories' => 0, 'field_sort_order' => 40],
      ['spec_key' => 'seat_note', 'field_type' => 'text', 'label' => 'Note', 'source_key' => 'note', 'apply_to_subcategories' => 0, 'field_sort_order' => 90],
    ],
    'P' => [
      ['spec_key' => 'plastics_my_item_note', 'field_type' => 'text', 'label' => 'My Item Note', 'source_key' => 'my-item-note', 'apply_to_subcategories' => 0, 'field_sort_order' => 20],
      ['spec_key' => 'plastics_note', 'field_type' => 'text', 'label' => 'Note', 'source_key' => 'note', 'apply_to_subcategories' => 0, 'field_sort_order' => 10],
    ],
    'F' => [
      ['spec_key' => 'fitting_note', 'field_type' => 'text', 'label' => 'Note', 'source_key' => 'note', 'apply_to_subcategories' => 0, 'field_sort_order' => 10],
    ],
  ];

  $items = $definitionsByDepartment[$department] ?? [];
  foreach ($items as &$definition) {
    $definition['department'] = $department;
  }
  unset($definition);

  return $items;
}
*/
function productSpecFieldDefinitions(mysqli $conn, string $department): array
{
  static $cache = [];

  $department = strtoupper(trim($department));
  if ($department === '') {
    return [];
  }

  if (isset($cache[$department])) {
    return $cache[$department];
  }

  $definitions = [];
  $seenSpecKeys = [];
  $hasFieldSortOrder = productSpecColumnExists($conn, 'field_sort_order');
  $hasFieldLabel = productSpecFieldLabelColumnExists($conn);
  $fieldSortSelect = $hasFieldSortOrder
    ? 'COALESCE(MIN(field_sort_order), MIN(sort_order), 999) AS field_sort_order,'
    : 'MIN(sort_order) AS field_sort_order,';
  $fieldLabelSelect = $hasFieldLabel
    ? "COALESCE(NULLIF(MAX(field_label), ''), '') AS field_label,"
    : "'' AS field_label,";
  $fieldSortOrderBy = $hasFieldSortOrder
    ? 'COALESCE(MIN(field_sort_order), MIN(sort_order), 999) ASC, MIN(id) ASC'
    : 'MIN(sort_order) ASC, MIN(id) ASC';
  $stmt = $conn->prepare("
    SELECT
      spec_key,
      COALESCE(MAX(field_type), 'dropdown') AS field_type,
      $fieldLabelSelect
      COALESCE(NULLIF(MAX(color), ''), '') AS source_key,
      $fieldSortSelect
      COALESCE(MAX(apply_to_subcategories), 0) AS apply_to_subcategories
    FROM product_spec_options
    WHERE active = 1
      AND (department = ? OR department IS NULL)
    GROUP BY spec_key
    ORDER BY $fieldSortOrderBy
  ");
  if ($stmt) {
    $stmt->bind_param('s', $department);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $specKey = trim((string) ($row['spec_key'] ?? ''));
        if ($specKey === '') {
          continue;
        }

        $definitions[] = [
          'spec_key' => $specKey,
          'field_type' => trim((string) ($row['field_type'] ?? 'dropdown')) ?: 'dropdown',
          'label' => trim((string) ($row['field_label'] ?? '')) !== ''
            ? trim((string) ($row['field_label'] ?? ''))
            : productSpecLabelFromKey($specKey, $department),
          'source_key' => trim((string) ($row['source_key'] ?? '')) !== ''
            ? trim((string) ($row['source_key'] ?? ''))
            : productSpecSourceKeyFromSpecKey($specKey, $department),
          'field_sort_order' => (int) ($row['field_sort_order'] ?? 999),
          'apply_to_subcategories' => (int) ($row['apply_to_subcategories'] ?? 0) === 1 ? 1 : 0,
          'department' => $department,
        ];
        $seenSpecKeys[$specKey] = true;
      }
    }
    $stmt->close();
  }
/*
  foreach (productSpecDefaultFieldDefinitions($department) as $defaultDefinition) {
    $defaultSpecKey = trim((string) ($defaultDefinition['spec_key'] ?? ''));
    if ($defaultSpecKey === '' || isset($seenSpecKeys[$defaultSpecKey])) {
      continue;
    }
    $definitions[] = $defaultDefinition;
  }
  */
  usort($definitions, static function (array $a, array $b): int {
    $ao = (int) ($a['field_sort_order'] ?? 999);
    $bo = (int) ($b['field_sort_order'] ?? 999);
    if ($ao !== $bo) {
      return $ao <=> $bo;
    }

    return strcmp((string) ($a['spec_key'] ?? ''), (string) ($b['spec_key'] ?? ''));
  });

  $cache[$department] = $definitions;
  return $definitions;
}
