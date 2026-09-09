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
$tabBar = status_post_int('tab_bar', 0) === 1 ? 1 : 0;
$targets = $_POST['targets'] ?? ['ALL'];

$color = $color !== '' ? $color : null;

$metaStmt = $conn->prepare('SELECT scope, department FROM status_definitions WHERE id = ? LIMIT 1');
if (!$metaStmt) {
  status_out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
}
$metaStmt->bind_param('i', $id);
$metaStmt->execute();
$definition = $metaStmt->get_result()->fetch_assoc();
$metaStmt->close();
if (!$definition) {
  status_out_json(404, ['ok' => false, 'error' => 'Status definition not found']);
}

$tabBarPositions = statusDefinitionNormalizeTabBarPositionIds(
  $conn,
  $_POST['tab_bar_positions'] ?? statusDefinitionDefaultTabBarPositionIds($conn, (string)$definition['scope'], $definition['department'])
);
if ($tabBar === 1 && !$tabBarPositions) {
  status_out_json(400, ['ok' => false, 'error' => 'Select at least one Tab Bar Applies To position.']);
}

$conn->begin_transaction();
$hasTabBarColumn = statusDefinitionHasTabBarColumn($conn);
if ($hasTabBarColumn) {
  $stmt = $conn->prepare("
    UPDATE status_definitions
    SET code = ?, label = ?, color = ?, sort_order = ?, active = ?, tab_bar = ?
    WHERE id = ?
    LIMIT 1
  ");
} else {
  $stmt = $conn->prepare("
    UPDATE status_definitions
    SET code = ?, label = ?, color = ?, sort_order = ?, active = ?
    WHERE id = ?
    LIMIT 1
  ");
}
if (!$stmt) {
  $conn->rollback();
  status_out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
}

if ($hasTabBarColumn) {
  $stmt->bind_param('sssiiii', $code, $label, $color, $sortOrder, $active, $tabBar, $id);
} else {
  $stmt->bind_param('sssiii', $code, $label, $color, $sortOrder, $active, $id);
}
if (!$stmt->execute()) {
  $conn->rollback();
  status_out_json(500, ['ok' => false, 'error' => $stmt->error]);
}
$stmt->close();

if (!statusDefinitionSaveTargets($conn, $id, (string)$definition['scope'], $definition['department'], $targets)) {
  $conn->rollback();
  status_out_json(500, ['ok' => false, 'error' => 'Could not save status targets']);
}
if (!statusDefinitionSaveTabBarPositions($conn, $id, $tabBarPositions)) {
  $conn->rollback();
  status_out_json(500, ['ok' => false, 'error' => 'Could not save tab bar positions']);
}
$conn->commit();

status_out_json(200, [
  'ok' => true,
  'targets' => statusDefinitionNormalizeTargetKeys($targets, $definition['department']),
  'tab_bar' => $tabBar,
  'tab_bar_positions' => $tabBarPositions,
]);
