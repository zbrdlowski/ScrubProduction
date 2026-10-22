<?php
declare(strict_types=1);

// Run from the server's shell. Never expose a database migration over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$mode = $argv[1] ?? '--check';
if (count($argv) > 2 || !in_array($mode, ['--check', '--apply', '--help'], true)) {
    fwrite(STDERR, "Použitie: php scripts/accounting/install_tatra.php [--check|--apply|--help]\n");
    exit(1);
}
if ($mode === '--help') {
    echo "Tatra B. Payouts – databázová migrácia\n\n";
    echo "  --check  Overí schému bez zmien (predvolené; kód 2 = migrácia chýba).\n";
    echo "  --apply  Vytvorí chýbajúce tabuľky a overí výsledok.\n";
    echo "  --help   Zobrazí tento návod bez pripojenia k databáze.\n\n";
    echo "Pripojenie používa includes/conn.php z tohto servera.\n";
    echo "Opakované spustenie zachová existujúce dáta. CSV sa neimportuje.\n";
    exit(0);
}

// conn.php can terminate the process itself if the connection fails.
// A failed connection must not be reported to deployment tooling as success.
$finished = false;
register_shutdown_function(static function () use (&$finished): void {
    if (!$finished) exit(1);
});

try {
    require_once dirname(__DIR__, 2) . '/includes/conn.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Databázové pripojenie nie je dostupné.');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Migrácia podporuje iba MySQL / MariaDB.');
    }
    echo 'Databáza: ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";

    $expectedColumns = [
        'accounting_tatra_imports' => [
            'id', 'original_filename', 'file_hash', 'source_row_count', 'imported_row_count',
            'duplicate_row_count', 'matched_order_count', 'imported_by', 'imported_at',
        ],
        'accounting_tatra_transactions' => [
            'id', 'import_id', 'source_key', 'transaction_date', 'billing_date', 'transaction_type',
            'type_raw', 'state', 'order_number', 'payer_name', 'service', 'amount', 'currency',
            'settlement_amount', 'settlement_currency', 'report_id', 'payout_reference',
            'matched_order_id', 'created_at',
        ],
        'accounting_tatra_bank_transactions' => [
            'id', 'import_id', 'source_key', 'transaction_date', 'processing_date', 'transaction_type',
            'type_raw', 'own_account', 'counterparty_account', 'counterparty_bank', 'payer_name',
            'amount', 'currency', 'variable_symbol', 'payer_reference', 'bank_reference',
            'payment_message', 'payout_reference', 'matched_order_id', 'matched_custom_order_id',
            'export_order_number', 'match_method', 'manual_override', 'excluded',
            'reviewed_by', 'reviewed_at', 'created_at',
        ],
    ];
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ? AND table_type = \'BASE TABLE\'');
    $missing = [];
    foreach (array_keys($expectedColumns) as $table) {
        $exists->execute([$table]);
        if (!(int) $exists->fetchColumn()) $missing[] = $table;
    }

    if ($mode === '--check' && $missing) {
        echo 'CHÝBA: ' . implode(', ', $missing) . "\n";
        echo "Spustite: php scripts/accounting/install_tatra.php --apply\n";
        $finished = true;
        exit(2);
    }

    if ($mode === '--apply' && $missing) {
        // The new foreign key must use the same signed BIGINT type as orders.id.
        // Refuse incompatible schemas instead of changing the existing orders table.
        $orderTable = $pdo->query("SELECT ENGINE FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'orders'")->fetchColumn();
        $orderIdType = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'id'")->fetchColumn();
        if (strcasecmp((string) $orderTable, 'InnoDB') !== 0
            || !preg_match('/^bigint(?:\(\d+\))?$/iD', (string) $orderIdType)) {
            throw new RuntimeException('Očakáva sa tabuľka orders s engine InnoDB a stĺpcom id typu BIGINT bez UNSIGNED. Existujúca schéma sa nemenila.');
        }
        $migrations = [
            'accounting_tatra.sql' => ['accounting_tatra_imports', 'accounting_tatra_transactions'],
            'accounting_tatra_bank.sql' => ['accounting_tatra_bank_transactions'],
        ];
        $scripts = [];
        foreach ($migrations as $file => $tables) {
            if (!array_intersect($missing, $tables)) continue;
            $path = dirname(__DIR__, 2) . '/db/' . $file;
            $sql = is_file($path) ? file_get_contents($path) : false;
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('Chýba alebo je prázdny súbor db/' . $file . '.');
            }
            $scripts[] = $sql;
        }
        // Read every required file before DDL. CREATE IF NOT EXISTS permits resuming.
        foreach ($scripts as $sql) $pdo->exec($sql);
        foreach ($missing as $table) echo 'Vytvorená tabuľka: ' . $table . "\n";
    }

    foreach ($expectedColumns as $table => $columns) {
        $pdo->query('SELECT ' . implode(', ', $columns) . ' FROM ' . $table . ' LIMIT 0');
    }
    foreach (['accounting_tatra_imports' => 'file_hash', 'accounting_tatra_transactions' => 'source_key', 'accounting_tatra_bank_transactions' => 'source_key'] as $table => $column) {
        $unique = $pdo->prepare('SELECT COUNT(*) FROM (
            SELECT index_name FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0
            GROUP BY index_name HAVING COUNT(*) = 1 AND MAX(column_name) = ?
        ) required_index');
        $unique->execute([$table, $column]);
        if (!(int) $unique->fetchColumn()) {
            throw new RuntimeException('Chýba jedinečný index pre ' . $table . '.' . $column . '. Existujúce tabuľky sa automaticky neopravujú.');
        }
    }
    $foreignKey = $pdo->prepare('SELECT COUNT(*) FROM information_schema.key_column_usage
        WHERE table_schema = DATABASE() AND table_name = ?
          AND column_name = ? AND referenced_table_schema = DATABASE()
          AND referenced_table_name = ? AND referenced_column_name = \'id\'');
    foreach (['accounting_tatra_transactions', 'accounting_tatra_bank_transactions'] as $table) {
        foreach (['import_id' => 'accounting_tatra_imports', 'matched_order_id' => 'orders'] as $column => $parent) {
            $foreignKey->execute([$table, $column, $parent]);
            if (!(int) $foreignKey->fetchColumn()) {
                throw new RuntimeException('Chýba väzba ' . $table . '.' . $column . ' → ' . $parent . '.id.');
            }
        }
    }
    echo "OK – Tatra B. Payouts: tabuľky, stĺpce, jedinečné indexy a väzby sú pripravené.\n";
    echo "CSV nahrajte cez index.php?page=accounting_tatra.\n";
    $finished = true;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'CHYBA: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Migrácia nevymazáva dáta. Po odstránení príčiny ju môžete spustiť znova.\n");
    exit(1);
}
