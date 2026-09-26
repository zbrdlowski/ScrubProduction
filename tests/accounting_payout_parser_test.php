<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/accounting/payout_helpers.php';

function payout_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function payout_test_close(float $actual, float $expected, float $tolerance, string $message): void
{
    payout_test_assert(abs($actual - $expected) <= $tolerance, $message . ": {$actual} != {$expected}");
}

$uk = accounting_payout_parse_file(dirname(__DIR__) . '/docs/Payout_UK.csv');
$de = accounting_payout_parse_file(dirname(__DIR__) . '/docs/Payout_DE.csv');

payout_test_assert($uk['source_region'] === 'UK', 'UK region detection failed');
payout_test_assert($uk['delimiter'] === ',', 'UK delimiter detection failed');
payout_test_assert(count($uk['rows']) === 94, 'Unexpected UK row count');
payout_test_assert($de['source_region'] === 'DE', 'DE region detection failed');
payout_test_assert($de['delimiter'] === ';', 'DE delimiter detection failed');
payout_test_assert(count($de['rows']) === 2, 'Unexpected DE row count');

$ukTypes = array_count_values(array_column($uk['rows'], 'transaction_type'));
payout_test_assert(($ukTypes['ORDER'] ?? 0) === 9, 'Unexpected UK order count');
payout_test_assert(($ukTypes['REFUND'] ?? 0) === 2, 'Unexpected UK refund count');
payout_test_assert(($ukTypes['OTHER_FEE'] ?? 0) === 83, 'Unexpected UK other-fee count');

$ukOrders = array_values(array_filter($uk['rows'], static function (array $row): bool {
    return $row['transaction_type'] === 'ORDER';
}));
$deOrders = array_values(array_filter($de['rows'], static function (array $row): bool {
    return $row['transaction_type'] === 'ORDER';
}));

payout_test_close(array_sum(array_column($ukOrders, 'gross_payout_amount')), 2026.09, 0.01, 'UK gross EUR total');
payout_test_close(array_sum(array_column($ukOrders, 'fee_payout_amount')), -276.18, 0.01, 'UK fee EUR total');
payout_test_close(array_sum(array_column($ukOrders, 'net_amount')), 1749.90, 0.01, 'UK net EUR total');
payout_test_close(array_sum(array_column($deOrders, 'gross_payout_amount')), 254.60, 0.01, 'DE gross EUR total');
payout_test_close(array_sum(array_column($deOrders, 'fee_payout_amount')), -31.46, 0.01, 'DE fee EUR total');
payout_test_close(array_sum(array_column($deOrders, 'net_amount')), 223.14, 0.01, 'DE net EUR total');

foreach (array_merge($ukOrders, $deOrders) as $orderRow) {
    payout_test_assert(
        abs((float) $orderRow['reconciliation_difference']) <= 0.01,
        'Gross amount plus fees must reconcile to the payout net amount'
    );
}

$refundAndOrder = array_values(array_filter($uk['rows'], static function (array $row): bool {
    return $row['order_number'] === '19-15191-41950';
}));
payout_test_assert(count($refundAndOrder) === 2, 'Expected order/refund pair was not parsed');
payout_test_assert($refundAndOrder[0]['source_key'] !== $refundAndOrder[1]['source_key'], 'Order and refund source keys must differ');

payout_test_close((float) accounting_payout_number('1,234.56'), 1234.56, 0.0001, 'UK grouped number');
payout_test_close((float) accounting_payout_number('1.234,56'), 1234.56, 0.0001, 'DE grouped number');

echo "accounting payout parser: OK\n";
