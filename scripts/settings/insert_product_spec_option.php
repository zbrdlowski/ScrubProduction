<?php
require_once __DIR__ . '/product_spec_ajax_bootstrap.php';

$specKey    = post_string('spec_key', 120);
$department = strtoupper(post_string_optional('department', 1));
$fieldType  = post_string_optional('field_type', 20);
$fieldLabel = post_string_optional('field_label', 190);
$sourceKey  = post_string_optional('source_key', 120);
$label      = post_string('label', 120);
$value      = post_string('value', 120);
$sortOrder  = post_int('sort_order', 0);
$fieldSortOrder = post_int('field_sort_order', $sortOrder);
$active     = post_int('active', 1) === 1 ? 1 : 0;
$autoCheckbox = ($_POST['auto_checkbox'] ?? '0') === '1';
$applyToSubcategories = post_int('apply_to_subcategories', 0) === 1 ? 1 : 0;

$allowedDepts      = ['G', 'S', 'P', 'F', ''];
$allowedFieldTypes = ['dropdown', 'text', 'checkbox', 'radio'];

$fieldType  = $fieldType  !== '' ? $fieldType  : 'dropdown';
$department = $department !== '' ? $department : null;
$fieldLabel = $fieldLabel !== '' ? $fieldLabel : null;
$sourceKey  = $sourceKey  !== '' ? $sourceKey  : null;

if (!in_array($department ?? '', $allowedDepts, true)) {
  out_json(400, ['ok' => false, 'error' => 'Invalid department']);
}
if (!in_array($fieldType, $allowedFieldTypes, true)) {
  out_json(400, ['ok' => false, 'error' => 'Invalid field_type']);
}

