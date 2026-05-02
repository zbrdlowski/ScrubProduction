<?php
declare(strict_types=1);

function sync_order_categories(mysqli $conn, int $orderId): void {
  $map = [
    'G' => ['GRAPHICS'],
    'P' => ['PLASTICS'],
    'T' => ['PLASTICS', 'GRAPHICS'],
    'M' => ['PLASTICS', 'GRAPHICS'],
    'S' => ['SEATCOVER'],
    'F' => ['FITTING'],
  ];

  $cats = [];

  $stmt = $conn->prepare("
    SELECT DISTINCT UPPER(TRIM(item_type_code)) AS type_code
    FROM order_items
    WHERE order_id = ?
      AND deleted_at IS NULL
      AND item_type_code IS NOT NULL
      AND TRIM(item_type_code) <> ''
  ");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($r = $res->fetch_assoc()) {
    $type = (string)$r['type_code'];
    foreach (str_split($type) as $ch) {
      if (isset($map[$ch])) {
        foreach ($map[$ch] as $cat) {
          $cats[$cat] = true;
        }
      }
    }
  }

  $stmt->close();

  $del = $conn->prepare("DELETE FROM order_categories WHERE order_id = ?");
  $del->bind_param('i', $orderId);
  $del->execute();
  $del->close();

  if (!$cats) return;

  $ins = $conn->prepare("
    INSERT INTO order_categories (order_id, category_id)
    SELECT ?, id
    FROM categories
    WHERE code = ?
    LIMIT 1
  ");

  foreach (array_keys($cats) as $code) {
    $ins->bind_param('is', $orderId, $code);
    $ins->execute();
  }

  $ins->close();
}