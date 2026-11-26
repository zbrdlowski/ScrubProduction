<?php
declare(strict_types=1);

/** Identify discount lines, never free/zero-priced production items. */
function ordersIsDiscountItem(array $item): bool
{
    foreach (['sku', 'item_sku', 'itemCode', 'custom_label'] as $key) {
        $value = $item[$key] ?? '';
        if (is_scalar($value) && preg_match('/^COUPON(?:[0-9_\-]|$)/i', trim((string) $value))) {
            return true;
        }
    }

    foreach (['title', 'item_name', 'itemName'] as $key) {
        $value = $item[$key] ?? '';
        if (is_scalar($value) && preg_match('/^(?:Rabattcode|Discount\s+(?:code|coupon)|(?:Zľavový|Zlavovy|Slevový|Slevovy)\s+(?:kupón|kupon|kód|kod))\b/iu', trim((string) $value))) {
            return true;
        }
    }

    return false;
}

/** Display metadata only: never infer a discount percentage from a COUPON SKU. */
function ordersDiscountDisplayDetails(array $item): ?array
{
    if (!ordersIsDiscountItem($item)) {
        return null;
    }

    $title = trim((string) ($item['title'] ?? ''));
    $percentage = '';
    if (preg_match('/(?<![\d.,])(\d{1,3}(?:[.,]\d{1,2})?)\s*%/u', $title, $match)) {
        $value = (float) str_replace(',', '.', $match[1]);
        if ($value > 0 && $value <= 100) {
            $percentage = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
        }
    }

    $code = '';
    $codeTitle = preg_replace('/\s*[-–—]\s*(?:Rabatt|Discount|Zľava|Zlava|Sleva)\b.*$/iu', '', $title) ?? $title;
    if (preg_match('/^(?:Rabattcode|Discount\s+(?:code|coupon)|(?:Zľavový|Zlavovy|Slevový|Slevovy)\s+(?:kupón|kupon|kód|kod))(?:\s+|:\s*)(?:(?:Nr\.?|No\.?|number|číslo|cislo)\s*)?[:#]?\s*([^\s]+?)(?=\s|$)/iu', $codeTitle, $match)) {
        $candidate = trim($match[1], "\"':# ");
        if ($candidate !== '' && !preg_match('/^(?:[-–—]|\d+(?:[.,]\d+)?%|Rabatt|Discount|Zľava|Zlava|Sleva)$/iu', $candidate)) {
            $code = $candidate;
        }
    }

    return [
        'percentage' => $percentage,
        'code' => $code,
        'description' => $code === ''
            ? ($title !== '' ? $title : trim((string) ($item['sku'] ?? $item['custom_label'] ?? '')))
            : '',
    ];
}

/** Coupons have no production department, so read them independently of production items. */
function ordersLoadAppliedDiscounts(mysqli $conn, int $orderId): array
{
    $stmt = $conn->prepare('SELECT sku, title, custom_label FROM order_items
        WHERE order_id = ? AND deleted_at IS NULL ORDER BY COALESCE(line_no, 999999), id');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $discounts = [];
    while ($item = $result->fetch_assoc()) {
        $details = ordersDiscountDisplayDetails($item);
        if ($details !== null) {
            $discounts[] = $details;
        }
    }
    $stmt->close();
    return $discounts;
}
