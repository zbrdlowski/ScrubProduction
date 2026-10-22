<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/scripts/accounting/tatra_helpers.php';

function tatra_assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function tatra_fixture(array $changes = [], array $extraRows = [], string $delimiter = ','): array
{
    $base = ['transactionDate' => '22. 10. 2026 9:51:43', 'billingDate' => '', 'reportId' => '',
        'payoutReference' => '', 'amount' => '139.8', 'currency' => 'EUR', 'settlementAmount' => '139.8',
        'settlementCurrency' => 'EUR', 'state' => 'APPROVED', 'type' => 'Payment',
        'service' => 'Online card payments', 'payer' => 'Test, Customer', 'maskedCardNumber' => '1234******5678',
        'orderId' => '2026002432'];
    $file = tempnam(sys_get_temp_dir(), 'tatra-test-');
    try {
        $stream = fopen($file, 'wb');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_keys($base), $delimiter, '"', '');
        foreach (array_merge([$changes], $extraRows) as $row) {
            fputcsv($stream, array_values(array_replace($base, $row)), $delimiter, '"', '');
        }
        fclose($stream);
        return accounting_tatra_parse_file($file);
    } finally {
        unlink($file);
    }
}
$parsed = tatra_fixture();
$row = $parsed['rows'][0];
tatra_assert($row['transaction_date'] === '2026-10-22 09:51:43', 'Slovak date/time.');
tatra_assert($row['amount'] === '139.80' && $row['transaction_type'] === 'ORDER', 'Amount/type parsing.');
tatra_assert($row['payer_name'] === 'Test, Customer', 'Quoted comma in payer.');
$settled = tatra_fixture(['state' => 'BILLED', 'billingDate' => '23. 10. 2026', 'reportId' => '123456']);
tatra_assert($settled['rows'][0]['source_key'] === $row['source_key'], 'State and billing metadata must not duplicate payments.');
tatra_assert(tatra_fixture([], [], ';')['rows'][0] === $row, 'BOM and semicolon CSV.');
tatra_assert(tatra_fixture(['amount' => '139,80'], [], ';')['rows'][0]['amount'] === '139.80', 'Decimal comma.');
tatra_assert(tatra_fixture(['orderId' => '000123'])['rows'][0]['order_number'] === '000123', 'Leading zero identifier.');
foreach ([['transactionDate' => '31. 2. 2026 10:00:00'], ['amount' => 'n/a'], ['settlementCurrency' => ''], ['amount' => '1.234']] as $bad) {
    $failed = false;
    try { tatra_fixture($bad); } catch (RuntimeException $e) { $failed = true; }
    tatra_assert($failed, 'Reject invalid financial data.');
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE order_sources (id INTEGER PRIMARY KEY, code TEXT);
    INSERT INTO order_sources VALUES (1, 'SHOPTET'), (2, 'EBAY');
    CREATE TABLE orders (id INTEGER PRIMARY KEY, source_id INTEGER, order_number TEXT, external_order_id TEXT);
    CREATE TABLE accounting_tatra_imports (id INTEGER PRIMARY KEY, original_filename TEXT, file_hash TEXT UNIQUE,
      source_row_count INTEGER, imported_row_count INTEGER DEFAULT 0, duplicate_row_count INTEGER DEFAULT 0,
      matched_order_count INTEGER DEFAULT 0, imported_by INTEGER);
    CREATE TABLE accounting_tatra_transactions (id INTEGER PRIMARY KEY, import_id INTEGER, source_key TEXT UNIQUE,
      transaction_date TEXT, billing_date TEXT, transaction_type TEXT, type_raw TEXT, state TEXT, order_number TEXT,
      payer_name TEXT, service TEXT, amount NUMERIC, currency TEXT, settlement_amount NUMERIC,
      settlement_currency TEXT, report_id TEXT, payout_reference TEXT, matched_order_id INTEGER)");
$pdo->exec("INSERT INTO orders VALUES (1, 2, '2026002432', '2026002432')");
tatra_assert(accounting_tatra_find_order($pdo, '2026002432') === null, 'Do not match another source.');
$pdo->beginTransaction();
$result = accounting_tatra_import($pdo, $parsed, 'daily.csv', null);
tatra_assert($result['imported'] === 1 && $result['matched'] === 0, 'First unmatched import.');
$pdo->exec("INSERT INTO orders VALUES (2, 1, 'INTERNAL-2432', '2026002432')");
$result = accounting_tatra_import($pdo, $parsed, 'daily.csv', null);
tatra_assert($result['imported'] === 0 && $result['rematched'] === 1, 'Identical upload rematches a late order.');
$result = accounting_tatra_import($pdo, $settled, 'next-day.csv', null);
tatra_assert($result['duplicates'] === 1 && $result['imported'] === 0, 'Overlapping settled report is not a new payment.');
tatra_assert($pdo->query('SELECT state FROM accounting_tatra_transactions')->fetchColumn() === 'BILLED', 'State updated.');
accounting_tatra_import($pdo, $parsed, 'old.csv', null);
tatra_assert($pdo->query('SELECT state FROM accounting_tatra_transactions')->fetchColumn() === 'BILLED', 'Old report must not downgrade state.');
$pdo->commit();
$extra = [
    ['transactionDate' => '22. 10. 2026 9:51:44'], // second real payment for same order
    ['type' => 'Refund', 'amount' => '-20', 'settlementAmount' => '-20', 'state' => 'BILLED'],
    ['type' => 'Transaction Fees', 'orderId' => '', 'amount' => '-5', 'settlementAmount' => '-5', 'state' => 'BILLED'],
    ['type' => 'Payout of Funds', 'orderId' => '', 'payoutReference' => 'PAYOUT-1', 'amount' => '-500', 'settlementAmount' => '-500', 'state' => 'PAYOUT_CONFIRMED'],
    ['orderId' => 'missing'],
    ['transactionDate' => '22. 10. 2026 9:51:45', 'settlementCurrency' => 'USD'],
    ['transactionDate' => '22. 10. 2026 9:51:46', 'state' => 'DECLINED'],
    ['transactionDate' => '22. 10. 2026 9:51:47', 'state' => 'UNKNOWN'],
    ['transactionDate' => '22. 10. 2026 9:51:48', 'amount' => '0', 'settlementAmount' => '0'],
    ['transactionDate' => '30. 9. 2026 23:59:59'],
    ['transactionDate' => '1. 11. 2026 0:00:00'],
];
accounting_tatra_import($pdo, tatra_fixture([], $extra), 'mixed.csv', null);
$exports = accounting_tatra_export_rows($pdo, '2026-10-01', '2026-11-01');
tatra_assert(count($exports) === 2, 'Only accepted matched EUR payments within the month; keep split payments.');
tatra_assert($exports[0]['export_order_number'] === 'INTERNAL-2432', 'Use the same order reference as OMEGA.');
$out = fopen('php://temp', 'w+b');
accounting_tatra_write_vycuc($out, $exports);
rewind($out);
$csv = stream_get_contents($out);
tatra_assert(substr($csv, 0, 3) === "\xEF\xBB\xBF", 'UTF-8 BOM.');
rewind($out);
$header = fgetcsv($out, 0, ';', '"', '');
$payment = fgetcsv($out, 0, ';', '"', '');
tatra_assert(count($header) === 6 && $payment === ['22.10.2026', '139,80', '0,00', 'INTERNAL-2432', 'Tatra B.', ''], 'Exact six-column OMEGA CSV.');
fclose($out);
$pdo->exec("INSERT INTO orders VALUES (3, 1, 'missing', 'missing'), (4, 1, 'missing', 'duplicate')");
tatra_assert(accounting_tatra_find_order($pdo, 'missing') === null, 'Ambiguous orders remain unmatched.');
tatra_assert(accounting_tatra_refresh_matches($pdo, '2026-10-01', '2026-11-01') === 0, 'Ambiguous refresh does not guess.');
$pdo->exec('DELETE FROM orders WHERE id = 4');
$pdo->beginTransaction();
tatra_assert(accounting_tatra_refresh_matches($pdo, '2026-10-01', '2026-11-01') === 1, 'Refresh finds the now unambiguous order.');
$pdo->rollBack();
tatra_assert(accounting_tatra_refresh_matches($pdo, '2026-10-01', '2026-11-01') === 1, 'Rollback restores matches and counters.');
tatra_assert(accounting_tatra_refresh_matches($pdo, '2026-10-01', '2026-11-01') === 0, 'Refresh idempotence.');
tatra_assert((int) $pdo->query('SELECT SUM(matched_order_count) FROM accounting_tatra_imports')->fetchColumn()
    === (int) $pdo->query('SELECT COUNT(*) FROM accounting_tatra_transactions WHERE matched_order_id IS NOT NULL')->fetchColumn(), 'Import match counters reconcile.');
$before = (int) $pdo->query('SELECT COUNT(*) FROM accounting_tatra_transactions')->fetchColumn();
$pdo->beginTransaction();
accounting_tatra_import($pdo, tatra_fixture(['transactionDate' => '25. 10. 2026 10:00:00']), 'rollback.csv', null);
$pdo->rollBack();
tatra_assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_tatra_transactions')->fetchColumn() === $before, 'Rollback leaves no partial import.');
if (isset($argv[1])) {
    $real = accounting_tatra_parse_file($argv[1]);
    $types = array_count_values(array_column($real['rows'], 'transaction_type'));
    tatra_assert(count(array_unique(array_column($real['rows'], 'source_key'))) === count($real['rows']), 'Real report identity uniqueness.');
    echo 'Real CSV: ' . json_encode(['rows' => count($real['rows']), 'types' => $types], JSON_UNESCAPED_UNICODE) . "\n";
}
echo "Tatra parser, import, matching and Vycuc: OK\n";
