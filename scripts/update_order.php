<?php
require_once('../includes/conn.php');

$id = $_POST['id'] ?? '';
$order = $_POST['order'] ?? '';

if (!$id || !$order) {
    echo "ERROR";
    exit;
}

$stmt = $pdo->prepare("UPDATE inventory_movements SET order_id = :order WHERE id = :id LIMIT 1");
$stmt->execute([
    'order' => $order,
    'id' => $id
]);

echo "OK";
?>