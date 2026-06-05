<?php
require_once __DIR__ . '/status_definition_ajax_bootstrap.php';

$id = status_post_int('id', 0);
if ($id <= 0) {
  status_out_json(400, ['ok' => false, 'error' => 'Invalid id']);
}

$code = strtoupper(status_post_string('code', 64));
$label = status_post_string('label', 120);
$color = status_post_string('color', 20, false);
$sortOrder = status_post_int('sort_order', 0);
$active = status_post_int('active', 1) === 1 ? 1 : 0;

$color = $color !== '' ? $color : null;

$stmt = $conn->prepare("
  UPDATE status_definitions
  SET code = ?, label = ?, color = ?, sort_order = ?, active = ?
  WHERE id = ?
  LIMIT 1
");
if (!$stmt) {
  status_out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
}

$stmt->bind_param('sssiii', $code, $label, $color, $sortOrder, $active, $id);
if (!$stmt->execute()) {
  status_out_json(500, ['ok' => false, 'error' => $stmt->error]);
}
$stmt->close();

status_out_json(200, ['ok' => true]);
