<?php
declare(strict_types=1);

/**
 * Production start cleanup.
 *
 * Deletes normal Orders data while preserving Custom Orders.
 * Temporary browser execution is protected by a run_key and exact confirmation text.
 * Delete this file from the server after use.
 *
 * CLI dry run:
 *   php scripts/production_start_cleanup.php
 *
 * CLI execute after a verified backup:
 *   php scripts/production_start_cleanup.php --execute --confirm=DELETE-NORMAL-ORDERS-KEEP-CUSTOM --delete-files
 */

$isCli = PHP_SAPI === 'cli';
$webRunKey = '3d875ca060404506bc6fb81cd8583821';
$requiredConfirmation = 'DELETE-NORMAL-ORDERS-KEEP-CUSTOM';

if (!$isCli) {
    @set_time_limit(0);
    @ignore_user_abort(true);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');

    $providedRunKey = (string) ($_REQUEST['run_key'] ?? '');
    if (!hash_equals($webRunKey, $providedRunKey)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
}

$options = $isCli ? getopt('', [
    'execute',
    'confirm:',
    'delete-files',
    'help',
]) : [];

if ($isCli && array_key_exists('help', $options)) {
    echo "Production start cleanup\n\n";
    echo "Dry run:\n";
    echo "  php scripts/production_start_cleanup.php\n\n";
    echo "Execute after backup:\n";
    echo "  php scripts/production_start_cleanup.php --execute --confirm={$requiredConfirmation} --delete-files\n";
    exit(0);
}

$base = dirname(__DIR__);
require $base . '/includes/conn.php';

$execute = $isCli
    ? array_key_exists('execute', $options)
    : (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['execute'] ?? '') === '1');
$deleteFiles = $isCli ? array_key_exists('delete-files', $options) : isset($_POST['delete_files']);
$confirmation = $isCli ? (string) ($options['confirm'] ?? '') : (string) ($_POST['confirm'] ?? '');

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

function collectCounts(PDO $pdo, array $tables): array
{
    $counts = [];
    foreach ($tables as $table) {
        if (tableExists($pdo, $table)) {
            $counts[] = [
                'table' => $table,
                'exists' => true,
                'count' => rowCount($pdo, $table),
            ];
        } else {
            $counts[] = [
                'table' => $table,
                'exists' => false,
                'count' => null,
            ];
        }
    }
    return $counts;
}

function printCountsCli(array $counts, string $heading): void
{
    echo "\n{$heading}\n";
    foreach ($counts as $row) {
        if ($row['exists']) {
            printf("  %-38s %d\n", $row['table'], $row['count']);
        }
    }
}

function deleteTables(PDO $pdo, array $tables): array
{
    $log = [];
    foreach ($tables as $table) {
        if (!tableExists($pdo, $table)) {
            continue;
        }
        $deleted = $pdo->exec('DELETE FROM ' . quoteIdentifier($table));
        $log[] = sprintf('deleted %-30s %d', $table, (int) $deleted);
    }
    return $log;
}

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

function normalOrderPhotoDirectories(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }

    $entries = scandir($root);
    if ($entries === false) {
        return [];
    }

    $matches = [];
    foreach ($entries as $entry) {
        $path = $root . DIRECTORY_SEPARATOR . $entry;
        if (preg_match('/^\d+$/D', $entry) === 1 && is_dir($path) && !is_link($path)) {
            $matches[] = $path;
        }
    }

    sort($matches, SORT_NATURAL);
    return $matches;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderCountRows(array $counts): string
{
    $html = '';
    foreach ($counts as $row) {
        $value = $row['exists'] ? (string) $row['count'] : 'missing';
        $class = $row['exists'] ? '' : ' class="muted"';
        $html .= '<tr' . $class . '><td>' . e((string) $row['table']) . '</td><td>' . e($value) . '</td></tr>';
    }
    return $html;
}

