<?php
declare(strict_types=1);

function accounting_payout_order_match_statement(PDO $pdo): PDOStatement
{
    return $pdo->prepare('
        SELECT id
        FROM orders
        WHERE external_order_id = :external_number OR order_number = :order_number
        ORDER BY CASE WHEN external_order_id = :sort_number THEN 0 ELSE 1 END, id DESC
        LIMIT 1
    ');
}

/**
 * Retry only unmatched transactions present in this report, including rows
 * originally stored by an overlapping report. The caller owns the transaction.
 */
function accounting_payout_rematch_rows(PDO $pdo, array $rows): int
{
    $findTransaction = $pdo->prepare('
        SELECT id, import_id, order_number
        FROM accounting_payout_transactions
        WHERE source_key = ? AND matched_order_id IS NULL
    ');
    $findOrder = accounting_payout_order_match_statement($pdo);
    $matchTransaction = $pdo->prepare('
        UPDATE accounting_payout_transactions
        SET matched_order_id = :matched_order_id
        WHERE id = :id AND matched_order_id IS NULL
    ');
    $updateImport = $pdo->prepare('
        UPDATE accounting_payout_imports
        SET matched_order_count = matched_order_count + 1
        WHERE id = ?
    ');

    $matched = 0;
    foreach ($rows as $row) {
        $findTransaction->execute([$row['source_key']]);
        $stored = $findTransaction->fetch(PDO::FETCH_ASSOC);
        if (!$stored || empty($stored['order_number'])) {
            continue;
        }

        // Use the saved order number; a repeat upload must not replace payment data.
        $findOrder->execute([
            ':external_number' => $stored['order_number'],
            ':order_number' => $stored['order_number'],
            ':sort_number' => $stored['order_number'],
        ]);
        $orderId = $findOrder->fetchColumn();
        if ($orderId === false) {
            continue;
        }

        $matchTransaction->execute([
            ':matched_order_id' => (int) $orderId,
            ':id' => (int) $stored['id'],
        ]);
        if ($matchTransaction->rowCount() > 0) {
            $updateImport->execute([(int) $stored['import_id']]);
            $matched++;
        }
    }

    return $matched;
}
