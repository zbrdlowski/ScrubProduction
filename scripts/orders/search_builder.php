<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

function orderSearchOut(int $code, array $payload): void
{
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

if (!isset($_SESSION['permission'])) {
  orderSearchOut(403, ['ok' => false, 'error' => 'Not logged in']);
}

$base = dirname(__DIR__, 2);
require_once $base . '/includes/conn.php';
require_once $base . '/includes/auth.php';
require_once $base . '/includes/order_search_registry.php';

if (!auth_can('orders.view')) {
  orderSearchOut(403, ['ok' => false, 'error' => 'No permission for this Orders action.']);
}

function orderSearchLower(string $value): string
{
  return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function orderSearchBind(mysqli_stmt $stmt, string $types, array $params): void
{
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }
}

function orderSearchTextSql(string $expr): string
{
  return "LOWER(TRIM(COALESCE(CAST(($expr) AS CHAR), '')))";
}

function orderSearchNonEmptySql(string $expr): string
{
  return "(($expr) IS NOT NULL AND TRIM(COALESCE(CAST(($expr) AS CHAR), '')) <> '')";
}

function orderSearchEmptySql(string $expr): string
{
  return "(($expr) IS NULL OR TRIM(COALESCE(CAST(($expr) AS CHAR), '')) = '')";
}

function orderSearchBuildTextCondition(string $expr, string $operator, string $value, string $value2 = ''): ?array
{
  $value = trim($value);
  $textSql = orderSearchTextSql($expr);
  if ($operator === 'is_empty') {
    return ['sql' => orderSearchEmptySql($expr), 'types' => '', 'params' => []];
  }
  if ($operator === 'is_not_empty') {
    return ['sql' => orderSearchNonEmptySql($expr), 'types' => '', 'params' => []];
  }
  if ($value === '') {
    return null;
  }

  $needle = orderSearchLower($value);
  if ($operator === 'contains') {
    return ['sql' => "$textSql LIKE ?", 'types' => 's', 'params' => ['%' . $needle . '%']];
  }
  if ($operator === 'equals') {
    return ['sql' => "$textSql = ?", 'types' => 's', 'params' => [$needle]];
  }
  if ($operator === 'not_equals') {
    return ['sql' => "($textSql <> ? OR " . orderSearchEmptySql($expr) . ')', 'types' => 's', 'params' => [$needle]];
  }
  if ($operator === 'starts_with') {
    return ['sql' => "$textSql LIKE ?", 'types' => 's', 'params' => [$needle . '%']];
  }
  return null;
}

function orderSearchValidDate(string $value): bool
{
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
    return false;
  }
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return $date && $date->format('Y-m-d') === $value;
}

function orderSearchBuildDateCondition(string $expr, string $operator, string $value, string $value2 = ''): ?array
{
  if ($operator === 'is_empty') {
    return ['sql' => "(($expr) IS NULL)", 'types' => '', 'params' => []];
  }
  if ($operator === 'is_not_empty') {
    return ['sql' => "(($expr) IS NOT NULL)", 'types' => '', 'params' => []];
  }
  $value = trim($value);
  $value2 = trim($value2);
  if (!orderSearchValidDate($value)) {
    return null;
  }
  $dateSql = "DATE($expr)";
  if ($operator === 'on') {
    return ['sql' => "$dateSql = ?", 'types' => 's', 'params' => [$value]];
  }
  if ($operator === 'before') {
    return ['sql' => "$dateSql <= ?", 'types' => 's', 'params' => [$value]];
  }
  if ($operator === 'after') {
    return ['sql' => "$dateSql >= ?", 'types' => 's', 'params' => [$value]];
  }
  if ($operator === 'between' && orderSearchValidDate($value2)) {
    if ($value2 < $value) {
      [$value, $value2] = [$value2, $value];
    }
    return ['sql' => "$dateSql BETWEEN ? AND ?", 'types' => 'ss', 'params' => [$value, $value2]];
  }
  return null;
}

function orderSearchBuildNumberCondition(string $expr, string $operator, string $value, string $value2 = ''): ?array
{
  if ($operator === 'is_empty') {
    return ['sql' => "(($expr) IS NULL)", 'types' => '', 'params' => []];
  }
  if ($operator === 'is_not_empty') {
    return ['sql' => "(($expr) IS NOT NULL)", 'types' => '', 'params' => []];
  }
  $value = trim($value);
  if ($value === '' || !is_numeric($value)) {
    return null;
  }
  $map = [
    'equals' => '=',
    'not_equals' => '<>',
    'gt' => '>',
    'gte' => '>=',
    'lt' => '<',
    'lte' => '<=',
  ];
  if (!isset($map[$operator])) {
    return null;
  }
  return ['sql' => "($expr) {$map[$operator]} ?", 'types' => 'd', 'params' => [(float) $value]];
}

function orderSearchJsonExpr(string $columnExpr, string $path): string
{
  $pathSql = "'" . str_replace("'", "''", $path) . "'";
  return "CASE WHEN JSON_VALID($columnExpr) THEN JSON_UNQUOTE(JSON_EXTRACT($columnExpr, $pathSql)) ELSE '' END";
}

