<?php
// scripts/get_open_suppliers.php

require_once('../includes/conn.php');

$stmt = $pdo->query("SELECT DISTINCT main_supplier
    FROM plastics_orders
    WHERE status IN ('sent', 'backorder')
      AND main_supplier IS NOT NULL
      AND main_supplier != ''
    ORDER BY main_supplier ASC");

$suppliers = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $suppliers[] = $row['main_supplier'];
}

header('Content-Type: application/json');
echo json_encode($suppliers);
?>