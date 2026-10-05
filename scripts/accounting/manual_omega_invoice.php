<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/omega_export_helpers.php';

function manualOmegaInvoiceRedirect(array $params): void
{
    $return = [
        'page' => 'accounting_omega_export',
        'import_from' => trim((string) ($_POST['import_from'] ?? '')),
        'import_to' => trim((string) ($_POST['import_to'] ?? '')),
        'processing_date' => trim((string) ($_POST['processing_date'] ?? '')),
    ];
    foreach (['import_from', 'import_to', 'processing_date'] as $key) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $return[$key])) {
            unset($return[$key]);
        }
    }
    header('Location: ../../index.php?' . http_build_query(array_merge($return, $params)));
    exit;
}

function manualOmegaInvoiceUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
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
    manualOmegaInvoiceRedirect(['error' => 'Neplatná alebo expirovaná požiadavka.']);
}
if (!($pdo instanceof PDO) || !omega_export_manual_invoice_schema_ready($pdo)) {
    manualOmegaInvoiceRedirect(['error' => 'Chýba databázová migrácia pre zberné faktúry.']);
}

$action = trim((string) ($_POST['action'] ?? 'create'));

try {
    if ($action === 'restore_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($itemId <= 0) {
            throw new RuntimeException('Chýba položka, ktorú treba vrátiť.');
        }
        $stmt = $pdo->prepare('
            UPDATE accounting_omega_manual_invoice_items
            SET restored_at = NOW(), restored_by = ?
            WHERE id = ? AND restored_at IS NULL
        ');
        $stmt->execute([manualOmegaInvoiceUserId() ?: null, $itemId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Objednávka už bola vrátená alebo položka neexistuje.');
        }
        manualOmegaInvoiceRedirect(['manual_restored' => 1]);
    }

    if ($action !== 'create') {
        throw new RuntimeException('Neznáma akcia.');
    }

    $invoiceNumber = trim((string) ($_POST['invoice_number'] ?? ''));
    $invoiceDate = trim((string) ($_POST['invoice_date'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));
    if ($invoiceNumber === '' || strlen($invoiceNumber) > 128) {
        throw new RuntimeException('Zadajte platné číslo zbernej faktúry.');
    }
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $invoiceDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$parsedDate || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))) {
        throw new RuntimeException('Zadajte platný dátum faktúry.');
    }

    $orderIds = [];
    foreach ((array) ($_POST['order_ids'] ?? []) as $orderId) {
        $orderId = (int) $orderId;
        if ($orderId > 0) {
            $orderIds[$orderId] = $orderId;
        }
    }
    $orderIds = array_values($orderIds);
    if (!$orderIds) {
        throw new RuntimeException('Označte aspoň jednu objednávku.');
    }
    if (count($orderIds) > 500) {
        throw new RuntimeException('Naraz možno zaradiť najviac 500 objednávok.');
    }

    $pdo->beginTransaction();
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare('
        SELECT o.id, o.order_number, o.customer_id, o.total, o.financial_total_value,
               os.code AS source_code, COALESCE(NULLIF(c.name, \'\'), \'Bez názvu\') AS customer_name,
               exported.id AS exported_item_id
        FROM orders o
        JOIN order_sources os ON os.id = o.source_id
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN accounting_omega_export_items exported ON exported.order_id = o.id
        WHERE o.id IN (' . $placeholders . ')
        ORDER BY o.id
        FOR UPDATE
    ');
    $stmt->execute($orderIds);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($orders) !== count($orderIds)) {
        throw new RuntimeException('Niektorá označená objednávka už neexistuje.');
    }

    $customerKey = null;
    foreach ($orders as $order) {
        if (strtoupper((string) $order['source_code']) !== 'CUSTOM') {
            throw new RuntimeException('Zberná faktúra môže obsahovať iba CUSTOM objednávky.');
        }
        if ($order['exported_item_id'] !== null) {
            throw new RuntimeException('Objednávka ' . $order['order_number'] . ' už patrí do OMEGA balíka.');
        }
        $key = $order['customer_id'] === null
            ? 'name:' . mb_strtolower((string) $order['customer_name'], 'UTF-8')
            : 'id:' . (int) $order['customer_id'];
        if ($customerKey === null) {
            $customerKey = $key;
        } elseif ($customerKey !== $key) {
            throw new RuntimeException('Jedna zberná faktúra môže obsahovať objednávky iba jedného zákazníka.');
        }
    }

    $stmt = $pdo->prepare('
        SELECT manual_item.order_number
        FROM accounting_omega_manual_invoice_items manual_item
        WHERE manual_item.order_id IN (' . $placeholders . ')
          AND manual_item.restored_at IS NULL
        LIMIT 1
    ');
    $stmt->execute($orderIds);
    $alreadyAssigned = $stmt->fetchColumn();
    if ($alreadyAssigned !== false) {
        throw new RuntimeException('Objednávka ' . $alreadyAssigned . ' už patrí do inej zbernej faktúry.');
    }

    $firstOrder = $orders[0];
    $stmt = $pdo->prepare('
        INSERT INTO accounting_omega_manual_invoices
          (invoice_number, invoice_date, customer_id, customer_name, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $invoiceNumber,
        $invoiceDate,
        $firstOrder['customer_id'] !== null ? (int) $firstOrder['customer_id'] : null,
        (string) $firstOrder['customer_name'],
        $note !== '' ? $note : null,
        manualOmegaInvoiceUserId() ?: null,
    ]);
    $manualInvoiceId = (int) $pdo->lastInsertId();

    $insertItem = $pdo->prepare('
        INSERT INTO accounting_omega_manual_invoice_items
          (manual_invoice_id, order_id, order_number, customer_name, total_eur)
        VALUES (?, ?, ?, ?, ?)
    ');
    foreach ($orders as $order) {
        $total = omega_export_decimal($order['financial_total_value']);
        if ($total <= 0) {
            $total = omega_export_decimal($order['total']);
        }
        $insertItem->execute([
            $manualInvoiceId,
            (int) $order['id'],
            (string) $order['order_number'],
            (string) $order['customer_name'],
            round($total, 2),
        ]);
    }
    $pdo->commit();

    manualOmegaInvoiceRedirect([
        'manual_created' => count($orders),
        'manual_invoice' => $manualInvoiceId,
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    manualOmegaInvoiceRedirect(['error' => $e->getMessage()]);
}
