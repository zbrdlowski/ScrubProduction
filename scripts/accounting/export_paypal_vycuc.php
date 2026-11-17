<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/paypal_helpers.php';

if (!accounting_payout_user_can_access('accounting.export')) {
    http_response_code(403);
    exit('Forbidden');
}
if (!($pdo instanceof PDO) || !accounting_paypal_schema_ready($pdo)) {
    http_response_code(503);
    exit('PayPal accounting schema is not installed.');
}

$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    http_response_code(400);
    exit('Invalid month.');
}
$from = $month . '-01';
$to = date('Y-m-d', strtotime($from . ' +1 month'));

$rows = accounting_paypal_vycuc_rows($pdo, $from, $to);

$filename = 'paypal-vycuc-' . $month . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'wb');
accounting_paypal_write_vycuc($out, $rows);
fclose($out);
