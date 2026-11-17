<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/accounting/paypal_helpers.php';

function paypal_matching_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Exercise the actual matching/export queries in an isolated database.
// SQLite's default text comparison is already case-sensitive.
class PaypalTestPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($query === 'SHOW TABLES LIKE ?') {
            $query = "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE ?";
        } else {
            $query = preg_replace('/\bBINARY\s+/', '', $query);
        }
        return parent::prepare($query, $options);
    }
}

$pdo = new PaypalTestPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("
    CREATE TABLE custom_orders (
        id INTEGER PRIMARY KEY, internal_code TEXT, official_order_number TEXT, production_order_id INTEGER,
        customer_email TEXT, billing_email TEXT, shipping_email TEXT, customer_name TEXT,
        status TEXT DEFAULT 'LEAD', updated_by INTEGER
    );
    CREATE TABLE custom_order_payments (
        id INTEGER PRIMARY KEY, custom_order_id INTEGER, paypal_transaction_id TEXT, payment_kind TEXT,
        amount NUMERIC, currency TEXT, received_at TEXT, note TEXT, created_by INTEGER
    );
    CREATE TABLE orders (id INTEGER PRIMARY KEY, order_number TEXT, external_order_id TEXT);
    CREATE TABLE accounting_paypal_transactions (
        id INTEGER PRIMARY KEY, transaction_date TEXT, transaction_time TEXT, transaction_id TEXT,
        reference_transaction_id TEXT, balance_impact TEXT, transaction_type TEXT,
        gross_amount NUMERIC, fee_amount NUMERIC, currency TEXT,
        payer_email TEXT, payer_name TEXT, raw_invoice_number TEXT, item_title TEXT, subject_text TEXT,
        note_text TEXT, raw_json TEXT DEFAULT '{}',
        matched_order_id INTEGER, matched_custom_order_id INTEGER, matched_custom_code TEXT,
        export_order_number TEXT, match_method TEXT, match_confidence INTEGER DEFAULT 0,
        manual_override INTEGER DEFAULT 0
    );
    INSERT INTO custom_orders (id, internal_code, official_order_number, production_order_id, customer_name)
        VALUES (1, 'CO000114', 'GOSO21662', 42, 'Test Customer'), (2, 'CO000115', NULL, NULL, 'New Customer');
    INSERT INTO custom_order_payments (custom_order_id, paypal_transaction_id)
        VALUES (1, 'TX-DEPOSIT'), (1, 'TX-BALANCE'), (1, 'TX-NO-REFERENCE');
    INSERT INTO orders VALUES (42, 'SO21662', NULL), (43, '2026002056', '2026002056');
");

function paypal_insert_fixture(PDO $pdo, array $changes): void
{
    $row = array_replace([
        'transaction_date' => '2026-09-25',
        'transaction_time' => '08:00:00',
        'balance_impact' => 'Credit',
        'transaction_type' => 'Express Checkout Payment',
        'gross_amount' => 30,
        'fee_amount' => -0.92,
        'currency' => 'EUR',
        'match_confidence' => 100,
        'export_order_number' => 'SO21662',
        'raw_json' => '{}',
    ], $changes);
    $pdo->prepare('INSERT INTO accounting_paypal_transactions (' . implode(', ', array_keys($row))
        . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
}

paypal_insert_fixture($pdo, ['id' => 1, 'transaction_id' => 'TX-DEPOSIT', 'item_title' => 'DEPOSIT - CO000114']);
paypal_insert_fixture($pdo, ['id' => 2, 'transaction_id' => 'TX-BALANCE', 'subject_text' => 'Balance GOSO21662', 'gross_amount' => 120]);
paypal_insert_fixture($pdo, ['id' => 3, 'transaction_id' => 'TX-NEW', 'subject_text' => 'DEPOSIT - CO000115',
    'export_order_number' => null, 'match_confidence' => 98]);
paypal_insert_fixture($pdo, ['id' => 4, 'transaction_id' => 'TX-DEPOSIT', 'balance_impact' => '',
    'transaction_type' => 'Shopping Cart Item']);
paypal_insert_fixture($pdo, ['id' => 5, 'transaction_id' => 'TX-REFUND', 'reference_transaction_id' => 'TX-DEPOSIT',
    'balance_impact' => 'Debit', 'transaction_type' => 'Payment Refund', 'gross_amount' => -10]);
paypal_insert_fixture($pdo, ['id' => 6, 'item_title' => 'CO000114', 'export_order_number' => 'SO21662-3', 'manual_override' => 1]);
paypal_insert_fixture($pdo, ['id' => 7, 'item_title' => 'CO999999', 'export_order_number' => null, 'match_confidence' => 0]);
paypal_insert_fixture($pdo, ['id' => 8, 'payer_name' => 'Test Customer', 'match_confidence' => 70]);
paypal_insert_fixture($pdo, ['id' => 9, 'item_title' => 'CO000114', 'transaction_date' => '2026-10-01']);
paypal_insert_fixture($pdo, ['id' => 10, 'export_order_number' => null]);
paypal_insert_fixture($pdo, ['id' => 11, 'raw_invoice_number' => 'Shoptet714761-2026002056',
    'export_order_number' => '2026002056']);
paypal_insert_fixture($pdo, ['id' => 12, 'item_title' => 'DEPOSIT - SO21662']);
paypal_insert_fixture($pdo, ['id' => 13, 'transaction_id' => 'TX-NO-REFERENCE']);
paypal_insert_fixture($pdo, ['id' => 14, 'raw_json' => '{"Custom Number":"CO000114"}']);

$before = $pdo->query('SELECT * FROM accounting_paypal_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$exports = accounting_paypal_vycuc_rows($pdo, '2026-09-01', '2026-10-01');
paypal_matching_assert(array_column($exports, 'id') === [1, 2, 3, 6, 11, 12, 13, 14],
    'Export only confirmed credits in the selected month; exclude details, refunds and unresolved rows.');
paypal_matching_assert(array_column($exports, 'export_order_number') === [
    'CO000114', 'SO21662', 'CO000115', 'SO21662-3', '2026002056', 'SO21662', 'SO21662', 'CO000114',
], 'Old imports must export their original CO/SO, even before SO exists; retain manual and non-CO/SO references.');
paypal_matching_assert($before === $pdo->query('SELECT * FROM accounting_paypal_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'Downloading the extract must not change stored transactions.');

$stream = fopen('php://temp', 'w+b');
accounting_paypal_write_vycuc($stream, $exports);
rewind($stream);
paypal_matching_assert(fread($stream, 3) === "\xEF\xBB\xBF", 'Keep the Excel UTF-8 BOM.');
paypal_matching_assert(fgetcsv($stream, 0, ';', '"', '') === [
    'Date', 'Suma', 'Poplatok', 'Order Number', 'PayPal', 'IBAN partnera',
], 'Keep the six-column OMEGA header.');
paypal_matching_assert(fgetcsv($stream, 0, ';', '"', '') === [
    '25.09.2026', '30,00', '-0,92', 'CO000114', 'PayPal', '',
], 'Export the deposit amount, fee and original CO.');
paypal_matching_assert(fgetcsv($stream, 0, ';', '"', '') === [
    '25.09.2026', '120,00', '-0,92', 'SO21662', 'PayPal', '',
], 'Keep the balance payment as a separate row under SO.');
fclose($stream);

$match = accounting_paypal_match_row($pdo, $before[0]);
paypal_matching_assert($match['match_method'] === 'TRANSACTION_ID'
    && $match['export_order_number'] === 'CO000114'
    && $match['matched_custom_order_id'] === 1 && $match['matched_order_id'] === 42,
    'Transaction ID must retain the original CO and the link to the current order.');
$coMatch = accounting_paypal_match_row($pdo, ['subject_text' => 'Deposit CO000114']);
paypal_matching_assert($coMatch['export_order_number'] === 'CO000114' && $coMatch['match_method'] === 'CUSTOM_CODE',
    'Matching by CO must not convert it to the current SO.');
$soMatch = accounting_paypal_match_row($pdo, ['subject_text' => 'DEPOSIT - SO21662']);
paypal_matching_assert($soMatch['export_order_number'] === 'SO21662', 'Deposits requested under SO must keep SO.');
$suffixMatch = accounting_paypal_match_row($pdo, ['transaction_id' => 'TX-BALANCE', 'subject_text' => 'SO21662-3']);
paypal_matching_assert($suffixMatch['export_order_number'] === 'SO21662-3', 'Preserve the requested SO suffix.');
$unmatched = accounting_paypal_match_row($pdo, ['subject_text' => 'CO999999']);
paypal_matching_assert($unmatched['export_order_number'] === null && $unmatched['match_confidence'] === 0,
    'An unknown CO must not become a confirmed payment.');

$result = accounting_paypal_refresh_matches($pdo, '2026-09-01', '2026-10-01');
$after = $pdo->query('SELECT * FROM accounting_paypal_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
paypal_matching_assert($after[0]['export_order_number'] === 'CO000114'
    && $after[2]['export_order_number'] === 'CO000115', 'Refresh must correct old SO mappings and CO waiting for SO.');
paypal_matching_assert($after[3]['export_order_number'] === 'CO000114'
    && $after[4]['export_order_number'] === 'CO000114', 'Details and refunds must follow the refreshed original payment.');
paypal_matching_assert($after[5] === $before[5], 'Refresh must preserve manual overrides.');
paypal_matching_assert($result['payments_added'] === 1 && $result['statuses_updated'] === 1,
    'A newly matched CO without SO must still register its deposit and update Lead status.');
$paymentCount = (int) $pdo->query('SELECT COUNT(*) FROM custom_order_payments')->fetchColumn();
$pdo->exec("UPDATE custom_orders SET official_order_number = 'SO21999' WHERE id = 2");
$again = accounting_paypal_refresh_matches($pdo, '2026-09-01', '2026-10-01');
paypal_matching_assert($pdo->query('SELECT export_order_number FROM accounting_paypal_transactions WHERE id = 3')->fetchColumn() === 'CO000115',
    'Creating SO later must not change the original CO.');
paypal_matching_assert($again['payments_added'] === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM custom_order_payments')->fetchColumn() === $paymentCount,
    'Repeated refresh must not duplicate deposits.');
foreach ($pdo->query('SELECT * FROM accounting_paypal_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $index => $row) {
    foreach (['raw_json', 'raw_invoice_number', 'item_title', 'subject_text', 'gross_amount', 'fee_amount'] as $column) {
        paypal_matching_assert($row[$column] === $before[$index][$column], 'Refresh must preserve the original payment data.');
    }
}

echo "accounting PayPal matching and Vycuc: OK\n";
