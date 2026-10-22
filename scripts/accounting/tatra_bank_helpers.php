<?php
declare(strict_types=1);

function accounting_tatra_bank_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT source_key, manual_override, excluded FROM accounting_tatra_bank_transactions LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function accounting_tatra_bank_has_custom(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id, internal_code, official_order_number, production_order_id, status FROM custom_orders LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function accounting_tatra_bank_parse(string $raw, string $fileHash, string $delimiter): array
{
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $raw);
    rewind($stream);
    $header = array_map('trim', fgetcsv($stream, 0, $delimiter, '"', ''));
    $required = ['Dátum_spracovania', 'Dátum zúčtovania', 'Číslo_účtu', 'Číslo_protiúčtu',
        'Názov_protiúčtu', 'Suma', 'Mena', 'Popis_obratu', 'Variabilný_symbol',
        'Referencia_platiteľa', 'Správa_pre_prijímateľa1'];
    if (array_diff($required, $header) || count($header) !== count(array_unique($header))) {
        fclose($stream);
        throw new RuntimeException('Neúplná hlavička výpisu Tatra banky.');
    }
    $rows = [];
    $occurrences = [];
    $line = 1;
    try {
        while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if (count($values) === 1 && trim((string) $values[0]) === '') continue;
            if (count($values) !== count($header)) throw new RuntimeException('Nesprávny počet stĺpcov.');
            $data = array_combine($header, array_map(static function ($v): string { return (string) $v; }, $values));
            $message = '';
            $identification = '';
            for ($i = 1; $i <= 4; $i++) {
                // The bank wraps text at a fixed width, including inside an order
                // number. Keep original spaces and concatenate without separators.
                $message .= $data['Správa_pre_prijímateľa' . $i] ?? '';
                $identification .= $data['Identifikácia_protiúčtu' . $i] ?? '';
            }
            $note = trim($data['Poznámka'] ?? '');
            if ($note !== '') $message .= "\n" . $note;
            $amount = accounting_tatra_amount($data['Suma']);
            $payer = trim($data['Názov_protiúčtu']);
            $description = trim($data['Popis_obratu']);
            $payout = null;
            if (preg_match('/\bSWPE[A-Z0-9]+\b/i', $message, $hit)) $payout = strtoupper($hit[0]);
            $processor = $payout !== null
                || preg_match('/^(?:Shoptet\b|PayPal\b|eBay\b)/iu', $payer)
                || preg_match('/\/PAYPAL\b/i', $message);
            $type = (float) $amount > 0 ? ($processor ? 'TRANSFER' : 'ORDER') : 'OTHER';
            if ((float) $amount < 0 && in_array(mb_strtolower($description, 'UTF-8'),
                ['poplatok', 'transakčná daň', 'debetný úrok'], true)) $type = 'FEE';
            $ownPrefix = trim($data['Predčíslo_účtu'] ?? '');
            $otherPrefix = trim($data['Predčíslo_protiúčtu'] ?? '');
            $row = [
                'transaction_date' => accounting_tatra_date(trim($data['Dátum zúčtovania']), false) . ' 00:00:00',
                'processing_date' => accounting_tatra_date(trim($data['Dátum_spracovania']), false),
                'transaction_type' => $type, 'type_raw' => $description,
                'own_account' => ($ownPrefix !== '' ? $ownPrefix . '-' : '') . trim($data['Číslo_účtu']),
                'counterparty_account' => ($otherPrefix !== '' ? $otherPrefix . '-' : '') . trim($data['Číslo_protiúčtu']),
                'counterparty_bank' => trim($data['Kód_banky_protiúčtu'] ?? ''),
                'payer_name' => $payer, 'amount' => $amount, 'currency' => strtoupper(trim($data['Mena'])),
                'variable_symbol' => trim($data['Variabilný_symbol']),
                'payer_reference' => trim($data['Referencia_platiteľa']),
                'bank_reference' => trim($identification), 'payment_message' => trim($message),
                'payout_reference' => $payout,
            ];
            if (!preg_match('/^[A-Z]{3}$/D', $row['currency']) || $row['own_account'] === '') {
                throw new RuntimeException('Chýba mena alebo vlastný účet.');
            }
            foreach (['type_raw' => 128, 'own_account' => 64, 'counterparty_account' => 64,
                'counterparty_bank' => 32, 'payer_name' => 255, 'variable_symbol' => 128,
                'payer_reference' => 255, 'bank_reference' => 4096, 'payment_message' => 8192,
                'payout_reference' => 128] as $field => $limit) {
                if (mb_strlen((string) $row[$field], 'UTF-8') > $limit) throw new RuntimeException('Príliš dlhá hodnota: ' . $field);
            }
            // Balances, statement numbers and processing dates can change between
            // overlapping exports. Generic domestic identifiers are not unique.
            $identity = [$row['own_account'], $row['transaction_date'], $row['amount'], $row['currency'],
                $row['counterparty_account'], $row['counterparty_bank'], $row['bank_reference'],
                $row['variable_symbol'], $row['payer_reference'], $row['payment_message'], $row['type_raw']];
            $baseKey = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $occurrences[$baseKey] = ($occurrences[$baseKey] ?? 0) + 1;
            $row['source_key'] = hash('sha256', 'TATRA_BANK:' . $baseKey . ':' . $occurrences[$baseKey]);
            $rows[] = $row;
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Riadok ' . $line . ': ' . $e->getMessage(), 0, $e);
    } finally {
        fclose($stream);
    }
    if (!$rows) throw new RuntimeException('Bankový CSV výpis neobsahuje transakcie.');
    return ['source' => 'BANK', 'file_hash' => $fileHash, 'rows' => $rows, 'source_row_count' => count($rows)];
}

