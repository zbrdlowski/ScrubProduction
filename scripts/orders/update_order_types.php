<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

function out(array $payload): void {
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if ((int)($_SESSION['permission'] ?? 0) < 300) {
  out(['ok' => false, 'error' => 'No permission']);
}

$orderId = (int)($_POST['order_id'] ?? 0);
$types = strtoupper(trim((string)($_POST['types'] ?? '')));
$userId = (int)($_SESSION['user_id'] ?? 0);

$allowed = [
  '',
  'G','P','S','F',
  'GP','GS','GF','PS','PF','SF',
  'GPS','GPF','GSF','PSF',
  'GPSF','GFPS'
];

if ($orderId <= 0 || !in_array($types, $allowed, true)) {
  out(['ok' => false, 'error' => 'Invalid types']);
}

$stmt = $conn->prepare("
  SELECT manual_types_override
  FROM orders
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  out(['ok' => false, 'error' => 'Order not found']);
}

$oldTypes = strtoupper((string)($row['manual_types_override'] ?? ''));

$newDbValue = ($types === '') ? null : $types;

$stmt = $conn->prepare("
  UPDATE orders
  SET manual_types_override = ?,
      manual_types_updated_by = ?,
      manual_types_updated_at = NOW()
  WHERE id = ?
  LIMIT 1
");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('sii', $newDbValue, $userId, $orderId);
$stmt->execute();
$stmt->close();

log_order_activity(
  $conn,
  $orderId,
  $userId,
  'types_override_updated',
  'order',
  $orderId,
  [
    'old' => $oldTypes,
    'new' => $types
  ],
  'Types override changed: ' . ($oldTypes !== '' ? $oldTypes : 'AUTO') . ' → ' . ($types !== '' ? $types : 'AUTO')
);

out(['ok' => true]);