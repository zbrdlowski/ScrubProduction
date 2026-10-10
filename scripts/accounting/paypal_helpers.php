<?php
declare(strict_types=1);

function accounting_paypal_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function accounting_paypal_schema_ready(PDO $pdo): bool
{
    return accounting_paypal_table_exists($pdo, 'accounting_paypal_imports')
        && accounting_paypal_table_exists($pdo, 'accounting_paypal_transactions');
}

function accounting_paypal_utf8(string $raw, ?string &$encoding = null): string
{
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $encoding = 'UTF-8 BOM';
        return substr($raw, 3);
    }
    if (preg_match('//u', $raw) === 1) {
        $encoding = 'UTF-8';
        return $raw;
    }
    $encoding = 'Windows-1252';
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    $converted = iconv('Windows-1252', 'UTF-8//TRANSLIT', $raw);
    if ($converted === false) {
        throw new RuntimeException('Nepodporované kódovanie PayPal CSV.');
    }
    return $converted;
}

function accounting_paypal_parse_number($value): ?float
{
    $value = trim(str_replace(["\u{00A0}", ' '], '', (string) $value));
    if ($value === '') {
        return null;
    }
    if (substr_count($value, ',') > 0 && substr_count($value, '.') > 0) {
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif (substr_count($value, ',') === 1) {
        $value = str_replace(',', '.', $value);
    }
    return is_numeric($value) ? (float) $value : null;
}

function accounting_paypal_parse_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    foreach (['!d/m/Y', '!d.m.Y', '!Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }
    }
    throw new RuntimeException('Nepodporovaný dátum v PayPal CSV: ' . $value);
}

function accounting_paypal_parse_time(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $value, $match)) {
        return sprintf('%02d:%02d:%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
    }
    return null;
}

function accounting_paypal_normalize_so(string $value): string
{
    $value = strtoupper(trim($value));
    if (preg_match('/(SO\d{4,}(?:-\d+)?)$/', $value, $match)) {
        return $match[1];
    }
    return '';
}

