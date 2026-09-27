<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/omega_export_helpers.php';

if (!accounting_payout_user_can_access()) {
    http_response_code(403);
    exit('Forbidden');
}
if (!($pdo instanceof PDO) || !omega_export_schema_ready($pdo)) {
    http_response_code(503);
    exit('OMEGA export schema is not installed.');
}
$batchId = (int) ($_GET['batch'] ?? 0);
$type = strtolower(trim((string) ($_GET['type'] ?? '')));
if ($batchId <= 0 || !in_array($type, ['partners', 'invoices'], true)) {
    http_response_code(400);
    exit('Invalid export request.');
}
$stmt = $pdo->prepare('SELECT * FROM accounting_omega_export_batches WHERE id = ? LIMIT 1');
$stmt->execute([$batchId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$batch) {
    http_response_code(404);
    exit('Export batch not found.');
}

try {
    $payloads = omega_export_batch_payloads($pdo, $batchId);
    if (!$payloads) {
        throw new RuntimeException('Exportný balík je prázdny.');
    }
    $rows = $type === 'partners'
        ? omega_export_partner_rows($payloads)
        : omega_export_invoice_rows($payloads);
    $content = omega_export_rows_to_ansi($rows);
    $column = $type === 'partners' ? 'partners_downloaded_at' : 'invoices_downloaded_at';
    $pdo->prepare('UPDATE accounting_omega_export_batches SET ' . $column . ' = NOW() WHERE id = ?')->execute([$batchId]);

    $label = $type === 'partners' ? 'OMEGA_partneri' : 'OMEGA_objednavky';
    $filename = $label . '_' . str_replace('-', '', (string) $batch['processing_date']) . '_balik_' . $batchId . '.txt';
    header('Content-Type: text/plain; charset=windows-1250');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('X-Content-Type-Options: nosniff');
    echo $content;
} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getMessage();
}
