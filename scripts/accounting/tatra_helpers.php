<?php
declare(strict_types=1);
require_once __DIR__ . '/tatra_bank_helpers.php';

function accounting_tatra_schema_ready(PDO $pdo): bool
{
    try {
        foreach (['accounting_tatra_imports', 'accounting_tatra_transactions'] as $table) {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function accounting_tatra_date(string $value, bool $time = true): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    $value = preg_replace('/\.\s*/', '.', $value) ?? $value;
    $formats = $time ? ['!j.n.Y G:i:s', '!Y-m-d H:i:s'] : ['!j.n.Y', '!Y-m-d'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) {
            return $date->format($time ? 'Y-m-d H:i:s' : 'Y-m-d');
        }
    }
    throw new RuntimeException('Neplatný dátum: ' . $value);
}

function accounting_tatra_amount(string $value): string
{
    $value = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], trim($value));
    if (!preg_match('/^[+-]?\d{1,14}(?:\.\d{1,2})?$/D', $value)) {
        throw new RuntimeException('Neplatná suma: ' . $value);
    }
    return number_format((float) $value, 2, '.', '');
}

function accounting_tatra_parse_file(string $file): array
{
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') throw new RuntimeException('CSV je prázdne alebo sa nedá prečítať.');
    if (strlen($raw) > 20 * 1024 * 1024) throw new RuntimeException('CSV je väčšie ako 20 MB.');
    $fileHash = hash('sha256', $raw);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    if (preg_match('//u', $raw) !== 1) {
        $raw = iconv('Windows-1250', 'UTF-8', $raw);
        if ($raw === false) throw new RuntimeException('Nepodporované kódovanie CSV.');
    }
    foreach ([',', ';', "\t"] as $bankDelimiter) {
        $bankHeader = array_map('trim', str_getcsv((string) strtok($raw, "\r\n"), $bankDelimiter, '"', ''));
        if (in_array('Dátum_spracovania', $bankHeader, true)) {
            return accounting_tatra_bank_parse($raw, $fileHash, $bankDelimiter);
        }
    }
    $required = ['transactionDate', 'billingDate', 'reportId', 'payoutReference', 'amount',
        'currency', 'settlementAmount', 'settlementCurrency', 'state', 'type', 'service',
        'payer', 'maskedCardNumber', 'orderId'];
    $first = strtok($raw, "\r\n");
    $delimiter = null;
    foreach ([',', ';', "\t"] as $candidate) {
        $header = array_map('trim', str_getcsv((string) $first, $candidate, '"', ''));
        if (!array_diff($required, $header) && count($header) === count(array_unique($header))) {
            $delimiter = $candidate;
            break;
        }
    }
    if ($delimiter === null) throw new RuntimeException('Hlavička Shoptet Pay ani Tatra banka CSV sa nenašla. Nahrajte pôvodný export.');
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $raw);
    rewind($stream);
    $header = array_map('trim', fgetcsv($stream, 0, $delimiter, '"', ''));
    $rows = [];
    $line = 1;
    try {
        while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if (count($values) === 1 && trim((string) $values[0]) === '') continue;
            if (count($values) !== count($header)) throw new RuntimeException('Nesprávny počet stĺpcov.');
            $data = array_combine($header, array_map(static function ($value): string { return trim((string) $value); }, $values));
            $type = ['Payment' => 'ORDER', 'Refund' => 'REFUND', 'Transaction Fees' => 'FEE', 'Payout of Funds' => 'PAYOUT'][$data['type']] ?? 'OTHER';
            $row = [
                'transaction_date' => accounting_tatra_date($data['transactionDate']),
                'billing_date' => $data['billingDate'] !== '' ? accounting_tatra_date($data['billingDate'], false) : null,
                'transaction_type' => $type, 'type_raw' => $data['type'], 'state' => strtoupper($data['state']),
                'order_number' => $data['orderId'] ?: null, 'payer_name' => $data['payer'] ?: null,
                'service' => $data['service'] ?: null, 'amount' => accounting_tatra_amount($data['amount']),
                'currency' => strtoupper($data['currency']),
                'settlement_amount' => accounting_tatra_amount($data['settlementAmount']),
                'settlement_currency' => strtoupper($data['settlementCurrency']),
                'report_id' => $data['reportId'] ?: null, 'payout_reference' => $data['payoutReference'] ?: null,
            ];
            foreach (['currency', 'settlement_currency'] as $field) {
                if (!preg_match('/^[A-Z]{3}$/D', $row[$field])) throw new RuntimeException('Neplatná mena.');
            }
            foreach (['type_raw' => 128, 'state' => 64, 'order_number' => 128, 'payer_name' => 255,
                'service' => 128, 'report_id' => 128, 'payout_reference' => 128] as $field => $limit) {
                if (mb_strlen((string) $row[$field], 'UTF-8') > $limit) throw new RuntimeException('Príliš dlhá hodnota: ' . $field);
            }
            if ($row['state'] === '' || $row['type_raw'] === '') throw new RuntimeException('Chýba typ alebo stav transakcie.');
            // Shoptet has no payment transaction ID. Billing metadata and state change
            // between daily reports; they must never form part of payment identity.
            $identity = [$row['type_raw'], $row['transaction_date'], $row['order_number'],
                $row['amount'], $row['currency'], $row['service'], $data['maskedCardNumber']];
            if ($type === 'PAYOUT' && $row['payout_reference']) $identity = ['PAYOUT', $row['payout_reference']];
            $row['source_key'] = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $rows[] = $row;
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Riadok ' . $line . ': ' . $e->getMessage(), 0, $e);
    } finally {
        fclose($stream);
    }
    if (!$rows) throw new RuntimeException('CSV neobsahuje transakcie.');
    return ['source' => 'SHOPTET', 'file_hash' => $fileHash, 'rows' => $rows, 'source_row_count' => count($rows)];
}

