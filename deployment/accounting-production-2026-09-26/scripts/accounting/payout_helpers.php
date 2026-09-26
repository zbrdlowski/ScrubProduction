<?php
declare(strict_types=1);

/**
 * Parser for raw eBay payout transaction reports.
 *
 * eBay currently exports the UK report as comma-separated English text and
 * the DE report as semicolon-separated German text. Both contain a ten-line
 * explanatory preamble before the actual 38-column transaction table.
 */

function accounting_payout_utf8(string $raw, ?string &$encoding = null): string
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
        throw new RuntimeException('Unsupported payout file encoding.');
    }
    return $converted;
}

function accounting_payout_text_key(string $value): string
{
    $value = trim($value);
    $value = str_replace(["\u{00A0}", "\u{2013}", "\u{2014}"], [' ', '-', '-'], $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function accounting_payout_detect_table(array $lines): array
{
    $firstHeaders = ['transaction creation date', 'datum der transaktionserstellung'];
    $max = min(count($lines), 40);

    for ($lineIndex = 0; $lineIndex < $max; $lineIndex++) {
        foreach ([',', ';', "\t"] as $delimiter) {
            $candidate = str_getcsv((string) $lines[$lineIndex], $delimiter);
            if (count($candidate) < 3) {
                continue;
            }

            $first = accounting_payout_text_key((string) ($candidate[0] ?? ''));
            if (in_array($first, $firstHeaders, true)) {
                return [
                    'header_index' => $lineIndex,
                    'delimiter' => $delimiter,
                    'header' => $candidate,
                    'region' => $first === 'datum der transaktionserstellung' ? 'DE' : 'UK',
                ];
            }
        }
    }

    throw new RuntimeException('The eBay transaction table header was not found.');
}

function accounting_payout_number($value): ?float
{
    if ($value === null || is_array($value) || is_object($value)) {
        return null;
    }

    $value = trim(str_replace(["\u{00A0}", ' '], '', (string) $value));
    if ($value === '' || $value === '--') {
        return null;
    }

    $commaCount = substr_count($value, ',');
    $dotCount = substr_count($value, '.');
    if ($commaCount > 0 && $dotCount > 0) {
        // The last separator is the decimal separator. This supports both
        // 1,234.56 (UK) and 1.234,56 (DE) exports.
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($commaCount === 1) {
        $value = str_replace(',', '.', $value);
    } elseif ($commaCount > 1) {
        $value = str_replace(',', '', $value);
    } elseif ($dotCount > 1) {
        $value = str_replace('.', '', $value);
    }

    return is_numeric($value) ? (float) $value : null;
}

function accounting_payout_date($value): ?string
{
    $value = trim((string) $value);
    if ($value === '' || $value === '--') {
        return null;
    }

    foreach (['!d.m.Y', '!Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            $date instanceof DateTimeImmutable
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        ) {
            return $date->format('Y-m-d');
        }
    }

    $normalized = accounting_payout_text_key($value);
    $normalized = str_replace('.', '', $normalized);
    $months = [
        'jan' => 1, 'january' => 1, 'januar' => 1,
        'feb' => 2, 'february' => 2, 'februar' => 2,
        'mar' => 3, 'march' => 3, 'märz' => 3, 'maerz' => 3,
        'apr' => 4, 'april' => 4,
        'may' => 5, 'mai' => 5,
        'jun' => 6, 'june' => 6, 'juni' => 6,
        'jul' => 7, 'july' => 7, 'juli' => 7,
        'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'oct' => 10, 'october' => 10, 'okt' => 10, 'oktober' => 10,
        'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12, 'dez' => 12, 'dezember' => 12,
    ];

    if (preg_match('/^(\d{1,2})\s+([^\s]+)\s+(\d{4})$/u', $normalized, $matches)) {
        $monthKey = $matches[2];
        if (isset($months[$monthKey])) {
            return sprintf('%04d-%02d-%02d', (int) $matches[3], $months[$monthKey], (int) $matches[1]);
        }
    }

    throw new RuntimeException('Unsupported payout date: ' . $value);
}

function accounting_payout_type(string $rawType): string
{
    $type = accounting_payout_text_key($rawType);
    $map = [
        'order' => 'ORDER',
        'bestellung' => 'ORDER',
        'refund' => 'REFUND',
        'rückerstattung' => 'REFUND',
        'other fee' => 'OTHER_FEE',
        'andere gebühr' => 'OTHER_FEE',
    ];

    if (isset($map[$type])) {
        return $map[$type];
    }

    $type = preg_replace('/[^a-z0-9]+/i', '_', $type) ?? '';
    return strtoupper(trim($type, '_')) ?: 'OTHER';
}

function accounting_payout_cell(array $row, int $index): ?string
{
    if (!array_key_exists($index, $row)) {
        return null;
    }
    $value = trim((string) $row[$index]);
    return $value === '' ? null : $value;
}

function accounting_payout_source_key(array $row): string
{
    $identity = null;
    if (!empty($row['transaction_id'])) {
        $identity = 'transaction:' . $row['transaction_id'] . ':' . $row['transaction_type'];
    } elseif (!empty($row['reference_id'])) {
        $identity = 'reference:' . $row['reference_id'] . ':' . $row['transaction_type'];
    }

    if ($identity === null) {
        $identity = implode('|', [
            $row['payout_id'] ?? '',
            $row['transaction_type'] ?? '',
            $row['order_number'] ?? '',
            $row['item_id'] ?? '',
            $row['transaction_date'] ?? '',
            $row['gross_transaction_amount'] ?? '',
            $row['net_amount'] ?? '',
            $row['description'] ?? '',
        ]);
    }

    return hash('sha256', $identity);
}

function accounting_payout_normalize_row(array $row, array $header, string $region): array
{
    if (count($row) < 38) {
        $row = array_pad($row, 38, '');
    }

    $raw = [];
    foreach ($header as $index => $name) {
        $raw[(string) $name] = $row[$index] ?? null;
    }

    $transactionCurrency = strtoupper((string) (accounting_payout_cell($row, 34) ?? ''));
    $payoutCurrency = strtoupper((string) (accounting_payout_cell($row, 11) ?? ''));
    $exchangeRate = accounting_payout_number($row[35] ?? null);
    if ($exchangeRate === null && $transactionCurrency !== '' && $transactionCurrency === $payoutCurrency) {
        $exchangeRate = 1.0;
    }

    $feeValues = [];
    foreach ([27, 28, 29, 30, 31, 32] as $feeIndex) {
        $feeValues[] = accounting_payout_number($row[$feeIndex] ?? null) ?? 0.0;
    }

    $gross = accounting_payout_number($row[33] ?? null);
    $net = accounting_payout_number($row[10] ?? null);
    $feeTransaction = round(array_sum($feeValues), 6);
    $grossPayout = $gross !== null && $exchangeRate !== null ? round($gross * $exchangeRate, 2) : null;
    $feePayout = $exchangeRate !== null ? round($feeTransaction * $exchangeRate, 2) : null;
    $reconciliationDifference = $net !== null && $grossPayout !== null && $feePayout !== null
        ? round($net - ($grossPayout + $feePayout), 2)
        : null;

    $normalized = [
        'source_region' => $region,
        'transaction_date' => accounting_payout_date($row[0] ?? null),
        'transaction_type_raw' => accounting_payout_cell($row, 1),
        'transaction_type' => accounting_payout_type((string) ($row[1] ?? '')),
        'order_number' => (($value = accounting_payout_cell($row, 2)) === '--') ? null : $value,
        'legacy_order_id' => (($value = accounting_payout_cell($row, 3)) === '--') ? null : $value,
        'buyer_username' => (($value = accounting_payout_cell($row, 4)) === '--') ? null : $value,
        'buyer_name' => (($value = accounting_payout_cell($row, 5)) === '--') ? null : $value,
        'post_country' => (($value = accounting_payout_cell($row, 9)) === '--') ? null : $value,
        'net_amount' => $net,
        'payout_currency' => $payoutCurrency ?: null,
        'payout_date' => accounting_payout_date($row[12] ?? null),
        'payout_id' => (($value = accounting_payout_cell($row, 13)) === '--') ? null : $value,
        'payout_status' => (($value = accounting_payout_cell($row, 15)) === '--') ? null : $value,
        'item_id' => (($value = accounting_payout_cell($row, 17)) === '--') ? null : $value,
        'transaction_id' => (($value = accounting_payout_cell($row, 18)) === '--') ? null : $value,
        'item_title' => (($value = accounting_payout_cell($row, 19)) === '--') ? null : $value,
        'custom_label' => (($value = accounting_payout_cell($row, 20)) === '--') ? null : $value,
        'quantity' => accounting_payout_number($row[21] ?? null),
        'item_subtotal' => accounting_payout_number($row[22] ?? null),
        'postage' => accounting_payout_number($row[23] ?? null),
        'seller_collected_tax' => accounting_payout_number($row[24] ?? null),
        'ebay_collected_tax' => accounting_payout_number($row[25] ?? null),
        'fixed_fee' => accounting_payout_number($row[27] ?? null),
        'variable_fee' => accounting_payout_number($row[28] ?? null),
        'regulatory_fee' => accounting_payout_number($row[29] ?? null),
        'very_high_inad_fee' => accounting_payout_number($row[30] ?? null),
        'below_standard_fee' => accounting_payout_number($row[31] ?? null),
        'international_fee' => accounting_payout_number($row[32] ?? null),
        'gross_transaction_amount' => $gross,
        'transaction_currency' => $transactionCurrency ?: null,
        'exchange_rate' => $exchangeRate,
        'gross_payout_amount' => $grossPayout,
        'fee_payout_amount' => $feePayout,
        'reconciliation_difference' => $reconciliationDifference,
        'reference_id' => (($value = accounting_payout_cell($row, 36)) === '--') ? null : $value,
        'description' => (($value = accounting_payout_cell($row, 37)) === '--') ? null : $value,
        'raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $normalized['source_key'] = accounting_payout_source_key($normalized);

    return $normalized;
}

function accounting_payout_parse_file(string $path, string $originalName = ''): array
{
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('The payout file is empty or unreadable.');
    }

    $encoding = null;
    $utf8 = accounting_payout_utf8($raw, $encoding);
    $lines = preg_split('/\R/u', $utf8);
    if (!is_array($lines)) {
        throw new RuntimeException('The payout file could not be split into lines.');
    }

    $table = accounting_payout_detect_table($lines);
    $header = $table['header'];
    if (count($header) < 38) {
        throw new RuntimeException('Unexpected payout layout: expected 38 columns.');
    }

    $rows = [];
    $sourceRowCount = 0;
    for ($index = $table['header_index'] + 1, $count = count($lines); $index < $count; $index++) {
        if (trim((string) $lines[$index]) === '') {
            continue;
        }
        $parsed = str_getcsv((string) $lines[$index], $table['delimiter']);
        if (!array_filter($parsed, static function ($value): bool {
            return trim((string) $value) !== '';
        })) {
            continue;
        }
        $sourceRowCount++;
        $rows[] = accounting_payout_normalize_row($parsed, $header, $table['region']);
    }

    if ($sourceRowCount === 0) {
        throw new RuntimeException('The payout report contains no transaction rows.');
    }

    return [
        'original_name' => $originalName !== '' ? $originalName : basename($path),
        'file_hash' => hash('sha256', $raw),
        'encoding' => $encoding,
        'delimiter' => $table['delimiter'],
        'source_region' => $table['region'],
        'header_row' => $table['header_index'] + 1,
        'source_row_count' => $sourceRowCount,
        'rows' => $rows,
    ];
}
