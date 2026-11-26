<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload): void
{
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['permission'])) {
  out(403, ['ok' => false, 'error' => 'Not logged in']);
}

$base = dirname(__DIR__, 2);
require_once $base . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once $base . '/includes/orders_workflow_helpers.php';

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
  out(400, ['ok' => false, 'error' => 'Invalid order_id']);
}

// Načítame aktuálny traffic_summary_json priamo z DB
$stmt = $conn->prepare("SELECT traffic_summary_json FROM orders WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  out(404, ['ok' => false, 'error' => 'Order not found']);
}

// Use the same production groups as status policies, including legacy coupon filtering.
$traffic = refreshOrderTrafficSummary($conn, $orderId);
$summary = $traffic['summary'];

out(200, ['ok' => true, 'order_id' => $orderId, 'summary' => $summary]);
