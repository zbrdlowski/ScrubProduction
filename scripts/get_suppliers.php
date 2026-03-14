<?php
require_once '../includes/conn.php';

$term = $_GET['term'] ?? '';

$stmt = $pdo->prepare("
    SELECT DISTINCT main_supplier
    FROM items
    WHERE main_supplier IS NOT NULL
      AND main_supplier != ''
      AND main_supplier LIKE :term
    ORDER BY main_supplier
    LIMIT 20
");

$stmt->execute([
    'term' => '%' . $term . '%'
]);

$suppliers = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode($suppliers);
?>