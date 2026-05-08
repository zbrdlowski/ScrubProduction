<?php
session_start();
require_once __DIR__ . '/../../includes/conn.php';

header('Content-Type: application/json');

function out($ok, $data = []) {
    echo json_encode(array_merge(['ok'=>$ok], $data));
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if(!$orderId) out(false, ['error'=>'Invalid ID']);

// kontrola prístupu
$sql = "
SELECT 1
FROM order_assignments
WHERE order_id = ?
AND employee_id = ?
AND removed_at IS NULL
AND role IN ('PRIMARY_GRAPHICS','COLLAB_GRAPHICS')
LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $orderId, $userId);
$stmt->execute();

if(!$stmt->get_result()->fetch_row()){
    out(false, ['error'=>'Access denied']);
}

// detail objednávky (zjednodušený)
$sql = "
SELECT 
    o.order_number,
    o.status,
    cu.name,
    cu.email
FROM orders o
LEFT JOIN customers cu ON cu.id = o.customer_id
WHERE o.id = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

$html = '
<div class="p-2">
    <b>Order:</b> '.htmlspecialchars($order['order_number']).'<br>
    <b>Status:</b> '.htmlspecialchars($order['status']).'<br>
    <b>Customer:</b> '.htmlspecialchars($order['name']).'<br>
    <b>Email:</b> '.htmlspecialchars($order['email']).'
</div>
';

out(true, ['html'=>$html]);