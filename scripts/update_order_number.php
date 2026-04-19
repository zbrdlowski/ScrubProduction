<?php
require_once '../includes/conn.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request');
}

$ids = $_POST['ids'] ?? [];
$orderNumber = trim($_POST['order_number'] ?? '');

if (!is_array($ids) || empty($ids) || $orderNumber === '') {
    http_response_code(400);
    exit('Missing data');
}

// Keep only numeric IDs
$ids = array_filter($ids, function($id) {
    return ctype_digit((string)$id);
});

if (empty($ids)) {
    http_response_code(400);
    exit('Invalid IDs');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

$sql = "UPDATE plastics_orders SET order_number = ? WHERE id IN ($placeholders)";
$stmt = $pdo->prepare($sql);

$params = array_merge([$orderNumber], array_values($ids));

if ($stmt->execute($params)) {
    echo "OK";
} else {
    http_response_code(500);
    echo "DB_ERROR";
}
?>