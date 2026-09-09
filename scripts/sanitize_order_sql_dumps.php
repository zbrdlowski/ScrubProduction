<?php
declare(strict_types=1);

/**
 * Removes normal-order INSERT statements from tracked SQL dumps while retaining
 * schemas, lookup/configuration data, warehouse ledgers, and Custom Orders.
 *
 * Preview: php scripts/sanitize_order_sql_dumps.php
 * Execute: php scripts/sanitize_order_sql_dumps.php --execute --confirm=SANITIZE-ORDER-DUMPS
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = dirname(__DIR__);
$options = getopt('', ['execute', 'confirm:']);
$execute = array_key_exists('execute', $options);
$confirmation = (string) ($options['confirm'] ?? '');

if ($execute && !hash_equals('SANITIZE-ORDER-DUMPS', $confirmation)) {
    fwrite(STDERR, "Safety stop: execution requires --confirm=SANITIZE-ORDER-DUMPS.\n");
    exit(2);
}

$tables = [
    'customers',
    'invoices',
    'orders',
    'orders_finish',
    'orders_2026',
    'order_activity',
    'order_addresses',
    'order_assignments',
    'order_categories',
    'order_invoices',
    'order_items',
    'order_item_assignments',
    'order_item_categories',
    'order_item_statuses',
    'order_photos',
    'order_production_notes',
    'order_status_history',
    'order_tracking_numbers',
    'shipments',
];
$tableLookup = array_fill_keys($tables, true);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base . '/db', FilesystemIterator::SKIP_DOTS)
);

$files = [];
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'sql') {
        $files[] = $file->getPathname();
    }
}
sort($files, SORT_NATURAL | SORT_FLAG_CASE);

$totalStatements = 0;
foreach ($files as $path) {
    $source = fopen($path, 'rb');
    if ($source === false) {
        throw new RuntimeException("Could not read {$path}");
    }

    $tempPath = $path . '.sanitize-' . bin2hex(random_bytes(6)) . '.tmp';
    $target = $execute ? fopen($tempPath, 'wb') : null;
    if ($execute && $target === false) {
        fclose($source);
        throw new RuntimeException("Could not create temporary file for {$path}");
    }

    $removed = 0;
    $skipStatement = false;
    while (($line = fgets($source)) !== false) {
        if (!$skipStatement && preg_match('/^\s*INSERT\s+INTO\s+(?:`[^`]+`\.)?`?([^`\s(.]+)`?/i', $line, $match) === 1) {
            $skipStatement = isset($tableLookup[$match[1]]);
            if ($skipStatement) {
                $removed++;
            }
        }

        if (!$skipStatement && $execute) {
            if (fwrite($target, $line) === false) {
                throw new RuntimeException("Could not write temporary file for {$path}");
            }
        }

        if ($skipStatement && preg_match('/;\s*$/', $line) === 1) {
            $skipStatement = false;
        }
    }

    fclose($source);
    if (is_resource($target)) {
        fflush($target);
        fclose($target);
    }

    if ($removed === 0) {
        if ($execute && is_file($tempPath)) {
            unlink($tempPath);
        }
        continue;
    }

    $relative = str_replace('\\', '/', substr($path, strlen($base) + 1));
    printf("%-75s %d statements %s\n", $relative, $removed, $execute ? 'removed' : 'would be removed');
    $totalStatements += $removed;

    if ($execute) {
        $backupPath = $path . '.sanitize-backup-' . bin2hex(random_bytes(6)) . '.bak';
        if (!rename($path, $backupPath)) {
            @unlink($tempPath);
            throw new RuntimeException("Could not move {$path} aside before replacing it");
        }
        if (!rename($tempPath, $path)) {
            @rename($backupPath, $path);
            @unlink($tempPath);
            throw new RuntimeException("Could not replace {$path} with its sanitized copy");
        }
        if (!unlink($backupPath)) {
            fwrite(STDERR, "Warning: sanitized {$path}, but could not remove backup {$backupPath}.\n");
        }
    }
}

echo "\n" . ($execute ? 'Removed' : 'Found') . " {$totalStatements} normal-order INSERT statements.\n";
if (!$execute) {
    echo "No files were changed.\n";
}
