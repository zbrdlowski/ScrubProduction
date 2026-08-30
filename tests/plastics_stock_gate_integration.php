<?php
declare(strict_types=1);

$base = dirname(__DIR__);
require_once $base . '/includes/conn.php';
require_once $base . '/includes/orders_status_helpers.php';
require_once $base . '/includes/orders_workflow_helpers.php';
require_once $base . '/scripts/orders/activity_helper.php';
require_once $base . '/includes/orders_plastics_gate_helpers.php';

function gateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$orderId = 0;
$conn->begin_transaction();
try {
    $sourceId = (int)$conn->query('SELECT id FROM order_sources ORDER BY id ASC LIMIT 1')->fetch_row()[0];
    $externalId = '__plastics_gate_test_' . bin2hex(random_bytes(6));
    $insertOrder = $conn->prepare("\n        INSERT INTO orders (source_id, external_order_id, order_number, imported_at, status)\n        VALUES (?, ?, ?, NOW(), 'NEW')\n    ");
    $insertOrder->bind_param('iss', $sourceId, $externalId, $externalId);
    $insertOrder->execute();
    $orderId = (int)$insertOrder->insert_id;
    $insertOrder->close();

    $insertItem = $conn->prepare("\n        INSERT INTO order_items (order_id, line_no, item_type_code, qty)\n        VALUES (?, ?, ?, 1)\n    ");
    $line = 1;
    foreach (['G', 'P', 'P', 'S', 'F'] as $department) {
        $insertItem->bind_param('iis', $orderId, $line, $department);
        $insertItem->execute();
        $line++;
    }
    $insertItem->close();

    gateAssert(ordersApplyPlasticsStockGate($conn, $orderId), 'Gate was not applied.');

    $conn->query("UPDATE orders SET status = 'PENDING' WHERE id = {$orderId}");
    recalculateOrderWorkflow($conn, $orderId);
    $pendingStatus = (string)$conn->query("SELECT status FROM orders WHERE id = {$orderId}")->fetch_row()[0];
    gateAssert($pendingStatus === 'PENDING', 'Workflow overwrote an unpaid order.');

    // Simulate payment confirmation: the manual endpoint clears the override
    // and hands the order back to the dynamic workflow.
    $conn->query("UPDATE orders SET status = 'NEW', status_override = 0 WHERE id = {$orderId}");
    recalculateOrderWorkflow($conn, $orderId);

    $orderStatus = (string)$conn->query("SELECT status FROM orders WHERE id = {$orderId}")->fetch_row()[0];
    gateAssert($orderStatus === 'PLASTICS_IN_STOCK', 'Overall status did not enter the plastics gate.');

    $plasticsIds = [];
    $result = $conn->query("\n        SELECT id FROM order_items\n        WHERE order_id = {$orderId} AND item_type_code = 'P'\n        ORDER BY id ASC\n    ");
    while ($row = $result->fetch_assoc()) {
        $plasticsIds[] = (int)$row['id'];
    }

    $conn->query("UPDATE order_items SET status = 'PK_✗' WHERE id = {$plasticsIds[0]}");
    gateAssert(
        ordersReleasePlasticsDependantsIfReady($conn, $orderId, 1) === [],
        'Dependent items were released before all plastics items were confirmed.'
    );

    $conn->query("UPDATE order_items SET status = 'PK_✗' WHERE id = {$plasticsIds[1]}");
    $released = ordersReleasePlasticsDependantsIfReady($conn, $orderId, 1);
    gateAssert(count($released) === 3, 'Expected G, S and F to be released.');

    recalculateOrderWorkflow($conn, $orderId);
    $result = $conn->query("\n        SELECT item_type_code, status FROM order_items\n        WHERE order_id = {$orderId}\n        ORDER BY item_type_code ASC, id ASC\n    ");
    $statuses = [];
    while ($row = $result->fetch_assoc()) {
        $statuses[$row['item_type_code']][] = $row['status'];
    }
    gateAssert($statuses['G'] === ['RTP_✗'], 'Graphics default was not restored.');
    gateAssert($statuses['S'] === ['SEW_✗'], 'Seat-cover default was not restored.');
    gateAssert($statuses['F'] === ['FIT_✗'], 'Fitting default was not restored.');
    gateAssert($statuses['P'] === ['PK_✗', 'PK_✗'], 'Plastics confirmations were not preserved.');

    $orderStatus = (string)$conn->query("SELECT status FROM orders WHERE id = {$orderId}")->fetch_row()[0];
    gateAssert($orderStatus === 'NEW', 'Overall status did not return to NEW after release.');

    echo "plastics-stock-gate: OK\n";
} finally {
    $conn->rollback();

    // Some local MariaDB configurations may end the surrounding transaction
    // while workflow helpers inspect metadata. Always remove this test fixture
    // explicitly as a second line of defence.
    if ($orderId > 0) {
        $cleanupQueries = [
            "DELETE ois FROM order_item_statuses ois JOIN order_items oi ON oi.id = ois.order_item_id WHERE oi.order_id = {$orderId}",
            "DELETE FROM order_activity WHERE order_id = {$orderId}",
            "DELETE FROM order_categories WHERE order_id = {$orderId}",
            "DELETE FROM order_items WHERE order_id = {$orderId}",
            "DELETE FROM orders WHERE id = {$orderId} AND external_order_id LIKE '__plastics_gate_test_%'",
        ];
        foreach ($cleanupQueries as $cleanupQuery) {
            $conn->query($cleanupQuery);
        }
    }
}
