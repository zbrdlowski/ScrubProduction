<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$productChartEditorIds = [2, 3, 16, 17, 10]; // explicitne povoleni useri (okrem superadmina)
$productChartUserId = (int)($_SESSION['user_id'] ?? 0);
$productChartPermission = (int)($_SESSION['permission'] ?? 0);
$canEditProductChart = $productChartPermission >= 900 || in_array($productChartUserId, $productChartEditorIds, true);

if ($productChartUserId <= 0) {
    out(403, ['ok' => false, 'error' => 'Not logged in']);
}

// Product chart editacia je obmedzena na superadmina a povolenych editorov.
if (!$canEditProductChart) {
    out(403, ['ok' => false, 'error' => 'Insufficient permission']);
}

// scripts/ a includes/ sú súrodenci — __DIR__ je darkscrub/scripts
$connFile = dirname(__DIR__) . '/includes/conn.php';
if (!is_file($connFile)) {
    out(500, ['ok' => false, 'error' => 'conn.php not found: ' . $connFile]);
}
require_once $connFile;

require_once __DIR__ . '/product_chart_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(405, ['ok' => false, 'error' => 'POST required.']);
}

try {
    $result = pcSaveModelDetails($pdo, $_POST, $productChartUserId);
    out(200, $result);
} catch (Throwable $error) {
    $status = (int)$error->getCode();
    if (!in_array($status, [400, 404, 409], true)) {
        error_log('Product chart save failed: ' . $error->getMessage());
        out(500, ['ok' => false, 'error' => 'Changes could not be saved. Nothing was saved; please try again.']);
    }
    out($status, ['ok' => false, 'error' => $error->getMessage()]);
}
