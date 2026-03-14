<?php
session_start();
require_once('../includes/conn.php');

$operator = $_SESSION['name'] ?? 'emergency input';

$sql = "SELECT im.*, it.name, it.description, it.color
        FROM inventory_movements im
        LEFT JOIN items it ON im.item_id = it.id
        WHERE im.operator IN (:operator, 'emergency input')
        ORDER BY im.timestamp DESC
        LIMIT 10";

$stmt = $pdo->prepare($sql);
$stmt->execute(['operator' => $operator]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($rows);
exit;
?>