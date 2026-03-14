<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

// Include your shared DB connection
include('../includes/conn.php'); // This defines $pdo

try {
    $sql = "SELECT dk.id, dk.position, dk.timestamp, dk.barcode, i.name AS name, i.description AS description, i.color,
               dk.missing_barcode, dk.order_number, dk.quantity, dk.user AS user, i.main_supplier,
               im.name AS missing_name, im.description AS missing_description
        FROM disassembled_kits dk
        LEFT JOIN items i ON dk.barcode = i.barcode
        LEFT JOIN items im ON dk.missing_barcode = im.barcode
        ORDER BY dk.position ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['data' => $data]);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

?>