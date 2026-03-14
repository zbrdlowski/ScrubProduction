<?php
// scripts/get_supplier_orders.php

require_once('../includes/conn.php');

if (empty($_GET['supplier'])) {
    http_response_code(400);
    echo json_encode([]);
    exit;
}

$supplier = trim($_GET['supplier']);

$stmt = $pdo->prepare("SELECT DISTINCT order_number
    FROM plastics_orders
    WHERE main_supplier = :supplier
      AND status IN ('sent', 'backorder')
      AND order_number IS NOT NULL
      AND order_number != ''
    ORDER BY created_at ASC");

$stmt->execute(['supplier' => $supplier]);

$orders = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $orders[] = ['order_number' => $row['order_number']];
}

header('Content-Type: application/json');
echo json_encode($orders);
?>