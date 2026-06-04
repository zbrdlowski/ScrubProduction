<?php
require_once __DIR__ . '/product_spec_ajax_bootstrap.php';

$id = post_int('id', 0);
if ($id <= 0) {
  out_json(400, ['ok' => false, 'error' => 'Invalid id']);
}

$label = post_string('label', 120);
$value = post_string('value', 120);
$sortOrder = post_int('sort_order', 0);
$active = post_int('active', 1) === 1 ? 1 : 0;

$stmt = $conn->prepare("UPDATE product_spec_options SET label = ?, value = ?, sort_order = ?, active = ? WHERE id = ? LIMIT 1");
if (!$stmt) {
  out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
}
$stmt->bind_param('ssiii', $label, $value, $sortOrder, $active, $id);
if (!$stmt->execute()) {
  out_json(500, ['ok' => false, 'error' => $stmt->error]);
}
$stmt->close();

out_json(200, ['ok' => true]);
