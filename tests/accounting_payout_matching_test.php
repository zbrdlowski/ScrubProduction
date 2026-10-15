<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/accounting/payout_matching.php';

function payout_matching_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, external_order_id TEXT, order_number TEXT)');
$pdo->exec('CREATE TABLE accounting_payout_imports (
    id INTEGER PRIMARY KEY, matched_order_count INTEGER NOT NULL DEFAULT 0,
    imported_row_count INTEGER NOT NULL, duplicate_row_count INTEGER NOT NULL DEFAULT 0
)');
$pdo->exec('CREATE TABLE accounting_payout_transactions (
    id INTEGER PRIMARY KEY, source_key TEXT NOT NULL UNIQUE, import_id INTEGER NOT NULL,
    order_number TEXT, matched_order_id INTEGER, net_amount REAL, raw_json TEXT
)');
$pdo->exec("INSERT INTO accounting_payout_imports (id, matched_order_count, imported_row_count) VALUES
    (1, 1, 5), (2, 0, 1)");
$pdo->exec("INSERT INTO accounting_payout_transactions VALUES
    (1, 'later-order', 1, '03-15282-42996', NULL, 133.39, 'original payment'),
    (2, 'already-matched', 1, 'PRESERVE', 10, 80, 'original matched payment'),
    (3, 'missing-order', 1, 'MISSING', NULL, 50, 'missing order payment'),
    (4, 'no-order-number', 1, NULL, NULL, -5, 'fee'),
    (5, 'outside-report', 1, 'OUTSIDE', NULL, 30, 'unrelated payment'),
    (6, 'overlapping-report', 2, 'FALLBACK', NULL, 40, 'overlapping payment')");
$pdo->exec("INSERT INTO orders VALUES
    (10, 'PRESERVE', 'PRESERVE'), (11, 'PRESERVE', 'PRESERVE'),
    (20, 'OUTSIDE', 'OUTSIDE'), (30, 'DIFFERENT', 'FALLBACK')");

$originalPayments = $pdo->query('SELECT * FROM accounting_payout_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$report = [
    ['source_key' => 'later-order', 'order_number' => '03-15282-42996'],
    ['source_key' => 'already-matched', 'order_number' => 'PRESERVE'],
    ['source_key' => 'missing-order', 'order_number' => 'MISSING'],
    ['source_key' => 'no-order-number', 'order_number' => null],
];
payout_matching_assert(accounting_payout_rematch_rows($pdo, $report) === 0, 'An absent order must stay unmatched.');

// The order arrives after the first payout import. Prefer its external ID over
// another order whose internal number happens to be the same.
$pdo->exec("INSERT INTO orders VALUES
    (40, '03-15282-42996', 'INTERNAL-40'), (41, 'OTHER', '03-15282-42996')");
payout_matching_assert(accounting_payout_rematch_rows($pdo, $report) === 1, 'An identical report must match an order imported later.');
payout_matching_assert(accounting_payout_rematch_rows($pdo, $report) === 0, 'Repeated uploads must not count existing matches again.');

// A newly downloaded report can overlap transactions owned by another import.
// Retry using saved payment data, even if an incoming field differs.
$overlap = [
    ['source_key' => 'overlapping-report', 'order_number' => 'OUTSIDE'],
    ['source_key' => 'later-order', 'order_number' => '03-15282-42996'],
    ['source_key' => 'overlapping-report', 'order_number' => 'OUTSIDE'],
    ['source_key' => 'not-stored-yet', 'order_number' => 'OUTSIDE'],
];
payout_matching_assert(accounting_payout_rematch_rows($pdo, $overlap) === 1, 'Overlapping and repeated rows must count a new match exactly once.');
$payments = $pdo->query('SELECT * FROM accounting_payout_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
payout_matching_assert(array_column($payments, 'matched_order_id') === [40, 10, null, null, null, 30], 'Only eligible payments in the uploaded reports may change.');
foreach ($payments as $index => $payment) {
    unset($payment['matched_order_id'], $originalPayments[$index]['matched_order_id']);
    payout_matching_assert($payment === $originalPayments[$index], 'Matching must preserve every payment field and its original import.');
}
payout_matching_assert(count($payments) === 6, 'Matching must not insert duplicate transactions.');
payout_matching_assert($pdo->query('SELECT matched_order_count FROM accounting_payout_imports ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === [2, 1], 'Match counters must update the original imports.');
payout_matching_assert($pdo->query('SELECT imported_row_count FROM accounting_payout_imports ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === [5, 1], 'Import row counts must stay unchanged.');

$pdo->exec("INSERT INTO orders VALUES (50, 'MISSING', 'MISSING')");
$pdo->beginTransaction();
payout_matching_assert(accounting_payout_rematch_rows($pdo, $report) === 1, 'The next newly available order must match.');
$pdo->rollBack();
payout_matching_assert($pdo->query('SELECT matched_order_id FROM accounting_payout_transactions WHERE id = 3')->fetchColumn() === null, 'Rolling back an import must roll back its matches.');
payout_matching_assert((int) $pdo->query('SELECT matched_order_count FROM accounting_payout_imports WHERE id = 1')->fetchColumn() === 2, 'Rolling back must also restore import counters.');

echo "accounting payout matching: OK\n";
