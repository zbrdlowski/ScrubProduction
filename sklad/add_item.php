<?php
include('db.php'); // Make sure your DB connection is set up correctly

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the data from the form
    $barcode = $_POST['barcode'];
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = $_POST['price'];
    $quantity = $_POST['quantity'];

    // Basic validation
    if (empty($barcode) || empty($name)) {
        echo "Barcode and Name are required fields!";
        exit;
    }

    // Prepare SQL statement to insert a new item
    $stmt = $pdo->prepare('
        INSERT INTO items (barcode, name, description, price, quantity)
        VALUES (:barcode, :name, :description, :price, :quantity)
    ');

    // Execute the statement
    try {
        $stmt->execute([
            'barcode' => $barcode,
            'name' => $name,
            'description' => $description,
            'price' => $price,
            'quantity' => $quantity
        ]);

        echo "Item added successfully!";
    } catch (Exception $e) {
        echo "Error adding item: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Item</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        .form-container {
            max-width: 500px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .form-container label {
            display: block;
            margin: 10px 0 5px;
        }
        .form-container input {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .form-container button {
            padding: 10px 15px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .form-container button:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>

<h2>Add New Item to Warehouse</h2>

<div class="form-container">
    <form action="add_item.php" method="POST">
        <label for="barcode">Item Barcode:</label>
        <input type="text" id="barcode" name="barcode" required>

        <label for="name">Item Name:</label>
        <input type="text" id="name" name="name" required>

        <label for="description">Item Description:</label>
        <input type="text" id="description" name="description">

        <label for="price">Item Price:</label>
        <input type="number" step="0.01" id="price" name="price">

        <label for="quantity">Item Quantity:</label>
        <input type="number" id="quantity" name="quantity" required>

        <button type="submit">Add Item</button>
    </form>
</div>

</body>
</html>