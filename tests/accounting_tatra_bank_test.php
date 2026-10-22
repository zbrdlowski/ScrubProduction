<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/scripts/accounting/tatra_helpers.php';

function bank_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function bank_fixture(array $changes = [], array $extra = [], string $encoding = 'UTF-8'): array {
    $base = [
        'Dátum_spracovania' => '02.10.2026', 'Dátum zúčtovania' => '01.10.2026',
        'Predčíslo_účtu' => '', 'Číslo_účtu' => '1234567890', 'Predčíslo_protiúčtu' => '',
        'Číslo_protiúčtu' => 'SK3112000000198742637541', 'Kód_banky_protiúčtu' => '1100',
        'Názov_protiúčtu' => 'Test Ľuboš', 'Suma' => '100,00', 'Mena' => 'EUR',
        'Kurz' => '', 'Zostatok' => '2000,00', 'Popis_obratu' => 'Prijatá platba',
        'Referencia_platiteľa' => '', 'Variabilný_symbol' => '',
        'Konštantný_symbol' => '', 'Špecifický_symbol' => '', 'Poznámka' => '', 'Číslo_výpisu' => '10',
        'Identifikácia_protiúčtu1' => 'Payment 1100/000000-1234567890',
        'Identifikácia_protiúčtu2' => '', 'Identifikácia_protiúčtu3' => '', 'Identifikácia_protiúčtu4' => '',
        'Správa_pre_prijímateľa1' => 'Variabiln. symbol platby: 202600231',
        'Správa_pre_prijímateľa2' => '3', 'Správa_pre_prijímateľa3' => '', 'Správa_pre_prijímateľa4' => '',
        'Priorita' => '',
    ];
    $stream = fopen('php://temp', 'w+b');
    fputcsv($stream, array_keys($base), ';', '"', '');
    foreach (array_merge([$changes], $extra) as $row) fputcsv($stream, array_values(array_replace($base, $row)), ';', '"', '');
    rewind($stream); $raw = stream_get_contents($stream); fclose($stream);
    $raw = $encoding === 'UTF-8' ? "\xEF\xBB\xBF" . $raw : iconv('UTF-8', $encoding, $raw);
    $file = tempnam(sys_get_temp_dir(), 'tatra-bank-');
    try {
        file_put_contents($file, $raw);
        return accounting_tatra_parse_file($file);
    } finally { unlink($file); }
}
function bank_db(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE order_sources (id INTEGER PRIMARY KEY, code TEXT);
        INSERT INTO order_sources VALUES (1, 'SHOPTET'), (2, 'CUSTOM');
        CREATE TABLE orders (id INTEGER PRIMARY KEY, source_id INTEGER, order_number TEXT, external_order_id TEXT,
            status TEXT, financial_total_value NUMERIC, financial_total_currency TEXT, payment_received_amount NUMERIC);
        INSERT INTO orders VALUES (1, 1, '2026002313', '2026002313', 'PENDING', 100, 'EUR', 0),
            (2, 2, 'SO20921', 'SO20921', 'NEW', 120, 'EUR', 0);
        CREATE TABLE custom_orders (id INTEGER PRIMARY KEY, internal_code TEXT, official_order_number TEXT,
            production_order_id INTEGER, status TEXT);
        INSERT INTO custom_orders VALUES (20, 'CO20921', 'SO20921', 2, 'CONFIRMED'),
            (21, 'CO30000', NULL, NULL, 'OPEN');
        CREATE TABLE accounting_tatra_imports (id INTEGER PRIMARY KEY, original_filename TEXT, file_hash TEXT UNIQUE,
            source_row_count INTEGER, imported_row_count INTEGER DEFAULT 0, duplicate_row_count INTEGER DEFAULT 0,
            matched_order_count INTEGER DEFAULT 0, imported_by INTEGER);
        CREATE TABLE accounting_tatra_transactions (id INTEGER PRIMARY KEY, import_id INTEGER, source_key TEXT UNIQUE,
            transaction_date TEXT, billing_date TEXT, transaction_type TEXT, type_raw TEXT, state TEXT, order_number TEXT,
            payer_name TEXT, service TEXT, amount NUMERIC, currency TEXT, settlement_amount NUMERIC,
            settlement_currency TEXT, report_id TEXT, payout_reference TEXT, matched_order_id INTEGER);
        CREATE TABLE accounting_tatra_bank_transactions (id INTEGER PRIMARY KEY, import_id INTEGER,
            source_key TEXT UNIQUE, transaction_date TEXT, processing_date TEXT, transaction_type TEXT,
            type_raw TEXT, own_account TEXT, counterparty_account TEXT, counterparty_bank TEXT,
            payer_name TEXT, amount NUMERIC, currency TEXT, variable_symbol TEXT, payer_reference TEXT,
            bank_reference TEXT, payment_message TEXT, payout_reference TEXT, matched_order_id INTEGER,
            matched_custom_order_id INTEGER, export_order_number TEXT, match_method TEXT DEFAULT 'UNMATCHED',
            manual_override INTEGER DEFAULT 0, excluded INTEGER DEFAULT 0, reviewed_by INTEGER, reviewed_at TEXT)");
    return $pdo;
}
function bank_row(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT * FROM accounting_tatra_bank_transactions WHERE id = ?'); $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
$parsed = bank_fixture();
$row = $parsed['rows'][0];
bank_assert($parsed['source'] === 'BANK' && $row['amount'] === '100.00', 'BOM, delimiter and decimal comma.');
bank_assert($row['transaction_date'] === '2026-10-01 00:00:00' && $row['processing_date'] === '2026-10-02', 'Accounting uses booking date.');
bank_assert(in_array('2026002313', accounting_tatra_bank_references($row), true), 'Reassemble an order number split between message columns.');
bank_assert(bank_fixture([], [], 'Windows-1250')['rows'][0] === $row, 'Windows-1250 bank export.');
$overlap = bank_fixture(['Zostatok' => '1,00', 'Číslo_výpisu' => '99', 'Dátum_spracovania' => '03.10.2026']);
bank_assert($overlap['rows'][0]['source_key'] === $row['source_key'], 'Ignore mutable balance, processing date and statement number.');
$pair = bank_fixture([], [[]]);
bank_assert(count(array_unique(array_column($pair['rows'], 'source_key'))) === 2, 'Preserve two identical real entries in the same report.');
foreach ([['Suma' => 'oops'], ['Dátum zúčtovania' => '31.02.2026'], ['Mena' => 'EU']] as $bad) {
    $failed = false;
    try { bank_fixture($bad); } catch (RuntimeException $e) { $failed = true; }
    bank_assert($failed, 'Reject invalid banking data.');
}
$pdo = bank_db();
$ordersBefore = $pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$result = accounting_tatra_import($pdo, $parsed, 'bank.csv', null);
bank_assert($result['imported'] === 1 && $result['matched'] === 1, 'Import and match a Pending order.');
$result = accounting_tatra_import($pdo, $overlap, 'overlap.csv', null);
bank_assert($result['duplicates'] === 1 && $result['imported'] === 0, 'Overlapping daily export deduplicates.');
$result = accounting_tatra_import($pdo, $pair, 'two.csv', null);
bank_assert($result['imported'] === 1 && $result['duplicates'] === 1, 'Same-day identical entries retain multiplicity.');
$result = accounting_tatra_import($pdo, $pair, 'two-again.csv', null);
bank_assert($result['imported'] === 0 && $result['duplicates'] === 2, 'Repeated identical report does not duplicate either entry.');
$noMessage = ['Správa_pre_prijímateľa1' => '', 'Správa_pre_prijímateľa2' => ''];
$extras = bank_fixture(array_merge($noMessage, ['Suma' => '20', 'Názov_protiúčtu' => 'Doplatok']), [
    array_merge($noMessage, ['Suma' => '35', 'Variabilný_symbol' => '2026002313']),
    array_merge($noMessage, ['Suma' => '130', 'Variabilný_symbol' => '2026002313', 'Dátum zúčtovania' => '30.09.2026']),
    array_merge($noMessage, ['Suma' => '120', 'Správa_pre_prijímateľa1' => 'SO20921 CO20921']),
    array_merge($noMessage, ['Suma' => '5', 'Správa_pre_prijímateľa1' => 'CO30000']),
    array_merge($noMessage, ['Suma' => '50', 'Správa_pre_prijímateľa1' => '2026002313 SO20921']),
    array_merge($noMessage, ['Suma' => '60', 'Názov_protiúčtu' => 'Shoptet s.r.o.', 'Správa_pre_prijímateľa1' => 'SWPE123', 'Správa_pre_prijímateľa2' => 'ABC']),
    array_merge($noMessage, ['Suma' => '70', 'Názov_protiúčtu' => 'PAYPAL EUROPE']),
    array_merge($noMessage, ['Suma' => '-3', 'Popis_obratu' => 'Poplatok']),
    array_merge($noMessage, ['Suma' => '-10', 'Variabilný_symbol' => '2026002313']),
    array_merge($noMessage, ['Suma' => '10', 'Mena' => 'USD', 'Variabilný_symbol' => '2026002313']),
]);
$result = accounting_tatra_import($pdo, $extras, 'extra.csv', null);
bank_assert($result['imported'] === 11 && $result['matched'] === 4, 'Topups, custom order and foreign currency match; transfers and ambiguity do not.');
bank_assert(bank_row($pdo, 6)['matched_order_id'] === 2 && bank_row($pdo, 6)['matched_custom_order_id'] === 20, 'SO and linked production order are one target.');
bank_assert(bank_row($pdo, 7)['match_method'] === 'WAITING_SO', 'CO lead waits for an official number.');
bank_assert(bank_row($pdo, 8)['match_method'] === 'AMBIGUOUS', 'Two different referenced orders require review.');
bank_assert(bank_row($pdo, 9)['transaction_type'] === 'TRANSFER' && bank_row($pdo, 9)['payout_reference'] === 'SWPE123ABC', 'Reassembled Shoptet transfer.');
bank_assert(bank_row($pdo, 10)['transaction_type'] === 'TRANSFER', 'PayPal transfer never becomes a direct order payment.');
bank_assert(bank_row($pdo, 11)['transaction_type'] === 'FEE' && bank_row($pdo, 12)['transaction_type'] === 'OTHER', 'Bank fees and expenses are not customer refunds.');
$from = '2026-10-01'; $to = '2026-11-01';
$export = accounting_tatra_export_rows($pdo, $from, $to, 'bank');
bank_assert(count($export) === 4, 'Export only matched positive EUR receipts in booking month.');
bank_assert($export[0]['partner_iban'] === 'SK3112000000198742637541', 'Export provided counterparty IBAN.');
$stream = fopen('php://temp', 'w+b');
accounting_tatra_write_vycuc($stream, $export); rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
bank_assert(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, ';0,00;') && str_contains($csv, 'SK3112000000198742637541'), 'Existing six-column Vycuc with bank IBAN.');
accounting_tatra_bank_manual($pdo, 3, 'match', '2026002313', 7);
bank_assert(bank_row($pdo, 3)['manual_override'] === 1, 'Manual topup match.');
accounting_tatra_import($pdo, $extras, 'again.csv', null);
accounting_tatra_refresh_matches($pdo, $from, $to);
bank_assert(bank_row($pdo, 3)['match_method'] === 'MANUAL', 'Reimport and refresh preserve manual choice.');
bank_assert(count(accounting_tatra_export_rows($pdo, $from, $to, 'bank')) === 5, 'Manually linked extra payment exported separately.');
$totals = accounting_tatra_received_totals($pdo, accounting_tatra_bank_target($pdo, bank_row($pdo, 1)));
bank_assert($totals['EUR']['cents'] === 38500 && $totals['EUR']['count'] === 5 && $totals['USD']['cents'] === 1000,
    'All-date receipts include installments and topups, with currencies separated.');
