<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__,2).'/includes/conn.php';

if ((int)($_SESSION['permission'] ?? 0) < 400) {
  echo json_encode(['ok'=>false,'error'=>'No permission']);
  exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$invoice = trim($_POST['invoice_number'] ?? '');

if (!$orderId || $invoice === '') {
  echo json_encode(['ok'=>false,'error'=>'Missing data']);
  exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);

$stmt = $conn->prepare("
  INSERT INTO order_invoices (order_id, invoice_number, created_by)
  VALUES (?, ?, ?)
");

$stmt->bind_param('isi', $orderId, $invoice, $userId);
$stmt->execute();

echo json_encode(['ok'=>true]);