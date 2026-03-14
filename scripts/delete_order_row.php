<?php
require_once('../includes/conn.php');

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo "ERR";
    exit;
}

// Option A: just delete the row
$stmt = $pdo->prepare("DELETE FROM plastics_orders WHERE id = :id LIMIT 1");
$ok = $stmt->execute(['id' => $id]);

if ($ok) {
    echo "OK";
} else {
    echo "ERR";
}
?>