function accounting_tatra_bank_references(array $row): array
{
    $refs = [];
    $vs = trim((string) ($row['variable_symbol'] ?? ''));
    if ($vs !== '') $refs[] = $vs;
    $text = implode(' ', [$row['payment_message'] ?? '', $row['payer_reference'] ?? '']);
    if (preg_match_all('/\/VS(\d+)/i', $text, $matches)) $refs = array_merge($refs, $matches[1]);
    if (preg_match_all('/(?<![A-Z0-9])(?:(?:SO|CO|SK|SC)[ -]?\d+(?:-\d+)?|\d{2}-\d{5}-\d{5}|\d{6,15})(?![A-Z0-9-])/i', $text, $matches)) {
        foreach ($matches[0] as $ref) {
            $refs[] = strtoupper($ref);
            $refs[] = preg_replace('/^(SO|CO|SK|SC)[ -](\d)/i', '$1$2', strtoupper($ref));
        }
    }
    return array_values(array_unique($refs));
}

function accounting_tatra_bank_empty_match(string $method = 'UNMATCHED'): array
{
    return ['matched_order_id' => null, 'matched_custom_order_id' => null,
        'export_order_number' => null, 'match_method' => $method];
}

function accounting_tatra_bank_match(PDO $pdo, array $row, ?string $manualReference = null): array
{
    if ($row['transaction_type'] !== 'ORDER' || (float) $row['amount'] <= 0) {
        return accounting_tatra_bank_empty_match('NOT_ORDER');
    }
    $refs = $manualReference !== null ? [trim($manualReference)] : accounting_tatra_bank_references($row);
    $orders = $pdo->prepare('SELECT id, order_number, external_order_id FROM orders
        WHERE order_number = ? OR external_order_id = ?');
    $custom = accounting_tatra_bank_has_custom($pdo) ? $pdo->prepare('SELECT id, internal_code,
        official_order_number, production_order_id FROM custom_orders
        WHERE official_order_number = ? OR internal_code = ?') : null;
    $candidates = [];
    foreach ($refs as $ref) {
        if ($ref === '') continue;
        $orders->execute([$ref, $ref]);
        foreach ($orders->fetchAll(PDO::FETCH_ASSOC) as $order) {
            if (!in_array(strtoupper($ref), [strtoupper((string) $order['order_number']), strtoupper((string) $order['external_order_id'])], true)) continue;
            $reference = trim((string) ($order['order_number'] ?: $order['external_order_id']));
            if ($reference === '' || preg_match('/^CO[ -]?\d/i', $reference)) $reference = null;
            if (isset($candidates['order:' . $order['id']])) continue;
            $candidates['order:' . $order['id']] = ['matched_order_id' => (int) $order['id'],
                'matched_custom_order_id' => null, 'export_order_number' => $reference,
                'match_method' => $reference !== null ? 'REFERENCE' : 'WAITING_SO'];
        }
        if (!$custom) continue;
        $custom->execute([$ref, $ref]);
        foreach ($custom->fetchAll(PDO::FETCH_ASSOC) as $order) {
            if (!in_array(strtoupper($ref), [strtoupper((string) $order['official_order_number']), strtoupper((string) $order['internal_code'])], true)) continue;
            $productionId = (int) ($order['production_order_id'] ?? 0);
            $key = $productionId ? 'order:' . $productionId : 'custom:' . $order['id'];
            $reference = trim((string) $order['official_order_number']);
            if ($productionId) {
                $find = $pdo->prepare('SELECT order_number, external_order_id FROM orders WHERE id = ?');
                $find->execute([$productionId]);
                $production = $find->fetch(PDO::FETCH_ASSOC);
                if ($production) $reference = trim((string) ($production['order_number'] ?: $production['external_order_id']));
                else { $productionId = 0; $key = 'custom:' . $order['id']; }
            }
            if ($reference === '' || preg_match('/^CO[ -]?\d/i', $reference)) $reference = null;
            $candidates[$key] = ['matched_order_id' => $productionId ?: null,
                'matched_custom_order_id' => (int) $order['id'], 'export_order_number' => $reference,
                'match_method' => $reference !== null ? 'REFERENCE' : 'WAITING_SO'];
        }
    }
    if (count($candidates) !== 1) return accounting_tatra_bank_empty_match($candidates ? 'AMBIGUOUS' : 'UNMATCHED');
    $result = reset($candidates);
    if ($manualReference !== null && $result['export_order_number']) $result['match_method'] = 'MANUAL';
    return $result;
}

function accounting_tatra_bank_save_match(PDO $pdo, array $row, array $match): bool
{
    $wasMatched = !empty($row['export_order_number']);
    $isMatched = !empty($match['export_order_number']);
    $stmt = $pdo->prepare('UPDATE accounting_tatra_bank_transactions SET matched_order_id = ?,
        matched_custom_order_id = ?, export_order_number = ?, match_method = ? WHERE id = ?');
    $stmt->execute([$match['matched_order_id'], $match['matched_custom_order_id'],
        $match['export_order_number'], $match['match_method'], $row['id']]);
    $delta = (int) $isMatched - (int) $wasMatched;
    if ($delta) {
        $pdo->prepare('UPDATE accounting_tatra_imports SET matched_order_count = matched_order_count + ? WHERE id = ?')
            ->execute([$delta, $row['import_id']]);
    }
    return !$wasMatched && $isMatched;
}

/** The caller owns the transaction. No order, invoice or production state is changed. */
function accounting_tatra_bank_import(PDO $pdo, array $parsed, string $name, ?int $userId): array
{
    if (!accounting_tatra_bank_schema_ready($pdo)) throw new RuntimeException('Pre bankový CSV výpis spustite scripts/accounting/install_tatra.php --apply.');
    $summary = ['imported' => 0, 'duplicates' => 0, 'matched' => 0, 'rematched' => 0];
    $stmt = $pdo->prepare('SELECT id FROM accounting_tatra_imports WHERE file_hash = ?');
    $stmt->execute([$parsed['file_hash']]);
    $importId = $stmt->fetchColumn();
    $isNew = !$importId;
    if ($isNew) {
        $pdo->prepare('INSERT INTO accounting_tatra_imports (original_filename, file_hash, source_row_count, imported_by) VALUES (?, ?, ?, ?)')
            ->execute([$name, $parsed['file_hash'], $parsed['source_row_count'], $userId]);
        $importId = (int) $pdo->lastInsertId();
    }
    $find = $pdo->prepare('SELECT * FROM accounting_tatra_bank_transactions WHERE source_key = ?'
        . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $fields = array_keys($parsed['rows'][0]);
    $insert = $pdo->prepare('INSERT INTO accounting_tatra_bank_transactions (import_id, ' . implode(', ', $fields)
        . ') VALUES (' . implode(', ', array_fill(0, count($fields) + 1, '?')) . ')');
    foreach ($parsed['rows'] as $row) {
        $find->execute([$row['source_key']]);
        $saved = $find->fetch(PDO::FETCH_ASSOC);
        if ($saved) {
            $summary['duplicates']++;
            if (!(int) $saved['manual_override'] && !(int) $saved['excluded']) {
                $newMatch = accounting_tatra_bank_match($pdo, $saved);
                if (accounting_tatra_bank_save_match($pdo, $saved, $newMatch)) {
                    $summary['matched']++;
                    $summary['rematched']++;
                }
            }
        } else {
            $insert->execute(array_merge([$importId], array_values($row)));
            $row['id'] = (int) $pdo->lastInsertId();
            $row['import_id'] = $importId;
            $summary['imported']++;
            if (accounting_tatra_bank_save_match($pdo, $row, accounting_tatra_bank_match($pdo, $row))) $summary['matched']++;
        }
    }
    if ($isNew || $summary['imported'] > 0) {
        $pdo->prepare('UPDATE accounting_tatra_imports SET imported_row_count = imported_row_count + ?, duplicate_row_count = ? WHERE id = ?')
            ->execute([$summary['imported'], $summary['duplicates'], $importId]);
    }
    return $summary;
}

function accounting_tatra_bank_refresh(PDO $pdo, string $from, string $to): int
{
    if (!accounting_tatra_bank_schema_ready($pdo)) return 0;
    $stmt = $pdo->prepare("SELECT * FROM accounting_tatra_bank_transactions WHERE transaction_date >= ?
        AND transaction_date < ? AND transaction_type = 'ORDER' AND manual_override = 0 AND excluded = 0"
        . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $stmt->execute([$from, $to]);
    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (accounting_tatra_bank_save_match($pdo, $row, accounting_tatra_bank_match($pdo, $row))) $count++;
    }
    return $count;
}

function accounting_tatra_bank_manual(PDO $pdo, int $id, string $action, string $reference, ?int $userId): void
{
    $stmt = $pdo->prepare('SELECT * FROM accounting_tatra_bank_transactions WHERE id = ?'
        . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['transaction_type'] !== 'ORDER') throw new RuntimeException('Tento pohyb nie je priamou prijatou platbou.');
    if ($action === 'match') {
        $match = accounting_tatra_bank_match($pdo, $row, $reference);
        if (empty($match['export_order_number'])) throw new RuntimeException('Zadajte jednoznačné číslo existujúcej objednávky s oficiálnou exportnou referenciou.');
        $manual = 1; $excluded = 0;
    } elseif ($action === 'exclude') {
        $match = accounting_tatra_bank_empty_match('EXCLUDED');
        $manual = 1; $excluded = 1;
    } elseif ($action === 'reset') {
        $match = accounting_tatra_bank_match($pdo, $row);
        $manual = 0; $excluded = 0;
    } else {
        throw new RuntimeException('Neplatná operácia.');
    }
    accounting_tatra_bank_save_match($pdo, $row, $match);
    $pdo->prepare('UPDATE accounting_tatra_bank_transactions SET manual_override = ?, excluded = ?,
        reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$manual, $excluded, $userId, $id]);
}

/** Resolve the current reference and status; never export a deleted order or a CO lead. */
function accounting_tatra_bank_target(PDO $pdo, array $row): ?array
{
    if (!empty($row['matched_order_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$row['matched_order_id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order) return ['kind' => 'order', 'id' => (int) $order['id'],
            'reference' => trim((string) ($order['order_number'] ?: $order['external_order_id'])),
            'status' => $order['status'] ?? '', 'financial_total_value' => $order['financial_total_value'] ?? null,
            'financial_total_currency' => $order['financial_total_currency'] ?? $order['currency'] ?? ''];
    }
    if (!empty($row['matched_custom_order_id']) && accounting_tatra_bank_has_custom($pdo)) {
        $stmt = $pdo->prepare('SELECT id, official_order_number, production_order_id, status FROM custom_orders WHERE id = ?');
        $stmt->execute([$row['matched_custom_order_id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order) {
            if ($order['production_order_id'] && (int) $order['production_order_id'] !== (int) ($row['matched_order_id'] ?? 0)) {
                $production = accounting_tatra_bank_target($pdo, ['matched_order_id' => $order['production_order_id']]);
                if ($production) return $production;
            }
            return ['kind' => 'custom', 'id' => (int) $order['id'],
                'reference' => trim((string) $order['official_order_number']), 'status' => $order['status'],
                'financial_total_value' => null, 'financial_total_currency' => null];
        }
    }
    return null;
}

function accounting_tatra_bank_export_rows(PDO $pdo, string $from, string $to): array
{
    if (!accounting_tatra_bank_schema_ready($pdo)) return [];
    $stmt = $pdo->prepare("SELECT * FROM accounting_tatra_bank_transactions WHERE transaction_date >= ?
        AND transaction_date < ? AND transaction_type = 'ORDER' AND excluded = 0
        AND amount > 0 AND currency = 'EUR' AND (matched_order_id IS NOT NULL OR matched_custom_order_id IS NOT NULL)
        ORDER BY transaction_date, id");
    $stmt->execute([$from, $to]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $target = accounting_tatra_bank_target($pdo, $row);
        if (!$target || $target['reference'] === '' || preg_match('/^CO[ -]?\d/i', $target['reference'])) continue;
        $iban = strtoupper(str_replace(' ', '', (string) $row['counterparty_account']));
        $rows[] = ['transaction_date' => $row['transaction_date'], 'settlement_amount' => $row['amount'],
            'export_order_number' => $target['reference'],
            'partner_iban' => preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $iban) ? $iban : '',
            'source' => 'BANK'];
    }
    return $rows;
}


/** Actual receipts in uploaded files, all dates; never an order-balance write. */
function accounting_tatra_received_totals(PDO $pdo, array $target): array
{
    $totals = [];
    $add = static function (array $rows) use (&$totals): void {
        foreach ($rows as $row) {
            $currency = $row['currency'];
            if (!isset($totals[$currency])) $totals[$currency] = ['cents' => 0, 'count' => 0];
            $totals[$currency]['cents'] += (int) round((float) $row['total'] * 100);
            $totals[$currency]['count'] += (int) $row['payments'];
        }
    };
    if ($target['kind'] === 'order') {
        $stmt = $pdo->prepare("SELECT settlement_currency AS currency, SUM(settlement_amount) AS total, COUNT(*) AS payments
            FROM accounting_tatra_transactions WHERE matched_order_id = ? AND transaction_type = 'ORDER'
            AND state IN ('APPROVED', 'BILLING', 'BILLED') AND amount > 0 AND settlement_amount > 0 GROUP BY settlement_currency");
        $stmt->execute([$target['id']]);
        $add($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    if (accounting_tatra_bank_schema_ready($pdo)) {
        $params = [$target['id']];
        if ($target['kind'] === 'order') {
            $where = 'matched_order_id = ?';
            if (accounting_tatra_bank_has_custom($pdo)) {
                $where .= ' OR (matched_order_id IS NULL AND matched_custom_order_id IN (SELECT id FROM custom_orders WHERE production_order_id = ?))';
                $params[] = $target['id'];
            }
        } else {
            $where = 'matched_custom_order_id = ? AND matched_order_id IS NULL';
        }
        $stmt = $pdo->prepare("SELECT currency, SUM(amount) AS total, COUNT(*) AS payments
            FROM accounting_tatra_bank_transactions WHERE (" . $where . ")
            AND transaction_type = 'ORDER' AND excluded = 0 AND amount > 0 GROUP BY currency");
        $stmt->execute($params);
        $add($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    ksort($totals);
    return $totals;
}

/** Reconcile processor transfers without treating them as new customer payments. */
function accounting_tatra_bank_transfer_check(PDO $pdo, array $row): string
{
    if ($row['transaction_type'] !== 'TRANSFER' || empty($row['payout_reference'])) return '';
    $stmt = $pdo->prepare("SELECT settlement_amount, settlement_currency FROM accounting_tatra_transactions
        WHERE transaction_type = 'PAYOUT' AND payout_reference = ?");
    $stmt->execute([$row['payout_reference']]);
    $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$payouts) return 'MISSING';
    if (count($payouts) !== 1) return 'AMBIGUOUS';
    $payout = $payouts[0];
    return $payout['settlement_currency'] === $row['currency']
        && (int) round((float) $payout['settlement_amount'] * 100) === -(int) round((float) $row['amount'] * 100)
        ? 'MATCHED' : 'DIFFERENCE';
}
