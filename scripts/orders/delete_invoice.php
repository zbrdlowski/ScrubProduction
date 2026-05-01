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

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  out(['ok' => false, 'error' => 'Missing invoice id']);
}

$stmt = $conn->prepare("UPDATE order_invoices
  SET deleted_at = NOW()
  WHERE id = ?
  LIMIT 1
");

if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();

out(['ok' => true]);