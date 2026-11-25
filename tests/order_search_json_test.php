<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/includes/order_search_registry.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$source = file_get_contents(dirname(__DIR__) . '/scripts/orders/search_builder.php');
preg_match_all('/^function orderSearch\w+\b.*?^\}/ms', $source, $functions);
foreach ($functions[0] as $function) { eval($function); }

// Connection-local fixtures shadow application tables; real orders are never changed.
$conn->query('CREATE TEMPORARY TABLE orders (id INT PRIMARY KEY, source_meta LONGTEXT)');
$conn->query('CREATE TEMPORARY TABLE order_items (order_id INT, options_json LONGTEXT, internal_options_json LONGTEXT, deleted_at DATETIME NULL)');
$fixtures = [
  1 => '{"_followup":{"customs_ddp_amount":5}}',
  2 => '{"_followup":{"customs_ddp_amount":15}}',
  3 => '{"_followup":{"customs_ddp_amount":50}}',
  4 => '{"customs_ddp_amount":5,"_followup":{"other":5}}',
  5 => '{"_followup":{"customs_ddp_amount":5.0}}',
  6 => '{"_followup":{"customs_ddp_amount":"5.00"}}',
  7 => '{"_followup":{"customs_ddp_amount":0}}',
  8 => '{"_followup":{"customs_ddp_amount":null}}',
  9 => '{broken',
  10 => null,
  11 => '{"_followup":{"customs_ddp_amount":"not a number"}}',
  12 => '{"_followup":{"customs_ddp_amount":""}}',
  13 => '{"_followup":{"customs_ddp_amount":false}}',
  14 => '{"_followup":{"customs_ddp_amount":[5]}}',
  15 => '{"_followup":{"customs_ddp_amount":{"nested":5}}}',
  16 => json_encode(['odd.key' => ["it's safe" => 5], 'lines' => [['amount' => 5], ['amount' => 15]], '0' => 'numeric object key', 'quote"key' => 'quoted']),
];
$stmt = $conn->prepare('INSERT INTO orders VALUES (?, ?)');
foreach ($fixtures as $id => $json) { $stmt->bind_param('is', $id, $json); $stmt->execute(); }
$stmt->close();
$conn->query("INSERT INTO order_items VALUES
 (1, '{\"price\":5}', '{\"price\":15}', NULL),
 (2, '{\"price\":15}', '{\"price\":5}', NULL),
 (3, '{\"price\":5}', '{\"price\":5}', '2026-01-01'),
 (4, 'broken', 'broken', NULL)");
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
  $checks++;
  if (!$condition) throw new RuntimeException($message);
};
$fields = orderSearchFieldRegistry();
$search = static function (string $field, string $operator, string $value, string $path, array $expected) use ($conn, $fields, $assert): void {
  $built = orderSearchBuildFieldCondition($fields[$field], $operator, $value, '', $path);
  $assert($built !== null, 'Filter builds');
  $assert(substr_count($built['sql'], '?') === strlen($built['types']), 'All parameters are bound');
  $stmt = $conn->prepare('SELECT o.id FROM orders o WHERE ' . $built['sql'] . ' ORDER BY o.id');
  orderSearchBind($stmt, $built['types'], $built['params']);
  $stmt->execute();
  $actual = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
  $stmt->close();
  $assert($actual === $expected, "$field $path $operator $value: " . json_encode($actual));
};
$path = '_followup.customs_ddp_amount';
$search('source_meta', 'equals', '5', $path, [1,5,6]);
$search('source_meta', 'equals', '5.00', '$.' . $path, [1,5,6]);
$search('source_meta', 'contains', '5', $path, [1,2,3,5,6]);
$search('source_meta', 'equals', '0', $path, [7]);
$search('source_meta', 'equals', 'false', $path, [13]);
$search('source_meta', 'not_equals', '5', $path, [2,3,4,7,8,9,10,11,12,13,14,15,16]);
$search('source_meta', 'not_equals', 'false', $path, [1,2,3,4,5,6,7,8,9,10,11,12,14,15,16]);
$search('source_meta', 'is_empty', '', $path, [4,8,9,10,12,14,15,16]);
$search('source_meta', 'is_not_empty', '', $path, [1,2,3,5,6,7,11,13]);
$search('source_meta', 'equals', '5', '"odd.key"."it\'s safe"', [16]);
$search('source_meta', 'equals', '5', 'lines[0].amount', [16]);
$search('source_meta', 'equals', '5', 'lines[1].amount', []);
$search('source_meta', 'equals', 'numeric object key', '"0"', [16]);
$search('source_meta', 'contains', 'other', '', [4]);
$search('item_options_text', 'equals', '5', 'price', [1]);
$search('internal_options_text', 'equals', '5', 'price', [2]);
foreach (['foo.', '.foo', 'foo..bar', 'foo[]', 'foo[*]', 'foo.[0]', 'foo[0]bar', '$', 'foo.**', '"bad\\escape"'] as $invalid) {
  try { orderSearchNormalizeJsonPath($invalid); throw new RuntimeException('Accepted invalid path: ' . $invalid); }
  catch (InvalidArgumentException $e) { $assert(true, 'Invalid path rejected'); }
}
$attack = "x' OR 1=1 --";
$search('source_meta', 'equals', '5', json_encode($attack), []);
$rows = orderSearchRawJsonFieldRows($conn, 'source_meta', 'customs_ddp_amount');
$assert(in_array($path, array_column($rows, 'path'), true), 'DDP key available in path picker');
$rows = orderSearchRawJsonFieldRows($conn, 'source_meta', '');
$assert(in_array('lines[0].amount', array_column($rows, 'path'), true), 'Array indexes are selectable');
$assert(in_array('"odd.key"."it\'s safe"', array_column($rows, 'path'), true), 'Quoted keys remain unambiguous');
$assert(in_array('"0"', array_column($rows, 'path'), true), 'Numeric object keys remain object keys');
$assert(strpos(orderSearchFilterLabel($fields['source_meta'], 'equals', '5', '', $path), $path . ' is 5') !== false, 'Result label includes path');
echo "order_search_json: $checks checks passed\n";
