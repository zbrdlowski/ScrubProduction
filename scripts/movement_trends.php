<?php
require_once('../includes/conn.php');

$stmt = $pdo->query("SELECT 
    DATE_FORMAT(timestamp, '%Y-%m') AS month,
    movement_type,
    COUNT(*) AS count
  FROM inventory_movements
  GROUP BY month, movement_type
  ORDER BY month DESC
  LIMIT 18
");

$raw = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $raw[$row['month']][$row['movement_type']] = (int)$row['count'];
}

// Prepare final arrays
$labels = array_reverse(array_keys($raw));
$in = $out = $relocate = [];

foreach ($labels as $month) {
  $in[] = $raw[$month]['IN'] ?? 0;
  $out[] = $raw[$month]['OUT'] ?? 0;
  $relocate[] = $raw[$month]['MOVE'] ?? 0;
}

echo json_encode([
  'labels' => $labels,
  'in' => $in,
  'out' => $out,
  'relocate' => $relocate
]);
?>