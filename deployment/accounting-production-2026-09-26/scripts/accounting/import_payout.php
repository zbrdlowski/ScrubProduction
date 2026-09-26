<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/payout_helpers.php';

function accounting_payout_redirect(array $params): void
{
    header('Location: ../../index.php?' . http_build_query(array_merge(['page' => 'accounting_payouts'], $params)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!accounting_payout_user_can_access()) {
    http_response_code(403);
    exit('Forbidden');
}

$csrf = (string) ($_POST['csrf_token'] ?? '');
$expectedCsrf = (string) ($_SESSION['accounting_payout_csrf'] ?? '');
if ($expectedCsrf === '' || !hash_equals($expectedCsrf, $csrf)) {
    accounting_payout_redirect(['error' => 'Neplatná alebo expirovaná požiadavka. Skúste import znova.']);
}

if (!isset($_FILES['payout_files'])) {
    accounting_payout_redirect(['error' => 'Vyberte aspoň jeden payout CSV súbor.']);
}

$upload = $_FILES['payout_files'];
$names = is_array($upload['name'] ?? null) ? $upload['name'] : [$upload['name'] ?? ''];
$tmpNames = is_array($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : [$upload['tmp_name'] ?? ''];
$sizes = is_array($upload['size'] ?? null) ? $upload['size'] : [$upload['size'] ?? 0];
$errors = is_array($upload['error'] ?? null) ? $upload['error'] : [$upload['error'] ?? UPLOAD_ERR_NO_FILE];

if (!($pdo instanceof PDO)) {
    accounting_payout_redirect(['error' => 'Databázové pripojenie nie je dostupné.']);
}

try {
    $pdo->query('SELECT 1 FROM accounting_payout_imports LIMIT 1');
    $pdo->query('SELECT 1 FROM accounting_payout_transactions LIMIT 1');
} catch (Throwable $e) {
    accounting_payout_redirect(['error' => 'Najprv spustite databázovú migráciu db/accounting_payouts.sql.']);
}

$findImport = $pdo->prepare('SELECT id FROM accounting_payout_imports WHERE file_hash = ? LIMIT 1');
$insertImport = $pdo->prepare('
    INSERT INTO accounting_payout_imports
      (original_filename, file_hash, source_region, detected_encoding, detected_delimiter,
       source_row_count, imported_by)
    VALUES
      (:original_filename, :file_hash, :source_region, :detected_encoding, :detected_delimiter,
       :source_row_count, :imported_by)
');
$updateImport = $pdo->prepare('
    UPDATE accounting_payout_imports
    SET imported_row_count = :imported_rows,
        duplicate_row_count = :duplicate_rows,
        matched_order_count = :matched_orders
    WHERE id = :id
');
$findOrder = $pdo->prepare('
    SELECT id
    FROM orders
    WHERE external_order_id = :external_number OR order_number = :order_number
    ORDER BY CASE WHEN external_order_id = :sort_number THEN 0 ELSE 1 END, id DESC
    LIMIT 1
');

$transactionFields = [
    'source_key', 'source_region', 'transaction_date', 'transaction_type', 'transaction_type_raw',
    'order_number', 'legacy_order_id', 'buyer_username', 'buyer_name', 'post_country',
    'net_amount', 'payout_currency', 'payout_date', 'payout_id', 'payout_status',
    'item_id', 'transaction_id', 'item_title', 'custom_label', 'quantity',
    'item_subtotal', 'postage', 'seller_collected_tax', 'ebay_collected_tax',
    'fixed_fee', 'variable_fee', 'regulatory_fee', 'very_high_inad_fee', 'below_standard_fee',
    'international_fee', 'gross_transaction_amount', 'transaction_currency', 'exchange_rate',
    'gross_payout_amount', 'fee_payout_amount', 'reconciliation_difference', 'reference_id',
    'description', 'raw_json',
];
$insertColumns = array_merge(['import_id'], $transactionFields, ['matched_order_id']);
$insertTransaction = $pdo->prepare(
    'INSERT IGNORE INTO accounting_payout_transactions (' . implode(', ', $insertColumns) . ') VALUES (' .
    implode(', ', array_map(static function (string $field): string {
        return ':' . $field;
    }, $insertColumns)) . ')'
);

$summary = [
    'files' => 0,
    'existing_files' => 0,
    'imported' => 0,
    'duplicates' => 0,
    'matched' => 0,
];

try {
    foreach ($names as $index => $originalName) {
        $uploadError = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload zlyhal pre súbor ' . basename((string) $originalName) . '.');
        }

        $tmpName = (string) ($tmpNames[$index] ?? '');
        $size = (int) ($sizes[$index] ?? 0);
        if ($size <= 0 || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Súbor ' . basename((string) $originalName) . ' je prázdny alebo neplatný.');
        }
        if ($size > 15 * 1024 * 1024) {
            throw new RuntimeException('Súbor ' . basename((string) $originalName) . ' je väčší ako 15 MB.');
        }
        if (strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('Payout import podporuje iba CSV súbory.');
        }

        $parsed = accounting_payout_parse_file($tmpName, basename((string) $originalName));
        $findImport->execute([$parsed['file_hash']]);
        if ($findImport->fetchColumn()) {
            $summary['existing_files']++;
            continue;
        }

        $pdo->beginTransaction();
        $insertImport->execute([
            ':original_filename' => $parsed['original_name'],
            ':file_hash' => $parsed['file_hash'],
            ':source_region' => $parsed['source_region'],
            ':detected_encoding' => $parsed['encoding'],
            ':detected_delimiter' => $parsed['delimiter'] === "\t" ? 'TAB' : $parsed['delimiter'],
            ':source_row_count' => $parsed['source_row_count'],
            ':imported_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
        ]);
        $importId = (int) $pdo->lastInsertId();
        $fileImported = 0;
        $fileDuplicates = 0;
        $fileMatched = 0;

        foreach ($parsed['rows'] as $row) {
            $matchedOrderId = null;
            if (!empty($row['order_number'])) {
                $findOrder->execute([
                    ':external_number' => $row['order_number'],
                    ':order_number' => $row['order_number'],
                    ':sort_number' => $row['order_number'],
                ]);
                $found = $findOrder->fetchColumn();
                $matchedOrderId = $found !== false ? (int) $found : null;
            }

            $params = [':import_id' => $importId, ':matched_order_id' => $matchedOrderId];
            foreach ($transactionFields as $field) {
                $params[':' . $field] = $row[$field] ?? null;
            }
            $insertTransaction->execute($params);
            if ($insertTransaction->rowCount() > 0) {
                $fileImported++;
                if ($matchedOrderId !== null) {
                    $fileMatched++;
                }
            } else {
                $fileDuplicates++;
            }
        }

        $updateImport->execute([
            ':imported_rows' => $fileImported,
            ':duplicate_rows' => $fileDuplicates,
            ':matched_orders' => $fileMatched,
            ':id' => $importId,
        ]);
        $pdo->commit();

        $summary['files']++;
        $summary['imported'] += $fileImported;
        $summary['duplicates'] += $fileDuplicates;
        $summary['matched'] += $fileMatched;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    accounting_payout_redirect(['error' => $e->getMessage()]);
}

accounting_payout_redirect([
    'imported' => $summary['imported'],
    'duplicates' => $summary['duplicates'],
    'matched' => $summary['matched'],
    'files' => $summary['files'],
    'existing_files' => $summary['existing_files'],
]);
