<?php
require_once('../includes/conn.php');

$stmt = $pdo->query("SELECT main_supplier, COUNT(DISTINCT barcode) AS count
  FROM items
  GROUP BY main_supplier
  ORDER BY count DESC
");

$labels = [];
$counts = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $labels[] = $row['main_supplier'] ?: 'Unknown';
  $counts[] = (int)$row['count'];
}

echo json_encode([
  'labels' => $labels,
  'counts' => $counts
]);
?>