accounting_tatra_bank_manual($pdo, 3, 'exclude', '', 7);
accounting_tatra_import($pdo, $extras, 'again.csv', null);
bank_assert(bank_row($pdo, 3)['excluded'] === 1 && count(accounting_tatra_export_rows($pdo, $from, $to, 'bank')) === 4, 'Excluded unrelated income stays excluded on reimport.');
accounting_tatra_bank_manual($pdo, 3, 'reset', '', 7);
bank_assert(bank_row($pdo, 3)['excluded'] === 0 && bank_row($pdo, 3)['match_method'] === 'UNMATCHED', 'Reset restores automated matching.');
$failed = false;
try { accounting_tatra_bank_manual($pdo, 3, 'match', 'nonexistent', 7); } catch (RuntimeException $e) { $failed = true; }
bank_assert($failed, 'Manual entry must identify an existing order.');
$failed = false;
try { accounting_tatra_bank_manual($pdo, 9, 'match', '2026002313', 7); } catch (RuntimeException $e) { $failed = true; }
bank_assert($failed, 'A processor transfer cannot be assigned as a direct receipt.');
$pdo->exec("UPDATE custom_orders SET official_order_number = 'SO30000' WHERE id = 21");
bank_assert(accounting_tatra_refresh_matches($pdo, $from, $to) === 1, 'Refresh finds a newly issued SO number.');
bank_assert(count(accounting_tatra_export_rows($pdo, $from, $to, 'bank')) === 5, 'Customer order can export before production link exists.');
bank_assert(accounting_tatra_bank_transfer_check($pdo, bank_row($pdo, 9)) === 'MISSING', 'Missing Shoptet payout indicated.');
$pdo->exec("INSERT INTO accounting_tatra_transactions (transaction_date, transaction_type, payout_reference, settlement_amount, settlement_currency)
    VALUES ('2026-10-01 00:00:00', 'PAYOUT', 'SWPE123ABC', -60, 'EUR')");
bank_assert(accounting_tatra_bank_transfer_check($pdo, bank_row($pdo, 9)) === 'MATCHED', 'Reconcile equal payout reference, amount and currency.');
$pdo->exec("UPDATE accounting_tatra_transactions SET settlement_amount = -61 WHERE payout_reference = 'SWPE123ABC'");
bank_assert(accounting_tatra_bank_transfer_check($pdo, bank_row($pdo, 9)) === 'DIFFERENCE', 'Detect payout amount mismatch.');
$pdo->exec("INSERT INTO accounting_tatra_transactions (transaction_date, transaction_type, amount, state, settlement_amount, settlement_currency, matched_order_id)
    VALUES ('2026-10-03 10:00:00', 'ORDER', 25, 'BILLED', 25, 'EUR', 1)");
bank_assert(count(accounting_tatra_export_rows($pdo, $from, $to)) === 6
    && count(accounting_tatra_export_rows($pdo, $from, $to, 'shoptet')) === 1
    && count(accounting_tatra_export_rows($pdo, $from, $to, 'bank')) === 5, 'Combined export and source selection.');
$totals = accounting_tatra_received_totals($pdo, accounting_tatra_bank_target($pdo, bank_row($pdo, 1)));
bank_assert($totals['EUR']['cents'] === 39000 && $totals['EUR']['count'] === 5, 'Shared receipt totals add Shoptet payments only once.');
bank_assert($pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $ordersBefore, 'Import, refresh and manual actions never alter status, finances or payment confirmation.');
bank_assert((int) $pdo->query('SELECT SUM(matched_order_count) FROM accounting_tatra_imports')->fetchColumn()
    === (int) $pdo->query("SELECT COUNT(*) FROM accounting_tatra_bank_transactions WHERE export_order_number IS NOT NULL")->fetchColumn(),
    'Import counters remain correct after manual actions, duplicate imports and late matches.');
$before = (int) $pdo->query('SELECT COUNT(*) FROM accounting_tatra_bank_transactions')->fetchColumn();
$pdo->beginTransaction();
accounting_tatra_import($pdo, bank_fixture(['Suma' => '123.45']), 'rollback.csv', null);
$pdo->rollBack();
bank_assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_tatra_bank_transactions')->fetchColumn() === $before, 'Transaction rollback leaves no partial import.');
if (isset($argv[1])) {
    $real = accounting_tatra_parse_file($argv[1]);
    bank_assert($real['source'] === 'BANK', 'Actual file format detection.');
    $types = array_count_values(array_column($real['rows'], 'transaction_type'));
    bank_assert(count(array_unique(array_column($real['rows'], 'source_key'))) === count($real['rows']), 'Actual report identities.');
    echo 'Actual bank CSV: ' . json_encode(['rows' => count($real['rows']), 'types' => $types]) . "\n";
}
echo "Tatra bank parsing, matching, manual review, deduplication and Vycuc: OK\n";
