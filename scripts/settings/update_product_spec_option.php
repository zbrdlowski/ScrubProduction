<?php
require_once __DIR__ . '/product_spec_ajax_bootstrap.php';

$id = post_int('id', 0);
if ($id <= 0) {
  out_json(400, ['ok' => false, 'error' => 'Invalid id']);
}

$label     = post_string('label', 120);
$value     = post_string('value', 120);
$sortOrder = post_int('sort_order', 0);
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

if ($fieldType !== '') {
  $stmt = $conn->prepare("
    UPDATE product_spec_options
    SET label = ?, value = ?, sort_order = ?, active = ?, field_type = ?, color = ?
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) {
    out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
  }
  $stmt->bind_param('ssiissi', $label, $value, $sortOrder, $active, $fieldType, $sourceKey, $id);
} else {
  $stmt = $conn->prepare("
    UPDATE product_spec_options
    SET label = ?, value = ?, sort_order = ?, active = ?, color = ?
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) {
    out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
  }
  $stmt->bind_param('ssiisi', $label, $value, $sortOrder, $active, $sourceKey, $id);
}

if (!$stmt->execute()) {
  out_json(500, ['ok' => false, 'error' => $stmt->error]);
}
$stmt->close();

if ($metaSpecKey !== '') {
  if ($fieldType !== '') {
    if ($hasApplyToSubcategories) {
      $metaUpdateStmt = $conn->prepare("
        UPDATE product_spec_options
        SET field_type = ?, color = ?, apply_to_subcategories = ?
        WHERE spec_key = ?
          AND ((department IS NULL AND ? IS NULL) OR department = ?)
      ");
      if ($metaUpdateStmt) {
        $metaUpdateStmt->bind_param('ssisss', $fieldType, $sourceKey, $applyToSubcategories, $metaSpecKey, $metaDepartment, $metaDepartment);
        $metaUpdateStmt->execute();
        $metaUpdateStmt->close();
      }
    } else {
      $metaUpdateStmt = $conn->prepare("
        UPDATE product_spec_options
        SET field_type = ?, color = ?
        WHERE spec_key = ?
          AND ((department IS NULL AND ? IS NULL) OR department = ?)
      ");
      if ($metaUpdateStmt) {
        $metaUpdateStmt->bind_param('sssss', $fieldType, $sourceKey, $metaSpecKey, $metaDepartment, $metaDepartment);
        $metaUpdateStmt->execute();
        $metaUpdateStmt->close();
      }
    }
  } elseif ($hasApplyToSubcategories) {
    $metaUpdateStmt = $conn->prepare("
      UPDATE product_spec_options
      SET color = ?, apply_to_subcategories = ?
      WHERE spec_key = ?
        AND ((department IS NULL AND ? IS NULL) OR department = ?)
    ");
    if ($metaUpdateStmt) {
      $metaUpdateStmt->bind_param('sisss', $sourceKey, $applyToSubcategories, $metaSpecKey, $metaDepartment, $metaDepartment);
      $metaUpdateStmt->execute();
      $metaUpdateStmt->close();
    }
  }
}

out_json(200, ['ok' => true]);
