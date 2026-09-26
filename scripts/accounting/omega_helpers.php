<?php
declare(strict_types=1);

/**
 * Parser for OMEGA invoice exports saved as tab-separated .txt/.tsv files.
 *
 * R01 is the invoice header. R02 rows immediately following it are invoice
 * lines. The fixed column positions correspond to the OMEGA T01 export used
 * by the existing Google Sheet named ranges.
 */

function accounting_omega_utf8(string $raw, ?string &$encoding = null): string
{
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $encoding = 'UTF-8 BOM';
        return substr($raw, 3);
    }

    if (preg_match('//u', $raw) === 1) {
        $encoding = 'UTF-8';
        return $raw;
    }

    $encoding = 'Windows-1250';
    $converted = iconv('CP1250', 'UTF-8//IGNORE', $raw);
    if ($converted === false) {
        throw new RuntimeException('OMEGA súbor sa nepodarilo previesť z Windows-1250 do UTF-8.');
    }
    return $converted;
}

function accounting_omega_text(?string $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function accounting_omega_number($value): ?float
{
    if ($value === null || is_array($value) || is_object($value)) {
        return null;
    }

    $value = trim(str_replace(["\u{00A0}", ' '], '', (string) $value));
    if ($value === '') {
        return null;
    }

    $commaCount = substr_count($value, ',');
    $dotCount = substr_count($value, '.');
    if ($commaCount > 0 && $dotCount > 0) {
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($commaCount > 0) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float) $value : null;
}

function accounting_omega_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    foreach (['!d.m.Y', '!Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        ) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function accounting_omega_invoice_from_columns(array $columns, int $lineNumber): array
{
    if (count($columns) < 43) {
        throw new RuntimeException('Riadok R01 #' . $lineNumber . ' má iba ' . count($columns) . ' stĺpcov; očakáva sa aspoň 43.');
    }

    $invoiceNumber = accounting_omega_text($columns[1] ?? null);
    $issueDate = accounting_omega_date($columns[4] ?? null);
    $total = accounting_omega_number($columns[42] ?? null);
    if ($invoiceNumber === null) {
        throw new RuntimeException('Riadok R01 #' . $lineNumber . ' nemá číslo faktúry v stĺpci B.');
    }
    if ($issueDate === null) {
        throw new RuntimeException('Faktúra ' . $invoiceNumber . ' má neplatný dátum v stĺpci E.');
    }
    if ($total === null) {
        throw new RuntimeException('Faktúra ' . $invoiceNumber . ' má neplatnú sumu v stĺpci AQ.');
    }

    return [
        'invoice_number' => $invoiceNumber,
        'issue_date' => $issueDate,
        'order_number' => accounting_omega_text($columns[33] ?? null),
        'payment_type' => accounting_omega_text($columns[37] ?? null),
        'total_amount' => round($total, 2),
        'currency' => strtoupper((string) (accounting_omega_text($columns[39] ?? null) ?? 'EUR')),
        'document_type' => accounting_omega_text($columns[18] ?? null),
        'customer_name' => accounting_omega_text($columns[2] ?? null),
        'raw_json' => json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'source_line' => $lineNumber,
        'items' => [],
    ];
}

function accounting_omega_item_from_columns(array $columns, int $lineNumber, int $itemNumber): array
{
    if (count($columns) < 5) {
        throw new RuntimeException('Riadok R02 #' . $lineNumber . ' má iba ' . count($columns) . ' stĺpcov; očakáva sa aspoň 5.');
    }

    return [
        'line_number' => $itemNumber,
        'description' => accounting_omega_text($columns[1] ?? null),
        'quantity' => accounting_omega_number($columns[2] ?? null),
        'unit' => accounting_omega_text($columns[3] ?? null),
        'unit_price_without_vat' => accounting_omega_number($columns[4] ?? null),
        'raw_json' => json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function accounting_omega_parse_file(string $path, string $originalName = ''): array
{
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('OMEGA súbor je prázdny alebo sa nedá prečítať.');
    }

    $encoding = null;
    $utf8 = accounting_omega_utf8($raw, $encoding);
    $lines = preg_split('/\R/u', $utf8);
    if (!is_array($lines)) {
        throw new RuntimeException('OMEGA súbor sa nepodarilo rozdeliť na riadky.');
    }

    $invoices = [];
    $currentInvoiceNumber = null;
    $sourceRowCount = 0;
    $itemRowCount = 0;
    $ignoredRowCount = 0;

    foreach ($lines as $zeroBasedLine => $line) {
        if ($line === '') {
            continue;
        }
        $lineNumber = $zeroBasedLine + 1;
        $columns = explode("\t", $line);
        $recordType = trim((string) ($columns[0] ?? ''));

        if ($recordType === 'R00') {
            continue;
        }
        $sourceRowCount++;

        if ($recordType === 'R01') {
            $invoice = accounting_omega_invoice_from_columns($columns, $lineNumber);
            $currentInvoiceNumber = $invoice['invoice_number'];
            if (isset($invoices[$currentInvoiceNumber])) {
                throw new RuntimeException('Číslo faktúry ' . $currentInvoiceNumber . ' je v súbore uvedené viackrát.');
            }
            $invoices[$currentInvoiceNumber] = $invoice;
            continue;
        }

        if ($recordType === 'R02') {
            if ($currentInvoiceNumber === null || !isset($invoices[$currentInvoiceNumber])) {
                throw new RuntimeException('Riadok R02 #' . $lineNumber . ' nemá pred sebou nadradený riadok R01.');
            }
            $itemRowCount++;
            $itemNumber = count($invoices[$currentInvoiceNumber]['items']) + 1;
            $invoices[$currentInvoiceNumber]['items'][] = accounting_omega_item_from_columns(
                $columns,
                $lineNumber,
                $itemNumber
            );
            continue;
        }

        $ignoredRowCount++;
    }

    foreach ($invoices as &$invoice) {
        $invoice['item_count'] = count($invoice['items']);
        $invoice['source_hash'] = hash('sha256', json_encode([
            'header' => $invoice['raw_json'],
            'items' => array_column($invoice['items'], 'raw_json'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    unset($invoice);

    if (!$invoices) {
        throw new RuntimeException('OMEGA súbor neobsahuje žiadny riadok R01.');
    }

    return [
        'original_name' => $originalName !== '' ? $originalName : basename($path),
        'file_hash' => hash('sha256', $raw),
        'encoding' => $encoding,
        'source_row_count' => $sourceRowCount,
        'invoice_row_count' => count($invoices),
        'item_row_count' => $itemRowCount,
        'ignored_row_count' => $ignoredRowCount,
        'invoices' => array_values($invoices),
    ];
}
