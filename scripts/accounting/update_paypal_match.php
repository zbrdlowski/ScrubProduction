<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/paypal_helpers.php';

function accounting_paypal_match_redirect(array $params): void
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
$csrf = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['accounting_paypal_csrf']) || !hash_equals((string) $_SESSION['accounting_paypal_csrf'], $csrf)) {
    accounting_paypal_match_redirect(['error' => 'Neplatná alebo expirovaná požiadavka.']);
}
$id = (int) ($_POST['transaction_row_id'] ?? 0);
$month = trim((string) ($_POST['month'] ?? date('Y-m')));
$reference = strtoupper(trim((string) ($_POST['export_order_number'] ?? '')));
try {
    if ($id <= 0 || !($pdo instanceof PDO) || !accounting_paypal_schema_ready($pdo)) {
        throw new RuntimeException('Neplatná PayPal transakcia.');
    }
    if ($reference !== '' && !accounting_paypal_valid_export_reference($reference)) {
        throw new RuntimeException('Použite SO, e-shop, eBay, SK alebo SC číslo. CO nie je finálna exportná referencia.');
    }
    $stmt = $pdo->prepare('SELECT transaction_id FROM accounting_paypal_transactions WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $transactionId = (string) ($stmt->fetchColumn() ?: '');
    if ($transactionId === '') {
        $pdo->prepare('
            UPDATE accounting_paypal_transactions
            SET export_order_number = ?, match_method = ?, match_confidence = ?, manual_override = 1
            WHERE id = ?
        ')->execute([$reference !== '' ? $reference : null, $reference !== '' ? 'MANUAL' : 'MANUAL_UNMATCHED', $reference !== '' ? 100 : 0, $id]);
    } else {
        $pdo->prepare('
            UPDATE accounting_paypal_transactions
            SET export_order_number = ?, match_method = ?, match_confidence = ?, manual_override = 1
            WHERE BINARY transaction_id = BINARY ?
        ')->execute([$reference !== '' ? $reference : null, $reference !== '' ? 'MANUAL' : 'MANUAL_UNMATCHED', $reference !== '' ? 100 : 0, $transactionId]);
    }
    accounting_paypal_match_redirect(['month' => $month, 'saved' => 1]);
} catch (Throwable $e) {
    accounting_paypal_match_redirect(['month' => $month, 'error' => $e->getMessage()]);
}
