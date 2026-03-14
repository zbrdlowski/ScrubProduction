<?php
 session_start();
include('../includes/conn.php');
       if(isset($_SESSION['error'])){
          echo "
            <div class='alert alert-danger alert-dismissible'>
              <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
              <h4><i class='icon fa fa-warning'></i> Nie je to dobré!</h4>
              ".$_SESSION['error']."
            </div>
          ";
          unset($_SESSION['error']);
        }
        if(isset($_SESSION['success'])){
          echo "
            <div class='alert alert-success alert-dismissible'>
              <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
              <h4><i class='icon fa fa-check'></i> Podarilo sa!</h4>
              ".$_SESSION['success']."
            </div>
          ";
          unset($_SESSION['success']);
        }


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'brand', 'barcode', 'scrubcode', 'name', 'description', 'color',
        'optimum', 'moq', 'main_supplier', 'baseline',
        'ufo_pn', 'ufo_barcode', 'rt_pn', 'rt_barcode',
        'ps_pn', 'ps_barcode', 'ac_pn', 'ac_barcode',
        'other_pn', 'other_barcode'
    ];

    $data = [];
    foreach ($fields as $field) {
        $data[$field] = $_POST[$field] ?? null;
    }

    if (empty($data['barcode']) || empty($data['name'])) {
        $_SESSION['error'] = "Barcode and Name are required fields!";
        header('location: ../index.php?page=add_item');
        exit;
    }

    $placeholders = implode(', ', array_map(fn($f) => ":$f", $fields));
    $columns = implode(', ', $fields);

    $stmt = $pdo->prepare("INSERT INTO items ($columns) VALUES ($placeholders)");

    try {
        $stmt->execute($data);
        $_SESSION['success'] = 'Item added successfully!';
        header('location: ../index.php?page=display_stock');
    } catch (Exception $e) {
        $_SESSION['error'] = "Error adding item: " . $e->getMessage();
        header('location: ../index.php?page=add_item');
    }
}
?>