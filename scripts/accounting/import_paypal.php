<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/paypal_helpers.php';

function accounting_paypal_import_redirect(array $params): void
{
    header('Location: ../../index.php?' . http_build_query(array_merge(['page' => 'accounting_paypal'], $params)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
if (!accounting_payout_user_can_access('accounting.import')) {
    http_response_code(403);
    exit('Forbidden');
}
$csrf = (string) ($_POST['csrf_token'] ?? '');
$expected = (string) ($_SESSION['accounting_paypal_csrf'] ?? '');
if ($expected === '' || !hash_equals($expected, $csrf)) {
    accounting_paypal_import_redirect(['error' => 'Neplatná alebo expirovaná požiadavka.']);
}
if (!($pdo instanceof PDO) || !accounting_paypal_schema_ready($pdo)) {
    accounting_paypal_import_redirect(['error' => 'Najprv spustite databázovú migráciu db/accounting_paypal.sql.']);
}
if (!isset($_FILES['paypal_file']) || (int) ($_FILES['paypal_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    accounting_paypal_import_redirect(['error' => 'Vyberte pôvodný PayPal CSV súbor.']);
}
$name = basename((string) ($_FILES['paypal_file']['name'] ?? 'paypal.csv'));
$tmp = (string) ($_FILES['paypal_file']['tmp_name'] ?? '');
$size = (int) ($_FILES['paypal_file']['size'] ?? 0);
if ($size <= 0 || $size > 20 * 1024 * 1024 || !is_uploaded_file($tmp)) {
    accounting_paypal_import_redirect(['error' => 'PayPal CSV je prázdne, neplatné alebo väčšie ako 20 MB.']);
}
if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
    accounting_paypal_import_redirect(['error' => 'Import podporuje iba CSV súbory.']);
}

try {
    $parsed = accounting_paypal_parse_file($tmp, $name);
    $exists = $pdo->prepare('SELECT id FROM accounting_paypal_imports WHERE file_hash = ? LIMIT 1');
    $exists->execute([$parsed['file_hash']]);
    if ($exists->fetchColumn()) {
        accounting_paypal_import_redirect(['existing' => 1]);
    }

    $pdo->beginTransaction();
    $insertImport = $pdo->prepare('
        INSERT INTO accounting_paypal_imports
          (original_filename, file_hash, detected_encoding, detected_delimiter, header_json, source_row_count, imported_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $insertImport->execute([
        $parsed['original_name'],
        $parsed['file_hash'],
        $parsed['encoding'],
        $parsed['delimiter'] === "\t" ? 'TAB' : $parsed['delimiter'],
        json_encode($parsed['header'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        count($parsed['rows']),
        (int) ($_SESSION['user_id'] ?? 0) ?: null,
    ]);
    $importId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare('
        INSERT IGNORE INTO accounting_paypal_transactions
          (import_id, source_key, source_row_number, transaction_date, transaction_time, payer_name,
           transaction_type, transaction_status, currency, gross_amount, fee_amount, net_amount,
           payer_email, transaction_id, reference_transaction_id, raw_invoice_number, item_title,
           subject_text, note_text, balance_impact, raw_json)
        VALUES
          (:import_id, :source_key, :source_row_number, :transaction_date, :transaction_time, :payer_name,
           :transaction_type, :transaction_status, :currency, :gross_amount, :fee_amount, :net_amount,
           :payer_email, :transaction_id, :reference_transaction_id, :raw_invoice_number, :item_title,
           :subject_text, :note_text, :balance_impact, :raw_json)
    ');
    $imported = 0;
    $duplicates = 0;
    foreach ($parsed['rows'] as $row) {
        $insert->execute([
            ':import_id' => $importId,
            ':source_key' => $row['source_key'],
            ':source_row_number' => $row['source_row_number'],
            ':transaction_date' => $row['transaction_date'],
            ':transaction_time' => $row['transaction_time'],
            ':payer_name' => $row['payer_name'],
            ':transaction_type' => $row['transaction_type'],
            ':transaction_status' => $row['transaction_status'],
            ':currency' => $row['currency'],
            ':gross_amount' => $row['gross_amount'],
            ':fee_amount' => $row['fee_amount'],
            ':net_amount' => $row['net_amount'],
            ':payer_email' => $row['payer_email'],
            ':transaction_id' => $row['transaction_id'],
            ':reference_transaction_id' => $row['reference_transaction_id'],
            ':raw_invoice_number' => $row['raw_invoice_number'],
            ':item_title' => $row['item_title'],
            ':subject_text' => $row['subject_text'],
            ':note_text' => $row['note_text'],
            ':balance_impact' => $row['balance_impact'],
            ':raw_json' => json_encode($row['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        if ($insert->rowCount() > 0) {
            $imported++;
        } else {
            $duplicates++;
        }
    }
    $pdo->commit();

    $range = $pdo->prepare('SELECT MIN(transaction_date), DATE_ADD(MAX(transaction_date), INTERVAL 1 DAY) FROM accounting_paypal_transactions WHERE import_id = ?');
    $range->execute([$importId]);
    [$from, $to] = $range->fetch(PDO::FETCH_NUM) ?: [null, null];
    $refresh = accounting_paypal_refresh_matches($pdo, $from ?: null, $to ?: null, (int) ($_SESSION['user_id'] ?? 0) ?: null);
    $counts = $pdo->prepare('
        SELECT
          SUM(export_order_number IS NOT NULL AND export_order_number <> \'\') AS matched_count,
          SUM(export_order_number IS NOT NULL AND export_order_number <> \'\' AND match_confidence < 90) AS review_count
        FROM accounting_paypal_transactions
        WHERE import_id = ? AND balance_impact IS NOT NULL AND balance_impact <> \'\'
    ');
    $counts->execute([$importId]);
    $summary = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
    $pdo->prepare('
        UPDATE accounting_paypal_imports
        SET imported_row_count = ?, duplicate_row_count = ?, matched_transaction_count = ?, review_transaction_count = ?
        WHERE id = ?
    ')->execute([$imported, $duplicates, (int) ($summary['matched_count'] ?? 0), (int) ($summary['review_count'] ?? 0), $importId]);

    $month = $from ? substr((string) $from, 0, 7) : date('Y-m');
    accounting_paypal_import_redirect([
        'month' => $month,
        'imported' => $imported,
        'duplicates' => $duplicates,
        'matched' => $refresh['matched'],
        'payments_added' => $refresh['payments_added'],
        'payments_linked' => $refresh['payments_linked'],
        'statuses_updated' => $refresh['statuses_updated'],
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    accounting_paypal_import_redirect(['error' => $e->getMessage()]);
}