function renderWebPage(array $data): void
{
    $statusClass = $data['ok'] ? 'ok' : 'error';
    http_response_code((int) $data['httpCode']);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Darkscrub Production Cleanup</title>';
    echo '<style>';
    echo 'body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#20262c;color:#e7edf3}main{max-width:1100px;margin:0 auto;padding:28px}h1{font-size:24px;margin:0 0 8px}.panel{border:1px solid #51606d;background:#2c343c;padding:18px;margin:14px 0}.status{padding:12px;border:1px solid #51606d;background:#263039}.ok{border-color:#1f9d5b}.error{border-color:#e24b4b;color:#ffd1d1}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}table{width:100%;border-collapse:collapse;font-size:13px}td,th{padding:7px 8px;border-bottom:1px solid #45525e;text-align:left}th{color:#9fc9ff}.muted{color:#8b96a0}.log{white-space:pre-wrap;background:#171c21;border:1px solid #45525e;padding:12px;max-height:360px;overflow:auto}input[type=text]{width:100%;box-sizing:border-box;padding:10px;background:#1b2127;color:#fff;border:1px solid #6b7a88}label{display:block;margin:12px 0}.btn{padding:11px 16px;background:#c83d3d;color:#fff;border:0;font-weight:bold;cursor:pointer}.btn:hover{background:#dc4f4f}code{background:#172029;padding:2px 5px}.small{font-size:13px;color:#aeb9c3}';
    echo '</style></head><body><main>';
    echo '<h1>Darkscrub Production Cleanup</h1>';
    echo '<p class="small">This temporary browser page deletes normal Orders and preserves Custom Orders. Delete this PHP file after the cleanup.</p>';
    echo '<div class="panel status ' . e($statusClass) . '"><strong>' . e((string) $data['headline']) . '</strong><br>' . e((string) $data['summary']) . '</div>';
    echo '<div class="grid"><section class="panel"><h2>Normal order rows</h2><table><thead><tr><th>Table</th><th>Rows</th></tr></thead><tbody>' . renderCountRows($data['normalCounts']) . '</tbody></table></section>';
    echo '<section class="panel"><h2>Custom order rows preserved</h2><table><thead><tr><th>Table</th><th>Rows</th></tr></thead><tbody>' . renderCountRows($data['customCounts']) . '</tbody></table></section></div>';
    echo '<div class="panel"><p><strong>Database:</strong> <code>' . e((string) $data['database']) . '</code></p><p><strong>Mode:</strong> ' . e((string) $data['mode']) . '</p><p><strong>Normal order photo directories:</strong> ' . e((string) $data['photoCount']) . '</p><p><strong>Photo action:</strong> ' . e((string) $data['photoAction']) . '</p></div>';

    if (!$data['completed']) {
        echo '<form method="post" class="panel"><input type="hidden" name="run_key" value="' . e((string) $data['webRunKey']) . '">';
        echo '<p>To execute, type this exact confirmation text:</p><p><code>' . e((string) $data['requiredConfirmation']) . '</code></p>';
        echo '<input type="text" name="confirm" autocomplete="off" spellcheck="false">';
        echo '<label><input type="checkbox" name="delete_files" value="1"> Delete normal order photo directories too</label>';
        echo '<button class="btn" type="submit" name="execute" value="1">Execute cleanup</button>';
        echo '</form>';
    }

    if (!empty($data['log'])) {
        echo '<section class="panel"><h2>Log</h2><div class="log">' . e(implode("\n", $data['log'])) . '</div></section>';
    }

    echo '</main></body></html>';
}

