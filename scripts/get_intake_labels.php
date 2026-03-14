<?php
require_once '../includes/conn.php';

$stmt = $pdo->query("SELECT
        q.id,
        q.intake_ref,
        q.barcode,
        q.item_name,
        i.description,
        i.color,
        q.quantity,
        q.shelf_location,
        q.supplier,
        q.created_at
    FROM intake_label_queue q
    LEFT JOIN items i 
        ON i.barcode = q.barcode
    WHERE q.printed = 0
    ORDER BY q.created_at ASC
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'data' => $rows
]);
?>