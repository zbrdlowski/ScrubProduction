<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';

if (!accounting_payout_user_can_access()) {
    http_response_code(403);
    exit('Forbidden');
}

$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    http_response_code(400);
    exit('Invalid month');
}

$from = $month . '-01';
$to = date('Y-m-d', strtotime($from . ' +1 month'));
$stmt = $pdo->prepare('
    SELECT
      MIN(transaction_date) AS transaction_date,
      order_number,
      ROUND(SUM(gross_payout_amount), 2) AS gross_eur,
      ROUND(SUM(fee_payout_amount), 2) AS fee_eur
    FROM accounting_payout_transactions
    WHERE transaction_type = \'ORDER\'
      AND payout_currency = \'EUR\'
      AND transaction_date >= :from_date
      AND transaction_date < :to_date
      AND order_number IS NOT NULL
      AND order_number <> \'\'
    GROUP BY order_number
    ORDER BY MIN(transaction_date), order_number
');
$stmt->execute([':from_date' => $from, ':to_date' => $to]);

$filename = 'ebay-vycuc-' . $month . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Date', 'Suma', 'Poplatok', 'Order Number', 'eBay', 'IBAN partnera'], ';');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        date('d.m.Y', strtotime((string) $row['transaction_date'])),
        number_format((float) $row['gross_eur'], 2, ',', ''),
        number_format((float) $row['fee_eur'], 2, ',', ''),
        (string) $row['order_number'],
        'eBay',
        '',
    ], ';');
}
fclose($out);
