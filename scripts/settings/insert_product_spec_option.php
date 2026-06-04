<?php
require_once __DIR__ . '/product_spec_ajax_bootstrap.php';

$allowedKeys = [
  'graphics_material',
  'graphics_finish',
  'graphics_grip',
  'graphics_tr_swingarms',
  'graphics_printer',
  'seat_waterproof_seams',
  'seat_enduro_pocket',
  'seat_side_brand_patches',
];

$specKey = post_string('spec_key', 64);
if (!in_array($specKey, $allowedKeys, true)) {
  out_json(400, ['ok' => false, 'error' => 'Invalid dropdown key']);
}

$label = post_string('label', 120);
$value = post_string('value', 120);
$sortOrder = post_int('sort_order', 0);
$active = post_int('active', 1) === 1 ? 1 : 0;

$stmt = $conn->prepare("INSERT INTO product_spec_options (spec_key, label, value, sort_order, active) VALUES (?, ?, ?, ?, ?)");
if (!$stmt) {
  out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
}
$stmt->bind_param('sssii', $specKey, $label, $value, $sortOrder, $active);
if (!$stmt->execute()) {
  out_json(500, ['ok' => false, 'error' => $stmt->error]);
}
$id = (int) $stmt->insert_id;
$stmt->close();

out_json(200, ['ok' => true, 'id' => $id]);
