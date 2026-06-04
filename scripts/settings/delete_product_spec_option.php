<?php
require_once __DIR__ . '/product_spec_ajax_bootstrap.php';

$id = post_int('id', 0);
if ($id <= 0) {
  out_json(400, ['ok' => false, 'error' => 'Invalid id']);
}

$stmt = $conn->prepare("DELETE FROM product_spec_options WHERE id = ? LIMIT 1");
if (!$stmt) {
  out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
}
$stmt->bind_param('i', $id);
if (!$stmt->execute()) {
  out_json(500, ['ok' => false, 'error' => $stmt->error]);
}
$stmt->close();

out_json(200, ['ok' => true]);
