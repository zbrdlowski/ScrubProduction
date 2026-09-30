<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/omega_export_helpers.php';

function omega_export_prepare_redirect(array $params): void
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
    omega_export_prepare_redirect(['error' => 'Neplatná alebo expirovaná požiadavka.']);
}
if (!($pdo instanceof PDO) || !omega_export_schema_ready($pdo)) {
    omega_export_prepare_redirect(['error' => 'Najprv spustite databázovú migráciu db/accounting_omega_exports.sql.']);
}

$from = trim((string) ($_POST['import_from'] ?? ''));
$to = trim((string) ($_POST['import_to'] ?? ''));
$processingDate = trim((string) ($_POST['processing_date'] ?? ''));
foreach ([$from, $to, $processingDate] as $date) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
        omega_export_prepare_redirect(['error' => 'Zadajte platné dátumy.']);
    }
}
if ($from > $to) {
    omega_export_prepare_redirect(['error' => 'Dátum od nemôže byť neskôr ako dátum do.']);
}
if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 31) {
    omega_export_prepare_redirect(['error' => 'Jeden balík môže pokrývať najviac 32 dní importov.']);
}

try {
    $candidates = omega_export_collect_candidates($pdo, $from, $to, $processingDate);
    $selectionSubmitted = (string) ($_POST['selection_submitted'] ?? '') === '1';
    $selectedIds = [];
    foreach ((array) ($_POST['order_ids'] ?? []) as $selectedId) {
        $selectedId = (int) $selectedId;
        if ($selectedId > 0) {
            $selectedIds[$selectedId] = true;
        }
    }
    if ($selectionSubmitted) {
        $candidates['ready'] = array_values(array_filter(
            $candidates['ready'],
            static function (array $order) use ($selectedIds): bool {
                return isset($selectedIds[(int) $order['id']]);
            }
        ));
    }
    if (!$candidates['ready']) {
        omega_export_prepare_redirect([
            'import_from' => $from,
            'import_to' => $to,
            'processing_date' => $processingDate,
            'error' => 'Pre zvolené dátumy nie je pripravená žiadna objednávka.',
        ]);
    }

    $pdo->beginTransaction();
    $setting = $pdo->query('SELECT current_customer_number FROM accounting_omega_export_settings WHERE id = 1 FOR UPDATE')->fetchColumn();
    if ($setting === false) {
        $pdo->exec('INSERT INTO accounting_omega_export_settings (id, current_customer_number) VALUES (1, 2602995)');
        $currentNumber = 2602995;
    } else {
        $currentNumber = max(2602995, (int) $setting);
    }

    $insertBatch = $pdo->prepare('
        INSERT INTO accounting_omega_export_batches
          (processing_date, import_from, import_to, custom_workday, order_count, partner_count,
           waiting_payout_count, blocked_count, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $readyCount = count($candidates['ready']);
    $insertBatch->execute([
        $processingDate, $from, $to, $candidates['customWorkday'], $readyCount, $readyCount,
        count($candidates['waiting']), count($candidates['blocked']),
        (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0) ?: null,
    ]);
    $batchId = (int) $pdo->lastInsertId();

    $insertItem = $pdo->prepare('
        INSERT INTO accounting_omega_export_items
          (batch_id, order_id, source_code, readiness_basis, partner_code,
           payout_transaction_id, exchange_rate, total_eur, payload_json)
        VALUES
          (:batch_id, :order_id, :source_code, :readiness_basis, :partner_code,
           :payout_transaction_id, :exchange_rate, :total_eur, :payload_json)
    ');
    foreach ($candidates['ready'] as $order) {
        $currentNumber++;
        $partnerCode = 'M' . $currentNumber;
        $payload = omega_export_payload($pdo, $order, $partnerCode);
        $payout = $order['_payout'] ?? null;
        $insertItem->execute([
            ':batch_id' => $batchId,
            ':order_id' => (int) $order['id'],
            ':source_code' => $order['source_code'],
            ':readiness_basis' => $order['_readiness_basis'],
            ':partner_code' => $partnerCode,
            ':payout_transaction_id' => $payout['id'] ?? null,
            ':exchange_rate' => $payload['exchange_rate'],
            ':total_eur' => $payload['total_eur'],
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }
    $updateSetting = $pdo->prepare('UPDATE accounting_omega_export_settings SET current_customer_number = ? WHERE id = 1');
    $updateSetting->execute([$currentNumber]);
    $pdo->commit();

    omega_export_prepare_redirect(['batch' => $batchId, 'created' => $readyCount]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    omega_export_prepare_redirect([
        'import_from' => $from,
        'import_to' => $to,
        'processing_date' => $processingDate,
        'error' => $e->getMessage(),
    ]);
}
