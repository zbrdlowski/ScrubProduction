<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/attendance_summary_service.php';

if ((int)($_SESSION['permission'] ?? 0) < 300) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

try {
    $summary = attendanceSummaryCalculate(
        $conn,
        (int)($_GET['employee_id'] ?? 0),
        (int)($_GET['year'] ?? 0),
        (int)($_GET['month'] ?? 0)
    );

    echo json_encode(['success' => true, 'summary' => $summary], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Attendance summary could not be loaded.'], JSON_UNESCAPED_UNICODE);
}