function orderSearchAliasFromFrom(string $from): string
{
  if (preg_match('/\s+([a-zA-Z_][a-zA-Z0-9_]*)$/', trim($from), $m)) {
    return $m[1];
  }
  return '';
}

function orderSearchAppendCondition(array &$parts, string &$types, array &$params, ?array $built): void
{
  if (!$built || trim((string) ($built['sql'] ?? '')) === '') {
    return;
  }
  $parts[] = '(' . $built['sql'] . ')';
  $types .= (string) ($built['types'] ?? '');
  foreach (($built['params'] ?? []) as $param) {
    $params[] = $param;
  }
}

function orderSearchBuildExpressionCondition(array $field, string $operator, string $value, string $value2 = ''): ?array
{
  $expr = (string) ($field['expr'] ?? '');
  if ($expr === '') {
    return null;
  }
  $type = (string) ($field['type'] ?? 'text');
  if ($type === 'date') {
    return orderSearchBuildDateCondition($expr, $operator, $value, $value2);
  }
  if ($type === 'number') {
    return orderSearchBuildNumberCondition($expr, $operator, $value, $value2);
  }
  return orderSearchBuildTextCondition($expr, $operator, $value, $value2);
}

function orderSearchBuildGlobalCondition(string $operator, string $value): ?array
{
  if ($operator !== 'contains' || trim($value) === '') {
    return null;
  }

  $parts = [];
  $types = '';
  $params = [];
  $direct = [
    'o.order_number', 'o.external_order_id', 'o.note', 'o.production_note', 'o.customs_identifier', 'o.source_meta',
    'os.code', 'cu.name', 'cu.email', 'cu.phone',
    'oa_ship.name', 'oa_ship.company', 'oa_ship.street', 'oa_ship.city', 'oa_ship.zip', 'oa_ship.state', 'oa_ship.country',
    'oa_bill.name', 'oa_bill.company', 'oa_bill.street', 'oa_bill.city', 'oa_bill.zip', 'oa_bill.state', 'oa_bill.country',
  ];
  foreach ($direct as $expr) {
    orderSearchAppendCondition($parts, $types, $params, orderSearchBuildTextCondition($expr, 'contains', $value));
  }

  $exists = [
    ['order_items oi_g', 'oi_g.order_id = o.id AND oi_g.deleted_at IS NULL', "CONCAT_WS(' ', oi_g.sku, oi_g.title, oi_g.custom_label, oi_g.item_type_code, oi_g.status, oi_g.waiting_note, oi_g.product_url, oi_g.options_json, oi_g.internal_options_json)"],
    ['order_invoices inv_g', 'inv_g.order_id = o.id AND inv_g.deleted_at IS NULL', "CONCAT_WS(' ', inv_g.invoice_number, inv_g.note)"],
    ['order_tracking_numbers tr_g', 'tr_g.order_id = o.id AND tr_g.deleted_at IS NULL', "CONCAT_WS(' ', tr_g.tracking_number, tr_g.carrier, tr_g.note, tr_g.fedex_status_detail)"],
    ['order_activity act_g', 'act_g.order_id = o.id', "CONCAT_WS(' ', act_g.action, act_g.note, act_g.payload, act_g.entity_type)"],
  ];
  foreach ($exists as [$from, $where, $expr]) {
    $inner = orderSearchBuildTextCondition($expr, 'contains', $value);
    if ($inner) {
      $parts[] = "EXISTS (SELECT 1 FROM $from WHERE $where AND ({$inner['sql']}))";
      $types .= $inner['types'];
      foreach ($inner['params'] as $param) {
        $params[] = $param;
      }
    }
  }

  return ['sql' => '(' . implode(' OR ', $parts) . ')', 'types' => $types, 'params' => $params];
}

function orderSearchBuildNotesCondition(string $operator, string $value): ?array
{
  $parts = [];
  $types = '';
  $params = [];
  foreach (['o.note', 'o.production_note'] as $expr) {
    orderSearchAppendCondition($parts, $types, $params, orderSearchBuildTextCondition($expr, $operator, $value));
  }
  $exists = [
    ['order_items oi_note', 'oi_note.order_id = o.id AND oi_note.deleted_at IS NULL', "CONCAT_WS(' ', oi_note.waiting_note, oi_note.options_json, oi_note.internal_options_json)"],
    ['order_activity act_note', 'act_note.order_id = o.id', "CONCAT_WS(' ', act_note.note, act_note.payload)"],
  ];
  foreach ($exists as [$from, $where, $expr]) {
    $inner = orderSearchBuildTextCondition($expr, $operator, $value);
    if ($inner) {
      $parts[] = "EXISTS (SELECT 1 FROM $from WHERE $where AND ({$inner['sql']}))";
      $types .= $inner['types'];
      foreach ($inner['params'] as $param) {
        $params[] = $param;
      }
    }
  }
  if (!$parts) {
    return null;
  }
  return ['sql' => '(' . implode(' OR ', $parts) . ')', 'types' => $types, 'params' => $params];
}

