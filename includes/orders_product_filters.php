<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/orders/department_config.php';

function ordersItemTypeFilterLabels(): array
{
  return [
    'G' => 'G - Graphics',
    'T' => 'T - TFP / Trim Kits',
    'M' => 'M - Bike Mats',
    'TM' => 'T + M - Trim Kits & Bike Mats',
    'P' => 'P - Plastics',
    'S' => 'S - Seat Covers',
    'F' => 'F - Fitting',
    '(NULL)' => '(NULL) - Unassigned',
  ];
}

/**
 * Product families are separate from production departments: imported TFP
 * becomes G/P/F, and G_MC becomes G/P. Keep legacy T/M rows searchable too.
 * The returned predicate expects the orders table to have alias "o".
 */
function ordersProductFamilyWhere(string $type): string
{
  if (!in_array($type, ['T', 'M', 'TM'], true)) {
    throw new InvalidArgumentException('Unsupported product family: ' . $type);
  }

  $conditions = [];
  if ($type === 'T' || $type === 'TM') {
    $conditions[] = "UPPER(TRIM(COALESCE(oipf.item_type_code, ''))) = 'T'";
    // Match complete configured TF/TFP/TFPS prefixes, including eBay labels.
    $trimPrefixes = array_filter(array_keys(DEPT_PREFIX_MAP), static function (string $prefix): bool {
      return strpos($prefix, 'TF') === 0;
    });
    $prefixSql = implode(', ', array_map(static function (string $prefix): string {
      return "'" . str_replace("'", "''", $prefix) . "'";
    }, $trimPrefixes));
    if ($prefixSql !== '') {
      foreach (['sku', 'custom_label'] as $column) {
        $conditions[] = "SUBSTRING_INDEX(UPPER(TRIM(SUBSTRING_INDEX(COALESCE(oipf.$column, ''), '|', 1))), '_', 1) IN ($prefixSql)";
      }
    }
  }

  if ($type === 'M' || $type === 'TM') {
    $conditions[] = "UPPER(TRIM(COALESCE(oipf.item_type_code, ''))) = 'M'";
    foreach (GRAPHICS_SUBCAT_PREFIX_MAP as $prefix => $subcategory) {
      if ($subcategory !== 'MOTO_CARPET') {
        continue;
      }
      $prefixSql = "'" . str_replace("'", "''", $prefix) . "'";
      $prefixLength = strlen($prefix);
      foreach (['sku', 'custom_label'] as $column) {
        // LEFT keeps the underscore literal; SQL LIKE would treat it as a wildcard.
        $conditions[] = "LEFT(UPPER(TRIM(COALESCE(oipf.$column, ''))), $prefixLength) = $prefixSql";
      }
    }
    foreach ([
      ['internal_options_json', '_subcat', 'MOTO_CARPET'],
      ['options_json', '_auto_generated', 'MOTO_CARPET_AUTO_PLASTICS'],
    ] as [$column, $key, $value]) {
      // Older rows can contain empty or malformed JSON.
      $conditions[] = "UPPER(TRIM(CASE WHEN JSON_VALID(oipf.$column)
        THEN JSON_UNQUOTE(JSON_EXTRACT(oipf.$column, '$.$key')) ELSE '' END)) = '$value'";
    }
  }

  return "EXISTS (
    SELECT 1 FROM order_items oipf
    WHERE oipf.order_id = o.id
      AND oipf.deleted_at IS NULL
      AND (" . implode("\n        OR ", $conditions) . ")
  )";
}

/** Counts for the product shortcuts, which reset other filters to Open Orders. */
function ordersGetProductFamilyCounts(mysqli $conn, array $excludedStatuses, int $customerId = 0): array
{
  $counts = ['T' => 0, 'M' => 0, 'TM' => 0];
  $where = [];
  $params = array_values($excludedStatuses);
  $types = str_repeat('s', count($params));
  if ($params) {
    $placeholders = implode(',', array_fill(0, count($params), '?'));
    $where[] = "o.status NOT IN ($placeholders)";
  }
  if ($customerId > 0) {
    $where[] = 'o.customer_id = ?';
    $params[] = $customerId;
    $types .= 'i';
  }
  $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
  $trimWhere = ordersProductFamilyWhere('T');
  $matWhere = ordersProductFamilyWhere('M');
  // One row per order: multiple matching items and mixed orders count once.
  $stmt = $conn->prepare("SELECT SUM(has_t) AS T, SUM(has_m) AS M,
      SUM(has_t OR has_m) AS TM
    FROM (
      SELECT $trimWhere AS has_t, $matWhere AS has_m
      FROM orders o $whereSql
    ) product_orders");
  if (!$stmt) {
    return $counts;
  }
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }
  if ($stmt->execute()) {
    $row = $stmt->get_result()->fetch_assoc();
    foreach ($counts as $type => $count) {
      $counts[$type] = (int) ($row[$type] ?? 0);
    }
  }
  $stmt->close();
  return $counts;
}
