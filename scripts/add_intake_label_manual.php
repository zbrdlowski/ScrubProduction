<?php
require_once '../includes/conn.php';

$barcode = trim($_POST['barcode'] ?? '');
$intake_ref = trim($_POST['intake_ref'] ?? '');

if ($barcode === '' || $intake_ref === '') {
    http_response_code(400);
    echo 'Barcode and Intake Reference are required.';
    exit;
}

// Fetch item info
$stmt = $pdo->prepare("SELECT name, main_supplier
    FROM items
    WHERE barcode = :barcode
    LIMIT 1
");
$stmt->execute(['barcode' => $barcode]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    http_response_code(404);
    echo 'Item not found.';
    exit;
}

// Insert label queue entry
$stmt = $pdo->prepare("INSERT INTO intake_label_queue
    (intake_ref, barcode, item_name, quantity, shelf_location, supplier)
    VALUES
    (:intake_ref, :barcode, :item_name, 1, 'A010', :supplier)
");
$stmt->execute([
    'intake_ref' => $intake_ref,
    'barcode' => $barcode,
    'item_name' => $item['name'],
    'supplier' => $item['main_supplier']
]);

echo 'OK';
?>