function orderSearchBuildAssignedWorkerCondition(string $operator, string $value): ?array
{
  $parts = [];
  $types = '';
  $params = [];
  $defs = [
    ['order_assignments oa_w JOIN employees e_w ON e_w.id = oa_w.employee_id', 'oa_w.order_id = o.id AND oa_w.removed_at IS NULL', "CONCAT_WS(' ', e_w.firstname, e_w.lastname, e_w.username, oa_w.role)"],
    ['order_item_assignments oia_w JOIN order_items oi_w ON oi_w.id = oia_w.item_id JOIN employees ei_w ON ei_w.id = oia_w.employee_id', 'oi_w.order_id = o.id AND oi_w.deleted_at IS NULL AND oia_w.removed_at IS NULL', "CONCAT_WS(' ', ei_w.firstname, ei_w.lastname, ei_w.username, oia_w.assignment_role)"],
  ];
  foreach ($defs as [$from, $where, $expr]) {
    $inner = orderSearchBuildTextCondition($expr, $operator, $value);
    if ($inner) {
      $parts[] = "EXISTS (SELECT 1 FROM $from WHERE $where AND ({$inner['sql']}))";
      $types .= $inner['types'];
      foreach ($inner['params'] as $param) {
        $params[] = $param;
      }
    }
  }
  if (!$parts) {
    return null;
  }
  return ['sql' => '(' . implode(' OR ', $parts) . ')', 'types' => $types, 'params' => $params];
}

function orderSearchBuildJsonMultiCondition(array $field, string $operator, string $value): ?array
{
  $from = (string) ($field['from'] ?? '');
  $where = (string) ($field['where'] ?? '');
  $alias = orderSearchAliasFromFrom($from);
  if ($from === '' || $where === '' || $alias === '' || empty($field['paths']) || !is_array($field['paths'])) {
    return null;
  }

  $parts = [];
  $types = '';
  $params = [];
  foreach ($field['paths'] as $pathDef) {
    if (!is_array($pathDef) || count($pathDef) !== 2) {
      continue;
    }
    [$column, $path] = $pathDef;
    if (!in_array($column, ['options_json', 'internal_options_json'], true)) {
      continue;
    }
    $expr = orderSearchJsonExpr($alias . '.' . $column, (string) $path);
    $inner = orderSearchBuildTextCondition($expr, $operator, $value);
    if ($inner) {
      orderSearchAppendCondition($parts, $types, $params, $inner);
    }
  }
  if (!$parts) {
    return null;
  }
  return ['sql' => "EXISTS (SELECT 1 FROM $from WHERE $where AND (" . implode(' OR ', $parts) . '))', 'types' => $types, 'params' => $params];
}

function orderSearchBuildFieldCondition(array $field, string $operator, string $value, string $value2 = ''): ?array
{
  $kind = (string) ($field['kind'] ?? 'direct');
  if ($kind === 'global_text') {
    return orderSearchBuildGlobalCondition($operator, $value);
  }
  if ($kind === 'notes') {
    return orderSearchBuildNotesCondition($operator, $value);
  }
  if ($kind === 'assigned_worker') {
    return orderSearchBuildAssignedWorkerCondition($operator, $value);
  }
  if ($kind === 'direct') {
    return orderSearchBuildExpressionCondition($field, $operator, $value, $value2);
  }
  if ($kind === 'exists') {
    $inner = orderSearchBuildExpressionCondition($field, $operator, $value, $value2);
    if (!$inner) {
      return null;
    }
    return [
      'sql' => "EXISTS (SELECT 1 FROM {$field['from']} WHERE {$field['where']} AND ({$inner['sql']}))",
      'types' => $inner['types'],
      'params' => $inner['params'],
    ];
  }
  if ($kind === 'json_multi') {
    return orderSearchBuildJsonMultiCondition($field, $operator, $value);
  }
  return null;
}

function orderSearchFilterLabel(array $field, string $operator, string $value, string $value2): string
{
  $ops = orderSearchOperatorLabels();
  $label = (string) ($field['label'] ?? 'Filter');
  $opLabel = $ops[$operator] ?? $operator;
  if (in_array($operator, ['is_empty', 'is_not_empty'], true)) {
    return $label . ' ' . $opLabel;
  }
  if ($operator === 'between') {
    return $label . ' between ' . $value . ' and ' . $value2;
  }
  return $label . ' ' . $opLabel . ' ' . $value;
}

function orderSearchSuggestSql(mysqli $conn, string $sql, string $q): array
{
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return [];
  }
  $like = '%' . $q . '%';
  $stmt->bind_param('s', $like);
  $stmt->execute();
  $res = $stmt->get_result();
  $items = [];
  while ($row = $res->fetch_assoc()) {
    $val = trim((string) ($row['val'] ?? ''));
    if ($val !== '') {
      $items[$val] = true;
    }
  }
  $stmt->close();
  return array_slice(array_keys($items), 0, 30);
}