function accounting_tatra_find_order(PDO $pdo, ?string $number): ?int
{
    if ($number === null || $number === '') return null;
    $stmt = $pdo->prepare("SELECT o.id, o.external_order_id, o.order_number
        FROM orders o JOIN order_sources s ON s.id = o.source_id
        WHERE s.code = 'SHOPTET' AND (o.external_order_id = ? OR o.order_number = ?)");
    $stmt->execute([$number, $number]);
    // Check exact strings too: DB collations can ignore case or trailing spaces.
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if ((string) $candidate['external_order_id'] === $number || (string) $candidate['order_number'] === $number) {
            $ids[(int) $candidate['id']] = true;
        }
    }
    return count($ids) === 1 ? (int) array_key_first($ids) : null;
}

function accounting_tatra_state_rank(string $state): int
{
    return ['PENDING' => 0, 'APPROVED' => 1, 'BILLING' => 2, 'BILLED' => 3,
        'PAYOUT_CONFIRMED' => 3, 'DECLINED' => 4, 'CANCELLED' => 4, 'CANCELED' => 4,
        'FAILED' => 4, 'REVERSED' => 4, 'REFUNDED' => 4][$state] ?? 4;
}

/** Caller owns the transaction, including all files in an upload. */
function accounting_tatra_import(PDO $pdo, array $parsed, string $name, ?int $userId): array
{
    if (($parsed['source'] ?? 'SHOPTET') === 'BANK') return accounting_tatra_bank_import($pdo, $parsed, $name, $userId);
    $summary = ['imported' => 0, 'duplicates' => 0, 'matched' => 0, 'rematched' => 0];
    $findImport = $pdo->prepare('SELECT id FROM accounting_tatra_imports WHERE file_hash = ?');
    $findImport->execute([$parsed['file_hash']]);
    $importId = $findImport->fetchColumn();
    $newImport = !$importId;
    if ($newImport) {
        $stmt = $pdo->prepare('INSERT INTO accounting_tatra_imports
            (original_filename, file_hash, source_row_count, imported_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $parsed['file_hash'], $parsed['source_row_count'], $userId]);
        $importId = (int) $pdo->lastInsertId();
    }
    $find = $pdo->prepare('SELECT * FROM accounting_tatra_transactions WHERE source_key = ?');
    $fields = array_keys($parsed['rows'][0]);
    $insert = $pdo->prepare('INSERT INTO accounting_tatra_transactions (import_id, ' . implode(', ', $fields)
        . ', matched_order_id) VALUES (' . implode(', ', array_fill(0, count($fields) + 2, '?')) . ')');
    $update = $pdo->prepare('UPDATE accounting_tatra_transactions SET
        billing_date = ?, report_id = ?, payout_reference = ?, state = ?,
        settlement_amount = ?, settlement_currency = ? WHERE id = ?');
    $match = $pdo->prepare('UPDATE accounting_tatra_transactions SET matched_order_id = ? WHERE id = ? AND matched_order_id IS NULL');
    $countMatch = $pdo->prepare('UPDATE accounting_tatra_imports SET matched_order_count = matched_order_count + 1 WHERE id = ?');
    foreach ($parsed['rows'] as $row) {
        $find->execute([$row['source_key']]);
        $stored = $find->fetch(PDO::FETCH_ASSOC);
        if ($stored) {
            $summary['duplicates']++;
            if (accounting_tatra_state_rank($row['state']) >= accounting_tatra_state_rank($stored['state'])) {
                $update->execute([$row['billing_date'] ?? $stored['billing_date'], $row['report_id'] ?? $stored['report_id'],
                    $row['payout_reference'] ?? $stored['payout_reference'], $row['state'],
                    $row['settlement_amount'], $row['settlement_currency'], $stored['id']]);
            }
            if (!$stored['matched_order_id'] && in_array($stored['transaction_type'], ['ORDER', 'REFUND'], true)) {
                $orderId = accounting_tatra_find_order($pdo, $stored['order_number']);
                if ($orderId !== null) {
                    $match->execute([$orderId, $stored['id']]);
                    if ($match->rowCount()) {
                        $countMatch->execute([$stored['import_id']]);
                        $summary['matched']++;
                        $summary['rematched']++;
                    }
                }
            }
        } else {
            $orderId = in_array($row['transaction_type'], ['ORDER', 'REFUND'], true)
                ? accounting_tatra_find_order($pdo, $row['order_number']) : null;
            $insert->execute(array_merge([$importId], array_values($row), [$orderId]));
            $summary['imported']++;
            if ($orderId !== null) {
                $countMatch->execute([$importId]);
                $summary['matched']++;
            }
        }
    }
    if ($newImport || $summary['imported'] > 0) {
        $stmt = $pdo->prepare('UPDATE accounting_tatra_imports SET
            imported_row_count = imported_row_count + ?, duplicate_row_count = ? WHERE id = ?');
        $stmt->execute([$summary['imported'], $summary['duplicates'], $importId]);
    }
    return $summary;
}

function accounting_tatra_refresh_matches(PDO $pdo, string $from, string $to): int
{
    $stmt = $pdo->prepare("SELECT id, import_id, order_number FROM accounting_tatra_transactions
        WHERE transaction_date >= ? AND transaction_date < ? AND matched_order_id IS NULL
        AND transaction_type IN ('ORDER', 'REFUND')");
    $stmt->execute([$from, $to]);
    $update = $pdo->prepare('UPDATE accounting_tatra_transactions SET matched_order_id = ? WHERE id = ? AND matched_order_id IS NULL');
    $count = $pdo->prepare('UPDATE accounting_tatra_imports SET matched_order_count = matched_order_count + 1 WHERE id = ?');
    $matched = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = accounting_tatra_find_order($pdo, $row['order_number']);
        if ($id === null) continue;
        $update->execute([$id, $row['id']]);
        if ($update->rowCount()) {
            $count->execute([$row['import_id']]);
            $matched++;
        }
    }
    return $matched + accounting_tatra_bank_refresh($pdo, $from, $to);
}

/** Shared by the page's export count and the actual download. */
function accounting_tatra_export_rows(PDO $pdo, string $from, string $to, string $source = 'all'): array
{
    if ($source === 'bank') return accounting_tatra_bank_export_rows($pdo, $from, $to);
    $stmt = $pdo->prepare("SELECT t.transaction_date, t.settlement_amount,
        COALESCE(NULLIF(o.order_number, ''), o.external_order_id) AS export_order_number
        FROM accounting_tatra_transactions t
        JOIN orders o ON o.id = t.matched_order_id
        JOIN order_sources s ON s.id = o.source_id AND s.code = 'SHOPTET'
        WHERE t.transaction_date >= ? AND t.transaction_date < ?
          AND t.transaction_type = 'ORDER' AND t.state IN ('APPROVED', 'BILLING', 'BILLED')
          AND t.amount > 0 AND t.settlement_amount > 0 AND t.settlement_currency = 'EUR'
          AND COALESCE(NULLIF(o.order_number, ''), o.external_order_id, '') <> ''
        ORDER BY t.transaction_date, t.id");
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($source !== 'shoptet') $rows = array_merge($rows, accounting_tatra_bank_export_rows($pdo, $from, $to));
    usort($rows, static function (array $a, array $b): int {
        return strcmp($a['transaction_date'], $b['transaction_date'])
            ?: strcmp($a['export_order_number'], $b['export_order_number']);
    });
    return $rows;
}

function accounting_tatra_write_vycuc($out, array $rows): void
{
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Suma', 'Poplatok', 'Order Number', 'Tatra B.', 'IBAN partnera'], ';', '"', '');
    foreach ($rows as $row) {
        // Fees are separate report totals. A zero here means no allocated fee.
        $reference = (string) $row['export_order_number'];
        if (preg_match('/^[=+@\-\t\r\n]/', $reference)) $reference = "'" . $reference;
        fputcsv($out, [date('d.m.Y', strtotime($row['transaction_date'])),
            number_format((float) $row['settlement_amount'], 2, ',', ''), '0,00',
            $reference, 'Tatra B.', $row['partner_iban'] ?? ''], ';', '"', '');
    }
}
