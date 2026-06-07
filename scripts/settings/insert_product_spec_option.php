<?php
require_once __DIR__ . '/product_spec_ajax_bootstrap.php';

$specKey = strtolower(post_string('spec_key', 64));
if (!preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $specKey)) {
  out_json(400, ['ok' => false, 'error' => 'Invalid dropdown key']);
}

$department = strtoupper(trim((string) ($_POST['department'] ?? '')));
if ($department !== '' && !in_array($department, ['G', 'S', 'P', 'F'], true)) {
  out_json(400, ['ok' => false, 'error' => 'Invalid department']);
}
$departmentOrNull = $department !== '' ? $department : null;

$label = post_string('label', 120);
$value = post_string('value', 120);
$sortOrder = post_int('sort_order', 0);
$active = post_int('active', 1) === 1 ? 1 : 0;

$stmt = $conn->prepare("INSERT INTO product_spec_options (spec_key, label, value, sort_order, active, department) VALUES (?, ?, ?, ?, ?, ?)");
if (!$stmt) {
  out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
}
$stmt->bind_param('sssiis', $specKey, $label, $value, $sortOrder, $active, $departmentOrNull);
if (!$stmt->execute()) {
  out_json(500, ['ok' => false, 'error' => $stmt->error]);
}
$id = (int) $stmt->insert_id;
$stmt->close();

out_json(200, ['ok' => true, 'id' => $id]);
