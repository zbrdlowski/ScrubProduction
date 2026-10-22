<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/tatra_helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
if (!accounting_payout_user_can_access('accounting.import')) { http_response_code(403); exit('Forbidden'); }
$month = (string) ($_POST['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) $month = date('Y-m');
$params = ['page' => 'accounting_tatra', 'month' => $month,
    'source' => in_array($_POST['source'] ?? '', ['bank', 'shoptet'], true) ? $_POST['source'] : 'all',
    'match' => in_array($_POST['match'] ?? '', ['all', 'matched', 'unmatched', 'pending', 'excluded'], true) ? $_POST['match'] : 'all'];
try {
    $csrf = (string) ($_SESSION['accounting_tatra_csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Neplatná alebo expirovaná požiadavka. Obnovte stránku.');
    if (!($pdo instanceof PDO) || !accounting_tatra_bank_schema_ready($pdo)) throw new RuntimeException('Najprv nainštalujte bankový import.');
    $pdo->beginTransaction();
    accounting_tatra_bank_manual($pdo, (int) ($_POST['transaction_id'] ?? 0),
        (string) ($_POST['action'] ?? ''), trim((string) ($_POST['reference'] ?? '')), (int) ($_SESSION['user_id'] ?? 0) ?: null);
    $pdo->commit();
    $params['saved'] = 1;
    $params['type'] = in_array($_POST['type'] ?? '', ['ALL', 'ORDER'], true) ? $_POST['type'] : 'ORDER';
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException) error_log('Tatra bank matching: ' . $e->getMessage());
    $params['error'] = $e instanceof PDOException ? 'Párovanie sa nepodarilo uložiť. Skúste znova.' : $e->getMessage();
}
header('Location: ../../index.php?' . http_build_query($params));
exit;
