<?php
require_once __DIR__ . '/status_definition_ajax_bootstrap.php';

$id = status_post_int('id', 0);
if ($id <= 0) {
  status_out_json(400, ['ok' => false, 'error' => 'Invalid id']);
}

$stmt = $conn->prepare("DELETE FROM status_definitions WHERE id = ? LIMIT 1");
if (!$stmt) {
  status_out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
}
$stmt->bind_param('i', $id);
if (!$stmt->execute()) {
  status_out_json(500, ['ok' => false, 'error' => $stmt->error]);
}
$stmt->close();

status_out_json(200, ['ok' => true]);
