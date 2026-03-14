<?php
require_once __DIR__ . '/../../includes/conn.php';
header('Content-Type: application/json; charset=utf-8');

$res = $conn->query("
  SELECT DISTINCT listed_platform
  FROM listings
  WHERE listed_platform IS NOT NULL AND listed_platform <> ''
  ORDER BY listed_platform ASC
");

$platforms = [];
while($row = $res->fetch_assoc()){
  $platforms[] = $row['listed_platform'];
}

echo json_encode(['ok'=>true,'platforms'=>$platforms]);
?>