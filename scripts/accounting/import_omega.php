<?php
declare(strict_types=1);

$accountingOmegaCli = PHP_SAPI === 'cli';
if (!$accountingOmegaCli && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/omega_helpers.php';

function accounting_omega_redirect(array $params): void
{
    if (PHP_SAPI === 'cli') {
        echo json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(isset($params['error']) ? 1 : 0);
    }
    header('Location: ../../index.php?' . http_build_query(array_merge(['page' => 'accounting_omega'], $params)));
    exit;
}

function accounting_omega_match_key(?string $value): string
{
    $value = trim((string) $value);
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

if (!$accountingOmegaCli && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!$accountingOmegaCli && !accounting_payout_user_can_access()) {
    http_response_code(403);
    exit('Forbidden');
}

if ($accountingOmegaCli) {
    $requestedPath = (string) ($argv[1] ?? '');
    $resolvedPath = $requestedPath !== '' ? realpath($requestedPath) : false;
    if ($resolvedPath === false || !is_file($resolvedPath)) {
        accounting_omega_redirect(['error' => 'Použitie: php scripts/accounting/import_omega.php cesta/export.txt']);
    }
    $originalName = basename($resolvedPath);
    $tmpName = $resolvedPath;
    $size = (int) filesize($resolvedPath);
} else {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $expectedCsrf = (string) ($_SESSION['accounting_payout_csrf'] ?? '');
    if ($expectedCsrf === '' || !hash_equals($expectedCsrf, $csrf)) {
        accounting_omega_redirect(['error' => 'Neplatná alebo expirovaná požiadavka. Skúste import znova.']);
    }

    if (!isset($_FILES['omega_file'])) {
        accounting_omega_redirect(['error' => 'Vyberte OMEGA TXT alebo TSV súbor.']);
    }

    $upload = $_FILES['omega_file'];
    $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        accounting_omega_redirect(['error' => 'Upload OMEGA súboru zlyhal. Kód chyby: ' . $uploadError]);
    }

    $originalName = basename((string) ($upload['name'] ?? 'omega.txt'));
    $tmpName = (string) ($upload['tmp_name'] ?? '');
    $size = (int) ($upload['size'] ?? 0);
}

$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($extension, ['txt', 'tsv'], true)) {
    accounting_omega_redirect(['error' => 'OMEGA import podporuje iba .txt a .tsv súbory.']);
}
if ($size <= 0 || (!$accountingOmegaCli && !is_uploaded_file($tmpName))) {
    accounting_omega_redirect(['error' => 'OMEGA súbor je prázdny alebo neplatný.']);
}
if ($size > 30 * 1024 * 1024) {
    accounting_omega_redirect(['error' => 'OMEGA súbor je väčší ako 30 MB.']);
}
if (!($pdo instanceof PDO)) {
    accounting_omega_redirect(['error' => 'Databázové pripojenie nie je dostupné.']);
}

try {
    $pdo->query('SELECT 1 FROM accounting_omega_imports LIMIT 1');
    $pdo->query('SELECT 1 FROM accounting_omega_invoices LIMIT 1');
    $pdo->query('SELECT 1 FROM accounting_omega_invoice_items LIMIT 1');
} catch (Throwable $e) {
    accounting_omega_redirect(['error' => 'Najprv spustite databázovú migráciu db/accounting_omega.sql.']);
}