function accounting_paypal_parse_file(string $path, string $originalName = ''): array
{
    $rawBytes = file_get_contents($path);
    if ($rawBytes === false || $rawBytes === '') {
        throw new RuntimeException('PayPal CSV je prázdne alebo nečitateľné.');
    }
    $encoding = null;
    $utf8 = accounting_paypal_utf8($rawBytes, $encoding);
    $lines = preg_split('/\R/u', $utf8);
    if (!is_array($lines) || !$lines) {
        throw new RuntimeException('PayPal CSV sa nepodarilo načítať.');
    }

    $delimiter = null;
    $header = [];
    foreach ([',', ';', "\t"] as $candidate) {
        $parsed = str_getcsv((string) $lines[0], $candidate);
        if (in_array('Transaction ID', $parsed, true) && in_array('Date', $parsed, true)) {
            $delimiter = $candidate;
            $header = $parsed;
            break;
        }
    }
    if ($delimiter === null) {
        throw new RuntimeException('PayPal CSV hlavička nebola rozpoznaná.');
    }
    foreach (['Date', 'Time', 'Name', 'Type', 'Status', 'Currency', 'Gross', 'Fee', 'Net', 'From Email Address', 'Transaction ID', 'Invoice Number', 'Subject', 'Note', 'Balance Impact'] as $required) {
        if (!in_array($required, $header, true)) {
            throw new RuntimeException('PayPal CSV nemá povinný stĺpec: ' . $required);
        }
    }

    $rows = [];
    for ($line = 1, $count = count($lines); $line < $count; $line++) {
        if (trim((string) $lines[$line]) === '') {
            continue;
        }
        $values = str_getcsv((string) $lines[$line], $delimiter);
        if (count($values) < count($header)) {
            $values = array_pad($values, count($header), '');
        }
        $raw = [];
        foreach ($header as $index => $name) {
            $raw[(string) $name] = (string) ($values[$index] ?? '');
        }
        $transactionId = trim($raw['Transaction ID'] ?? '');
        $type = trim($raw['Type'] ?? '');
        $identity = $transactionId !== ''
            ? 'transaction:' . $transactionId . ':' . $type
            : 'row:' . hash('sha256', json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $rows[] = [
            'source_key' => hash('sha256', $identity),
            'source_row_number' => $line + 1,
            'transaction_date' => accounting_paypal_parse_date((string) ($raw['Date'] ?? '')),
            'transaction_time' => accounting_paypal_parse_time((string) ($raw['Time'] ?? '')),
            'payer_name' => trim((string) ($raw['Name'] ?? '')) ?: null,
            'transaction_type' => $type ?: null,
            'transaction_status' => trim((string) ($raw['Status'] ?? '')) ?: null,
            'currency' => strtoupper(trim((string) ($raw['Currency'] ?? ''))) ?: null,
            'gross_amount' => accounting_paypal_parse_number($raw['Gross'] ?? null),
            'fee_amount' => accounting_paypal_parse_number($raw['Fee'] ?? null),
            'net_amount' => accounting_paypal_parse_number($raw['Net'] ?? null),
            'payer_email' => trim((string) ($raw['From Email Address'] ?? '')) ?: null,
            'transaction_id' => $transactionId ?: null,
            'reference_transaction_id' => trim((string) ($raw['Reference Txn ID'] ?? '')) ?: null,
            'raw_invoice_number' => trim((string) ($raw['Invoice Number'] ?? '')) ?: null,
            'item_title' => trim((string) ($raw['Item Title'] ?? '')) ?: null,
            'subject_text' => trim((string) ($raw['Subject'] ?? '')) ?: null,
            'note_text' => trim((string) ($raw['Note'] ?? '')) ?: null,
            'balance_impact' => trim((string) ($raw['Balance Impact'] ?? '')) ?: null,
            'raw' => $raw,
        ];
    }
    if (!$rows) {
        throw new RuntimeException('PayPal CSV neobsahuje žiadne dátové riadky.');
    }
    return [
        'original_name' => $originalName !== '' ? $originalName : basename($path),
        'file_hash' => hash('sha256', $rawBytes),
        'encoding' => $encoding,
        'delimiter' => $delimiter,
        'header' => $header,
        'rows' => $rows,
    ];
}

function accounting_paypal_extract_reference(array $row): array
{
    $rawInvoice = trim((string) ($row['raw_invoice_number'] ?? ''));
    if (preg_match('/^Shoptet\d+-(20\d{8})$/i', $rawInvoice, $match)) {
        return ['kind' => 'ESHOP', 'value' => $match[1]];
    }
    $text = implode(' ', [
        (string) ($row['item_title'] ?? ''),
        (string) ($row['subject_text'] ?? ''),
        (string) ($row['note_text'] ?? ''),
        (string) ($row['raw']['Custom Number'] ?? ''),
        $rawInvoice,
    ]);
    if (preg_match('/\b(?:GO)?(SO\d{4,}(?:-\d+)?)\b/i', $text, $match)) {
        return ['kind' => 'SO', 'value' => strtoupper($match[1])];
    }
    if (preg_match('/\b(20\d{8})\b/', $text, $match)) {
        return ['kind' => 'ESHOP', 'value' => $match[1]];
    }
    if (preg_match('/\b(\d{2}-\d{5}-\d{5})\b/', $text, $match)) {
        return ['kind' => 'EBAY', 'value' => $match[1]];
    }
    if (preg_match('/\b(CO\d{4,})\b/i', $text, $match)) {
        return ['kind' => 'CO', 'value' => strtoupper($match[1])];
    }
    if (preg_match('/\b(SK-\d+|SC\d+)\b/i', $text, $match)) {
        return ['kind' => 'SPECIAL', 'value' => strtoupper($match[1])];
    }
    return ['kind' => '', 'value' => ''];
}

function accounting_paypal_empty_match(string $method = 'UNMATCHED'): array
{
    return [
        'matched_order_id' => null,
        'matched_custom_order_id' => null,
        'matched_custom_code' => null,
        'export_order_number' => null,
        'match_method' => $method,
        'match_confidence' => 0,
    ];
}

function accounting_paypal_custom_match(array $record, string $method, int $confidence): array
{
    $official = strtoupper(trim((string) ($record['official_order_number'] ?? '')));
    $so = accounting_paypal_normalize_so($official);
    $exportNumber = $so;
    if ($exportNumber === '' && preg_match('/^(SK-\d+|SC\d+)$/', $official)) {
        $exportNumber = $official;
    }
    return [
        'matched_order_id' => !empty($record['production_order_id']) ? (int) $record['production_order_id'] : null,
        'matched_custom_order_id' => !empty($record['id']) ? (int) $record['id'] : null,
        'matched_custom_code' => trim((string) ($record['internal_code'] ?? '')) ?: null,
        'export_order_number' => $exportNumber !== '' ? $exportNumber : null,
        'match_method' => $exportNumber !== '' ? $method : $method . '_WAITING_SO',
        'match_confidence' => $confidence,
    ];
}

function accounting_paypal_match_row(PDO $pdo, array $row): array
{
    $transactionId = trim((string) ($row['transaction_id'] ?? ''));
    if ($transactionId !== '') {
        $stmt = $pdo->prepare('
            SELECT DISTINCT co.id, co.internal_code, co.official_order_number, co.production_order_id
            FROM custom_order_payments p
            JOIN custom_orders co ON co.id = p.custom_order_id
            WHERE BINARY TRIM(p.paypal_transaction_id) = BINARY ?
        ');
        $stmt->execute([$transactionId]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) === 1) {
            return accounting_paypal_custom_match($matches[0], 'TRANSACTION_ID', 100);
        }
        if (count($matches) > 1) {
            return accounting_paypal_empty_match('AMBIGUOUS_TRANSACTION_ID');
        }
    }

    $reference = accounting_paypal_extract_reference($row);
    if ($reference['kind'] === 'CO') {
        $stmt = $pdo->prepare('SELECT id, internal_code, official_order_number, production_order_id FROM custom_orders WHERE BINARY internal_code = BINARY ? ORDER BY id DESC');
        $stmt->execute([$reference['value']]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) === 1) {
            return accounting_paypal_custom_match($matches[0], 'CUSTOM_CODE', 98);
        }
        return accounting_paypal_empty_match(count($matches) > 1 ? 'AMBIGUOUS_CUSTOM_CODE' : 'CUSTOM_CODE_NOT_FOUND');
    }
    if ($reference['value'] !== '') {
        if (in_array($reference['kind'], ['SO', 'SPECIAL'], true)) {
            $stmt = $pdo->prepare('
                SELECT id, internal_code, official_order_number, production_order_id
                FROM custom_orders
                WHERE BINARY official_order_number = BINARY ? OR BINARY official_order_number = BINARY ?
                ORDER BY id DESC
            ');
            $stmt->execute([$reference['value'], 'GO' . $reference['value']]);
            $customMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($customMatches) === 1) {
                return accounting_paypal_custom_match($customMatches[0], 'EXPLICIT_' . $reference['kind'], 99);
            }
            if (count($customMatches) > 1) {
                return accounting_paypal_empty_match('AMBIGUOUS_EXPLICIT_' . $reference['kind']);
            }
        }
        $stmt = $pdo->prepare('SELECT id FROM orders WHERE BINARY order_number = BINARY ? OR BINARY external_order_id = BINARY ? ORDER BY id DESC LIMIT 2');
        $stmt->execute([$reference['value'], $reference['value']]);
        $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($orderIds) > 1) {
            return accounting_paypal_empty_match('AMBIGUOUS_EXPLICIT_' . $reference['kind']);
        }
        return [
            'matched_order_id' => count($orderIds) === 1 ? (int) $orderIds[0] : null,
            'matched_custom_order_id' => null,
            'matched_custom_code' => null,
            'export_order_number' => $reference['value'],
            'match_method' => 'EXPLICIT_' . $reference['kind'] . (count($orderIds) === 1 ? '' : '_NOT_FOUND_REVIEW'),
            'match_confidence' => count($orderIds) === 1 ? 99 : 85,
        ];
    }

    $email = trim((string) ($row['payer_email'] ?? ''));
    $name = trim((string) ($row['payer_name'] ?? ''));
    if ($email !== '' || $name !== '') {
        $stmt = $pdo->prepare('
            SELECT DISTINCT id, internal_code, official_order_number, production_order_id
            FROM custom_orders
            WHERE (? <> \'\' AND (
                    LOWER(TRIM(customer_email)) = LOWER(TRIM(?))
                 OR LOWER(TRIM(billing_email)) = LOWER(TRIM(?))
                 OR LOWER(TRIM(shipping_email)) = LOWER(TRIM(?))
            )) OR (? <> \'\' AND LOWER(TRIM(customer_name)) = LOWER(TRIM(?)))
            ORDER BY id DESC
        ');
        $stmt->execute([$email, $email, $email, $email, $name, $name]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) === 1) {
            return accounting_paypal_custom_match($matches[0], 'UNIQUE_IDENTITY_REVIEW', 70);
        }
        if (count($matches) > 1) {
            return accounting_paypal_empty_match('AMBIGUOUS_IDENTITY');
        }
    }
    return accounting_paypal_empty_match();
}

