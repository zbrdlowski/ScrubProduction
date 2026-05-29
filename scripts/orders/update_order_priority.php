<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

function out(array $payload): void
{
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['permission'])) {
  out(['ok' => false, 'error' => 'Not logged in']);
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$newPriority = (int) ($_POST['priority'] ?? -1);
$userId = (int) ($_SESSION['user_id'] ?? 0);

$priorityLabels = [
  0  => 'Normal',
  10 => 'Deadline',
  20 => 'Priority',
];

if ($orderId <= 0 || !isset($priorityLabels[$newPriority])) {
  out(['ok' => false, 'error' => 'Invalid priority']);
}

$stmt = $conn->prepare("
  SELECT priority
  FROM orders
  WHERE id = ?
  LIMIT 1
");
if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('i', $orderId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  out(['ok' => false, 'error' => 'Order not found']);
}

$oldPriority = (int) ($row['priority'] ?? 0);

if ($oldPriority === $newPriority) {
  out(['ok' => true, 'unchanged' => true]);
}

$stmt = $conn->prepare("
  UPDATE orders
  SET priority = ?
  WHERE id = ?
  LIMIT 1
");
if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('ii', $newPriority, $orderId);
$stmt->execute();
$stmt->close();

$oldLabel = $priorityLabels[$oldPriority] ?? ('Priority ' . $oldPriority);
$newLabel = $priorityLabels[$newPriority];

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'priority_changed',
  'order',
  $orderId,
  [
    'old' => $oldPriority,
    'new' => $newPriority,
    'old_label' => $oldLabel,
    'new_label' => $newLabel,
  ],
  'Priority changed: ' . $oldLabel . ' -> ' . $newLabel
);

// Badge HTML + row CSS class pre JS in-place update (bez reloadu)
// Normal=badge-success/zelená, Deadline=badge-warning/oranžová, Priority=badge-danger/červená
if ($newPriority >= 20) {
  $badgeClass    = 'badge-danger';
  $priorityEmoji = '🔴';
  $rowClass      = 'order-priority-priority';
} elseif ($newPriority >= 10) {
  $badgeClass    = 'badge-warning';
  $priorityEmoji = '🟠';
  $rowClass      = 'order-priority-deadline';
} else {
  $badgeClass    = 'badge-success';
  $priorityEmoji = '🟢';
  $rowClass      = '';
}

out([
  'ok'             => true,
  'order_id'       => $orderId,
  'priority'       => $newPriority,
  'priority_label' => $newLabel,
  'priority_html'  => '<span class="badge ' . $badgeClass . '">' . $priorityEmoji . ' ' . htmlspecialchars($newLabel) . '</span>',
  'priority_class' => $rowClass,
]);