<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/get_order_detail_product_spec_selects.php';
require_once __DIR__ . '/department_config.php';

function manualItemDepartmentFromType(string $type): string
{
  $type = strtoupper(trim($type));
  switch ($type) {
    case 'G':
      return 'G';
    case 'S':
      return 'S';
    case 'F':
      return 'F';
    case 'P':
    case 'T':
    case 'M':
      return 'P';
    default:
      return '';
  }
}

function manualItemTypeLabel(string $type): string
{
  $labels = [
    'G' => 'Graphics',
    'P' => 'Plastics',
    'S' => 'Seat Cover',
    'F' => 'Fitting',
    'T' => 'Accessories',
    'M' => 'Misc / Upsell',
  ];

  $type = strtoupper(trim($type));
  return $labels[$type] ?? $type;
}

function manualItemGraphicsSubcategoryLabels(): array
{
  return defined('GRAPHICS_SUBCAT_LABELS') && is_array(GRAPHICS_SUBCAT_LABELS)
    ? GRAPHICS_SUBCAT_LABELS
    : [];
}

function manualItemNormalizeGraphicsSubcategory(?string $subcat): string
{
  $subcat = strtoupper(trim((string) $subcat));
  $labels = manualItemGraphicsSubcategoryLabels();
  return isset($labels[$subcat]) ? $subcat : '';
}

function manualItemGraphicsSubcategoryFromSpecKey(string $specKey, string $department): string
{
  if (strtoupper(trim($department)) !== 'G') {
    return '';
  }

  static $slugMap = null;
  if ($slugMap === null) {
    $slugMap = [];
    if (defined('GRAPHICS_SUBCAT_LABELS') && is_array(GRAPHICS_SUBCAT_LABELS)) {
      foreach (GRAPHICS_SUBCAT_LABELS as $subCategoryCode => $_label) {
        $slugMap[(string) $subCategoryCode] = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', (string) $subCategoryCode));
      }
    }
  }

  $normalizedSpecKey = strtolower(trim($specKey));
  foreach ($slugMap as $subCategoryCode => $subCategorySlug) {
    $prefix = 'graphics_' . $subCategorySlug . '_';
    if (strpos($normalizedSpecKey, $prefix) === 0) {
      return strtoupper(trim((string) $subCategoryCode));
    }
  }

  return '';
}

function manualItemGraphicsSubcategoryForType(string $itemTypeCode, string $selectedSubcategory = ''): string
{
  $itemTypeCode = strtoupper(trim($itemTypeCode));
  if ($itemTypeCode !== 'G') {
    return '';
  }

  return manualItemNormalizeGraphicsSubcategory($selectedSubcategory);
}

function manualItemBuilderSpecLabel(string $itemTypeCode, array $definition): string
{
  $label = trim((string) ($definition['label'] ?? $definition['spec_key'] ?? ''));
  $sourceKey = trim((string) ($definition['source_key'] ?? ''));
  $itemTypeCode = strtoupper(trim($itemTypeCode));

  if ($itemTypeCode === 'G' && $sourceKey === 'note') {
    return 'Buyer Note';
  }

  return $label;
}

function manualItemRenderSpecFieldInput(mysqli $conn, array $definition): string
{
  $specKey = trim((string) ($definition['spec_key'] ?? ''));
  if ($specKey === '') {
    return '';
  }

  return renderProductSpecField(
    $conn,
    $specKey,
    '',
    [],
    'manual-item-spec-control',
    'name="spec_' . htmlspecialchars($specKey, ENT_QUOTES, 'UTF-8') . '"'
  );
}

