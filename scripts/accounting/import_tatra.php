<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/tatra_helpers.php';

function accounting_tatra_redirect(array $params): void
{
    header('Location: ../../index.php?' . http_build_query(array_merge(['page' => 'accounting_tatra'], $params)));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
if (!accounting_payout_user_can_access('accounting.import')) {
    http_response_code(403);
    exit('Forbidden');
}
$month = (string) ($_POST['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) $month = date('Y-m');
$csrf = (string) ($_SESSION['accounting_tatra_csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
    accounting_tatra_redirect(['month' => $month, 'error' => 'Neplatná alebo expirovaná požiadavka. Obnovte stránku.']);
}
if (!($pdo instanceof PDO) || !accounting_tatra_schema_ready($pdo)) {
    accounting_tatra_redirect(['error' => 'Najprv spustite migráciu db/accounting_tatra.sql.']);
}
try {
    if (($_POST['action'] ?? '') === 'refresh') {
        $pdo->beginTransaction();
        $matched = accounting_tatra_refresh_matches($pdo, $month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month')));
        $pdo->commit();
        accounting_tatra_redirect(['month' => $month, 'source' => in_array($_POST['source'] ?? '', ['bank', 'shoptet'], true) ? $_POST['source'] : 'all', 'checked' => $matched]);
    }
    $upload = $_FILES['tatra_files'] ?? [];
    $names = $upload['name'] ?? [];
    if (!is_array($names) || !$names || count($names) > 20) throw new RuntimeException('Vyberte 1 až 20 pôvodných Shoptet Pay alebo Tatra banka CSV súborov.');
    $parsedFiles = [];
    foreach ($names as $index => $name) {
        $tmp = (string) ($upload['tmp_name'][$index] ?? '');
        if (($upload['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($tmp) || (int) ($upload['size'][$index] ?? 0) <= 0) {
            throw new RuntimeException('Nahratie CSV zlyhalo. Vyberte súbory znova.');
        }
        if ((int) $upload['size'][$index] > 20 * 1024 * 1024 || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('Povolené sú CSV súbory s veľkosťou najviac 20 MB.');
        }
        $parsedFiles[] = ['name' => mb_substr(basename($name), 0, 255), 'parsed' => accounting_tatra_parse_file($tmp)];
    }
    $summary = ['imported' => 0, 'duplicates' => 0, 'matched' => 0, 'rematched' => 0];
    $latestDate = '';
    $sources = [];
    $pdo->beginTransaction();
    foreach ($parsedFiles as $file) {
        $sources[$file['parsed']['source'] ?? 'SHOPTET'] = true;
        $result = accounting_tatra_import($pdo, $file['parsed'], $file['name'], (int) ($_SESSION['user_id'] ?? 0) ?: null);
        foreach ($summary as $key => $value) $summary[$key] += $result[$key];
        foreach ($file['parsed']['rows'] as $row) $latestDate = max($latestDate, $row['transaction_date']);
    }
    $pdo->commit();
    accounting_tatra_redirect(array_merge($summary, ['month' => substr($latestDate, 0, 7), 'source' => count($sources) === 1 ? (isset($sources['BANK']) ? 'bank' : 'shoptet') : 'all']));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException) {
        error_log('Tatra payout import: ' . $e->getMessage());
        $message = 'Import sa nepodaril uložiť. Žiadne zmeny sa neuložili; skúste import zopakovať.';
    } else {
        $message = $e->getMessage();
    }
    accounting_tatra_redirect(['month' => $month, 'error' => $message]);
}
