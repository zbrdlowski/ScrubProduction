<?php
include ('../includes/conn.php');
 session_start();

$selected = $_POST['selected'] ?? [];
$qty = $_POST['qty'] ?? [];
$supplier = $_POST['supplier'] ?? '';
$orderNumber = $_POST['order_number'] ?? '';
if (empty($selected)) {
    die("No items selected.");
}

$placeholders = implode(',', array_fill(0, count($selected), '?'));
$stmt = $pdo->prepare("SELECT * FROM items WHERE id IN ($placeholders)");
$stmt->execute($selected);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($selected as $itemId) {
    $orderQty = (int)($qty[$itemId] ?? 0);
    if ($orderQty <= 0) continue;

    // Fetch item details from DB
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) continue;

    $insert = $pdo->prepare("INSERT INTO plastics_orders (
      order_number, brand, barcode, name, description, color, quantity_to_order,
      scrubcode, ufo_pn, ufo_barcode, rt_pn, rt_barcode,
      ps_pn, ps_barcode, ac_pn, ac_barcode, other_pn, other_barcode,
      main_supplier
    ) VALUES (
      :order_number, :brand, :barcode, :name, :description, :color, :quantity_to_order,
      :scrubcode, :ufo_pn, :ufo_barcode, :rt_pn, :rt_barcode,
      :ps_pn, :ps_barcode, :ac_pn, :ac_barcode, :other_pn, :other_barcode,
      :main_supplier
    )");

    $insert->execute([
      'order_number' => $orderNumber,
      'brand' => $item['brand'],
      'barcode' => $item['barcode'],
      'name' => $item['name'],
      'description' => $item['description'],
      'color' => $item['color'],
      'quantity_to_order' => $orderQty,
      'scrubcode' => $item['scrubcode'],
      'ufo_pn' => $item['ufo_pn'],
      'ufo_barcode' => $item['ufo_barcode'],
      'rt_pn' => $item['rt_pn'],
      'rt_barcode' => $item['rt_barcode'],
      'ps_pn' => $item['ps_pn'],
      'ps_barcode' => $item['ps_barcode'],
      'ac_pn' => $item['ac_pn'],
      'ac_barcode' => $item['ac_barcode'],
      'other_pn' => $item['other_pn'],
      'other_barcode' => $item['other_barcode'],
      'main_supplier' => $item['main_supplier']
    ]);
}


$_SESSION['success'] = 'Order ' . $orderNumber .' for: '.$supplier .' Succesfully created';
        header('location: ../index.php?page=order_prepare&supplier=');
