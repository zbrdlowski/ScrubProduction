<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/accounting/omega_helpers.php';

function omega_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function omega_test_r01(string $invoiceNumber, string $date, string $orderNumber, string $paymentType, string $sum): string
{
    $columns = array_fill(0, 105, '');
    $columns[0] = 'R01';
    $columns[1] = $invoiceNumber;
    $columns[2] = 'Žltý zákazník';
    $columns[4] = $date;
    $columns[18] = 'OF';
    $columns[33] = $orderNumber;
    $columns[37] = $paymentType;
    $columns[39] = 'EUR';
    $columns[42] = $sum;
    return implode("\t", $columns);
}

function omega_test_r02(string $description, string $quantity, string $unit, string $unitPrice): string
{
    $columns = array_fill(0, 59, '');
    $columns[0] = 'R02';
    $columns[1] = $description;
    $columns[2] = $quantity;
    $columns[3] = $unit;
    $columns[4] = $unitPrice;
    return implode("\t", $columns);
}

$utf8 = implode("\r\n", [
    "R00\tT01",
    omega_test_r01('20260001', '01.03.2026', 'SO10001', 'Shoptet', '123,45'),
    omega_test_r02('Grafická sada', '2', 'ks', '50,0000'),
    omega_test_r02('Shipping & handling', '1', 'ks', '23,4500'),
    omega_test_r01('20260002', '02.03.2026', 'SO10001', 'Prevodný príkaz', '40.00'),
    omega_test_r02('Depozit', '1', 'ks', '40.00'),
]) . "\r\n";

$encoded = iconv('UTF-8', 'CP1250//TRANSLIT', $utf8);
omega_test_assert(is_string($encoded), 'Windows-1250 fixture conversion failed');

$path = tempnam(sys_get_temp_dir(), 'omega-parser-');
omega_test_assert(is_string($path), 'Temporary fixture could not be created');

try {
    file_put_contents($path, $encoded);
    $parsed = accounting_omega_parse_file($path, 'fixture.txt');

    omega_test_assert($parsed['encoding'] === 'Windows-1250', 'Encoding detection failed');
    omega_test_assert($parsed['source_row_count'] === 5, 'Unexpected source row count');
    omega_test_assert($parsed['invoice_row_count'] === 2, 'Unexpected R01 count');
    omega_test_assert($parsed['item_row_count'] === 3, 'Unexpected R02 count');
    omega_test_assert($parsed['ignored_row_count'] === 0, 'Unexpected ignored rows');

    $first = $parsed['invoices'][0];
    omega_test_assert($first['invoice_number'] === '20260001', 'Invoice number mapping failed');
    omega_test_assert($first['issue_date'] === '2026-03-01', 'Invoice date mapping failed');
    omega_test_assert($first['order_number'] === 'SO10001', 'Order number mapping failed');
    omega_test_assert($first['payment_type'] === 'Shoptet', 'Payment type mapping failed');
    omega_test_assert(abs($first['total_amount'] - 123.45) < 0.001, 'Invoice sum mapping failed');
    omega_test_assert($first['customer_name'] === 'Žltý zákazník', 'Windows-1250 text conversion failed');
    omega_test_assert($first['item_count'] === 2, 'R02 grouping failed');
    omega_test_assert($first['items'][0]['description'] === 'Grafická sada', 'R02 description mapping failed');
    omega_test_assert(abs($first['items'][0]['quantity'] - 2.0) < 0.001, 'R02 quantity mapping failed');
    omega_test_assert(abs($first['items'][0]['unit_price_without_vat'] - 50.0) < 0.001, 'R02 unit price mapping failed');
    omega_test_assert($parsed['invoices'][1]['order_number'] === 'SO10001', 'Multiple invoices per order must be retained');
    omega_test_assert($first['source_hash'] !== $parsed['invoices'][1]['source_hash'], 'Invoice source hashes must differ');
} finally {
    if (is_string($path) && is_file($path)) {
        unlink($path);
    }
}

echo "accounting OMEGA parser: OK\n";
