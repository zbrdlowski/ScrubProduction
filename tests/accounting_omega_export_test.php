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

$selectionDb = new PDO('sqlite::memory:');
$selectionDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$selectionDb->exec('CREATE TABLE order_sources (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
$selectionDb->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, email TEXT, phone TEXT)');
$selectionDb->exec('CREATE TABLE orders (
    id INTEGER PRIMARY KEY, order_number TEXT, external_order_id TEXT, imported_at TEXT, order_date TEXT,
    currency TEXT, total REAL, financial_total_value REAL, financial_total_currency TEXT,
    payment_method TEXT, shipping_method TEXT, source_meta TEXT, customer_id INTEGER, source_id INTEGER
)');
$selectionDb->exec('CREATE TABLE accounting_omega_export_items (id INTEGER PRIMARY KEY, order_id INTEGER)');
$selectionDb->exec("INSERT INTO order_sources (id, code) VALUES (1, 'CUSTOM'), (2, 'EBAY'), (3, 'SHOPTET')");
$selectionDb->exec("INSERT INTO customers (id, name, email, phone) VALUES (1, 'Test', 'test@example.test', '123')");
$selectionDb->exec("INSERT INTO orders
    (id, order_number, imported_at, order_date, currency, total, financial_total_value, customer_id, source_id)
    VALUES
    (1, 'SO-OLD-CUSTOM', '2025-01-10 10:00:00', '2025-01-10', 'EUR', 100, 100, 1, 1),
    (2, 'SO-EXPORTED-CUSTOM', '2026-10-03 10:00:00', '2026-10-03', 'EUR', 100, 100, 1, 1),
    (3, 'EBAY-IN-RANGE', '2026-10-03 10:00:00', '2026-10-03', 'EUR', 100, 100, 1, 2),
    (4, 'EBAY-OUTSIDE-RANGE', '2026-09-01 10:00:00', '2026-09-01', 'EUR', 100, 100, 1, 2)");
$selectionDb->exec('INSERT INTO accounting_omega_export_items (id, order_id) VALUES (1, 2)');
$selectedBaseOrders = omega_export_base_orders($selectionDb, '2026-10-03', '2026-10-03');
$selectedBaseIds = array_map(static function (array $order): int { return (int) $order['id']; }, $selectedBaseOrders);
omegaExportAssert(in_array(1, $selectedBaseIds, true), 'An older unexported CUSTOM order must be selected.');
omegaExportAssert(!in_array(2, $selectedBaseIds, true), 'A CUSTOM order already present in an immutable batch must be excluded.');
omegaExportAssert(in_array(3, $selectedBaseIds, true), 'An eBay order inside the selected interval must be selected.');
omegaExportAssert(!in_array(4, $selectedBaseIds, true), 'An eBay order outside the selected interval must remain excluded.');

$selectionDb->exec('CREATE TABLE accounting_omega_manual_invoices (id INTEGER PRIMARY KEY)');
$selectionDb->exec('CREATE TABLE accounting_omega_manual_invoice_items (
    id INTEGER PRIMARY KEY, manual_invoice_id INTEGER, order_id INTEGER, restored_at TEXT
)');
$selectionDb->exec('INSERT INTO accounting_omega_manual_invoices (id) VALUES (1)');
$selectionDb->exec('INSERT INTO accounting_omega_manual_invoice_items (id, manual_invoice_id, order_id) VALUES (1, 1, 1)');
$manualFilteredOrders = omega_export_base_orders($selectionDb, '2026-10-03', '2026-10-03');
$manualFilteredIds = array_map(static function (array $order): int { return (int) $order['id']; }, $manualFilteredOrders);
omegaExportAssert(!in_array(1, $manualFilteredIds, true), 'A CUSTOM order assigned to a manual invoice must be excluded.');
$selectionDb->exec("UPDATE accounting_omega_manual_invoice_items SET restored_at = '2026-10-05 12:00:00' WHERE id = 1");
$restoredOrders = omega_export_base_orders($selectionDb, '2026-10-03', '2026-10-03');
$restoredIds = array_map(static function (array $order): int { return (int) $order['id']; }, $restoredOrders);
omegaExportAssert(in_array(1, $restoredIds, true), 'A restored manual-invoice order must return to the export candidates.');

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
