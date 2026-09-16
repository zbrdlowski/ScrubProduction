<?php
declare(strict_types=1);

function order_financial_money_value($value): ?float
{
  if ($value === null || is_array($value) || is_object($value)) {
    return null;
  }

  $value = trim(str_replace(["\u{00A0}", ' '], '', (string) $value));
  $value = preg_replace('/[^\d.,\-]/', '', $value) ?? '';
  if ($value === '') {
    return null;
  }

  if (substr_count($value, ',') === 1 && substr_count($value, '.') === 0) {
    $value = str_replace(',', '.', $value);
  } elseif (substr_count($value, ',') > 0 && substr_count($value, '.') === 1) {
    $value = str_replace(',', '', $value);
  }

  return is_numeric($value) ? (float) $value : null;
}

function order_financial_table_exists(mysqli $conn, string $table): bool
{
  $escaped = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '{$escaped}'");
  if (!$res) {
    return false;
  }

  $exists = $res->num_rows > 0;
  $res->free();
  return $exists;
}

function order_financial_column_exists(mysqli $conn, string $table, string $column): bool
{
  $tableEsc = $conn->real_escape_string($table);
  $columnEsc = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
  if (!$res) {
    return false;
  }

  $exists = $res->num_rows > 0;
  $res->free();
  return $exists;
}

function order_financial_order_columns_ready(mysqli $conn): bool
{
  foreach (['financial_total_value', 'financial_total_currency', 'financial_total_updated_by', 'financial_total_updated_at'] as $column) {
    if (!order_financial_column_exists($conn, 'orders', $column)) {
      return false;
    }
  }

  return true;
}

function order_financial_adjustments_ready(mysqli $conn): bool
{
  return order_financial_table_exists($conn, 'order_financial_adjustments');
}

function order_financial_require_schema(mysqli $conn): void
{
  if (!order_financial_order_columns_ready($conn) || !order_financial_adjustments_ready($conn)) {
    throw new RuntimeException('Financial DB migration is not installed. Run db/orders/add_financial_breakdown_overrides.sql first.');
  }
}

function order_financial_base_total_from_order(array $order, array $sourceMeta = []): float
{
  $sourceTotal = order_financial_money_value($sourceMeta['total_price_with_vat'] ?? null);
  if ($sourceTotal !== null) {
    return round($sourceTotal, 2);
  }

  $orderTotal = order_financial_money_value($order['total'] ?? null);
  if ($orderTotal !== null) {
    return round($orderTotal, 2);
  }

  return 0.0;
}

function order_financial_fetch_adjustments(mysqli $conn, int $orderId): array
{
  if ($orderId <= 0 || !order_financial_adjustments_ready($conn)) {
    return [];
  }

  $stmt = $conn->prepare("
    SELECT
      ofa.id,
      ofa.order_id,
      ofa.type,
      ofa.reference,
      ofa.purpose,
      ofa.amount,
      ofa.currency,
      ofa.created_by,
      ofa.created_at,
      TRIM(CONCAT(COALESCE(e.firstname, ''), ' ', COALESCE(e.lastname, ''))) AS created_by_name
    FROM order_financial_adjustments ofa
    LEFT JOIN employees e ON e.id = ofa.created_by
    WHERE ofa.order_id = ?
      AND ofa.deleted_at IS NULL
    ORDER BY ofa.created_at ASC, ofa.id ASC
  ");

  if (!$stmt) {
    return [];
  }

  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
  }
  $stmt->close();

  return $rows;
}

function order_financial_adjustment_total(mysqli $conn, int $orderId): float
{
  if ($orderId <= 0 || !order_financial_adjustments_ready($conn)) {
    return 0.0;
  }

  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM order_financial_adjustments
    WHERE order_id = ?
      AND deleted_at IS NULL
  ");

  if (!$stmt) {
    return 0.0;
  }

  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();

  return round((float) ($row['total'] ?? 0), 2);
}

function order_financial_effective_totals(mysqli $conn, array $order, ?array $sourceMeta = null): array
{
  if ($sourceMeta === null) {
    $decoded = json_decode((string) ($order['source_meta'] ?? ''), true);
    $sourceMeta = is_array($decoded) ? $decoded : [];
  }

  $sourceCurrency = strtoupper(trim((string) ($order['currency'] ?? 'EUR')));
  if ($sourceCurrency === '') {
    $sourceCurrency = 'EUR';
  }

  $baseTotal = order_financial_base_total_from_order($order, $sourceMeta);
  $adjustmentsTotal = order_financial_adjustment_total($conn, (int) ($order['id'] ?? 0));
  $calculatedTotal = max(0.0, round($baseTotal + $adjustmentsTotal, 2));

  $overrideValue = order_financial_money_value($order['financial_total_value'] ?? null);
  $overrideActive = $overrideValue !== null;

  $overrideCurrency = strtoupper(trim((string) ($order['financial_total_currency'] ?? 'EUR')));
  if ($overrideCurrency === '') {
    $overrideCurrency = 'EUR';
  }

  $effectiveTotal = $overrideActive ? max(0.0, round((float) $overrideValue, 2)) : $calculatedTotal;
  $effectiveCurrency = ($overrideActive || abs($adjustmentsTotal) >= 0.005) ? $overrideCurrency : $sourceCurrency;

  return [
    'base_total' => $baseTotal,
    'source_currency' => $sourceCurrency,
    'adjustments_total' => $adjustmentsTotal,
    'calculated_total' => $calculatedTotal,
    'override_active' => $overrideActive,
    'override_value' => $overrideActive ? round((float) $overrideValue, 2) : null,
    'override_currency' => $overrideCurrency,
    'effective_total' => $effectiveTotal,
    'effective_currency' => $effectiveCurrency,
  ];
}

function order_financial_currency_suffix(string $currency): string
{
  $currency = strtoupper(trim($currency));
  if ($currency === 'EUR') {
    return ' €';
  }

  return $currency !== '' ? ' ' . $currency : '';
}