function accounting_paypal_update_match(PDO $pdo, int $id, array $match): void
{
    $stmt = $pdo->prepare('
        UPDATE accounting_paypal_transactions
        SET matched_order_id = :matched_order_id,
            matched_custom_order_id = :matched_custom_order_id,
            matched_custom_code = :matched_custom_code,
            export_order_number = :export_order_number,
            match_method = :match_method,
            match_confidence = :match_confidence
        WHERE id = :id AND manual_override = 0
    ');
    $stmt->execute([
        ':matched_order_id' => $match['matched_order_id'],
        ':matched_custom_order_id' => $match['matched_custom_order_id'],
        ':matched_custom_code' => $match['matched_custom_code'],
        ':export_order_number' => $match['export_order_number'],
        ':match_method' => $match['match_method'],
        ':match_confidence' => $match['match_confidence'],
        ':id' => $id,
    ]);
}

function accounting_paypal_log_custom_order_activity(PDO $pdo, int $customOrderId, ?int $actorId, string $action, array $payload, string $note): void
{
    if (!accounting_paypal_table_exists($pdo, 'custom_order_activity')) {
        return;
    }
    $stmt = $pdo->prepare('
        INSERT INTO custom_order_activity (custom_order_id, actor_employee_id, action, payload, note)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $customOrderId,
        $actorId && $actorId > 0 ? $actorId : null,
        $action,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $note,
    ]);
}

