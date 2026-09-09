<?php
declare(strict_types=1);

/**
 * CLI-only pilot data reset.
 *
 * Dry runs:
 *   php scripts/pilot_reset.php --scope=orders
 *   php scripts/pilot_reset.php --scope=custom-orders
 *
 * Execute:
 *   php scripts/pilot_reset.php --scope=orders --execute --confirm=RESET-PILOT-ORDERS --delete-files
 *   php scripts/pilot_reset.php --scope=custom-orders --execute --confirm=RESET-CUSTOM-ORDERS --delete-files
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = dirname(__DIR__);
require $base . '/includes/conn.php';

$options = getopt('', [
    'scope:',
    'execute',
    'confirm:',
    'delete-files',
    'reset-custom-sequences',
]);

$scope = strtolower(trim((string) ($options['scope'] ?? 'orders')));
$execute = array_key_exists('execute', $options);
$deleteFiles = array_key_exists('delete-files', $options);
$resetCustomSequences = array_key_exists('reset-custom-sequences', $options);
$confirmation = (string) ($options['confirm'] ?? '');

$validScopes = ['orders', 'custom-orders', 'all'];
if (!in_array($scope, $validScopes, true)) {
    fwrite(STDERR, "Invalid --scope. Use orders, custom-orders, or all.\n");
    exit(2);
}

$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($database !== 'scrubproduction') {
    fwrite(STDERR, "Safety stop: connected database is '{$database}', expected 'scrubproduction'.\n");
    exit(2);
}

switch ($scope) {
    case 'orders':
        $requiredConfirmation = 'RESET-PILOT-ORDERS';
        break;
    case 'custom-orders':
        $requiredConfirmation = 'RESET-CUSTOM-ORDERS';
        break;
    case 'all':
        $requiredConfirmation = 'RESET-ALL-ORDERS';
        break;
    default:
        $requiredConfirmation = '';
}

if ($execute && !hash_equals($requiredConfirmation, $confirmation)) {
    fwrite(STDERR, "Safety stop: execution requires --confirm={$requiredConfirmation}.\n");
    exit(2);
}

if ($resetCustomSequences && !$execute) {
    echo "Note: --reset-custom-sequences has no effect in dry-run mode.\n";
}

if ($resetCustomSequences && $scope === 'orders') {
    fwrite(STDERR, "Safety stop: custom sequences can only be reset with custom-orders or all scope.\n");
    exit(2);
}

/** @return bool */
function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        LIMIT 1
    SQL);
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

/** @return bool */
function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        LIMIT 1
    SQL);
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function quoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/** @return bool */
function tableHasAutoIncrementColumn(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND EXTRA LIKE '%auto_increment%'
        LIMIT 1
    SQL);
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function rowCount(PDO $pdo, string $table): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . quoteIdentifier($table))->fetchColumn();
}

/** @param list<string> $tables */
function printCounts(PDO $pdo, array $tables, string $heading): void
{
    echo "\n{$heading}\n";
    foreach ($tables as $table) {
        if (tableExists($pdo, $table)) {
            printf("  %-38s %d\n", $table, rowCount($pdo, $table));
        }
    }
}

/** @param list<string> $tables */
function deleteTables(PDO $pdo, array $tables): void
{
    foreach ($tables as $table) {
        if (!tableExists($pdo, $table)) {
            continue;
        }
        $deleted = $pdo->exec('DELETE FROM ' . quoteIdentifier($table));
        printf("  deleted %-30s %d\n", $table, (int) $deleted);
    }
}

/** @param list<string> $tables */
function resetAutoIncrement(PDO $pdo, array $tables): void
{
    foreach ($tables as $table) {
        if (!tableExists($pdo, $table) || !tableHasAutoIncrementColumn($pdo, $table) || rowCount($pdo, $table) !== 0) {
            continue;
        }
        $pdo->exec('ALTER TABLE ' . quoteIdentifier($table) . ' AUTO_INCREMENT = 1');
    }
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException("Could not delete file: {$path}");
        }
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        throw new RuntimeException("Could not read directory: {$path}");
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }

    if (!rmdir($path)) {
        throw new RuntimeException("Could not delete directory: {$path}");
    }
}

/** @return list<string> */
function matchingPhotoDirectories(string $root, string $scope): array
{
    if (!is_dir($root)) {
        return [];
    }

    $matches = [];
    $entries = scandir($root);
    if ($entries === false) {
        return [];
    }

    foreach ($entries as $entry) {
        switch ($scope) {
            case 'orders':
                $matchesScope = preg_match('/^\d+$/D', $entry) === 1;
                break;
            case 'custom-orders':
                $matchesScope = preg_match('/^custom-\d+$/D', $entry) === 1;
                break;
            case 'all':
                $matchesScope = preg_match('/^(?:\d+|custom-\d+)$/D', $entry) === 1;
                break;
            default:
                $matchesScope = false;
        }
        $path = $root . DIRECTORY_SEPARATOR . $entry;
        if ($matchesScope && is_dir($path) && !is_link($path)) {
            $matches[] = $path;
        }
    }

    sort($matches, SORT_NATURAL);
    return $matches;
}