function orderSearchSuggestJsonPaths(mysqli $conn, array $paths, string $q): array
{
  $items = [];
  $like = '%' . $q . '%';
  foreach ($paths as $pathDef) {
    if (!is_array($pathDef) || count($pathDef) !== 2) {
      continue;
    }
    [$column, $path] = $pathDef;
    if (!in_array($column, ['options_json', 'internal_options_json'], true)) {
      continue;
    }
    $expr = orderSearchJsonExpr((string) $column, (string) $path);
    $sql = "SELECT DISTINCT $expr AS val
            FROM order_items
            WHERE deleted_at IS NULL
            HAVING val IS NOT NULL AND val <> '' AND val LIKE ?
            ORDER BY val ASC
            LIMIT 30";
    foreach (orderSearchSuggestSql($conn, $sql, $q) as $val) {
      $items[$val] = true;
    }
    if (count($items) >= 30) {
      break;
    }
  }
  $out = array_keys($items);
  natcasesort($out);
  return array_slice(array_values($out), 0, 30);
}

function orderSearchExplorerSources(): array
{
  return [
    'options_json' => [
      'label' => 'Customer options JSON',
      'sql' => "SELECT options_json AS json_text FROM order_items WHERE deleted_at IS NULL AND options_json IS NOT NULL AND options_json <> ''",
    ],
    'internal_options_json' => [
      'label' => 'Internal options JSON',
      'sql' => "SELECT internal_options_json AS json_text FROM order_items WHERE deleted_at IS NULL AND internal_options_json IS NOT NULL AND internal_options_json <> ''",
    ],
    'source_meta' => [
      'label' => 'Order source_meta JSON',
      'sql' => "SELECT source_meta AS json_text FROM orders WHERE source_meta IS NOT NULL AND source_meta <> ''",
    ],
  ];
}

function orderSearchJsonExplorerValue($value): string
{
  if (is_bool($value)) {
    return $value ? 'true' : 'false';
  }
  if ($value === null) {
    return 'null';
  }
  if (is_int($value) || is_float($value)) {
    return (string) $value;
  }
  if (is_array($value) || is_object($value)) {
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return $encoded === false ? '' : orderSearchTrimSummary($encoded, 120);
  }
  return orderSearchTrimSummary((string) $value, 120);
}

function orderSearchJsonExplorerFlatten($value, string $prefix, array &$out): void
{
  if (is_array($value)) {
    if ($value === []) {
      if ($prefix !== '') {
        $out[$prefix][] = '[]';
      }
      return;
    }

    $isList = array_keys($value) === range(0, count($value) - 1);
    foreach ($value as $key => $child) {
      $keyPart = $isList ? '[]' : (string) $key;
      $path = $prefix === '' ? $keyPart : ($isList ? $prefix . '[]' : $prefix . '.' . $keyPart);
      orderSearchJsonExplorerFlatten($child, $path, $out);
    }
    return;
  }

  if ($prefix === '') {
    return;
  }
  $out[$prefix][] = orderSearchJsonExplorerValue($value);
}

function orderSearchJsonExplorerAddValue(array &$row, string $value): void
{
  $value = trim($value);
  if ($value === '') {
    return;
  }
  if (!isset($row['example_map'])) {
    $row['example_map'] = [];
  }
  $key = orderSearchLower($value);
  if (count($row['example_map']) < 5 || isset($row['example_map'][$key])) {
    $row['example_map'][$key] = $value;
  }
}

