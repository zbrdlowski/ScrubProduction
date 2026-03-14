<?php
require_once('../includes/conn.php');
error_log("Requested order: " . $_GET['order_number']);

header('Content-Type: application/json');

$orderNumber = $_GET['order_number'] ?? '';
if (!$orderNumber) {
  echo json_encode(['error' => 'Missing order number']);
  exit;
}

$stmt = $pdo->prepare("SELECT po.barcode, po.quantity_to_order, po.note, COALESCE(i.name, '') AS name
  FROM plastics_orders po
  LEFT JOIN items i ON po.barcode = i.barcode
  WHERE po.order_number = ? AND po.status = 'sent'");
$stmt->execute([$orderNumber]);

$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: show raw output
if (empty($items)) {
  echo json_encode(['error' => 'No items found']);
  exit;
}

echo json_encode($items);