<?php
require_once __DIR__ . '/product_spec_ajax_bootstrap.php';

$id = post_int('id', 0);
if ($id <= 0) {
  out_json(400, ['ok' => false, 'error' => 'Invalid id']);
}

$label     = post_string('label', 120);
$value     = post_string('value', 120);
$sortOrder = post_int('sort_order', 0);
$fieldSortOrder = post_int('field_sort_order', $sortOrder);
$active    = post_int('active', 1) === 1 ? 1 : 0;
$fieldType = post_string_optional('field_type', 20);
$sourceKey = post_string_optional('source_key', 120);
$applyToSubcategories = post_int('apply_to_subcategories', 0) === 1 ? 1 : 0;

$allowedFieldTypes = ['dropdown', 'text', 'checkbox', 'radio'];

if ($fieldType !== '' && !in_array($fieldType, $allowedFieldTypes, true)) {
  out_json(400, ['ok' => false, 'error' => 'Invalid field_type']);
}

// Field-level metadata should stay unified across every option row of the same field.
$metaSpecKey = '';
$metaDepartment = null;
$metaStmt = $conn->prepare("SELECT spec_key, department FROM product_spec_options WHERE id = ? LIMIT 1");
if ($metaStmt) {
  $metaStmt->bind_param('i', $id);
  if ($metaStmt->execute()) {
    $metaRow = $metaStmt->get_result()->fetch_assoc();
    if ($metaRow) {
      $metaSpecKey = trim((string) ($metaRow['spec_key'] ?? ''));
      $metaDepartment = $metaRow['department'] ?? null;
    }
  }
  $metaStmt->close();
}

$hasApplyToSubcategories = product_spec_column_exists($conn, 'apply_to_subcategories');
$hasFieldSortOrder = product_spec_column_exists($conn, 'field_sort_order');

if ($fieldType !== '') {
  $sql = "
    UPDATE product_spec_options
    SET label = ?, value = ?, sort_order = ?, active = ?, field_type = ?, color = ?";
  if ($hasFieldSortOrder) {
    $sql .= ", field_sort_order = ?";
  }
  $sql .= "
    WHERE id = ?
    LIMIT 1
  ";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
  }
  if ($hasFieldSortOrder) {
    $stmt->bind_param('ssiissii', $label, $value, $sortOrder, $active, $fieldType, $sourceKey, $fieldSortOrder, $id);
  } else {
    $stmt->bind_param('ssiissi', $label, $value, $sortOrder, $active, $fieldType, $sourceKey, $id);
  }
} else {
  $sql = "
    UPDATE product_spec_options
    SET label = ?, value = ?, sort_order = ?, active = ?, color = ?";
  if ($hasFieldSortOrder) {
    $sql .= ", field_sort_order = ?";
  }
  $sql .= "
    WHERE id = ?
    LIMIT 1
  ";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
  }
  if ($hasFieldSortOrder) {
    $stmt->bind_param('ssiisii', $label, $value, $sortOrder, $active, $sourceKey, $fieldSortOrder, $id);
  } else {
    $stmt->bind_param('ssiisi', $label, $value, $sortOrder, $active, $sourceKey, $id);
  }
}

if (!$stmt->execute()) {
  out_json(500, ['ok' => false, 'error' => $stmt->error]);
}
$stmt->close();

if ($metaSpecKey !== '') {
  if ($fieldType !== '') {
    if ($hasApplyToSubcategories) {
      $metaSql = "
        UPDATE product_spec_options
        SET field_type = ?, color = ?, apply_to_subcategories = ?";
      if ($hasFieldSortOrder) {
        $metaSql .= ", field_sort_order = ?";
      }
      $metaSql .= "
        WHERE spec_key = ?
          AND ((department IS NULL AND ? IS NULL) OR department = ?)
      ";
      $metaUpdateStmt = $conn->prepare($metaSql);
      if ($metaUpdateStmt) {
        if ($hasFieldSortOrder) {
          $metaUpdateStmt->bind_param('ssissss', $fieldType, $sourceKey, $applyToSubcategories, $fieldSortOrder, $metaSpecKey, $metaDepartment, $metaDepartment);
        } else {
          $metaUpdateStmt->bind_param('ssisss', $fieldType, $sourceKey, $applyToSubcategories, $metaSpecKey, $metaDepartment, $metaDepartment);
        }
        $metaUpdateStmt->execute();
        $metaUpdateStmt->close();
      }
    } else {
      $metaSql = "
        UPDATE product_spec_options
        SET field_type = ?, color = ?";
      if ($hasFieldSortOrder) {
        $metaSql .= ", field_sort_order = ?";
      }
      $metaSql .= "
        WHERE spec_key = ?
          AND ((department IS NULL AND ? IS NULL) OR department = ?)
      ";
      $metaUpdateStmt = $conn->prepare($metaSql);
      if ($metaUpdateStmt) {
        if ($hasFieldSortOrder) {
          $metaUpdateStmt->bind_param('ssssss', $fieldType, $sourceKey, $fieldSortOrder, $metaSpecKey, $metaDepartment, $metaDepartment);
        } else {
          $metaUpdateStmt->bind_param('sssss', $fieldType, $sourceKey, $metaSpecKey, $metaDepartment, $metaDepartment);
        }
        $metaUpdateStmt->execute();
        $metaUpdateStmt->close();
      }
    }
  } elseif ($hasApplyToSubcategories) {
    $metaSql = "
      UPDATE product_spec_options
      SET color = ?, apply_to_subcategories = ?";
    if ($hasFieldSortOrder) {
      $metaSql .= ", field_sort_order = ?";
    }
    $metaSql .= "
      WHERE spec_key = ?
        AND ((department IS NULL AND ? IS NULL) OR department = ?)
    ";
    $metaUpdateStmt = $conn->prepare($metaSql);
    if ($metaUpdateStmt) {
      if ($hasFieldSortOrder) {
        $metaUpdateStmt->bind_param('sissss', $sourceKey, $applyToSubcategories, $fieldSortOrder, $metaSpecKey, $metaDepartment, $metaDepartment);
      } else {
        $metaUpdateStmt->bind_param('sisss', $sourceKey, $applyToSubcategories, $metaSpecKey, $metaDepartment, $metaDepartment);
      }
      $metaUpdateStmt->execute();
      $metaUpdateStmt->close();
    }
  } elseif ($hasFieldSortOrder) {
    $metaUpdateStmt = $conn->prepare("
      UPDATE product_spec_options
      SET color = ?, field_sort_order = ?
      WHERE spec_key = ?
        AND ((department IS NULL AND ? IS NULL) OR department = ?)
    ");
    if ($metaUpdateStmt) {
      $metaUpdateStmt->bind_param('sisss', $sourceKey, $fieldSortOrder, $metaSpecKey, $metaDepartment, $metaDepartment);
      $metaUpdateStmt->execute();
      $metaUpdateStmt->close();
    }
  }
}

out_json(200, ['ok' => true]);
