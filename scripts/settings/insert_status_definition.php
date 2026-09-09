<?php
require_once __DIR__ . '/status_definition_ajax_bootstrap.php';

$groupKey = status_post_string('group_key', 20);
$group = status_parse_group($groupKey);

$code = strtoupper(status_post_string('code', 64));
$label = status_post_string('label', 120);
$color = status_post_string('color', 20, false);
$sortOrder = status_post_int('sort_order', 0);
$active = status_post_int('active', 1) === 1 ? 1 : 0;
$tabBar = status_post_int('tab_bar', 0) === 1 ? 1 : 0;
$targets = $_POST['targets'] ?? ['ALL'];

$scope = $group['scope'];
$department = $group['department'];
$tabBarPositions = statusDefinitionNormalizeTabBarPositionIds(
  $conn,
  $_POST['tab_bar_positions'] ?? statusDefinitionDefaultTabBarPositionIds($conn, $scope, $department)
);
$color = $color !== '' ? $color : null;

if ($tabBar === 1 && !$tabBarPositions) {
  status_out_json(400, ['ok' => false, 'error' => 'Select at least one Tab Bar Applies To position.']);
}

$conn->begin_transaction();
$hasTabBarColumn = statusDefinitionHasTabBarColumn($conn);
if ($hasTabBarColumn) {
  $stmt = $conn->prepare("
    INSERT INTO status_definitions (scope, department, code, label, color, sort_order, active, tab_bar)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  ");
} else {
  $stmt = $conn->prepare("
    INSERT INTO status_definitions (scope, department, code, label, color, sort_order, active)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
}
if (!$stmt) {
  $conn->rollback();
  status_out_json(500, ['ok' => false, 'error' => mysqli_error($conn)]);
}

if ($hasTabBarColumn) {
  $stmt->bind_param('sssssiii', $scope, $department, $code, $label, $color, $sortOrder, $active, $tabBar);
} else {
  $stmt->bind_param('sssssii', $scope, $department, $code, $label, $color, $sortOrder, $active);
}
if (!$stmt->execute()) {
  $conn->rollback();
  status_out_json(500, ['ok' => false, 'error' => $stmt->error]);
}

$id = (int) $stmt->insert_id;
$stmt->close();

if (!statusDefinitionSaveTargets($conn, $id, $scope, $department, $targets)) {
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
  'id' => $id,
  'targets' => statusDefinitionNormalizeTargetKeys($targets, $department),
  'tab_bar' => $tabBar,
  'tab_bar_positions' => $tabBarPositions,
]);
