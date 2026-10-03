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
$blockers = $pdo->prepare('
    SELECT COUNT(*)
    FROM accounting_paypal_transactions
    WHERE transaction_date >= ? AND transaction_date < ?
      AND balance_impact IS NOT NULL AND balance_impact <> \'\'
      AND transaction_type <> \'User Initiated Withdrawal\'
      AND (
          export_order_number IS NULL OR export_order_number = \'\'
          OR match_confidence < 90
      )
');
$blockers->execute([$from, $to]);
$blockerCount = (int) $blockers->fetchColumn();
if ($blockerCount > 0) {
    http_response_code(409);
    exit('Export is blocked: ' . $blockerCount . ' payment rows still require an SO or manual confirmation.');
}
$stmt = $pdo->prepare('
    SELECT raw_json, export_order_number
    FROM accounting_paypal_transactions
    WHERE transaction_date >= ? AND transaction_date < ?
    ORDER BY transaction_date, transaction_time, id
');
$stmt->execute([$from, $to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    http_response_code(404);
    exit('No PayPal rows for selected month.');
}
$first = json_decode((string) $rows[0]['raw_json'], true);
if (!is_array($first) || !$first) {
    http_response_code(500);
    exit('Stored PayPal row is invalid.');
}
$header = array_keys($first);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="paypal-accounting-' . $month . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $header, ',', '"', '');
foreach ($rows as $stored) {
    $raw = json_decode((string) $stored['raw_json'], true);
    if (!is_array($raw)) {
        continue;
    }
    if (!empty($stored['export_order_number'])) {
        $raw['Invoice Number'] = (string) $stored['export_order_number'];
    }
    $values = [];
    foreach ($header as $column) {
        $values[] = (string) ($raw[$column] ?? '');
    }
    fputcsv($out, $values, ',', '"', '');
}
fclose($out);
