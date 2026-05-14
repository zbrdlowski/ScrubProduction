<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['name'])) {
    http_response_code(401);
    echo json_encode(['status' => 'expired']);
    exit;
}

echo json_encode(['status' => 'ok']);
exit;