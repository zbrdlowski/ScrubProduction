<?php
session_start();
require "../includes/conn.php";

//----------------------------
// INPUTS
//----------------------------
$barcode   = trim($_POST['barcode'] ?? '');
$qty       = intval($_POST['quantity_to_order'] ?? 0);
$existing  = trim($_POST['existing_order'] ?? '');
$new       = trim($_POST['new_order_number'] ?? '');
$new_sup   = trim($_POST['new_order_supplier'] ?? '');
$note      = trim($_POST['order_note'] ?? '');
$return    = $_POST['return_url'] ?? '../index.php?page=items';

//--------------------------------------------------
// Determine final order number
//--------------------------------------------------
$order_number = $existing ?: $new;

if (!$order_number) {
    $_SESSION['error'] = "You must choose or create an order number.";
    header("Location: index.php?page=items");
    exit;
}

//--------------------------------------------------
// Fetch item
//--------------------------------------------------
// Fetch item
$stmt = $conn->prepare("SELECT * FROM items WHERE barcode=? LIMIT 1");
$stmt->bind_param("s", $barcode);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc(); 

if (!$item) {
    $_SESSION['error'] = "Item not found!";
    header("Location: index.php?page=items");
    exit;
}

// Determine supplier
if (!empty($new_sup)) {
    // User selected a supplier for a new order
    $main_supplier = $new_sup;
} else {
    // Default supplier from the item
    $main_supplier = $item['main_supplier'] ?? '';
}

//--------------------------------------------------
// Prepare values with safe defaults
//--------------------------------------------------
$fields = [
    'brand','name','description','color','scrubcode',
    'ufo_pn','ufo_barcode','rt_pn','rt_barcode',
    'ps_pn','ps_barcode','ac_pn','ac_barcode',
    'other_pn','other_barcode','main_supplier'
];

$data = [];
foreach ($fields as $f) {
    $data[$f] = $item[$f] ?? '';
}

// supplier override
if (empty($_POST['existing_order']) && !empty($new_sup)) {
    $data['main_supplier'] = $new_sup;
}

//--------------------------------------------------
// INSERT INTO plastics_orders
//--------------------------------------------------
$sql = "INSERT INTO plastics_orders 
(
    order_number, brand, barcode, name, description, color,
    quantity_to_order, scrubcode,
    ufo_pn, ufo_barcode, rt_pn, rt_barcode,
    ps_pn, ps_barcode, ac_pn, ac_barcode,
    other_pn, other_barcode, main_supplier, note, status
)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'created')";

$stmt = $conn->prepare($sql);

// Assign all values to variables
$order_number_var = $order_number;
$brand_var        = $item['brand'] ?? '';
$barcode_var      = $item['barcode'];
$name_var         = $item['name'] ?? '';
$desc_var         = $item['description'] ?? '';
$color_var        = $item['color'] ?? '';
$scrubcode_var    = $item['scrubcode'] ?? '';
$ufo_pn_var       = $item['ufo_pn'] ?? '';
$ufo_barcode_var  = $item['ufo_barcode'] ?? '';
$rt_pn_var        = $item['rt_pn'] ?? '';
$rt_barcode_var   = $item['rt_barcode'] ?? '';
$ps_pn_var        = $item['ps_pn'] ?? '';
$ps_barcode_var   = $item['ps_barcode'] ?? '';
$ac_pn_var        = $item['ac_pn'] ?? '';
$ac_barcode_var   = $item['ac_barcode'] ?? '';
$other_pn_var     = $item['other_pn'] ?? '';
$other_barcode_var= $item['other_barcode'] ?? '';
$main_supplier_var= !empty($new_sup) ? $new_sup : ($item['main_supplier'] ?? '');
$note_var         = $note;

// Bind parameters using variables
$stmt->bind_param(
    "ssssssssssssssssssss",
    $order_number_var,
    $brand_var,
    $barcode_var,
    $name_var,
    $desc_var,
    $color_var,
    $qty,
    $scrubcode_var,
    $ufo_pn_var, $ufo_barcode_var,
    $rt_pn_var, $rt_barcode_var,
    $ps_pn_var, $ps_barcode_var,
    $ac_pn_var, $ac_barcode_var,
    $other_pn_var, $other_barcode_var,
    $main_supplier_var,
    $note_var
);

$stmt->execute();

//--------------------------------------------------
// DONE
//--------------------------------------------------
$_SESSION['success'] = "Item added to order <b>$order_number</b>.";
header("Location: $return");
exit;
?>