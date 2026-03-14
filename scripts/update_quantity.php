<?php
require_once('../includes/conn.php');

$id = $_POST['id'] ?? 0;
$qty = $_POST['qty'] ?? null;

if ($id && $qty !== null) {
    $stmt = $pdo->prepare("UPDATE plastics_orders SET quantity_to_order = :qty WHERE id = :id");
    $stmt->execute(['qty' => $qty, 'id' => $id]);
    echo "OK";
}
?>
