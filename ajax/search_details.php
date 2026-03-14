<?php
require_once('../includes/conn.php');
$barcode = $_GET['barcode'] ?? '';

$stmt = $pdo->prepare("  SELECT items.*, stock_levels.shelf_name, stock_levels.quantity
  FROM items
  LEFT JOIN stock_levels ON items.id = stock_levels.item_id
  WHERE items.barcode = :barcode");
$stmt->execute(['barcode' => $barcode]);

echo "<table width='100%' class='table table-bordered'>";
echo "<thead><tr><th>Barcode</th><th>Name</th><th>Description</th><th>Color</th><th>Shelf</th><th>Qty</th></tr></thead><tbody>";

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  echo "<tr>
    <td>{$row['barcode']}</td>
    <td>{$row['name']}</td>
    <td>{$row['description']}</td>
    <td>{$row['color']}</td>
    <td>{$row['shelf_name']}</td>
    <td>{$row['quantity']}</td>
  </tr>";
}
echo "</tbody></table>";
?>