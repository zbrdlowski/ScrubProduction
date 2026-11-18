<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/accounting/omega_export_helpers.php';

function omegaKvdphAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE order_addresses (
    id INTEGER PRIMARY KEY, order_id INTEGER, type TEXT, name TEXT, company TEXT,
    company_id TEXT, country TEXT, street TEXT, city TEXT, zip TEXT
)');
$pdo->exec('CREATE TABLE order_items (
    id INTEGER PRIMARY KEY, order_id INTEGER, line_no INTEGER, sku TEXT, title TEXT,
    custom_label TEXT, item_type_code TEXT, qty REAL, unit_price REAL, options_json TEXT, deleted_at TEXT
)');
$pdo->exec("INSERT INTO order_items VALUES (1, 1, 1, 'TEST', 'Test product', '', 'G', 2, 100, NULL, NULL)");
$order = [
    'id' => 1, 'source_code' => 'CUSTOM', 'source_meta' => '{"shipping_price":24}',
    '_total_eur' => 264.0, 'order_number' => 'SO12345', 'external_order_id' => '',
    'order_date' => '2026-11-18 08:00:00', 'imported_at' => '2026-11-18 08:00:00',
    'customer_name' => 'Test Customer', 'customer_email' => 'test@example.test',
    'customer_phone' => '', 'payment_method' => 'Bank transfer', 'shipping_method' => 'Courier',
];
$address = $pdo->prepare("INSERT INTO order_addresses
    (id, order_id, type, name, company, company_id, country, street, city, zip)
    VALUES (?, 1, ?, 'Test Customer', ?, ?, ?, 'Test 1', 'Test City', '12345')");
$cases = [
    ['company name', 'Test s.r.o.', '', 'Delivery Company', true],
    ['company ID only', '', '12345678', '', true],
    ['both company fields', 'Test s.r.o.', '12345678', '', true],
    ['trimmed company', " \tTest s.r.o. \n", '', '', true],
    ['personal billing with company shipping', '', '', 'Delivery Company', false],
    ['whitespace is not a company', " \t\n", " \t\n", '', false],
];
$checkedLines = 0;
foreach (['CUSTOM', 'SHOPTET', 'EBAY'] as $source) {
    $order['source_code'] = $source;
    foreach (['SK', 'AT', 'US'] as $country) {
        foreach ($cases as [$label, $company, $companyId, $shippingCompany, $isCompany]) {
            $pdo->exec('DELETE FROM order_addresses');
            // Insert shipping first so billing precedence must be explicit.
            $address->execute([1, 'SHIPPING', $shippingCompany, '', $country]);
            $address->execute([2, 'BILLING', $company, $companyId, $country]);
            $payload = omega_export_payload($pdo, $order, 'M2603000');
            // eBay obtains shipping from its source item; provide a known shipping
            // amount here to verify the same R02 mapping for every export source.
            $payload['shipping_eur'] = 24.0;
            omegaKvdphAssert($payload['billing_is_company'] === $isCompany, $source . '/' . $country . ': ' . $label);
            $rows = omega_export_invoice_rows([$payload]);
            $expected = $isCompany ? 'A1' : 'X';
            omegaKvdphAssert(count($rows) === 4, 'Header, product and shipping records must be retained.');
            foreach ([2, 3] as $index) {
                omegaKvdphAssert($rows[$index][0] === 'R02' && count($rows[$index]) === 94, 'R02 must keep 94 columns.');
                omegaKvdphAssert($rows[$index][53] === $expected, 'BB must apply to products and shipping: ' . $label);
                $checkedLines++;
            }

            $personalPayload = $payload;
            $personalPayload['billing_is_company'] = false;
            $personalRows = omega_export_invoice_rows([$personalPayload]);
            foreach ([2, 3] as $index) {
                $expectedRow = $personalRows[$index];
                $expectedRow[53] = $expected;
                omegaKvdphAssert($rows[$index] === $expectedRow, 'No other R02 field, VAT or amount may change.');
            }
            $txt = omega_export_rows_to_ansi($rows);
            $lines = explode("\r\n", rtrim($txt, "\r\n"));
            foreach ([2, 3] as $index) {
                $columns = explode("\t", $lines[$index]);
                omegaKvdphAssert(count($columns) === 94 && $columns[53] === $expected, 'Serialized TXT must keep the exact BB position.');
            }

            // Existing saved batches have company fields but no new flag.
            $legacyPayload = $payload;
            unset($legacyPayload['billing_is_company']);
            $legacyRows = omega_export_invoice_rows([$legacyPayload]);
            omegaKvdphAssert($legacyRows[2][53] === $expected && $legacyRows[3][53] === $expected,
                'Re-download of a legacy batch uses saved company information.');
        }
    }
}

// Shipping-only data is still useful for the existing address fallback, but it
// must not mark a new invoice as a company when the orders page shows a person.
$pdo->exec('DELETE FROM order_addresses');
$address->execute([1, 'SHIPPING', 'Receiving warehouse s.r.o.', '12345678', 'SK']);
$payload = omega_export_payload($pdo, $order, 'M2603001');
omegaKvdphAssert($payload['company'] === 'Receiving warehouse s.r.o.', 'Retain the existing shipping-address fallback.');
omegaKvdphAssert($payload['billing_is_company'] === false, 'Shipping-only company must not trigger A1.');
omegaKvdphAssert(omega_export_invoice_rows([$payload])[2][53] === 'X', 'Explicit false flag must override fallback company data.');
$pdo->exec('DELETE FROM order_addresses');
$payload = omega_export_payload($pdo, $order, 'M2603002');
omegaKvdphAssert($payload['billing_is_company'] === false, 'Missing address must not trigger A1.');
omegaKvdphAssert(omega_export_invoice_rows([$payload])[2][53] === 'X', 'Missing-address export retains X.');

// Batch download uses the stored snapshot even if live addresses change.
$pdo->exec('CREATE TABLE accounting_omega_export_items (id INTEGER PRIMARY KEY, batch_id INTEGER, payload_json TEXT)');
$payload['company'] = 'Snapshot Company';
$payload['billing_is_company'] = true;
$payload['shipping_eur'] = 24;
$pdo->prepare('INSERT INTO accounting_omega_export_items VALUES (1, 1, ?)')->execute([json_encode($payload)]);
$batchRows = omega_export_invoice_rows(omega_export_batch_payloads($pdo, 1));
omegaKvdphAssert($batchRows[2][53] === 'A1' && $batchRows[3][53] === 'A1', 'Download must use immutable batch classification.');
echo 'OMEGA KVDPH: OK (' . $checkedLines . " product/shipping rows, TXT column BB, billing and saved-batch cases).\n";
