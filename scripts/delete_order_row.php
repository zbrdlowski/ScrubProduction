<?php
require_once('../includes/conn.php');

$id  = (int)($_POST['id'] ?? 0);
$ids = $_POST['ids'] ?? [];

// ----------------------
// Bulk delete
// ----------------------
if (!empty($ids) && is_array($ids)) {
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function ($v) {
        return $v > 0;
    });

    if (empty($ids)) {
        http_response_code(400);
        echo "ERR";
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM plastics_orders WHERE id IN ($placeholders)");
    $ok = $stmt->execute($ids);

    echo $ok ? "OK" : "ERR";
    exit;
}

// ----------------------
// Single delete
// ----------------------
if ($id > 0) {
    $stmt = $pdo->prepare("DELETE FROM plastics_orders WHERE id = :id LIMIT 1");
    $ok = $stmt->execute(['id' => $id]);

    echo $ok ? "OK" : "ERR";
    exit;
}

http_response_code(400);
echo "ERR";
exit;
?>
