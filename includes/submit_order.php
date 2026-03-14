<?php


$selected = $_POST['selected'] ?? [];
$qty = $_POST['qty'] ?? [];
$supplier = $_POST['supplier'] ?? '';

if (empty($selected)) {
    die("No items selected.");
}

$placeholders = implode(',', array_fill(0, count($selected), '?'));
$stmt = $pdo->prepare("SELECT * FROM items WHERE id IN ($placeholders)");
$stmt->execute($selected);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($items as $item) {
    $orderQty = (int)($qty[$item['id']] ?? 0);
    if ($orderQty <= 0) continue;

    $insert = $pdo->prepare("INSERT INTO plastics_orders (
        brand, barcode, name, description, color, quantity_to_order,
        scrubcode, ufo_pn, ufo_barcode, rt_pn, rt_barcode,
        ps_pn, ps_barcode, ac_pn, ac_barcode, other_pn, other_barcode,
        main_supplier
    ) VALUES (
        :brand, :barcode, :name, :description, :color, :quantity_to_order,
        :scrubcode, :ufo_pn, :ufo_barcode, :rt_pn, :rt_barcode,
        :ps_pn, :ps_barcode, :ac_pn, :ac_barcode, :other_pn, :other_barcode,
        :main_supplier
    )");

    $insert->execute([
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

echo "Order submitted successfully.";
