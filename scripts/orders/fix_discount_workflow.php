<?php
declare(strict_types=1);

/**
 * Existing coupon cleanup and stale automatic Ready-status repair.
 * Browser: Orders admin, GET preview / POST apply.
 * CLI: php scripts/orders/fix_discount_workflow.php [--apply]
 */
require_once dirname(__DIR__, 2) . '/includes/orders_workflow_helpers.php';
require_once dirname(__DIR__) . '/order_import_lib.php';

function discountWorkflowRepairEligible(array $order): bool
{
    return (int) ($order['status_override'] ?? 0) === 0
        && !in_array(strtoupper(trim((string) $order['status'])), ['PENDING', 'SHIPPED', 'DELIVERED', 'CANCELLED', 'DONE'], true);
}

function discountWorkflowRepairRows(mysqli $conn): array
{
    $result = $conn->query("SELECT id, order_number, status, status_override FROM orders
        WHERE COALESCE(status_override, 0) = 0
          AND UPPER(TRIM(status)) NOT IN ('PENDING', 'SHIPPED', 'DELIVERED', 'CANCELLED', 'DONE')
        ORDER BY id");
    $rows = [];
    while ($order = $result->fetch_assoc()) {
        $orderId = (int) $order['id'];
        if (!ordersWorkflowCurrentStatusIsAllowedByAnyRule($conn, (string) $order['status'])) continue;
        $items = $conn->query("SELECT id, sku, title, custom_label, item_type_code, status
            FROM order_items WHERE order_id = $orderId AND deleted_at IS NULL
              AND COALESCE(item_type_code, '') <> ''")->fetch_all(MYSQLI_ASSOC);
        $coupons = array_values(array_filter($items, 'ordersIsDiscountItem'));
        $staleReady = false;
        if (in_array(strtoupper((string) $order['status']), ['READY_TO_SHIP', 'READY_TO_INVOICE'], true)) {
            $traffic = ordersComputeTrafficFromGroups($conn, ordersBuildWorkflowGroups($conn, $orderId));
            $staleReady = $traffic['traffic_light'] !== 'GREEN';
        }
        if ($coupons || $staleReady) {
            $order['coupons'] = $coupons;
            $order['stale_ready'] = $staleReady;
            $rows[] = $order;
        }
    }
    return $rows;
}

function discountWorkflowRepairApply(mysqli $conn, ?int $actorId): array
{
    // The definition loader may install additive metadata. Run it before the
    // transaction so that its DDL cannot commit a partially repaired order.
    ordersLoadStatusDefinitions($conn);
    $candidates = discountWorkflowRepairRows($conn);
    $repaired = [];
    foreach ($candidates as $candidate) {
        $orderId = (int) $candidate['id'];
        $conn->begin_transaction();
        try {
            $order = $conn->query("SELECT id, order_number, status, status_override FROM orders WHERE id = $orderId FOR UPDATE")->fetch_assoc();
            if (!$order || !discountWorkflowRepairEligible($order)
                || !ordersWorkflowCurrentStatusIsAllowedByAnyRule($conn, (string) $order['status'])) {
                $conn->rollback();
                continue;
            }
            $items = $conn->query("SELECT id, sku, title, custom_label, item_type_code, status
                FROM order_items WHERE order_id = $orderId AND deleted_at IS NULL FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
            $coupons = array_values(array_filter($items, static function (array $item): bool {
                return trim((string) $item['item_type_code']) !== '' && ordersIsDiscountItem($item);
            }));
            foreach ($coupons as $coupon) {
                $itemId = (int) $coupon['id'];
                $conn->query("UPDATE order_items SET item_type_code = NULL, status = NULL WHERE id = $itemId");
                $conn->query("DELETE FROM order_item_categories WHERE item_id = $itemId");
            }
            if ($coupons) oi_refresh_order_categories($conn, $orderId);
            recalculateOrderWorkflow($conn, $orderId);
            $newStatus = ordersGetCurrentOrderStatus($conn, $orderId);
            if ($coupons || $newStatus !== $order['status']) {
                addOrderActivity($conn, $orderId, $actorId, 'DISCOUNT_WORKFLOW_REPAIR', 'order', $orderId, [
                    'previous_status' => $order['status'], 'status' => $newStatus,
                    'coupons' => $coupons,
                ], 'Discount lines excluded from production; automatic workflow recalculated');
                $order['coupons'] = $coupons;
                $order['new_status'] = $newStatus;
                $repaired[] = $order;
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
    return $repaired;
}

function discountWorkflowRepairMain(): int
{
    global $conn, $pdo;
    $cli = PHP_SAPI === 'cli';
    $apply = false;
    $actorId = null;
    if (!$cli) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        if (empty($_SESSION['user_id'])) {
            http_response_code(403);
            echo 'Najprv sa prihlás do administrácie.';
            return 1;
        }
    }
    require_once dirname(__DIR__, 2) . '/includes/conn.php';
    require_once dirname(__DIR__, 2) . '/includes/auth.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if ($cli) {
        $args = array_slice($GLOBALS['argv'] ?? [], 1);
        if ($args !== [] && $args !== ['--apply']) {
            fwrite(STDERR, "Usage: php scripts/orders/fix_discount_workflow.php [--apply]\n");
            return 1;
        }
        $apply = $args === ['--apply'];
    } else {
        auth_require('orders.admin', 'Na opravu je potrebné administrátorské oprávnenie Orders.');
        $actorId = (int) $_SESSION['user_id'];
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'POST'], true)) {
            http_response_code(405);
            header('Allow: GET, POST');
            return 1;
        }
        if (empty($_SESSION['discount_workflow_repair_token'])) {
            $_SESSION['discount_workflow_repair_token'] = bin2hex(random_bytes(32));
        }
        if ($method === 'POST') {
            $token = $_POST['csrf_token'] ?? null;
            if (($_POST['action'] ?? '') !== 'repair' || !is_string($token)
                || !hash_equals($_SESSION['discount_workflow_repair_token'], $token)) {
                http_response_code(403);
                echo 'Neplatný formulár. Obnov stránku.';
                return 1;
            }
            $apply = true;
        }
    }
    try {
        $rows = $apply ? discountWorkflowRepairApply($conn, $actorId) : discountWorkflowRepairRows($conn);
    } catch (Throwable $e) {
        if (!$cli) http_response_code(500);
        echo $cli ? $e->getMessage() : htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        return 1;
    }
    if (!$cli) echo '<!doctype html><html lang="sk"><meta charset="utf-8"><title>Oprava kupónov a Ready statusu</title><body><h1>Oprava kupónov a Ready statusu</h1><p>Kupóny zostanú uložené s pôvodnou cenou, ale prestanú patriť do výroby. Automatické statusy sa prepočítajú podľa skutočných výrobkov. Ručné, uzavreté a nezaplatené objednávky sa nemenia.</p><pre>';
    echo $apply ? 'Opravené objednávky: ' : 'Náhľad — objednávky na opravu: ';
    echo count($rows), "\n";
    foreach ($rows as $row) {
        $line = '#' . $row['id'] . ' ' . $row['order_number'] . ': ' . $row['status'];
        if (isset($row['new_status'])) $line .= ' -> ' . $row['new_status'];
        $line .= '; kupóny: ' . count($row['coupons']);
        if (!empty($row['stale_ready'])) $line .= '; nedokončená výroba';
        echo $cli ? $line : htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "\n";
    }
    if ($cli && !$apply) echo "Iba náhľad. Pre uloženie spusti s --apply.\n";
    if (!$cli) {
        echo '</pre>';
        if (!$apply && $rows) {
            echo '<form method="post"><input type="hidden" name="action" value="repair"><input type="hidden" name="csrf_token" value="',
                htmlspecialchars($_SESSION['discount_workflow_repair_token'], ENT_QUOTES, 'UTF-8'),
                '"><button type="submit">Opraviť uvedené objednávky</button></form>';
        }
        echo '<p>Oprava sa uloží do histórie objednávky. Opakované spustenie je bezpečné.</p></body></html>';
    }
    return 0;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    exit(discountWorkflowRepairMain());
}
