<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/add_item_helpers.php';

$checks = 0;
function itemAssert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$valid = ['barcode' => 'GFP-Stock093', 'name' => 'YZ 85 2015-2021'];
$result = add_item_validate($valid);
itemAssert($result['errors'] === [], 'Minimum valid item must pass');
foreach (['optimum', 'moq', 'baseline'] as $field) {
    itemAssert($result['data'][$field] === '0', 'Missing numbers must default to zero');
    foreach (['', ' ', "\t", null] as $empty) {
        $result = add_item_validate($valid + [$field => $empty]);
        itemAssert($result['errors'] === [] && $result['data'][$field] === '0', 'Empty number must default to zero');
    }
    foreach (['-1', '1.5', '1e3', '+2', '2,5', 'abc', ['3'], true, str_repeat('9', 100)] as $invalid) {
        itemAssert(isset(add_item_validate($valid + [$field => $invalid])['errors'][$field]), 'Invalid number must be rejected');
    }
    $result = add_item_validate($valid + [$field => '00012']);
    itemAssert($result['errors'] === [] && $result['data'][$field] === '12', 'Leading zeros must not alter the quantity');
}
foreach (['barcode', 'name'] as $field) {
    foreach (['', '   ', "\u{00A0}", ['value']] as $invalid) {
        itemAssert(isset(add_item_validate(array_replace($valid, [$field => $invalid]))['errors'][$field]), 'Required field must contain text');
    }
}

foreach (add_item_rules() as $field => $rule) {
    if (($rule['type'] ?? '') === 'integer') {
        itemAssert(!isset(add_item_validate($valid + [$field => (string) $rule['max']])['errors'][$field]), 'Maximum integer must be accepted');
        itemAssert(isset(add_item_validate($valid + [$field => (string) ($rule['max'] + 1)])['errors'][$field]), 'Integer overflow must be rejected');
    } else {
        itemAssert(!isset(add_item_validate(array_replace($valid, [$field => str_repeat('a', $rule['maxLength'])]))['errors'][$field]), 'Exact text limit must pass');
        itemAssert(isset(add_item_validate(array_replace($valid, [$field => str_repeat('a', $rule['maxLength'] + 1)]))['errors'][$field]), 'Overlong text must be rejected');
    }
}
itemAssert(add_item_validate($valid + ['brand' => str_repeat('Ž', 50)])['errors'] === [], 'Count Unicode characters, not bytes, for VARCHAR');
itemAssert(isset(add_item_validate($valid + ['description' => str_repeat('Ž', 32768)])['errors']['description']), 'TEXT byte limit must be enforced');
foreach (["a\0b", "\xC3\x28", "\u{1F600}"] as $invalid) {
    itemAssert(isset(add_item_validate($valid + ['color' => $invalid])['errors']['color']), 'Unstorable text must be rejected');
}
$quoted = '" onfocus="alert(1)';
$result = add_item_validate($valid + ['brand' => $quoted]);
itemAssert($result['input']['brand'] === $quoted, 'User text is retained for safe HTML escaping at render time');
$result = add_item_validate(['barcode' => ' GFP-Stock093 ', 'name' => "\u{00A0}YZ 85\u{00A0}", 'optimum' => '-1', 'color' => 'Blue']);
itemAssert($result['input']['barcode'] === 'GFP-Stock093' && $result['input']['name'] === 'YZ 85', 'Trim surrounding whitespace');
itemAssert($result['input']['optimum'] === '-1' && $result['input']['color'] === 'Blue', 'Preserve values after validation failure');
itemAssert(!array_key_exists('id', add_item_validate($valid + ['id' => '42'])['data']), 'Ignore unrecognized fields');

echo 'add_item_validation: ', $checks, " checks passed\n";

if (!in_array('--database', $argv ?? [], true)) {
    exit;
}

// The real items table is only read for its schema; all writes target a
// connection-local temporary table that shadows it.
require dirname(__DIR__) . '/includes/conn.php';
$definition = $pdo->query('SHOW CREATE TABLE items')->fetch(PDO::FETCH_NUM)[1];
$temporaryDefinition = preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $definition, 1, $replacements);
itemAssert($replacements === 1, 'Temporary table definition must be explicit');
$pdo->exec($temporaryDefinition);
try {
    itemAssert(strpos($pdo->query('SHOW CREATE TABLE items')->fetch(PDO::FETCH_NUM)[1], 'CREATE TEMPORARY TABLE') === 0, 'Integration test must use a temporary table');
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $data = add_item_validate($valid + ['optimum' => '', 'moq' => '0007', 'baseline' => ''])['data'];
    itemAssert(add_item_insert($pdo, $data), 'Valid item must be inserted in strict mode');
    $stored = $pdo->query('SELECT optimum, moq, baseline FROM items')->fetch(PDO::FETCH_NUM);
    itemAssert(array_map('intval', $stored) === [0, 7, 0], 'Numbers must be stored correctly');
    itemAssert(!add_item_insert($pdo, $data), 'Duplicate barcode must not be inserted');
    $data['barcode'] = strtolower($data['barcode']);
    itemAssert(!add_item_insert($pdo, $data), 'Duplicate check must use the database collation');
    itemAssert((int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn() === 1, 'Rejected duplicates must not add rows');

    // A failed insert must also release the lock and allow another request.
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec('ALTER TABLE items DROP COLUMN other_barcode');
    $data['barcode'] = '__prepare_failure';
    $failed = false;
    try {
        add_item_insert($pdo, $data);
    } catch (PDOException $e) {
        $failed = true;
    }
    itemAssert($failed, 'Schema error must be raised to the handler');
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare('SELECT IS_FREE_LOCK(:lock_name)');
    $stmt->execute(['lock_name' => 'darkscrub:add_item:' . sha1($database)]);
    itemAssert((int) $stmt->fetchColumn() === 1, 'Failed inserts must release the creation lock');
    echo "add_item_database: strict-mode inserts, duplicates and lock release passed\n";
} finally {
    $pdo->exec('DROP TEMPORARY TABLE items');
}