function orderSearchJsonExplorer(mysqli $conn): void
{
  $sources = orderSearchExplorerSources();
  $requestedSource = trim((string) ($_REQUEST['source'] ?? 'all'));
  $q = orderSearchLower(trim((string) ($_REQUEST['q'] ?? '')));
  $limit = max(20, min(800, (int) ($_REQUEST['limit'] ?? 400)));
  $selectedSources = $requestedSource !== 'all' && isset($sources[$requestedSource])
    ? [$requestedSource => $sources[$requestedSource]]
    : $sources;

  $rows = [];
  $scanned = [];
  foreach ($selectedSources as $sourceKey => $source) {
    $scanned[$sourceKey] = 0;
    $res = $conn->query((string) $source['sql']);
    if (!$res) {
      continue;
    }
    while ($dbRow = $res->fetch_assoc()) {
      $json = (string) ($dbRow['json_text'] ?? '');
      if ($json === '') {
        continue;
      }
      $data = json_decode($json, true);
      if (!is_array($data)) {
        continue;
      }
      $scanned[$sourceKey]++;
      $flattened = [];
      orderSearchJsonExplorerFlatten($data, '', $flattened);
      foreach ($flattened as $path => $values) {
        if ($q !== '' && strpos(orderSearchLower($path), $q) === false) {
          $matchedValue = false;
          foreach ($values as $value) {
            if (strpos(orderSearchLower((string) $value), $q) !== false) {
              $matchedValue = true;
              break;
            }
          }
          if (!$matchedValue) {
            continue;
          }
        }
        $rowKey = $sourceKey . '|' . $path;
        if (!isset($rows[$rowKey])) {
          $rows[$rowKey] = [
            'source' => $sourceKey,
            'source_label' => (string) $source['label'],
            'path' => $path,
            'count' => 0,
            'example_map' => [],
          ];
        }
        $rows[$rowKey]['count']++;
        foreach ($values as $value) {
          orderSearchJsonExplorerAddValue($rows[$rowKey], (string) $value);
        }
      }
    }
    $res->free();
  }

  usort($rows, static function (array $a, array $b): int {
    $sourceCompare = strcmp((string) $a['source_label'], (string) $b['source_label']);
    if ($sourceCompare !== 0) {
      return $sourceCompare;
    }
    $countCompare = ((int) $b['count']) <=> ((int) $a['count']);
    if ($countCompare !== 0) {
      return $countCompare;
    }
    return strcmp((string) $a['path'], (string) $b['path']);
  });

  $total = count($rows);
  $rows = array_slice($rows, 0, $limit);
  foreach ($rows as &$row) {
    $examples = array_values($row['example_map'] ?? []);
    unset($row['example_map']);
    $row['examples'] = $examples;
  }
  unset($row);

  orderSearchOut(200, [
    'ok' => true,
    'sources' => array_map(static fn(array $source): string => (string) $source['label'], $sources),
    'rows' => $rows,
    'total' => $total,
    'limit' => $limit,
    'limited' => $total > count($rows),
    'scanned' => $scanned,
  ]);
}
function orderSearchJsonFieldValueRows(mysqli $conn, array $field, string $q, int $limit): array
{
  $paths = $field['paths'] ?? [];
  if (!is_array($paths)) {
    return [];
  }

  $rows = [];
  $perPathLimit = max($limit, 40);
  foreach ($paths as $pathDef) {
    if (!is_array($pathDef) || count($pathDef) !== 2) {
      continue;
    }
    [$column, $path] = $pathDef;
    if (!in_array($column, ['options_json', 'internal_options_json'], true)) {
      continue;
    }

    $expr = orderSearchJsonExpr((string) $column, (string) $path);
    $sql = "SELECT val, COUNT(*) AS cnt
            FROM (SELECT $expr AS val FROM order_items WHERE deleted_at IS NULL) json_values
            WHERE val IS NOT NULL AND TRIM(CAST(val AS CHAR)) <> ''";
    $types = '';
    $params = [];
    if ($q !== '') {
      $sql .= " AND LOWER(TRIM(CAST(val AS CHAR))) LIKE ?";
      $types .= 's';
      $params[] = '%' . $q . '%';
    }
    $sql .= " GROUP BY val ORDER BY cnt DESC, val ASC LIMIT $perPathLimit";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      continue;
    }
    orderSearchBind($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $value = orderSearchTrimSummary((string) ($row['val'] ?? ''), 180);
      if ($value === '') {
        continue;
      }
      $key = orderSearchLower($value);
      if (!isset($rows[$key])) {
        $rows[$key] = [
          'value' => $value,
          'count' => 0,
          'sources' => [],
          'source_map' => [],
        ];
      }
      $rows[$key]['count'] += (int) ($row['cnt'] ?? 0);
      $source = (string) $column . ' -> ' . (string) $path;
      $sourceKey = orderSearchLower($source);
      if (!isset($rows[$key]['source_map'][$sourceKey])) {
        $rows[$key]['source_map'][$sourceKey] = true;
        $rows[$key]['sources'][] = $source;
      }
    }
    $stmt->close();
  }

  $out = array_values($rows);
  usort($out, static function (array $a, array $b): int {
    $countCompare = ((int) $b['count']) <=> ((int) $a['count']);
    if ($countCompare !== 0) {
      return $countCompare;
    }
    return strnatcasecmp((string) $a['value'], (string) $b['value']);
  });

  foreach ($out as &$row) {
    unset($row['source_map']);
  }
  unset($row);

  return $out;
}

function orderSearchRawJsonSourceForField(string $fieldKey): string
{
  $map = [
    'source_meta' => 'source_meta',
    'item_options_text' => 'options_json',
    'internal_options_text' => 'internal_options_json',
  ];
  return (string) ($map[$fieldKey] ?? '');
}

function orderSearchRawJsonFieldRows(mysqli $conn, string $sourceKey, string $q): array
{
  $sources = orderSearchExplorerSources();
  if (!isset($sources[$sourceKey])) {
    return [];
  }

  $rows = [];
  $res = $conn->query((string) $sources[$sourceKey]['sql']);
  if (!$res) {
    return [];
  }

  while ($dbRow = $res->fetch_assoc()) {
    $json = (string) ($dbRow['json_text'] ?? '');
    if ($json === '') {
      continue;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
      continue;
    }
    $flattened = [];
    orderSearchJsonExplorerFlatten($data, '', $flattened);
    foreach ($flattened as $path => $values) {
      if ($q !== '' && strpos(orderSearchLower($path), $q) === false) {
        $matchedValue = false;
        foreach ($values as $value) {
          if (strpos(orderSearchLower((string) $value), $q) !== false) {
            $matchedValue = true;
            break;
          }
        }
        if (!$matchedValue) {
          continue;
        }
      }
      if (!isset($rows[$path])) {
        $rows[$path] = [
          'path' => (string) $path,
          'count' => 0,
          'examples' => [],
          'example_map' => [],
        ];
      }
      $rows[$path]['count']++;
      foreach ($values as $value) {
        orderSearchJsonExplorerAddValue($rows[$path], (string) $value);
      }
    }
  }
  $res->free();

  $out = array_values($rows);
  usort($out, static function (array $a, array $b): int {
    $countCompare = ((int) $b['count']) <=> ((int) $a['count']);
    if ($countCompare !== 0) {
      return $countCompare;
    }
    return strnatcasecmp((string) $a['path'], (string) $b['path']);
  });

  foreach ($out as &$row) {
    $row['examples'] = array_values($row['example_map'] ?? []);
    unset($row['example_map']);
  }
  unset($row);

  return $out;
}

