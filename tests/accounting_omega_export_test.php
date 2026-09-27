<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/accounting/omega_export_helpers.php';
require_once dirname(__DIR__) . '/scripts/accounting/omega_helpers.php';

function omegaExportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$payload = [
    'source_code' => 'CUSTOM',
    'order_number' => 'SO12345',
    'order_date' => '2026-09-25 08:00:00',
    'customer_name' => 'Žofia Černá',
    'customer_email' => 'zofia@example.test',
    'customer_phone' => '+43 660 123 4567',
    'company' => '',
    'company_id' => '',
    'street' => 'Hauptstraße 1',
    'city' => 'Wien',
    'zip' => '1010',
    'country_code' => 'AT',
    'country_name' => 'Austria',
    'state' => '',
    'payment_method' => 'CUSTOM',
    'shipping_method' => 'FedEx Economy',
    'partner_code' => 'M2602996',
    'total_eur' => 264.0,
    'shipping_eur' => 24.0,
    'exchange_rate' => 1.0,
    'items' => [[
        'description' => 'Testovacia položka',
        'synthetic' => '601',
        'analytic' => '003',
        'qty' => 2.0,
        'unit_gross_eur' => 120.0,
    ]],
];

$partnerRows = omega_export_partner_rows([$payload]);
$invoiceRows = omega_export_invoice_rows([$payload]);
omegaExportAssert(count($partnerRows) === 4, 'Partner export must contain R00 + R01/R02/R03.');
omegaExportAssert(count($invoiceRows) === 4, 'Invoice export must contain R00 + R01/product R02/shipping R02.');
foreach ($partnerRows as $row) {
    omegaExportAssert(count($row) === 45, 'Every partner row must have 45 columns.');
}
foreach ($invoiceRows as $row) {
    omegaExportAssert(count($row) === 94, 'Every invoice row must have 94 columns.');
}
omegaExportAssert($partnerRows[1][23] === 'M2602996', 'Partner code must be stored in T04 column X.');
omegaExportAssert($invoiceRows[1][20] === 'M2602996', 'Partner code must be stored in T01 column U.');
omegaExportAssert($invoiceRows[1][17] === '11', 'T01 record must be an incoming order.');
omegaExportAssert($invoiceRows[2][4] === '100', 'R02 column E must contain unit price without VAT.');
omegaExportAssert($invoiceRows[2][2] === '2', 'R02 quantity must be preserved.');
omegaExportAssert($invoiceRows[1][88] === '-1', 'EU order must be marked as OSS.');

$ansi = omega_export_rows_to_ansi($partnerRows);
omegaExportAssert(strpos($ansi, "\r\n") !== false, 'Output must use CRLF.');
omegaExportAssert(preg_match('//u', $ansi) !== 1, 'Windows-1250 output with Slovak characters must not remain UTF-8.');

$invoiceAnsi = omega_export_rows_to_ansi($invoiceRows);
$temporaryFile = tempnam(sys_get_temp_dir(), 'omega-export-test-');
omegaExportAssert($temporaryFile !== false, 'Temporary test file could not be created.');
file_put_contents($temporaryFile, $invoiceAnsi);
try {
    $parsed = accounting_omega_parse_file($temporaryFile, 'test.txt');
    omegaExportAssert($parsed['encoding'] === 'Windows-1250', 'Generated invoice must be detected as Windows-1250.');
    omegaExportAssert($parsed['invoice_row_count'] === 1, 'Generated invoice must be readable by the OMEGA parser.');
    omegaExportAssert($parsed['item_row_count'] === 2, 'Generated product and shipping lines must be readable.');
} finally {
    @unlink($temporaryFile);
}

echo "accounting_omega_export_test: OK\n";
