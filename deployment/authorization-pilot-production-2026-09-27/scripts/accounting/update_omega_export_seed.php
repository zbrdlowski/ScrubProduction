<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/omega_export_helpers.php';

function omega_export_seed_redirect(array $params): void
{
    header('Location: ../../index.php?' . http_build_query(array_merge(['page' => 'accounting_omega_export'], $params)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
if (!accounting_payout_user_can_access('accounting.export')) {
    http_response_code(403);
    exit('Forbidden');
}
$csrf = (string) ($_POST['csrf_token'] ?? '');
$expected = (string) ($_SESSION['accounting_payout_csrf'] ?? '');
if ($expected === '' || !hash_equals($expected, $csrf)) {
    omega_export_seed_redirect(['error' => 'Neplatná alebo expirovaná požiadavka.']);
}
if (!($pdo instanceof PDO) || !omega_export_schema_ready($pdo)) {
    omega_export_seed_redirect(['error' => 'Najprv spustite databázovú migráciu db/accounting_omega_exports.sql.']);
}

$seed = strtoupper(trim((string) ($_POST['customer_seed'] ?? '')));
if (!preg_match('/^M([0-9]{1,9})$/', $seed, $matches)) {
    omega_export_seed_redirect(['error' => 'Seed musí mať tvar M2602995.']);
}
$seedNumber = (int) $matches[1];

try {
    $highestUsed = (int) $pdo->query('
        SELECT COALESCE(MAX(CAST(SUBSTRING(partner_code, 2) AS UNSIGNED)), 0)
        FROM accounting_omega_export_items
        WHERE partner_code REGEXP \'^M[0-9]+$\'
    ')->fetchColumn();
    if ($seedNumber < $highestUsed) {
        throw new RuntimeException(
            'Seed nemožno znížiť pod už pridelený kód M' . $highestUsed . '. Posledný použitý kód musí byť aspoň M' . $highestUsed . '.'
        );
    }
    $stmt = $pdo->prepare('UPDATE accounting_omega_export_settings SET current_customer_number = ? WHERE id = 1');
    $stmt->execute([$seedNumber]);
    omega_export_seed_redirect(['seed_updated' => 'M' . $seedNumber]);
} catch (Throwable $e) {
    omega_export_seed_redirect(['error' => $e->getMessage()]);
}
