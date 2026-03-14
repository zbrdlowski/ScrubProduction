<?php
session_start();
include('../includes/conn.php');

try {
    $stmt = $pdo->prepare("DELETE FROM stock_levels WHERE quantity = 0");
    $stmt->execute();

    $deleted = $stmt->rowCount();

    $_SESSION['success'] = "Empty shelf entries cleaned up! ($deleted deleted)";
} catch (Exception $e) {
    $_SESSION['error'] = "Cleanup failed: " . $e->getMessage();
}

header('Location: ../index.php?page=cleanup');
?>