function accounting_paypal_assign_custom_payment(PDO $pdo, array $row, array $match, ?int $actorId = null): array
{
    $result = ['payment' => 'skipped', 'status_updated' => false];
    $customOrderId = (int) ($match['matched_custom_order_id'] ?? 0);
    $confidence = (int) ($match['match_confidence'] ?? 0);
    $transactionId = trim((string) ($row['transaction_id'] ?? ''));
    $amount = (float) ($row['gross_amount'] ?? 0);
    $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
    $transactionDate = trim((string) ($row['transaction_date'] ?? ''));
    $transactionTime = trim((string) ($row['transaction_time'] ?? ''));

    if ($customOrderId <= 0 || $confidence < 90 || strcasecmp(trim((string) ($row['balance_impact'] ?? '')), 'Credit') !== 0
        || $transactionId === '' || $amount <= 0 || $currency === '' || $transactionDate === ''
        || !accounting_paypal_table_exists($pdo, 'custom_order_payments')) {
        return $result;
    }

    $existingStmt = $pdo->prepare('
        SELECT id, custom_order_id
        FROM custom_order_payments
        WHERE BINARY TRIM(paypal_transaction_id) = BINARY ?
        ORDER BY id
        LIMIT 2
    ');
    $existingStmt->execute([$transactionId]);
    $existing = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($existing) {
        foreach ($existing as $payment) {
            if ((int) $payment['custom_order_id'] !== $customOrderId) {
                $result['payment'] = 'conflict';
                return $result;
            }
        }
        $result['payment'] = 'existing';
    } else {
        $manualStmt = $pdo->prepare('
            SELECT id
            FROM custom_order_payments
            WHERE custom_order_id = ?
              AND (paypal_transaction_id IS NULL OR TRIM(paypal_transaction_id) = \'\')
              AND ABS(amount - ?) < 0.005
              AND UPPER(TRIM(currency)) = ?
              AND DATE(received_at) = ?
            ORDER BY id
            LIMIT 2
        ');
        $manualStmt->execute([$customOrderId, $amount, $currency, $transactionDate]);
        $manualMatches = $manualStmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($manualMatches) > 1) {
            $result['payment'] = 'conflict';
            return $result;
        }
        if (count($manualMatches) === 1) {
            $paymentId = (int) $manualMatches[0];
            $pdo->prepare('
                UPDATE custom_order_payments
                SET paypal_transaction_id = ?,
                    note = CASE WHEN TRIM(COALESCE(note, \'\')) = \'\' THEN ? ELSE note END
                WHERE id = ?
            ')->execute([$transactionId, 'Automatic payment assignment', $paymentId]);
            accounting_paypal_log_custom_order_activity($pdo, $customOrderId, $actorId, 'payment_updated', [
                'payment_id' => $paymentId,
                'paypal_transaction_id' => $transactionId,
                'amount' => $amount,
                'currency' => $currency,
                'note' => 'Automatic payment assignment',
            ], 'PayPal Transaction ID assigned automatically');
            $result['payment'] = 'linked';
        } else {
            $receivedAt = $transactionDate . ' ' . ($transactionTime !== '' ? $transactionTime : '00:00:00');
            $insert = $pdo->prepare('
                INSERT INTO custom_order_payments
                  (custom_order_id, payment_kind, paypal_transaction_id, amount, currency, received_at, note, created_by)
                VALUES (?, \'DEPOSIT\', ?, ?, ?, ?, ?, ?)
            ');
            $insert->execute([
                $customOrderId,
                $transactionId,
                $amount,
                $currency,
                $receivedAt,
                'Automatic payment assignment',
                $actorId && $actorId > 0 ? $actorId : null,
            ]);
            $paymentId = (int) $pdo->lastInsertId();
            accounting_paypal_log_custom_order_activity($pdo, $customOrderId, $actorId, 'payment_added', [
                'payment_id' => $paymentId,
                'kind' => 'DEPOSIT',
                'paypal_transaction_id' => $transactionId,
                'amount' => $amount,
                'currency' => $currency,
                'note' => 'Automatic payment assignment',
            ], 'Automatic payment assignment');
            $result['payment'] = 'added';
        }
    }

    $statusStmt = $pdo->prepare('
        UPDATE custom_orders
        SET status = \'DEPOSIT_PAID\', updated_by = COALESCE(?, updated_by)
        WHERE id = ? AND status = \'LEAD\'
    ');
    $statusStmt->execute([$actorId && $actorId > 0 ? $actorId : null, $customOrderId]);
    if ($statusStmt->rowCount() > 0) {
        accounting_paypal_log_custom_order_activity($pdo, $customOrderId, $actorId, 'status_changed', [
            'from' => 'LEAD',
            'to' => 'DEPOSIT_PAID',
            'paypal_transaction_id' => $transactionId,
        ], 'Status changed automatically after PayPal payment assignment');
        $result['status_updated'] = true;
    }

    return $result;
}

function accounting_paypal_refresh_matches(PDO $pdo, ?string $from = null, ?string $to = null, ?int $actorId = null): array
{
    $where = [];
    $params = [];
    if ($from !== null) {
        $where[] = 'transaction_date >= ?';
        $params[] = $from;
    }
    if ($to !== null) {
        $where[] = 'transaction_date < ?';
        $params[] = $to;
    }
    $sql = 'SELECT * FROM accounting_paypal_transactions' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY transaction_date, transaction_time, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $byTransaction = [];
    foreach ($rows as $row) {
        if (!empty($row['transaction_id'])) {
            $byTransaction[(string) $row['transaction_id']][] = $row;
        }
    }

    $matched = 0;
    $review = 0;
    $unmatched = 0;
    $paymentsAdded = 0;
    $paymentsLinked = 0;
    $paymentsExisting = 0;
    $paymentConflicts = 0;
    $statusesUpdated = 0;
    foreach ($rows as $row) {
        if (!empty($row['manual_override'])) {
            continue;
        }
        $wasUnmatched = trim((string) ($row['export_order_number'] ?? '')) === '';
        $raw = json_decode((string) $row['raw_json'], true);
        if (!is_array($raw)) {
            $raw = [];
        }
        $candidateRow = $row;
        $candidateRow['raw'] = $raw;
        $match = accounting_paypal_match_row($pdo, $candidateRow);

        $referenceId = trim((string) ($row['reference_transaction_id'] ?? ''));
        if ($referenceId !== '') {
            $refStmt = $pdo->prepare('
                SELECT matched_order_id, matched_custom_order_id, matched_custom_code, export_order_number
                FROM accounting_paypal_transactions
                WHERE BINARY transaction_id = BINARY ? AND export_order_number IS NOT NULL AND export_order_number <> \'\'
                ORDER BY (balance_impact IS NOT NULL AND balance_impact <> \'\') DESC, match_confidence DESC, id DESC
                LIMIT 1
            ');
            $refStmt->execute([$referenceId]);
            $original = $refStmt->fetch(PDO::FETCH_ASSOC);
            if ($original) {
                $match = array_merge($match, $original, ['match_method' => 'REFERENCE_TRANSACTION', 'match_confidence' => 100]);
            }
        }

        $transactionId = trim((string) ($row['transaction_id'] ?? ''));
        if (($row['balance_impact'] ?? '') === '' && $transactionId !== '' && isset($byTransaction[$transactionId])) {
            foreach ($byTransaction[$transactionId] as $related) {
                if (($related['balance_impact'] ?? '') !== '' && !empty($related['export_order_number'])) {
                    $match = [
                        'matched_order_id' => $related['matched_order_id'] ? (int) $related['matched_order_id'] : null,
                        'matched_custom_order_id' => $related['matched_custom_order_id'] ? (int) $related['matched_custom_order_id'] : null,
                        'matched_custom_code' => $related['matched_custom_code'] ?: null,
                        'export_order_number' => $related['export_order_number'],
                        'match_method' => 'TRANSACTION_DETAIL',
                        'match_confidence' => (int) $related['match_confidence'],
                    ];
                    break;
                }
            }
        }
        accounting_paypal_update_match($pdo, (int) $row['id'], $match);
        $assignment = $wasUnmatched
            ? accounting_paypal_assign_custom_payment($pdo, $candidateRow, $match, $actorId)
            : ['payment' => 'skipped', 'status_updated' => false];
        if ($assignment['payment'] === 'added') {
            $paymentsAdded++;
        } elseif ($assignment['payment'] === 'linked') {
            $paymentsLinked++;
        } elseif ($assignment['payment'] === 'existing') {
            $paymentsExisting++;
        } elseif ($assignment['payment'] === 'conflict') {
            $paymentConflicts++;
        }
        if (!empty($assignment['status_updated'])) {
            $statusesUpdated++;
        }
        if (!empty($match['export_order_number'])) {
            $matched++;
            if ((int) $match['match_confidence'] < 90) {
                $review++;
            }
        } else {
            $unmatched++;
        }
    }
    return [
        'matched' => $matched,
        'review' => $review,
        'unmatched' => $unmatched,
        'payments_added' => $paymentsAdded,
        'payments_linked' => $paymentsLinked,
        'payments_existing' => $paymentsExisting,
        'payment_conflicts' => $paymentConflicts,
        'statuses_updated' => $statusesUpdated,
    ];
}

function accounting_paypal_valid_export_reference(string $value): bool
{
    $value = strtoupper(trim($value));
    return preg_match('/^(SO\d+(?:-\d+)?|20\d{8}|\d{2}-\d{5}-\d{5}|SK-\d+|SC\d+)$/', $value) === 1;
}
