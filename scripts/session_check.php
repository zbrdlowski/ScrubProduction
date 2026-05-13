// scripts/session_check.php
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || empty($_SESSION['name'])) {
    http_response_code(401);
    echo json_encode(['status' => 'expired']);
    exit;
}

echo json_encode(['status' => 'ok']);