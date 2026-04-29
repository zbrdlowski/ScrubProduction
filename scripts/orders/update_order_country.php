<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['permission']) || (int)$_SESSION['permission'] < 400) {
  out(403, ['ok' => false, 'error' => 'Forbidden']);
}

$base = dirname(__DIR__, 2);
require_once $base . '../includes/conn.php';

$orderId = (int)($_POST['order_id'] ?? 0);
$country = strtoupper(trim((string)($_POST['country'] ?? '')));

if ($orderId <= 0) {
  out(400, ['ok' => false, 'error' => 'Invalid order_id']);
}

if (!preg_match('/^[A-Z]{2}$/', $country)) {
  out(400, ['ok' => false, 'error' => 'Country must be 2-letter code, e.g. GB, US, DE']);
}

if ($country === 'UK') $country = 'GB';
if ($country === 'UM') $country = 'US';
if ($country === 'KX') $country = 'XK';

$stmt = $conn->prepare("
  UPDATE order_addresses
  SET country = ?
  WHERE order_id = ?
    AND UPPER(type) IN ('SHIPPING', 'BILLING')
");

if (!$stmt) {
  out(500, ['ok' => false, 'error' => 'Prepare failed: ' . $conn->error]);
}

$stmt->bind_param('si', $country, $orderId);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

out(200, [
  'ok' => true,
  'country' => $country,
  'affected' => $affected
]);