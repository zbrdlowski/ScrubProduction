<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/get_order_detail_product_spec_selects.php';
require_once __DIR__ . '/department_config.php';

function manualItemDepartmentFromType(string $type): string
{
  $type = strtoupper(trim($type));
  switch ($type) {
    case 'G':
    case 'M':
      return 'G';
    case 'S':
      return 'S';
    case 'F':
      return 'F';
    case 'P':
    case 'T':
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
    'T' => 'Trim Kit',
    'M' => 'Bike Mats',
  ];

  $type = strtoupper(trim($type));
  return $labels[$type] ?? $type;
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

function manualItemGraphicsSubcategoryForType(string $itemTypeCode): string
{
  $itemTypeCode = strtoupper(trim($itemTypeCode));
  if ($itemTypeCode === 'M') {
    return 'MOTO_CARPET';
  }

  return '';
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

function manualItemFieldDefinitions(mysqli $conn, string $itemTypeCode): array
{
  $department = manualItemDepartmentFromType($itemTypeCode);
  if ($department === '') {
    return [];
  }

  $targetSubcategory = manualItemGraphicsSubcategoryForType($itemTypeCode);
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
  $definitions = $department !== '' ? manualItemFieldDefinitions($conn, $type) : [];
  $targetSubcategory = manualItemGraphicsSubcategoryForType($type);

  $options = [
    '_manual' => true,
  ];
  $internal = [];

  if ($targetSubcategory !== '') {
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

  return [
    'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'internal_options_json' => json_encode($internal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  ];
}
