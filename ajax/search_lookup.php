<?php
require_once('../includes/conn.php');
$q = $_GET['q'] ?? '';

if ($q) {
  $stmt = $pdo->prepare("SELECT DISTINCT items.barcode, items.name, stock_levels.shelf_name
    FROM items
    LEFT JOIN stock_levels ON items.id = stock_levels.item_id
    WHERE 
      items.barcode LIKE :q OR
      items.name LIKE :q OR
      stock_levels.shelf_name LIKE :q
    LIMIT 10");
  $stmt->execute(['q' => "%$q%"]);

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<a href='#' class='list-group-item search-item' data-barcode='{$row['barcode']}'>
            <strong>{$row['barcode']}</strong> — {$row['name']} <em>({$row['shelf_name']})</em>
          </a>";
  }
}
?>