<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/plastics_access.php';
require_once __DIR__ . '/../includes/add_item_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?page=add_item', true, 303);
    exit;
}

$result = add_item_validate($_POST);
$_SESSION['add_item_form'] = ['input' => $result['input'], 'errors' => $result['errors']];
unset($_SESSION['error'], $_SESSION['success']);

if ($result['errors']) {
    header('Location: ../index.php?page=add_item', true, 303);
    exit;
}

try {
    if (!add_item_insert($pdo, $result['data'])) {
        $_SESSION['add_item_form']['errors']['barcode'] = 'An item with this barcode already exists. Use a different barcode.';
        header('Location: ../index.php?page=add_item', true, 303);
    } else {
        unset($_SESSION['add_item_form']);
        $_SESSION['success'] = 'Item added successfully!';
        header('Location: ../index.php?page=display_stock', true, 303);
    }
} catch (Throwable $e) {
    error_log('add_item save: ' . $e->getMessage());
    $_SESSION['error'] = 'The item could not be saved. Your values have been kept. Please try again; if the problem persists, contact the administrator.';
    header('Location: ../index.php?page=add_item', true, 303);
}
exit;