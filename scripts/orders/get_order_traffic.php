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

$summaryRaw = (string)($row['traffic_summary_json'] ?? '');
$summary = json_decode($summaryRaw, true);

// Fallback ak traffic_summary_json nie je nastavený — vypočítame z položiek
if (!is_array($summary) || empty($summary)) {
  $fallbackStmt = $conn->prepare("
    SELECT DISTINCT item_type_code
    FROM order_items
    WHERE order_id = ? AND deleted_at IS NULL
      AND item_type_code IS NOT NULL AND item_type_code <> ''
  ");
  $fallbackStmt->bind_param('i', $orderId);
  $fallbackStmt->execute();
  $fbRes = $fallbackStmt->get_result();
  $summary = [];
  while ($fb = $fbRes->fetch_assoc()) {
    $t = strtoupper((string)$fb['item_type_code']);
    if ($t === 'T' || $t === 'M') {
      $summary['G'] = $summary['G'] ?? 'ORANGE';
      $summary['P'] = $summary['P'] ?? 'ORANGE';
    } elseif (in_array($t, ['G', 'F', 'P', 'S'], true)) {
      $summary[$t] = 'ORANGE';
    }
  }
  $fallbackStmt->close();
}

// Prepočítame semafor na základe skutočných statusov položiek
// GREEN = všetky READY/PRINTED/CUT/DONE/COMPLETED
// ORANGE = niektoré v progress (PRINT_QUEUE, PROCESSING, RTP...)
// RED = niektoré stále NEW alebo WAITING
$typeStatusStmt = $conn->prepare("
  SELECT item_type_code, status
  FROM order_items
  WHERE order_id = ? AND deleted_at IS NULL
    AND item_type_code IS NOT NULL AND item_type_code <> ''
");
$typeStatusStmt->bind_param('i', $orderId);
$typeStatusStmt->execute();
$tsRes = $typeStatusStmt->get_result();

$typeStatuses = []; // type => [status, status, ...]
while ($ts = $tsRes->fetch_assoc()) {
  $t = strtoupper((string)$ts['item_type_code']);
  $s = strtoupper((string)($ts['status'] ?? 'NEW'));

  // T a M mapujeme na G a P pre semafor
  if ($t === 'T' || $t === 'M') {
    $typeStatuses['G'][] = $s;
    $typeStatuses['P'][] = $s;
  } elseif (in_array($t, ['G', 'F', 'P', 'S'], true)) {
    $typeStatuses[$t][] = $s;
  }
}
$typeStatusStmt->close();

$greenStatuses  = ['READY', 'PRINTED', 'CUT', 'DONE', 'COMPLETED', 'SHIPPED'];
$orangeStatuses = ['RTP', 'PRINT_QUEUE', 'PROCESSING', 'WAITING'];

$computed = [];
foreach ($typeStatuses as $type => $statuses) {
  $allGreen  = true;
  $anyOrange = false;

  foreach ($statuses as $s) {
    if (!in_array($s, $greenStatuses, true)) {
      $allGreen = false;
    }
    if (in_array($s, $orangeStatuses, true)) {
      $anyOrange = true;
    }
  }

  if ($allGreen) {
    $computed[$type] = 'GREEN';
  } elseif ($anyOrange) {
    $computed[$type] = 'ORANGE';
  } else {
    $computed[$type] = 'RED';
  }
}

// Ak sa podarilo vypočítať, uložíme späť do DB
if (!empty($computed)) {
  $newJson = json_encode($computed, JSON_UNESCAPED_UNICODE);
  $upd = $conn->prepare("UPDATE orders SET traffic_summary_json = ? WHERE id = ?");
  $upd->bind_param('si', $newJson, $orderId);
  $upd->execute();
  $upd->close();
  $summary = $computed;
}

out(200, ['ok' => true, 'order_id' => $orderId, 'summary' => $summary]);
