<?php
session_start();
require_once '../db.php'; // adjust path

header('Content-Type: application/json');

$operator = $_SESSION['name'] ?? 'emergency input';

$sql = "SELECT 
    im.timestamp,
    im.operator,
    im.order_id,
    im.quantity,
    im.movement_type,
    it.barcode,
    im.shelf_name
FROM inventory_movements im
LEFT JOIN items it ON im.item_id = it.id
WHERE im.operator IN (:operator, 'emergency input')
ORDER BY im.timestamp DESC
LIMIT 10";

$stmt = $pdo->prepare($sql);
$stmt->execute(['operator' => $operator]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>