$orderTables = [
    'order_item_statuses',
    'order_item_categories',
    'order_item_assignments',
    'order_photos',
    'order_production_notes',
    'order_status_history',
    'invoices',
    'shipments',
    'order_tracking_numbers',
    'order_invoices',
    'order_assignments',
    'order_activity',
    'order_categories',
    'order_addresses',
    'order_items',
    'orders_finish',
    'orders_2026',
    'orders',
    'customers',
];

$customOrderTables = [
    'custom_order_note_revisions',
    'custom_order_photos',
    'custom_order_activity',
    'custom_order_followups',
    'custom_order_payments',
    'custom_order_items',
    'custom_order_notes',
    'custom_orders',
    'custom_order_contacts',
];

$selectedTables = [];
if ($scope === 'orders' || $scope === 'all') {
    $selectedTables = array_merge($selectedTables, $orderTables);
}
if ($scope === 'custom-orders' || $scope === 'all') {
    $selectedTables = array_merge($selectedTables, $customOrderTables);
    if ($resetCustomSequences) {
        $selectedTables[] = 'custom_order_number_sequences';
    }
}

echo "Database: {$database}\n";
echo 'Mode: ' . ($execute ? 'EXECUTE' : 'DRY RUN') . "\n";
echo "Scope: {$scope}\n";
printCounts($pdo, $selectedTables, 'Rows in scope:');

if (($scope === 'orders' || $scope === 'all') && tableExists($pdo, 'orders')) {
    $ledgerChecks = [
        'inventory_movements' => 'order_id',
        'archive_inventory_movements' => 'order_id',
        'plastics_orders' => 'order_number',
        'disassembled_kits' => 'order_number',
    ];

    echo "\nRelated warehouse rows (reported only; never deleted):\n";
    foreach ($ledgerChecks as $table => $column) {
        if (!tableExists($pdo, $table) || !columnExists($pdo, $table, $column)) {
            continue;
        }
        $tableName = quoteIdentifier($table);
        $columnName = quoteIdentifier($column);
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM {$tableName} ledger
            INNER JOIN orders o
              ON TRIM(ledger.{$columnName}) IN (TRIM(o.order_number), TRIM(o.external_order_id))
        SQL;
        printf("  %-38s %d\n", $table, (int) $pdo->query($sql)->fetchColumn());
    }
}

$photoRoot = $base . '/uploads/order_photos';
$photoScope = $scope;
$photoDirectories = matchingPhotoDirectories($photoRoot, $photoScope);
echo "\nPhoto directories in scope: " . count($photoDirectories) . "\n";
echo 'Photo action: ' . ($deleteFiles ? ($execute ? 'DELETE' : 'would delete') : 'keep (--delete-files not supplied)') . "\n";

if (!$execute) {
    echo "\nDry run only. No database rows or files were changed.\n";
    echo "To execute, add --execute --confirm={$requiredConfirmation}.\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    if ($scope === 'orders' || $scope === 'all') {
        // Preserved custom orders must not retain dangling links to deleted production rows.
        if (tableExists($pdo, 'custom_order_photos') && columnExists($pdo, 'custom_order_photos', 'production_photo_id')) {
            $pdo->exec('UPDATE custom_order_photos SET production_photo_id = NULL WHERE production_photo_id IS NOT NULL');
        }
        if (tableExists($pdo, 'custom_orders') && columnExists($pdo, 'custom_orders', 'production_order_id')) {
            $pdo->exec('UPDATE custom_orders SET production_order_id = NULL WHERE production_order_id IS NOT NULL');
        }
        deleteTables($pdo, $orderTables);
    }

    if ($scope === 'custom-orders' || $scope === 'all') {
        deleteTables($pdo, $customOrderTables);
        if ($resetCustomSequences && tableExists($pdo, 'custom_order_number_sequences')) {
            $deleted = $pdo->exec('DELETE FROM custom_order_number_sequences');
            printf("  deleted %-30s %d\n", 'custom_order_number_sequences', (int) $deleted);
        }
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Reset failed; database transaction was rolled back.\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

try {
    resetAutoIncrement($pdo, $selectedTables);
} catch (Throwable $exception) {
    fwrite(STDERR, "Warning: rows were deleted, but an AUTO_INCREMENT reset failed: {$exception->getMessage()}\n");
}

if ($deleteFiles) {
    foreach ($photoDirectories as $directory) {
        removeTree($directory);
        echo "  deleted photo directory " . basename($directory) . "\n";
    }
}

printCounts($pdo, $selectedTables, 'Rows remaining:');
echo "\nPilot reset completed.\n";
