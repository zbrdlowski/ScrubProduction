<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/tatra_helpers.php';
if (!accounting_payout_user_can_access('accounting.export')) {
    http_response_code(403);
    exit('Forbidden');
}
if (!($pdo instanceof PDO) || !accounting_tatra_schema_ready($pdo)) {
    http_response_code(503);
    exit('Tatra accounting schema is not installed.');
}
$month = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
    http_response_code(400);
    exit('Invalid month.');
}
$source = (string) ($_GET['source'] ?? 'all');
if (!in_array($source, ['all', 'shoptet', 'bank'], true)) {
    http_response_code(400);
    exit('Invalid source.');
}
$rows = accounting_tatra_export_rows($pdo, $month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month')), $source);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="tatra-vycuc-' . $month . ($source === 'all' ? '' : '-' . $source) . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'wb');
accounting_tatra_write_vycuc($out, $rows);
fclose($out);