// Zabráň vytvoreniu duplicitného text fieldu
if ($fieldType === 'text') {

    $check = $conn->prepare("
        SELECT id
        FROM product_spec_options
        WHERE spec_key = ?
          AND department <=> ?
          AND field_type = 'text'
        LIMIT 1
    ");

    if (!$check) {
        out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
    }

    $check->bind_param('ss', $specKey, $department);
    $check->execute();

    if ($check->get_result()->num_rows > 0) {
        $check->close();

        out_json(400, [
            'ok' => false,
            'error' => 'This text field already exists.'
        ]);
    }

    $check->close();
}

$hasApplyToSubcategories = product_spec_column_exists($conn, 'apply_to_subcategories');
$hasFieldSortOrder = product_spec_column_exists($conn, 'field_sort_order');
$insertColumns = ['spec_key', 'department', 'field_type', 'label', 'value', 'sort_order', 'active', 'color'];
$placeholders = ['?', '?', '?', '?', '?', '?', '?', '?'];
if (product_spec_column_exists($conn, 'field_label')) {
  array_splice($insertColumns, 3, 0, ['field_label']);
  array_splice($placeholders, 3, 0, ['?']);
}
if ($hasFieldSortOrder) {
  $insertColumns[] = 'field_sort_order';
  $placeholders[] = '?';
}
if ($hasApplyToSubcategories) {
  $insertColumns[] = 'apply_to_subcategories';
  $placeholders[] = '?';
}
$insertSql = "INSERT INTO product_spec_options (" . implode(', ', $insertColumns) . ")
     VALUES (" . implode(', ', $placeholders) . ")";

$stmt = $conn->prepare($insertSql);
if (!$stmt) out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
if (product_spec_column_exists($conn, 'field_label') && $hasFieldSortOrder && $hasApplyToSubcategories) {
  $stmt->bind_param('ssssssiisii', $specKey, $department, $fieldType, $fieldLabel, $label, $value, $sortOrder, $active, $sourceKey, $fieldSortOrder, $applyToSubcategories);
} elseif (product_spec_column_exists($conn, 'field_label') && $hasFieldSortOrder) {
  $stmt->bind_param('ssssssiisi', $specKey, $department, $fieldType, $fieldLabel, $label, $value, $sortOrder, $active, $sourceKey, $fieldSortOrder);
} elseif (product_spec_column_exists($conn, 'field_label') && $hasApplyToSubcategories) {
  $stmt->bind_param('ssssssiisi', $specKey, $department, $fieldType, $fieldLabel, $label, $value, $sortOrder, $active, $sourceKey, $applyToSubcategories);
} elseif (product_spec_column_exists($conn, 'field_label')) {
  $stmt->bind_param('ssssssiis', $specKey, $department, $fieldType, $fieldLabel, $label, $value, $sortOrder, $active, $sourceKey);
} elseif ($hasFieldSortOrder && $hasApplyToSubcategories) {
  $stmt->bind_param('sssssiisii', $specKey, $department, $fieldType, $label, $value, $sortOrder, $active, $sourceKey, $fieldSortOrder, $applyToSubcategories);
} elseif ($hasFieldSortOrder) {
  $stmt->bind_param('sssssiisi', $specKey, $department, $fieldType, $label, $value, $sortOrder, $active, $sourceKey, $fieldSortOrder);
} elseif ($hasApplyToSubcategories) {
  $stmt->bind_param('sssssiisi', $specKey, $department, $fieldType, $label, $value, $sortOrder, $active, $sourceKey, $applyToSubcategories);
} else {
  $stmt->bind_param('sssssiis', $specKey, $department, $fieldType, $label, $value, $sortOrder, $active, $sourceKey);
}
if (!$stmt->execute()) out_json(500, ['ok' => false, 'error' => $stmt->error]);
$insertedId = (int) $stmt->insert_id;
$stmt->close();

// Checkbox — automaticky pridaj No (0) ako druhú option
if ($autoCheckbox) {
  $labelNo  = 'No';
  $valueNo  = '0';
  $sortNo   = $sortOrder + 10;
  $insertSql2 = $insertSql;
  $stmt2 = $conn->prepare($insertSql2);
  if ($stmt2) {
    if (product_spec_column_exists($conn, 'field_label') && $hasFieldSortOrder && $hasApplyToSubcategories) {
      $stmt2->bind_param('ssssssiisii', $specKey, $department, $fieldType, $fieldLabel, $labelNo, $valueNo, $sortNo, $active, $sourceKey, $fieldSortOrder, $applyToSubcategories);
    } elseif (product_spec_column_exists($conn, 'field_label') && $hasFieldSortOrder) {
      $stmt2->bind_param('ssssssiisi', $specKey, $department, $fieldType, $fieldLabel, $labelNo, $valueNo, $sortNo, $active, $sourceKey, $fieldSortOrder);
    } elseif (product_spec_column_exists($conn, 'field_label') && $hasApplyToSubcategories) {
      $stmt2->bind_param('ssssssiisi', $specKey, $department, $fieldType, $fieldLabel, $labelNo, $valueNo, $sortNo, $active, $sourceKey, $applyToSubcategories);
    } elseif (product_spec_column_exists($conn, 'field_label')) {
      $stmt2->bind_param('ssssssiis', $specKey, $department, $fieldType, $fieldLabel, $labelNo, $valueNo, $sortNo, $active, $sourceKey);
    } elseif ($hasFieldSortOrder && $hasApplyToSubcategories) {
      $stmt2->bind_param('sssssiisii', $specKey, $department, $fieldType, $labelNo, $valueNo, $sortNo, $active, $sourceKey, $fieldSortOrder, $applyToSubcategories);
    } elseif ($hasFieldSortOrder) {
      $stmt2->bind_param('sssssiisi', $specKey, $department, $fieldType, $labelNo, $valueNo, $sortNo, $active, $sourceKey, $fieldSortOrder);
    } elseif ($hasApplyToSubcategories) {
      $stmt2->bind_param('sssssiisi', $specKey, $department, $fieldType, $labelNo, $valueNo, $sortNo, $active, $sourceKey, $applyToSubcategories);
    } else {
      $stmt2->bind_param('sssssiis', $specKey, $department, $fieldType, $labelNo, $valueNo, $sortNo, $active, $sourceKey);
    }
    $stmt2->execute();
    $stmt2->close();
  }
}

out_json(200, ['ok' => true, 'id' => $insertedId]);
