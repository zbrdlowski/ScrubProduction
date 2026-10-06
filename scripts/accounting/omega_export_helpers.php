<?php
declare(strict_types=1);

function omega_export_table_exists(PDO $pdo, string $table): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function omega_export_manual_invoice_schema_ready(PDO $pdo): bool
{
    return omega_export_table_exists($pdo, 'accounting_omega_manual_invoices')
        && omega_export_table_exists($pdo, 'accounting_omega_manual_invoice_items');
}

function omega_export_schema_ready(PDO $pdo): bool
{
    foreach (['accounting_omega_export_settings', 'accounting_omega_export_batches', 'accounting_omega_export_items'] as $table) {
        if (!omega_export_table_exists($pdo, $table)) {
            return false;
        }
    }
    return true;
}

function omega_export_previous_workday(string $date): string
{
    $day = new DateTimeImmutable($date);
    do {
        $day = $day->modify('-1 day');
    } while ((int) $day->format('N') >= 6);
    return $day->format('Y-m-d');
}

function omega_export_decimal($value): float
{
    if ($value === null || is_array($value) || is_object($value)) {
        return 0.0;
    }
    $text = trim((string) $value);
    if ($text === '') {
        return 0.0;
    }
    $text = str_replace(["\xc2\xa0", ' '], '', $text);
    $text = preg_replace('/[^0-9,\.\-]/u', '', $text) ?? '';
    if (strpos($text, ',') !== false && strpos($text, '.') !== false) {
        if ((int) strrpos($text, ',') > (int) strrpos($text, '.')) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        } else {
            $text = str_replace(',', '', $text);
        }
    } else {
        $text = str_replace(',', '.', $text);
    }
    return is_numeric($text) ? (float) $text : 0.0;
}

function omega_export_number(float $value, int $decimals = 2): string
{
    $formatted = number_format(round($value, $decimals), $decimals, ',', '');
    return rtrim(rtrim($formatted, '0'), ',');
}

function omega_export_clean($value): string
{
    $value = str_replace(["\t", "\r", "\n"], ' ', trim((string) $value));
    return preg_replace('/\s{2,}/u', ' ', $value) ?? $value;
}