function orderSearchFieldValues(mysqli $conn): void
{
  $fields = orderSearchFieldRegistry();
  $fieldKey = trim((string) ($_REQUEST['field'] ?? ''));
  if (!isset($fields[$fieldKey])) {
    orderSearchOut(400, ['ok' => false, 'error' => 'Unknown field.']);
  }

  $field = $fields[$fieldKey];
  $q = orderSearchLower(trim((string) ($_REQUEST['q'] ?? '')));
  $limit = max(20, min(200, (int) ($_REQUEST['limit'] ?? 120)));
  $kind = (string) ($field['kind'] ?? '');

  if ($kind === 'json_multi') {
    $allRows = orderSearchJsonFieldValueRows($conn, $field, $q, $limit);
    $total = count($allRows);
    orderSearchOut(200, [
      'ok' => true,
      'mode' => 'values',
      'field' => $fieldKey,
      'label' => (string) ($field['label'] ?? $fieldKey),
      'rows' => array_slice($allRows, 0, $limit),
      'total' => $total,
      'limit' => $limit,
      'limited' => $total > $limit,
    ]);
  }

  $sourceKey = orderSearchRawJsonSourceForField($fieldKey);
  if ($sourceKey !== '') {
    $allRows = orderSearchRawJsonFieldRows($conn, $sourceKey, $q);
    $total = count($allRows);
    orderSearchOut(200, [
      'ok' => true,
      'mode' => 'paths',
      'field' => $fieldKey,
      'label' => (string) ($field['label'] ?? $fieldKey),
      'rows' => array_slice($allRows, 0, $limit),
      'total' => $total,
      'limit' => $limit,
      'limited' => $total > $limit,
    ]);
  }

  orderSearchOut(200, [
    'ok' => true,
    'mode' => 'values',
    'field' => $fieldKey,
    'label' => (string) ($field['label'] ?? $fieldKey),
    'rows' => [],
    'total' => 0,
    'limit' => $limit,
    'limited' => false,
  ]);
}

