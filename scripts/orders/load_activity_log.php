<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';

function out(array $payload): void {
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if ((int)($_SESSION['permission'] ?? 0) < 400) {
  out(['ok' => false, 'error' => 'No permission']);
}

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$orderId = (int)($_POST['order_id'] ?? 0);
$offset = max(0, (int)($_POST['offset'] ?? 0));

if ($orderId <= 0) {
  out(['ok' => false, 'error' => 'Invalid order_id']);
}

$limit = 30;

$stmt = $conn->prepare("SELECT
    oa.id,
    oa.action,
    oa.entity_type,
    oa.entity_id,
    oa.payload,
    oa.note,
    oa.created_at,
    CONCAT(e.firstname, ' ', e.lastname) AS actor_name
  FROM order_activity oa
  LEFT JOIN employees e ON e.id = oa.actor_employee_id
  WHERE oa.order_id = ?
  ORDER BY oa.id DESC
  LIMIT ? OFFSET ?
");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('iii', $orderId, $limit, $offset);
$stmt->execute();
$res = $stmt->get_result();

$html = '';

while ($a = $res->fetch_assoc()) {
  $html .= '<div class="border-bottom py-1 activity-log-row">';
  $html .= '<span class="text-muted">' . h($a['created_at']) . '</span>';
  $html .= ' — ';
  $html .= '<b>' . h($a['actor_name'] ?: 'System') . '</b>';
  $html .= ' : ';
  $html .= '<span>' . h($a['note'] ?: $a['action']) . '</span>';
  $html .= '</div>';
}

$stmt->close();

out([
  'ok' => true,
  'html' => $html,
  'count' => substr_count($html, 'activity-log-row')
]);