function omega_export_limit($value, int $length): string
{
    $value = omega_export_clean($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

function omega_export_country_rule(string $countryCode): array
{
    $countryCode = strtoupper(trim($countryCode));
    $rates = [
        'AT' => 20.0, 'BE' => 21.0, 'BG' => 20.0, 'HR' => 25.0, 'CY' => 19.0,
        'CZ' => 21.0, 'DK' => 25.0, 'EE' => 22.0, 'FI' => 25.5, 'FR' => 20.0,
        'DE' => 19.0, 'GR' => 24.0, 'HU' => 27.0, 'IE' => 23.0, 'IT' => 22.0,
        'LV' => 21.0, 'LT' => 21.0, 'LU' => 17.0, 'MT' => 18.0, 'NL' => 21.0,
        'PL' => 23.0, 'PT' => 23.0, 'RO' => 21.0, 'SK' => 23.0, 'SI' => 22.0,
        'ES' => 21.0, 'SE' => 25.0,
    ];
    $vat = $rates[$countryCode] ?? 0.0;
    $type = $countryCode === 'SK' ? '03' : (isset($rates[$countryCode]) ? 'OSSzd' : '15t');
    return [
        'code' => $countryCode,
        'oss_code' => $countryCode === 'GR' ? 'EL' : $countryCode,
        'vat' => $vat,
        'type' => $type,
        'is_oss' => $type === 'OSSzd',
    ];
}

function omega_export_country_name(string $code): string
{
    $names = [
        'AT' => 'Austria', 'AU' => 'Australia', 'BE' => 'Belgium', 'BG' => 'Bulgaria',
        'CA' => 'Canada', 'CH' => 'Switzerland', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic',
        'DE' => 'Germany', 'DK' => 'Denmark', 'EE' => 'Estonia', 'ES' => 'Spain',
        'FI' => 'Finland', 'FR' => 'France', 'GB' => 'United Kingdom', 'GR' => 'Greece',
        'HR' => 'Croatia', 'HU' => 'Hungary', 'IE' => 'Ireland', 'IT' => 'Italy',
        'JP' => 'Japan', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia',
        'MT' => 'Malta', 'NL' => 'Netherlands', 'NO' => 'Norway', 'NZ' => 'New Zealand',
        'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar', 'RO' => 'Romania',
        'SE' => 'Sweden', 'SI' => 'Slovenia', 'SK' => 'Slovakia', 'US' => 'United States',
    ];
    $code = strtoupper(trim($code));
    return $names[$code] ?? $code;
}

function omega_export_product(string $type, string $customLabel = '', string $title = ''): array
{
    $prefix = strtoupper(trim($type));
    if ($customLabel !== '') {
        $labelPrefix = strtoupper(trim((string) strtok($customLabel, '_')));
        if ($labelPrefix !== '') {
            $prefix = $labelPrefix;
        }
    }
    $products = [
        'P' => ['Motorcycle replacement body panels, molded plastic- 3,5 kg / EU HS code 8714.10 /U.S. HTS code 8714.10.0050/ Made in Italy', '604', '003'],
        'GFP' => ['Motorcycle replacement body panels, molded plastic with stickers applied – made entirely of plastic 3,5 kg / EU HS code 8714.10 /U.S. HTS code 8714.10.0050 / Made in Slovakia', '601', '006'],
        'TFP' => ['Motorcycle replacement body panels, molded plastic with stickers applied – made entirely of plastic 3,5 kg / EU HS code 8714.10 /U.S. HTS code 8714.10.0050 / Made in Slovakia', '601', '006'],
        'GFPS' => ['Motorcycle replacement body panels, molded plastic with stickers applied and motorcycle seat protection – synthetic leather and plastic- 4 kg / EU HS code 8714.10 /U.S. HTS code 8714.10.0050 / Made in Slovakia', '601', '007'],
        'TFPS' => ['Motorcycle replacement body panels, molded plastic with stickers applied and motorcycle seat protection – synthetic leather and plastic- 4 kg / EU HS code 8714.10 /U.S. HTS code 8714.10.0050 / Made in Slovakia', '601', '007'],
        'G' => ['Stickers made of vinyl film for the motorcycle, total size 0,63 m2 /EU HS code:3919.90 /US HTS code 3919.90.5060 /Made in Slovakia', '601', '003'],
        'GS' => ['Stickers made of vinyl film for the motorcycle, total size 0,63 m2 /EU HS code:3919.90 /US HTS code 3919.90.5060 /Made in Slovakia', '601', '003'],
        'S' => ['Motorcycle seat protection /synthetic leather/ - total size 0,32 m2 /EU HS code 8714.10 /U.S. HTS code 8714.10.0050 / Made in Slovakia', '601', '001'],
        'L' => ['Motorcycle seat protection /synthetic leather/ - total size 0,32 m2 /EU HS code 8714.10 /U.S. HTS code 8714.10.0050 / Made in Slovakia', '601', '001'],
        'M' => ['Carpet - motorcycle mat 100% Nylon - Velours / HS code: 57050030 / HTS code 5705.00.9090 / Country of origin: Slovakia', '601', '000'],
    ];
    if (isset($products[$prefix])) {
        return ['description' => $products[$prefix][0], 'synthetic' => $products[$prefix][1], 'analytic' => $products[$prefix][2]];
    }
    return ['description' => $title !== '' ? $title : 'Manual Order', 'synthetic' => '601', 'analytic' => '003'];
}

function omega_export_decode_json(?string $json): array
{
    if (!$json) {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function omega_export_find_payout(PDO $pdo, int $orderId, string $orderNumber, string $from, string $to): ?array
{
    $stmt = $pdo->prepare('
        SELECT t.id, t.exchange_rate, t.gross_payout_amount, t.gross_transaction_amount,
               t.transaction_currency, t.payout_currency, i.imported_at
        FROM accounting_payout_transactions t
        JOIN accounting_payout_imports i ON i.id = t.import_id
        WHERE t.transaction_type = \'ORDER\'
          AND t.exchange_rate IS NOT NULL
          AND (t.matched_order_id = :order_id OR t.order_number = :order_number)
          AND DATE(i.imported_at) >= :from_date
          AND DATE(i.imported_at) <= :to_date
        ORDER BY i.imported_at DESC, t.id DESC
        LIMIT 1
    ');
    $stmt->execute([
        ':order_id' => $orderId,
        ':order_number' => $orderNumber,
        ':from_date' => $from,
        ':to_date' => $to,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function omega_export_base_orders(PDO $pdo, string $from, string $to): array
{
    $manualInvoiceFilter = omega_export_manual_invoice_schema_ready($pdo)
        ? 'AND NOT EXISTS (
            SELECT 1
            FROM accounting_omega_manual_invoice_items manual_item
            WHERE manual_item.order_id = o.id
              AND manual_item.restored_at IS NULL
          )'
        : '';
    $stmt = $pdo->prepare('
        SELECT o.id, o.order_number, o.external_order_id, o.imported_at, o.order_date,
               o.currency, o.total, o.financial_total_value, o.financial_total_currency,
               o.payment_method, o.shipping_method, o.source_meta, o.customer_id, os.code AS source_code,
               c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone
        FROM orders o
        JOIN order_sources os ON os.id = o.source_id
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN accounting_omega_export_items exported ON exported.order_id = o.id
        WHERE exported.id IS NULL
          ' . $manualInvoiceFilter . '
          AND os.code IN (\'EBAY\', \'SHOPTET\', \'CUSTOM\')
          AND DATE(o.imported_at) BETWEEN :from_date AND :to_date
        ORDER BY o.imported_at, o.id
    ');
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function omega_export_late_payout_orders(PDO $pdo, string $from, string $to): array
{
    $manualInvoiceFilter = omega_export_manual_invoice_schema_ready($pdo)
        ? 'AND NOT EXISTS (
            SELECT 1
            FROM accounting_omega_manual_invoice_items manual_item
            WHERE manual_item.order_id = o.id
              AND manual_item.restored_at IS NULL
          )'
        : '';
    $stmt = $pdo->prepare('
        SELECT DISTINCT o.id, o.order_number, o.external_order_id, o.imported_at, o.order_date,
               o.currency, o.total, o.financial_total_value, o.financial_total_currency,
               o.payment_method, o.shipping_method, o.source_meta, o.customer_id, os.code AS source_code,
               c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone
        FROM accounting_payout_transactions t
        JOIN accounting_payout_imports i ON i.id = t.import_id
        JOIN orders o ON (o.id = t.matched_order_id OR BINARY o.order_number = BINARY t.order_number)
        JOIN order_sources os ON os.id = o.source_id AND os.code = \'EBAY\'
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN accounting_omega_export_items exported ON exported.order_id = o.id
        WHERE exported.id IS NULL
          ' . $manualInvoiceFilter . '
          AND UPPER(COALESCE(o.currency, \'EUR\')) <> \'EUR\'
          AND t.transaction_type = \'ORDER\'
          AND t.exchange_rate IS NOT NULL
          AND DATE(i.imported_at) BETWEEN :from_date AND :to_date
        ORDER BY o.imported_at, o.id
    ');
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function omega_export_collect_candidates(PDO $pdo, string $from, string $to, string $processingDate): array
{
    // Kept as batch metadata for backward compatibility with existing batches.
    $customWorkday = omega_export_previous_workday($processingDate);
    $orders = omega_export_base_orders($pdo, $from, $to);
    $seen = [];
    foreach ($orders as $order) {
        $seen[(int) $order['id']] = true;
    }
    foreach (omega_export_late_payout_orders($pdo, $from, $to) as $order) {
        if (!isset($seen[(int) $order['id']])) {
            $orders[] = $order;
            $seen[(int) $order['id']] = true;
        }
    }

    $ready = [];
    $waiting = [];
    $blocked = [];
    foreach ($orders as $order) {
        $source = strtoupper((string) $order['source_code']);
        $currency = strtoupper((string) ($order['currency'] ?: 'EUR'));
        $payout = null;
        $basis = 'ORDER_IMPORT';
        if ($source === 'EBAY' && $currency !== 'EUR') {
            $payout = omega_export_find_payout($pdo, (int) $order['id'], (string) $order['order_number'], $from, $to);
            if (!$payout) {
                $waiting[] = $order;
                continue;
            }
            $basis = 'PAYOUT_IMPORT';
        } elseif ($source === 'CUSTOM') {
            $basis = 'CUSTOM_NOT_EXPORTED';
        }

        $total = $payout
            ? omega_export_decimal($payout['gross_payout_amount'])
            : omega_export_decimal($order['financial_total_value'] ?? null);
        if ($total <= 0) {
            $total = omega_export_decimal($order['total']);
        }
        if ($total <= 0) {
            $order['block_reason'] = 'Chýba kladná fakturovaná suma.';
            $blocked[] = $order;
            continue;
        }
        $order['_payout'] = $payout;
        $order['_readiness_basis'] = $basis;
        $order['_total_eur'] = round($total, 2);
        $ready[] = $order;
    }
    return compact('ready', 'waiting', 'blocked', 'customWorkday');
}

function omega_export_address(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('
        SELECT * FROM order_addresses
        WHERE order_id = ?
        ORDER BY CASE type WHEN \'BILLING\' THEN 0 WHEN \'SHIPPING\' THEN 1 ELSE 2 END, id
        LIMIT 1
    ');
    $stmt->execute([$orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function omega_export_items(PDO $pdo, int $orderId, string $source): array
{
    $stmt = $pdo->prepare('
        SELECT id, line_no, sku, title, custom_label, item_type_code, qty, unit_price, options_json
        FROM order_items
        WHERE order_id = ? AND deleted_at IS NULL
        ORDER BY COALESCE(line_no, 999999), id
    ');
    $stmt->execute([$orderId]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $options = omega_export_decode_json($item['options_json'] ?? null);
        if ($source !== 'CUSTOM' && !empty($options['_auto_generated'])) {
            continue;
        }
        $item['_options'] = $options;
        $items[] = $item;
    }
    return $items;
}

function omega_export_payload(PDO $pdo, array $order, string $partnerCode): array
{
    $source = strtoupper((string) $order['source_code']);
    $address = omega_export_address($pdo, (int) $order['id']);
    $items = omega_export_items($pdo, (int) $order['id'], $source);
    $meta = omega_export_decode_json($order['source_meta'] ?? null);
    $payout = $order['_payout'] ?? null;
    $rate = $payout ? max(omega_export_decimal($payout['exchange_rate']), 0.0) : 1.0;
    $total = (float) $order['_total_eur'];
    $shipping = omega_export_decimal($meta['shipping_price'] ?? 0);

    if ($source === 'EBAY' && $items) {
        $firstOptions = $items[0]['_options'] ?? [];
        $raw = is_array($firstOptions['_source_raw'] ?? null) ? $firstOptions['_source_raw'] : $firstOptions;
        $shipping = omega_export_decimal($raw['Postage and packaging'] ?? 0) * $rate;
    }
    $shipping = round(max(0.0, min($shipping, $total)), 2);
    $goodsTarget = round($total - $shipping, 2);

    $lineWeights = [];
    $weightTotal = 0.0;
    foreach ($items as $index => $item) {
        $qty = max(omega_export_decimal($item['qty'] ?? 1), 1.0);
        $weight = omega_export_decimal($item['unit_price'] ?? 0) * $qty;
        if ($source === 'EBAY' && $rate > 0) {
            $weight *= $rate;
        }
        $lineWeights[$index] = max(0.0, $weight);
        $weightTotal += $lineWeights[$index];
    }
    if (!$items) {
        $items[] = ['title' => 'Manual Order', 'custom_label' => '', 'item_type_code' => 'G', 'qty' => 1, 'unit_price' => $goodsTarget, '_options' => []];
        $lineWeights = [0 => $goodsTarget];
        $weightTotal = $goodsTarget;
    }

    $invoiceItems = [];
    $allocated = 0.0;
    $lastIndex = count($items) - 1;
    foreach ($items as $index => $item) {
        $qty = max(omega_export_decimal($item['qty'] ?? 1), 1.0);
        if ($index === $lastIndex) {
            $lineGross = round($goodsTarget - $allocated, 2);
        } else {
            $lineGross = $weightTotal > 0
                ? round($goodsTarget * ($lineWeights[$index] / $weightTotal), 2)
                : 0.0;
            $allocated += $lineGross;
        }
        $product = omega_export_product(
            (string) ($item['item_type_code'] ?? ''),
            (string) ($item['custom_label'] ?? ''),
            (string) ($item['title'] ?? '')
        );
        $invoiceItems[] = array_merge($product, [
            'qty' => $qty,
            'unit_gross_eur' => $qty > 0 ? round($lineGross / $qty, 4) : 0.0,
        ]);
    }

    $countryCode = strtoupper((string) ($address['country'] ?? ''));
    $name = omega_export_clean($address['name'] ?? $order['customer_name'] ?? '');
    $email = omega_export_clean($address['email'] ?? $order['customer_email'] ?? '');
    $phone = omega_export_clean($address['phone'] ?? $order['customer_phone'] ?? '');
    return [
        'order_id' => (int) $order['id'],
        'source_code' => $source,
        'order_number' => omega_export_clean($order['order_number'] ?: $order['external_order_id']),
        'order_date' => (string) ($order['order_date'] ?: $order['imported_at']),
        'customer_name' => $name,
        'customer_email' => $email,
        'customer_phone' => $phone,
        'company' => omega_export_clean($address['company'] ?? ''),
        'company_id' => omega_export_clean($address['company_id'] ?? ''),
        'street' => omega_export_clean($address['street'] ?? ''),
        'city' => omega_export_clean($address['city'] ?? ''),
        'zip' => omega_export_clean($address['zip'] ?? ''),
        'country_code' => $countryCode,
        'country_name' => omega_export_country_name($countryCode),
        'state' => omega_export_clean($address['state'] ?? ''),
        'payment_method' => $source === 'EBAY' ? 'Ebay-CSOB' : omega_export_clean($order['payment_method'] ?? ''),
        'shipping_method' => omega_export_clean($order['shipping_method'] ?? ''),
        'partner_code' => $partnerCode,
        'total_eur' => $total,
        'shipping_eur' => $shipping,
        'exchange_rate' => $rate,
        'items' => $invoiceItems,
    ];
}

function omega_export_empty_row(int $columns): array
{
    return array_fill(0, $columns, '');
}

function omega_export_partner_rows(array $payloads): array
{
    $rows = [array_replace(omega_export_empty_row(45), [0 => 'R00', 1 => 'T04'])];
    foreach ($payloads as $payload) {
        $row = omega_export_empty_row(45);
        $row[0] = 'R01';
        $row[1] = omega_export_limit($payload['company'] !== '' ? $payload['company'] : $payload['customer_name'], 75);
        $row[3] = omega_export_limit($payload['street'], 40);
        $row[4] = omega_export_limit($payload['zip'], 6);
        $row[5] = omega_export_limit($payload['city'], 40);
        $row[7] = omega_export_limit($payload['country_name'], 30);
        $row[12] = 'F';
        $row[13] = omega_export_clean($payload['customer_email']);
        $row[23] = omega_export_limit($payload['partner_code'], 20);
        $row[28] = '-1';
        $row[29] = '-1';
        $row[30] = '-1';
        $row[31] = 'T';
        $row[34] = '-1';
        $rows[] = $row;
        $rows[] = array_replace(omega_export_empty_row(45), [0 => 'R02']);

        $contact = omega_export_empty_row(45);
        $contact[0] = 'R03';
        $contact[1] = 'T22';
        [$first, $last] = omega_export_split_name($payload['customer_name']);
        [$dial, $phone] = omega_export_split_phone($payload['customer_phone'], $payload['country_code']);
        $contact[2] = omega_export_limit($first, 30);
        $contact[3] = omega_export_limit($last, 30);
        $contact[4] = omega_export_limit($dial, 10);
        $contact[5] = omega_export_limit($phone, 20);
        $contact[8] = $payload['customer_email'];
        $rows[] = $contact;
    }
    return $rows;
}

function omega_export_split_name(string $name): array
{
    $parts = preg_split('/\s+/u', trim($name), 2) ?: [];
    return [$parts[0] ?? '', $parts[1] ?? ''];
}

function omega_export_split_phone(string $phone, string $countryCode): array
{
    $dialCodes = [
        'AT' => '43', 'AU' => '61', 'BE' => '32', 'CA' => '1', 'CH' => '41', 'CZ' => '420',
        'DE' => '49', 'DK' => '45', 'ES' => '34', 'FI' => '358', 'FR' => '33', 'GB' => '44',
        'GR' => '30', 'HR' => '385', 'HU' => '36', 'IE' => '353', 'IT' => '39', 'JP' => '81',
        'NL' => '31', 'NO' => '47', 'NZ' => '64', 'PL' => '48', 'PT' => '351', 'SE' => '46',
        'SI' => '386', 'SK' => '421', 'US' => '1',
    ];
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $dial = $dialCodes[strtoupper($countryCode)] ?? '';
    if ($dial !== '' && substr($digits, 0, strlen($dial)) === $dial) {
        $digits = substr($digits, strlen($dial));
    }
    $digits = ltrim($digits, '0');
    return [$dial, substr($digits, 0, 20)];
}

function omega_export_invoice_rows(array $payloads): array
{
    $rows = [array_replace(omega_export_empty_row(94), [0 => 'R00', 1 => 'T01'])];
    foreach ($payloads as $payload) {
        $rule = omega_export_country_rule($payload['country_code']);
        $vatRate = (float) $rule['vat'];
        $total = (float) $payload['total_eur'];
        $netTotal = $vatRate > 0 ? round($total / (1 + $vatRate / 100), 2) : $total;
        $vatAmount = round($total - $netTotal, 2);
        $issue = new DateTimeImmutable($payload['order_date']);
        $addressText = implode(', ', array_filter([$payload['street'], $payload['city'], $payload['state']]));

        $header = omega_export_empty_row(94);
        $header[0] = 'R01';
        $header[1] = omega_export_limit($payload['order_number'], 20);
        $header[2] = omega_export_limit($payload['company'] !== '' ? $payload['company'] : $payload['customer_name'], 75);
        $header[4] = $issue->format('d.m.Y');
        $header[5] = $issue->modify('+4 days')->format('d.m.Y');
        $header[6] = $issue->format('d.m.Y');
        $header[7] = '0';
        $header[8] = omega_export_number($vatRate > 0 ? $netTotal : 0.0);
        $header[9] = '0';
        $header[10] = omega_export_number($vatRate > 0 ? 0.0 : $netTotal);
        $header[11] = '10';
        $header[12] = omega_export_number($vatRate, 1);
        $header[13] = '0';
        $header[14] = omega_export_number($vatAmount);
        $header[15] = '0';
        $header[16] = omega_export_number($total);
        $header[17] = '11';
        $header[18] = 'OD';
        $header[19] = 'OD';
        $header[20] = omega_export_limit($payload['partner_code'], 20);
        $header[24] = omega_export_limit($addressText, 40);
        $header[25] = omega_export_limit($payload['zip'], 6);
        $header[26] = omega_export_limit($payload['city'], 40);
        $header[28] = date('H:i:s');
        $header[30] = 'Fakturujeme: Summary invoice for:';
        $header[31] = $vatRate > 0
            ? 'Dakujeme za objednavku. Thank you for your Order'
            : 'Oslobodene od DPH podla § 47 Zakona o DPH. Exempt from VAT according to § 47 of the VAT Act.';
        $header[33] = omega_export_limit($payload['order_number'], 20);
        $header[34] = 'Bc. Bulejkova Michaela';
        $header[37] = omega_export_clean($payload['payment_method']);
        $header[38] = omega_export_limit($payload['shipping_method'], 3);
        $header[39] = 'EUR';
        $header[40] = '1';
        $header[41] = '1';
        $header[42] = omega_export_number($total);
        $header[46] = omega_export_limit($payload['country_name'], 30);
        $header[48] = omega_export_limit($payload['company_id'], 50);
        $header[49] = '4019227326/7500';
        $header[50] = 'Ceskoslovenska obchodna banka, a.s.';
        $header[51] = 'Trencín';
        $header[52] = omega_export_limit($payload['country_name'], 30);
        $header[53] = '5';
        $header[54] = omega_export_limit($payload['customer_name'], 15);
        $header[55] = 'CEKOSKBX';
        $header[56] = 'SK8275000000004019227326';
        $header[57] = 'SK';
        $header[58] = '2023926982';
        $header[59] = 'SLOVENSKO';
        $header[60] = '-2';
        $header[61] = '3';
        $header[62] = '0';
        $header[63] = '999';
        $header[65] = '0';
        $header[66] = '1';
        $header[67] = '0';
        $header[69] = '0';
        $header[70] = omega_export_limit($payload['order_number'], 20);
        $header[72] = omega_export_clean($payload['company'] !== '' ? $payload['company'] : $payload['customer_name']);
        $header[75] = omega_export_clean($payload['street']);
        $header[76] = omega_export_limit($payload['zip'], 6);
        $header[77] = omega_export_clean($payload['city']);
        $header[84] = '0';
        $header[85] = '0';
        $header[88] = $rule['is_oss'] ? '-1' : '0';
        $header[89] = $rule['is_oss'] ? $rule['oss_code'] : '';
        $header[90] = $rule['is_oss'] ? '2b' : '';
        $header[92] = '0';
        $header[93] = '0';
        $rows[] = $header;

        foreach ($payload['items'] as $item) {
            $rows[] = omega_export_invoice_item_row($item, $rule, $payload['source_code'] === 'EBAY');
        }
        if ((float) $payload['shipping_eur'] > 0) {
            $rows[] = omega_export_invoice_item_row([
                'description' => 'Shipping & Handling', 'synthetic' => '602', 'analytic' => '001',
                'qty' => 1.0, 'unit_gross_eur' => (float) $payload['shipping_eur'],
            ], $rule, false, true);
        }
    }
    return $rows;
}

function omega_export_invoice_item_row(array $item, array $rule, bool $stockItem, bool $shipping = false): array
{
    $vatRate = (float) $rule['vat'];
    $unitGross = (float) $item['unit_gross_eur'];
    $unitNet = $vatRate > 0 ? $unitGross / (1 + $vatRate / 100) : $unitGross;
    $qty = (float) $item['qty'];
    $row = omega_export_empty_row(94);
    $row[0] = 'R02';
    $row[1] = omega_export_clean($item['description']);
    $row[2] = omega_export_number($qty, 3);
    $row[3] = 'ks';
    $row[4] = omega_export_number($unitNet, 4);
    $row[5] = $vatRate > 0 ? 'V' : '0';
    $row[6] = '0';
    $row[7] = omega_export_number($unitNet, 4);
    $row[8] = '0';
    $row[9] = $stockItem ? 'K' : 'V';
    $row[12] = '0';
    $row[13] = $item['synthetic'];
    $row[14] = $item['analytic'];
    $row[20] = 'X';
    $row[21] = '(Nedefinované)';
    $row[22] = 'X';
    $row[23] = '(Nedefinované)';
    $row[24] = 'X';
    $row[25] = '(Nedefinované)';
    $row[26] = 'X';
    $row[27] = '(Nedefinované)';
    $row[29] = $shipping && $rule['type'] === '15t' ? '15s' : $rule['type'];
    $row[30] = '0';
    $row[31] = '0';
    $row[32] = '0';
    $row[34] = '0';
    $row[35] = '0';
    $row[36] = '0';
    $row[37] = 'ks';
    $row[38] = omega_export_number($qty, 3);
    $row[44] = '-4';
    $row[45] = '3';
    $row[46] = '2';
    $row[48] = omega_export_number($unitGross, 4);
    $row[49] = '0';
    $row[50] = omega_export_number($unitGross, 4);
    $row[51] = '0';
    $row[52] = '0';
    $row[53] = 'X';
    $row[57] = '0';
    return $row;
}

function omega_export_rows_to_ansi(array $rows): string
{
    $lines = [];
    foreach ($rows as $row) {
        $lines[] = implode("\t", array_map('omega_export_clean', $row));
    }
    $utf8 = implode("\r\n", $lines) . "\r\n";
    $ansi = iconv('UTF-8', 'Windows-1250//TRANSLIT//IGNORE', $utf8);
    if ($ansi === false) {
        throw new RuntimeException('Výstup sa nepodarilo previesť do Windows-1250.');
    }
    return $ansi;
}

function omega_export_batch_payloads(PDO $pdo, int $batchId): array
{
    $stmt = $pdo->prepare('SELECT payload_json FROM accounting_omega_export_items WHERE batch_id = ? ORDER BY id');
    $stmt->execute([$batchId]);
    $payloads = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $payload = json_decode((string) $json, true);
        if (is_array($payload)) {
            $payloads[] = $payload;
        }
    }
    return $payloads;
}