function manualItemFieldDefinitions(mysqli $conn, string $itemTypeCode, string $selectedGraphicsSubcategory = ''): array
{
  $department = manualItemDepartmentFromType($itemTypeCode);
  if ($department === '') {
    return [];
  }

  $targetSubcategory = manualItemGraphicsSubcategoryForType($itemTypeCode, $selectedGraphicsSubcategory);
  $definitions = productSpecFieldDefinitions($conn, $department);
  $filtered = [];
  foreach ($definitions as $definition) {
    $fieldSubcategory = '';
    if ($department === 'G') {
      $fieldSubcategory = manualItemGraphicsSubcategoryFromSpecKey(
        (string) ($definition['spec_key'] ?? ''),
        $department
      );
    }

    $fieldAppliesToSubcategories = (int) ($definition['apply_to_subcategories'] ?? 0) === 1;
    if ($targetSubcategory !== '') {
      if ($fieldSubcategory !== '' && $fieldSubcategory !== $targetSubcategory) {
        continue;
      }
      if ($fieldSubcategory === '' && !$fieldAppliesToSubcategories) {
        continue;
      }
    } elseif ($fieldSubcategory !== '') {
      continue;
    }

    $filtered[] = $definition;
  }

  usort($filtered, static function (array $a, array $b): int {
    $ao = (int) ($a['field_sort_order'] ?? 999);
    $bo = (int) ($b['field_sort_order'] ?? 999);
    if ($ao !== $bo) {
      return $ao <=> $bo;
    }

    return strcmp((string) ($a['spec_key'] ?? ''), (string) ($b['spec_key'] ?? ''));
  });

  return $filtered;
}

function manualItemPayloadFromPost(mysqli $conn, string $type): array
{
  $department = manualItemDepartmentFromType($type);
  $targetSubcategory = manualItemGraphicsSubcategoryForType($type, (string) ($_POST['graphics_subcategory'] ?? ''));
  $definitions = $department !== '' ? manualItemFieldDefinitions($conn, $type, $targetSubcategory) : [];

  $options = [
    '_manual' => true,
    'category_info' => trim((string) ($_POST['category_info'] ?? '')),
  ];

  foreach ([
    'category_brand' => 'category_brand',
    'category_model' => 'category_model',
    'category_year_range' => 'category_year_range',
    'category_modelcode' => 'category_modelcode',
  ] as $postKey => $optionKey) {
    $value = trim((string) ($_POST[$postKey] ?? ''));
    if ($value !== '') {
      $options[$optionKey] = $value;
    }
  }

  $internal = [];

  if ($department === 'G' && $targetSubcategory !== '') {
    $internal['_subcat'] = $targetSubcategory;
  }

  foreach ($definitions as $definition) {
    $specKey = trim((string) ($definition['spec_key'] ?? ''));
    $sourceKey = trim((string) ($definition['source_key'] ?? ''));
    if ($specKey === '' || $sourceKey === '') {
      continue;
    }

    $postKey = 'spec_' . $specKey;
    if (!array_key_exists($postKey, $_POST)) {
      continue;
    }

    $value = trim((string) $_POST[$postKey]);
    if ($value === '') {
      continue;
    }

    $options[$sourceKey] = $value;
  }

  $legacyMap = [
    'option_name' => 'name',
    'option_number' => 'number',
    'option_material' => 'base-material',
    'option_finish' => 'graphics-finish',
    'option_grip' => 'grip',
    'option_tr_swingarms' => 'tr-swingarms',
    'option_patch_style' => 'patch-style',
    'option_waterproof_seams' => 'waterproof-seams',
    'option_enduro_pocket' => 'enduro-pocket',
    'option_side_brand_patches' => 'side-brand-patches',
    'option_note' => 'note',
    'option_printer' => 'printer',
    'option_my_item_note' => 'my-item-note',
  ];
  foreach ($legacyMap as $postKey => $sourceKey) {
    if (isset($options[$sourceKey])) {
      continue;
    }
    $value = trim((string) ($_POST[$postKey] ?? ''));
    if ($value !== '') {
      $options[$sourceKey] = $value;
    }
  }

  $options = array_filter($options, static function ($value) {
    return $value !== '';
  });

  return [
    'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'internal_options_json' => json_encode($internal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  ];
}