function orderSearchSuggestions(mysqli $conn, string $fieldKey, string $q): array
{
  $fields = orderSearchFieldRegistry();
  if (!isset($fields[$fieldKey]) || empty($fields[$fieldKey]['suggest'])) {
    return [];
  }
  if (($fields[$fieldKey]['kind'] ?? '') === 'json_multi') {
    return orderSearchSuggestJsonPaths($conn, $fields[$fieldKey]['paths'] ?? [], $q);
  }

  $queries = [
    'order_number' => "SELECT DISTINCT o.order_number AS val FROM orders o WHERE o.order_number IS NOT NULL AND o.order_number <> '' AND o.order_number LIKE ? ORDER BY val ASC LIMIT 30",
    'external_order_id' => "SELECT DISTINCT o.external_order_id AS val FROM orders o WHERE o.external_order_id IS NOT NULL AND o.external_order_id <> '' AND o.external_order_id LIKE ? ORDER BY val ASC LIMIT 30",
    'source' => "SELECT DISTINCT os.code AS val FROM order_sources os WHERE os.code IS NOT NULL AND os.code <> '' AND os.code LIKE ? ORDER BY val ASC LIMIT 30",
    'status' => "SELECT DISTINCT o.status AS val FROM orders o WHERE o.status IS NOT NULL AND o.status <> '' AND o.status LIKE ? ORDER BY val ASC LIMIT 30",
    'payment_method' => "SELECT DISTINCT o.payment_method AS val FROM orders o WHERE o.payment_method IS NOT NULL AND o.payment_method <> '' AND o.payment_method LIKE ? ORDER BY val ASC LIMIT 30",
    'shipping_method' => "SELECT DISTINCT o.shipping_method AS val FROM orders o WHERE o.shipping_method IS NOT NULL AND o.shipping_method <> '' AND o.shipping_method LIKE ? ORDER BY val ASC LIMIT 30",
    'customer_name' => "SELECT DISTINCT cu.name AS val FROM customers cu WHERE cu.name IS NOT NULL AND cu.name <> '' AND cu.name LIKE ? ORDER BY val ASC LIMIT 30",
    'customer_email' => "SELECT DISTINCT cu.email AS val FROM customers cu WHERE cu.email IS NOT NULL AND cu.email <> '' AND cu.email LIKE ? ORDER BY val ASC LIMIT 30",
    'country' => "SELECT DISTINCT oa.country AS val FROM order_addresses oa WHERE oa.country IS NOT NULL AND oa.country <> '' AND oa.country LIKE ? ORDER BY val ASC LIMIT 30",
    'city' => "SELECT DISTINCT oa.city AS val FROM order_addresses oa WHERE oa.city IS NOT NULL AND oa.city <> '' AND oa.city LIKE ? ORDER BY val ASC LIMIT 30",
    'item_type' => "SELECT DISTINCT oi.item_type_code AS val FROM order_items oi WHERE oi.deleted_at IS NULL AND oi.item_type_code IS NOT NULL AND oi.item_type_code <> '' AND oi.item_type_code LIKE ? ORDER BY val ASC LIMIT 30",
    'item_status' => "SELECT DISTINCT oi.status AS val FROM order_items oi WHERE oi.deleted_at IS NULL AND oi.status IS NOT NULL AND oi.status <> '' AND oi.status LIKE ? ORDER BY val ASC LIMIT 30",
    'item_title' => "SELECT DISTINCT oi.title AS val FROM order_items oi WHERE oi.deleted_at IS NULL AND oi.title IS NOT NULL AND oi.title <> '' AND oi.title LIKE ? ORDER BY val ASC LIMIT 30",
    'item_sku' => "SELECT DISTINCT oi.sku AS val FROM order_items oi WHERE oi.deleted_at IS NULL AND oi.sku IS NOT NULL AND oi.sku <> '' AND oi.sku LIKE ? ORDER BY val ASC LIMIT 30",
    'item_label' => "SELECT DISTINCT oi.custom_label AS val FROM order_items oi WHERE oi.deleted_at IS NULL AND oi.custom_label IS NOT NULL AND oi.custom_label <> '' AND oi.custom_label LIKE ? ORDER BY val ASC LIMIT 30",
    'category' => "SELECT DISTINCT c.code AS val FROM categories c WHERE c.code IS NOT NULL AND c.code <> '' AND c.code LIKE ? ORDER BY val ASC LIMIT 30",
    'invoice_number' => "SELECT DISTINCT inv.invoice_number AS val FROM order_invoices inv WHERE inv.deleted_at IS NULL AND inv.invoice_number IS NOT NULL AND inv.invoice_number <> '' AND inv.invoice_number LIKE ? ORDER BY val ASC LIMIT 30",
    'assigned_worker' => "SELECT DISTINCT TRIM(CONCAT(e.firstname, ' ', e.lastname)) AS val FROM employees e WHERE TRIM(CONCAT(e.firstname, ' ', e.lastname)) LIKE ? ORDER BY val ASC LIMIT 30",
  ];

  return isset($queries[$fieldKey]) ? orderSearchSuggestSql($conn, $queries[$fieldKey], $q) : [];
}

function orderSearchTrimSummary(?string $value, int $max = 260): string
{
  $value = trim((string) $value);
  if ($value === '') {
    return '';
  }
  if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $max) {
    return mb_substr($value, 0, $max - 1, 'UTF-8') . '...';
  }
  if (!function_exists('mb_strlen') && strlen($value) > $max) {
    return substr($value, 0, $max - 1) . '...';
  }
  return $value;
}

function orderSearchDetailUrl(array $row): string
{
  $id = (int) ($row['id'] ?? 0);
  $needle = trim((string) ($row['order_number'] ?? ''));
  if ($needle === '') {
    $needle = trim((string) ($row['external_order_id'] ?? ''));
  }
  if ($needle !== '') {
    return 'index.php?page=orders&q=' . rawurlencode($needle) . '#order-' . $id;
  }
  $customerId = (int) ($row['customer_id'] ?? 0);
  if ($customerId > 0) {
    return 'index.php?page=orders&customer_id=' . $customerId . '&customer_scope=all#order-' . $id;
  }
  return 'index.php?page=orders#order-' . $id;
}

