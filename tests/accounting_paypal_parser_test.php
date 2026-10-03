<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/accounting/paypal_helpers.php';

function paypal_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$header = [
    'Date', 'Time', 'Name', 'Type', 'Status', 'Currency', 'Gross', 'Fee', 'Net',
    'From Email Address', 'Transaction ID', 'Invoice Number', 'Custom Number',
    'Reference Txn ID', 'Item Title', 'Subject', 'Note', 'Balance Impact',
];
$data = [
    ['25/09/2026', '08:56:55', 'Leonardo Munari', 'Express Checkout Payment', 'Completed', 'EUR', '30.00', '-0.92', '29.08', 'leo@example.test', 'TX-1', '', '', '', 'DEPOSIT - CO000114', 'CO000114', '', 'Credit'],
    ['25/09/2026', '00:07:58', 'Shop Customer', 'Express Checkout Payment', 'Completed', 'EUR', '89.80', '-2.95', '86.85', 'shop@example.test', 'TX-2', 'Shoptet714761-2026002056', '89.80|EUR|2026002056', '', 'Bestellungsnummer 2026002056', '', '', 'Credit'],
];
$temporary = tempnam(sys_get_temp_dir(), 'paypal-test-');
if ($temporary === false) {
    throw new RuntimeException('Temporary file could not be created.');
}
try {
    $handle = fopen($temporary, 'wb');
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $header, ',', '"', '');
    foreach ($data as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    fclose($handle);

    $parsed = accounting_paypal_parse_file($temporary, 'PP.CSV');
    paypal_test_assert(count($parsed['rows']) === 2, 'Unexpected parsed row count');
    paypal_test_assert($parsed['encoding'] === 'UTF-8 BOM', 'BOM encoding was not detected');
    paypal_test_assert($parsed['delimiter'] === ',', 'CSV delimiter was not detected');
    paypal_test_assert($parsed['rows'][0]['transaction_date'] === '2026-09-25', 'Date normalization failed');
    paypal_test_assert($parsed['rows'][0]['transaction_time'] === '08:56:55', 'Time normalization failed');
    paypal_test_assert(abs((float) $parsed['rows'][0]['gross_amount'] - 30.0) < 0.001, 'Gross normalization failed');

    $co = accounting_paypal_extract_reference($parsed['rows'][0]);
    paypal_test_assert($co === ['kind' => 'CO', 'value' => 'CO000114'], 'CO extraction failed');
    $shop = accounting_paypal_extract_reference($parsed['rows'][1]);
    paypal_test_assert($shop === ['kind' => 'ESHOP', 'value' => '2026002056'], 'Shoptet reference extraction failed');
    paypal_test_assert(accounting_paypal_normalize_so('GOSO21708') === 'SO21708', 'SO normalization failed');
    paypal_test_assert(!accounting_paypal_valid_export_reference('CO000114'), 'CO must not be an export reference');
    paypal_test_assert(accounting_paypal_valid_export_reference('SO21662'), 'SO should be an export reference');
} finally {
    @unlink($temporary);
}

echo "accounting PayPal parser: OK\n";
