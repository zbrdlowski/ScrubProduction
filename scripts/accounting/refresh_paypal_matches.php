<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/paypal_helpers.php';

function accounting_paypal_refresh_redirect(array $params): void
{
    header('Location: ../../index.php?' . http_build_query(array_merge(['page' => 'accounting_paypal'], $params)));
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
$matchFilter = strtolower(trim((string) ($_POST['match'] ?? 'all')));
if (!in_array($matchFilter, ['all', 'matched', 'review', 'unmatched'], true)) {
    $matchFilter = 'all';
}
$csrf = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['accounting_paypal_csrf']) || !hash_equals((string) $_SESSION['accounting_paypal_csrf'], $csrf)) {
    accounting_paypal_refresh_redirect(['match' => $matchFilter, 'error' => 'Neplatná alebo expirovaná požiadavka.']);
}
$month = trim((string) ($_POST['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}
try {
    if (!($pdo instanceof PDO) || !accounting_paypal_schema_ready($pdo)) {
        throw new RuntimeException('Najprv spustite databázovú migráciu db/accounting_paypal.sql.');
    }
    $from = $month . '-01';
    $to = date('Y-m-d', strtotime($from . ' +1 month'));
    $pdo->beginTransaction();
    $result = accounting_paypal_refresh_matches($pdo, $from, $to, (int) ($_SESSION['user_id'] ?? 0) ?: null);
    $pdo->commit();
    accounting_paypal_refresh_redirect(array_merge(['month' => $month, 'match' => $matchFilter, 'refreshed' => 1], $result));
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    accounting_paypal_refresh_redirect(['month' => $month, 'match' => $matchFilter, 'error' => $e->getMessage()]);
}