function orderSearchRun(mysqli $conn): void
{
  $fields = orderSearchFieldRegistry();
  $rawFilters = json_decode((string) ($_POST['filters'] ?? '[]'), true);
  if (!is_array($rawFilters)) {
    orderSearchOut(400, ['ok' => false, 'error' => 'Invalid filters JSON']);
  }
  $mode = strtoupper(trim((string) ($_POST['mode'] ?? 'AND'))) === 'OR' ? 'OR' : 'AND';
  $limit = max(1, min(1000, (int) ($_POST['limit'] ?? 300)));

  $where = [];
  $types = '';
  $params = [];
  $labels = [];

  foreach (array_slice($rawFilters, 0, 15) as $filter) {
    if (!is_array($filter)) {
      continue;
    }
    $fieldKey = (string) ($filter['field'] ?? '');
    if (!isset($fields[$fieldKey])) {
      continue;
    }
    $field = $fields[$fieldKey];
    $operator = (string) ($filter['operator'] ?? 'contains');
    if (!in_array($operator, $field['operators'], true)) {
      continue;
    }
    $value = trim((string) ($filter['value'] ?? ''));
    $value2 = trim((string) ($filter['value2'] ?? ''));
    $built = orderSearchBuildFieldCondition($field, $operator, $value, $value2);
    if (!$built) {
      continue;
    }
    $where[] = '(' . $built['sql'] . ')';
    $types .= $built['types'];
    foreach ($built['params'] as $param) {
      $params[] = $param;
    }
    $labels[] = orderSearchFilterLabel($field, $operator, $value, $value2);
  }

  if (!$where) {
    orderSearchOut(200, ['ok' => true, 'data' => [], 'total' => 0, 'message' => 'Add at least one valid filter.']);
  }

  $whereSql = 'WHERE ' . implode(" $mode ", $where);
  $baseFrom = "FROM orders o
    JOIN order_sources os ON os.id = o.source_id
    LEFT JOIN customers cu ON cu.id = o.customer_id
    LEFT JOIN order_addresses oa_ship ON oa_ship.order_id = o.id AND UPPER(oa_ship.type) = 'SHIPPING'
    LEFT JOIN order_addresses oa_bill ON oa_bill.order_id = o.id AND UPPER(oa_bill.type) = 'BILLING'";

  $countSql = "SELECT COUNT(DISTINCT o.id) AS total $baseFrom $whereSql";
  $countStmt = $conn->prepare($countSql);
  if (!$countStmt) {
    orderSearchOut(500, ['ok' => false, 'error' => 'Count prepare failed: ' . $conn->error]);
  }
  orderSearchBind($countStmt, $types, $params);
  $countStmt->execute();
  $total = (int) (($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
  $countStmt->close();

  $sql = "SELECT
      o.id,
      o.customer_id,
      o.order_number,
      o.external_order_id,
      o.order_date,
      o.imported_at,
      o.status,
      o.priority,
      o.total,
      o.currency,
      os.code AS source_code,
      cu.name AS customer_name,
      cu.email AS customer_email,
      COALESCE(oa_ship.country, oa_bill.country) AS country_code,
      COALESCE(oa_ship.city, oa_bill.city) AS city,
      (
        SELECT GROUP_CONCAT(DISTINCT c.code ORDER BY c.code SEPARATOR ', ')
        FROM order_categories oc
        JOIN categories c ON c.id = oc.category_id
        WHERE oc.order_id = o.id
      ) AS categories,
      (
        SELECT GROUP_CONCAT(
          CONCAT(
            COALESCE(NULLIF(oi.item_type_code, ''), '?'),
            ': ',
            LEFT(COALESCE(NULLIF(oi.custom_label, ''), NULLIF(oi.title, ''), NULLIF(oi.sku, ''), ''), 120)
          )
          ORDER BY oi.line_no, oi.id
          SEPARATOR ' | '
        )
        FROM order_items oi
        WHERE oi.order_id = o.id AND oi.deleted_at IS NULL
      ) AS item_summary
    $baseFrom
    $whereSql
    ORDER BY COALESCE(o.imported_at, o.order_date) DESC, o.id DESC
    LIMIT $limit";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    orderSearchOut(500, ['ok' => false, 'error' => 'Search prepare failed: ' . $conn->error]);
  }
  orderSearchBind($stmt, $types, $params);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($row = $res->fetch_assoc()) {
    $row['id'] = (int) ($row['id'] ?? 0);
    $row['priority'] = (int) ($row['priority'] ?? 0);
    $row['item_summary'] = orderSearchTrimSummary($row['item_summary'] ?? '', 280);
    $row['match_summary'] = implode('; ', $labels);
    $row['detail_url'] = orderSearchDetailUrl($row);
    $rows[] = $row;
  }
  $stmt->close();

  orderSearchOut(200, [
    'ok' => true,
    'data' => $rows,
    'total' => $total,
    'limited' => $total > count($rows),
    'limit' => $limit,
    'mode' => $mode,
  ]);
}

try {
  $action = trim((string) ($_REQUEST['action'] ?? 'search'));
  if ($action === 'fields') {
    orderSearchOut(200, ['ok' => true, 'fields' => orderSearchPublicFields(), 'operators' => orderSearchOperatorLabels()]);
  }
  if ($action === 'field_values') {
    orderSearchFieldValues($conn);
  }
  if ($action === 'suggest') {
    $field = trim((string) ($_REQUEST['field'] ?? ''));
    $q = trim((string) ($_REQUEST['q'] ?? ''));
    orderSearchOut(200, ['ok' => true, 'items' => orderSearchSuggestions($conn, $field, $q)]);
  }
  if ($action === 'json_explorer') {
    orderSearchJsonExplorer($conn);
  }
  if ($action === 'search') {
    orderSearchRun($conn);
  }
  orderSearchOut(400, ['ok' => false, 'error' => 'Invalid action']);
} catch (Throwable $e) {
  orderSearchOut(500, ['ok' => false, 'error' => $e->getMessage()]);
}