<?php
declare(strict_types=1);
session_start();
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/orders_pending_contact_helpers.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli && ((int) ($_SESSION['user_id'] ?? 0) <= 0 || !auth_can('orders.admin'))) {
    http_response_code(403);
    exit('Administrator permission is required to install this feature.');
}
// Diagnostics apply only to this installer, after its administrator access check.
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!$isCli) header('Cache-Control: no-store');

if (empty($_SESSION['pending_contacts_install_csrf'])) {
    $_SESSION['pending_contacts_install_csrf'] = bin2hex(random_bytes(32));
}
$run = $isCli ? in_array('--execute', $argv ?? [], true) : ($_SERVER['REQUEST_METHOD'] === 'POST');
if (!$isCli) header('Content-Type: text/html; charset=utf-8');
if (!$run) {
    if ($isCli) {
        echo "Creates or updates contact tracking, including optional follow-up dates for cancellation requests. Existing orders and contact history are preserved.\n";
        echo "Run: php scripts/orders/install_pending_contacts_2026_11.php --execute\n";
    } else {
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Pending contacts</title><body>';
        echo '<h1>Install or update contact tracking</h1><p>Creates or updates contact tracking, including optional follow-up dates for cancellation requests. Existing orders and contact history are preserved.</p>';
        echo '<form method="post"><input type="hidden" name="csrf" value="'
            . pendingContactEscape($_SESSION['pending_contacts_install_csrf'])
            . '"><button type="submit">Install / update</button></form></body></html>';
    }
    exit;
}
if (!$isCli && (!is_string($_POST['csrf'] ?? null)
    || !hash_equals($_SESSION['pending_contacts_install_csrf'], $_POST['csrf']))) {
    http_response_code(403);
    exit('Invalid form. Refresh the page.');
}
$readMigrationSql = static function (string $relativePath): string {
    $path = dirname(__DIR__, 2) . '/' . $relativePath;
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Migration SQL file is missing or not readable: ' . $path);
    }
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Migration SQL file is empty or could not be read: ' . $path);
    }
    return $sql;
};
$executeMigrationSql = static function (string $sql) use ($conn) {
    $result = $conn->query($sql);
    if ($result === false) {
        throw new RuntimeException('MySQL error ' . $conn->errno . ' (SQLSTATE ' . $conn->sqlstate . '): ' . $conn->error, $conn->errno);
    }
    return $result;
};
$step = 'Read base migration SQL: db/orders/pending_contacts_2026_11.sql';
try {
    $sql = $readMigrationSql('db/orders/pending_contacts_2026_11.sql');
    $step = 'Create the contact table if it does not exist';
    $executeMigrationSql($sql);

    $step = 'Inspect the next_contact_at column';
    $result = $executeMigrationSql("SHOW COLUMNS FROM order_pending_contacts LIKE 'next_contact_at'");
    $column = $result->fetch_assoc();
    $result->free();
    if (!$column) throw new RuntimeException('Column order_pending_contacts.next_contact_at does not exist.');
    if (($column['Null'] ?? '') !== 'YES') {
        $step = 'Read upgrade SQL: db/orders/pending_contacts_optional_next_2026_11.sql';
        $upgrade = $readMigrationSql('db/orders/pending_contacts_optional_next_2026_11.sql');
        $step = 'Allow NULL in order_pending_contacts.next_contact_at';
        $executeMigrationSql($upgrade);
    }

    // Verify directly here: the normal readiness helper intentionally hides SQL errors.
    $step = 'Verify all required contact columns';
    $result = $executeMigrationSql('SELECT id, order_id, actor_employee_id, contacted_at, channel, outcome, note, next_contact_at, request_token FROM order_pending_contacts LIMIT 0');
    $result->free();
    $step = 'Verify that next_contact_at allows NULL';
    $result = $executeMigrationSql("SHOW COLUMNS FROM order_pending_contacts LIKE 'next_contact_at'");
    $column = $result->fetch_assoc();
    $result->free();
    if (($column['Null'] ?? '') !== 'YES') {
        throw new RuntimeException('The next_contact_at column still does not allow NULL after the upgrade.');
    }
    echo 'OK — contact tracking is ready. Open index.php?page=orders&status=PENDING.';
} catch (Throwable $e) {
    http_response_code(500);
    $details = 'Step: ' . $step
        . "\nError type: " . get_class($e)
        . "\nCode: " . $e->getCode()
        . "\nMessage: " . $e->getMessage()
        . "\nLocation: " . $e->getFile() . ':' . $e->getLine()
        . "\nPHP version: " . PHP_VERSION;
    if ($conn->errno) {
        $details .= "\nMySQL error code: " . $conn->errno . "\nSQLSTATE: " . $conn->sqlstate;
    }
    error_log('Pending contacts migration: ' . $details);
    if ($isCli) {
        echo "Installation failed.\n", $details, "\n";
        exit(1);
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Contact tracking installation failed</title><body>';
    echo '<h1>Installation failed</h1><p>Copy the error details below:</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere">'
        . htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre></body></html>';
}