try {
    $parsed = accounting_omega_parse_file($tmpName, $originalName);

    $findImport = $pdo->prepare('SELECT id FROM accounting_omega_imports WHERE file_hash = ? LIMIT 1');
    $findImport->execute([$parsed['file_hash']]);
    if ($findImport->fetchColumn()) {
        accounting_omega_redirect(['existing_file' => 1]);
    }

    $orderMap = [];
    $orderRows = $pdo->query('SELECT id, external_order_id, order_number FROM orders ORDER BY id DESC');
    while ($row = $orderRows->fetch(PDO::FETCH_ASSOC)) {
        foreach ([$row['external_order_id'] ?? null, $row['order_number'] ?? null] as $candidate) {
            $key = accounting_omega_match_key($candidate);
            if ($key !== '' && !isset($orderMap[$key])) {
                $orderMap[$key] = (int) $row['id'];
            }
        }
    }

    $customOrderMap = [];
    $customRows = $pdo->query('SELECT id, internal_code, official_order_number FROM custom_orders ORDER BY id DESC');
    while ($row = $customRows->fetch(PDO::FETCH_ASSOC)) {
        foreach ([$row['official_order_number'] ?? null, $row['internal_code'] ?? null] as $candidate) {
            $key = accounting_omega_match_key($candidate);
            if ($key !== '' && !isset($customOrderMap[$key])) {
                $customOrderMap[$key] = (int) $row['id'];
            }
        }
    }

    $pdo->beginTransaction();

    $insertImport = $pdo->prepare(' 
        INSERT INTO accounting_omega_imports
          (original_filename, file_hash, detected_encoding, source_row_count,
           invoice_row_count, item_row_count, imported_by)
        VALUES
          (:filename, :file_hash, :encoding, :source_rows, :invoice_rows, :item_rows, :imported_by)
    ');
    $insertImport->execute([
        ':filename' => $parsed['original_name'],
        ':file_hash' => $parsed['file_hash'],
        ':encoding' => $parsed['encoding'],
        ':source_rows' => $parsed['source_row_count'],
        ':invoice_rows' => $parsed['invoice_row_count'],
        ':item_rows' => $parsed['item_row_count'],
        ':imported_by' => $accountingOmegaCli ? null : ((int) ($_SESSION['user_id'] ?? 0) ?: null),
    ]);
    $importId = (int) $pdo->lastInsertId();

    $findInvoice = $pdo->prepare('SELECT id, source_hash FROM accounting_omega_invoices WHERE invoice_number = ? LIMIT 1');
    $insertInvoice = $pdo->prepare(' 
        INSERT INTO accounting_omega_invoices
          (invoice_number, issue_date, order_number, payment_type, total_amount, currency,
           document_type, customer_name, item_count, source_hash, raw_json,
           matched_order_id, matched_custom_order_id, first_import_id, last_import_id)
        VALUES
          (:invoice_number, :issue_date, :order_number, :payment_type, :total_amount, :currency,
           :document_type, :customer_name, :item_count, :source_hash, :raw_json,
           :matched_order_id, :matched_custom_order_id, :first_import_id, :last_import_id)
    ');
    $updateInvoice = $pdo->prepare(' 
        UPDATE accounting_omega_invoices
        SET issue_date = :issue_date,
            order_number = :order_number,
            payment_type = :payment_type,
            total_amount = :total_amount,
            currency = :currency,
            document_type = :document_type,
            customer_name = :customer_name,
            item_count = :item_count,
            source_hash = :source_hash,
            raw_json = :raw_json,
            matched_order_id = :matched_order_id,
            matched_custom_order_id = :matched_custom_order_id,
            last_import_id = :last_import_id
        WHERE id = :id
    ');
    $deleteItems = $pdo->prepare('DELETE FROM accounting_omega_invoice_items WHERE invoice_id = ?');
    $insertItem = $pdo->prepare(' 
        INSERT INTO accounting_omega_invoice_items
          (invoice_id, line_number, description, quantity, unit, unit_price_without_vat, raw_json)
        VALUES
          (:invoice_id, :line_number, :description, :quantity, :unit, :unit_price, :raw_json)
    ');

    $created = 0;
    $updated = 0;
    $unchanged = 0;
    $matchedOrders = 0;
    $matchedCustomOrders = 0;

    foreach ($parsed['invoices'] as $invoice) {
        $matchKey = accounting_omega_match_key($invoice['order_number'] ?? null);
        $matchedOrderId = $matchKey !== '' ? ($orderMap[$matchKey] ?? null) : null;
        $matchedCustomOrderId = $matchKey !== '' ? ($customOrderMap[$matchKey] ?? null) : null;
        if ($matchedOrderId !== null) {
            $matchedOrders++;
        }
        if ($matchedCustomOrderId !== null) {
            $matchedCustomOrders++;
        }

        $findInvoice->execute([$invoice['invoice_number']]);
        $existing = $findInvoice->fetch(PDO::FETCH_ASSOC) ?: null;
        $invoiceParams = [
            ':issue_date' => $invoice['issue_date'],
            ':order_number' => $invoice['order_number'],
            ':payment_type' => $invoice['payment_type'],
            ':total_amount' => $invoice['total_amount'],
            ':currency' => $invoice['currency'],
            ':document_type' => $invoice['document_type'],
            ':customer_name' => $invoice['customer_name'],
            ':item_count' => $invoice['item_count'],
            ':source_hash' => $invoice['source_hash'],
            ':raw_json' => $invoice['raw_json'],
            ':matched_order_id' => $matchedOrderId,
            ':matched_custom_order_id' => $matchedCustomOrderId,
            ':last_import_id' => $importId,
        ];

        $rewriteItems = false;
        if ($existing === null) {
            $insertInvoice->execute($invoiceParams + [
                ':invoice_number' => $invoice['invoice_number'],
                ':first_import_id' => $importId,
            ]);
            $invoiceId = (int) $pdo->lastInsertId();
            $created++;
            $rewriteItems = true;
        } else {
            $invoiceId = (int) $existing['id'];
            $changed = !hash_equals((string) $existing['source_hash'], (string) $invoice['source_hash']);
            $updateInvoice->execute($invoiceParams + [':id' => $invoiceId]);
            if ($changed) {
                $updated++;
                $rewriteItems = true;
            } else {
                $unchanged++;
            }
        }

        if ($rewriteItems) {
            $deleteItems->execute([$invoiceId]);
            foreach ($invoice['items'] as $item) {
                $insertItem->execute([
                    ':invoice_id' => $invoiceId,
                    ':line_number' => $item['line_number'],
                    ':description' => $item['description'],
                    ':quantity' => $item['quantity'],
                    ':unit' => $item['unit'],
                    ':unit_price' => $item['unit_price_without_vat'],
                    ':raw_json' => $item['raw_json'],
                ]);
            }
        }
    }

    $updateImport = $pdo->prepare(' 
        UPDATE accounting_omega_imports
        SET created_invoice_count = :created,
            updated_invoice_count = :updated,
            unchanged_invoice_count = :unchanged,
            matched_order_count = :matched_orders,
            matched_custom_order_count = :matched_custom_orders
        WHERE id = :id
    ');
    $updateImport->execute([
        ':created' => $created,
        ':updated' => $updated,
        ':unchanged' => $unchanged,
        ':matched_orders' => $matchedOrders,
        ':matched_custom_orders' => $matchedCustomOrders,
        ':id' => $importId,
    ]);

    $pdo->commit();
    accounting_omega_redirect([
        'omega_imported' => 1,
        'created' => $created,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'items' => $parsed['item_row_count'],
        'matched_orders' => $matchedOrders,
        'matched_custom' => $matchedCustomOrders,
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    accounting_omega_redirect(['error' => $e->getMessage()]);
}