function fatalExit(bool $isCli, string $message, int $exitCode = 1, int $httpCode = 500): void
{
    if ($isCli) {
        fwrite(STDERR, $message . "\n");
        exit($exitCode);
    }

    http_response_code($httpCode);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:Arial;background:#20262c;color:#ffd1d1;padding:24px"><h1>Cleanup stopped</h1><p>' . e($message) . '</p></body>';
    exit;
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

$preservedCustomTables = [
    'custom_order_note_revisions',
    'custom_order_photos',
    'custom_order_activity',
    'custom_order_followups',
    'custom_order_payments',
    'custom_order_items',
    'custom_order_notes',
    'custom_orders',
    'custom_order_contacts',
    'custom_order_number_sequences',
];

$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($database !== 'scrubproduction') {
    fatalExit($isCli, "Safety stop: connected database is '{$database}', expected 'scrubproduction'.", 2, 500);
}

$photoDirectories = normalOrderPhotoDirectories($base . '/uploads/order_photos');
$normalCounts = collectCounts($pdo, $orderTables);
$customCounts = collectCounts($pdo, $preservedCustomTables);
$photoAction = $deleteFiles ? ($execute ? 'DELETE' : 'would delete') : 'keep';

if (!$execute) {
    if ($isCli) {
        echo "Database: {$database}\n";
        echo "Mode: DRY RUN\n";
        echo "Action: delete normal Orders, preserve Custom Orders\n";
        printCountsCli($normalCounts, 'Normal order rows in scope:');
        printCountsCli($customCounts, 'Custom order rows preserved:');
        echo "\nNormal order photo directories: " . count($photoDirectories) . "\n";
        echo "Photo action: keep (--delete-files not supplied)\n";
        echo "\nDry run only. No database rows or files were changed.\n";
        echo "To execute after backup, run:\n";
        echo "  php scripts/production_start_cleanup.php --execute --confirm={$requiredConfirmation} --delete-files\n";
        exit(0);
    }

    renderWebPage([
        'httpCode' => 200,
        'ok' => true,
        'completed' => false,
        'headline' => 'Preview only. Nothing was changed.',
        'summary' => 'Opening this page never runs deletion by itself.',
        'database' => $database,
        'mode' => 'DRY RUN',
        'photoCount' => count($photoDirectories),
        'photoAction' => 'keep unless checked during execute',
        'normalCounts' => $normalCounts,
        'customCounts' => $customCounts,
        'log' => [],
        'requiredConfirmation' => $requiredConfirmation,
        'webRunKey' => $webRunKey,
    ]);
    exit;
}

if (!hash_equals($requiredConfirmation, $confirmation)) {
    if ($isCli) {
        fwrite(STDERR, "Safety stop: execution requires --confirm={$requiredConfirmation}.\n");
        exit(2);
    }

    renderWebPage([
        'httpCode' => 400,
        'ok' => false,
        'completed' => false,
        'headline' => 'Cleanup was not executed.',
        'summary' => 'The confirmation text did not match exactly.',
        'database' => $database,
        'mode' => 'DRY RUN',
        'photoCount' => count($photoDirectories),
        'photoAction' => 'keep unless checked during execute',
        'normalCounts' => $normalCounts,
        'customCounts' => $customCounts,
        'log' => [],
        'requiredConfirmation' => $requiredConfirmation,
        'webRunKey' => $webRunKey,
    ]);
    exit;
}

$log = [];
try {
    $pdo->beginTransaction();

    if (tableExists($pdo, 'custom_order_photos') && columnExists($pdo, 'custom_order_photos', 'production_photo_id')) {
        $updated = $pdo->exec('UPDATE custom_order_photos SET production_photo_id = NULL WHERE production_photo_id IS NOT NULL');
        $log[] = sprintf('cleared custom_order_photos.production_photo_id %d', (int) $updated);
    }
    if (tableExists($pdo, 'custom_orders') && columnExists($pdo, 'custom_orders', 'production_order_id')) {
        $updated = $pdo->exec('UPDATE custom_orders SET production_order_id = NULL WHERE production_order_id IS NOT NULL');
        $log[] = sprintf('cleared custom_orders.production_order_id %d', (int) $updated);
    }

    $log = array_merge($log, deleteTables($pdo, $orderTables));
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fatalExit($isCli, 'Cleanup failed; database transaction was rolled back. ' . $exception->getMessage(), 1, 500);
}

try {
    resetAutoIncrement($pdo, $orderTables);
} catch (Throwable $exception) {
    $log[] = 'Warning: rows were deleted, but an AUTO_INCREMENT reset failed: ' . $exception->getMessage();
}

if ($deleteFiles) {
    try {
        foreach ($photoDirectories as $directory) {
            removeTree($directory);
            $log[] = 'deleted photo directory ' . basename($directory);
        }
    } catch (Throwable $exception) {
        $log[] = 'Warning: database cleanup completed, but file cleanup failed: ' . $exception->getMessage();
    }
}

$normalCountsAfter = collectCounts($pdo, $orderTables);
$customCountsAfter = collectCounts($pdo, $preservedCustomTables);

if ($isCli) {
    echo "Database: {$database}\n";
    echo "Mode: EXECUTE\n";
    echo "Action: delete normal Orders, preserve Custom Orders\n";
    foreach ($log as $line) {
        echo "  {$line}\n";
    }
    printCountsCli($normalCountsAfter, 'Normal order rows remaining:');
    printCountsCli($customCountsAfter, 'Custom order rows preserved after cleanup:');
    echo "\nProduction start cleanup completed.\n";
    exit(0);
}

renderWebPage([
    'httpCode' => 200,
    'ok' => true,
    'completed' => true,
    'headline' => 'Cleanup completed.',
    'summary' => 'Normal Orders were deleted. Custom Orders were preserved.',
    'database' => $database,
    'mode' => 'EXECUTE',
    'photoCount' => count($photoDirectories),
    'photoAction' => $deleteFiles ? 'deleted' : 'kept',
    'normalCounts' => $normalCountsAfter,
    'customCounts' => $customCountsAfter,
    'log' => $log,
    'requiredConfirmation' => $requiredConfirmation,
    'webRunKey' => $webRunKey,
]);