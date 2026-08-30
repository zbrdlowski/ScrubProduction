<?php
declare(strict_types=1);

function ordersApplyPlasticsStockGate(mysqli $conn, int $orderId): bool
{
    $check = $conn->prepare("\n        SELECT 1 FROM order_items\n        WHERE order_id = ? AND deleted_at IS NULL\n          AND UPPER(item_type_code) = 'P'\n        LIMIT 1\n    ");
    $check->bind_param('i', $orderId);
    $check->execute();
    $hasPlastics = (bool)$check->get_result()->fetch_row();
    $check->close();

    if (!$hasPlastics) {
        return false;
    }

    $update = $conn->prepare("\n        UPDATE order_items\n        SET status = CASE\n              WHEN UPPER(item_type_code) = 'P' THEN 'CHECK_STOCK'\n              WHEN UPPER(item_type_code) IN ('G', 'S', 'F') THEN 'PLASTICS_IN_STOCK'\n              ELSE status\n            END,\n            waiting_note = NULL, expected_date = NULL,\n            completed_by = NULL, completed_at = NULL\n        WHERE order_id = ? AND deleted_at IS NULL\n          AND UPPER(item_type_code) IN ('G', 'S', 'P', 'F')\n    ");
    $update->bind_param('i', $orderId);
    $update->execute();
    $update->close();
    return true;
}

/**
 * @return array<int, array{id:int,item_type_code:string,old_status:string,new_status:string}>
 */
function ordersReleasePlasticsDependantsIfReady(mysqli $conn, int $orderId, int $userId): array
{
    $plasticsStmt = $conn->prepare("\n        SELECT status FROM order_items\n        WHERE order_id = ? AND deleted_at IS NULL\n          AND UPPER(item_type_code) = 'P'\n        ORDER BY id ASC FOR UPDATE\n    ");
    $plasticsStmt->bind_param('i', $orderId);
    $plasticsStmt->execute();
    $plasticsResult = $plasticsStmt->get_result();
    $plasticsCount = 0;
    $allConfirmed = true;
    while ($plasticsItem = $plasticsResult->fetch_assoc()) {
        $plasticsCount++;
        if (strtoupper(trim((string)($plasticsItem['status'] ?? ''))) !== 'PK_✗') {
            $allConfirmed = false;
        }
    }
    $plasticsStmt->close();

    if ($plasticsCount === 0 || !$allConfirmed) {
        return [];
    }

    $dependentStmt = $conn->prepare("\n        SELECT id, UPPER(item_type_code) AS item_type_code, status\n        FROM order_items\n        WHERE order_id = ? AND deleted_at IS NULL\n          AND UPPER(item_type_code) IN ('G', 'S', 'F')\n          AND UPPER(COALESCE(status, '')) = 'PLASTICS_IN_STOCK'\n        ORDER BY id ASC FOR UPDATE\n    ");
    $dependentStmt->bind_param('i', $orderId);
    $dependentStmt->execute();
    $dependentResult = $dependentStmt->get_result();
    $dependentItems = [];
    while ($dependentItem = $dependentResult->fetch_assoc()) {
        $dependentItems[] = $dependentItem;
    }
    $dependentStmt->close();

    if (!$dependentItems) {
        return [];
    }

    $defaults = ['G' => 'RTP_✗', 'S' => 'SEW_✗', 'F' => 'FIT_✗'];
    $updateStmt = $conn->prepare("\n        UPDATE order_items\n        SET status = ?, waiting_note = NULL, expected_date = NULL,\n            completed_by = NULL, completed_at = NULL\n        WHERE id = ?\n          AND UPPER(COALESCE(status, '')) = 'PLASTICS_IN_STOCK'\n    ");
    $historyStmt = $conn->prepare("\n        INSERT INTO order_item_statuses\n            (order_item_id, old_status, new_status, note, expected_date, changed_by)\n        VALUES (?, ?, ?, 'Released after plastics stock confirmation', NULL, ?)\n    ");

    $released = [];
    foreach ($dependentItems as $dependentItem) {
        $itemType = (string)$dependentItem['item_type_code'];
        $newStatus = $defaults[$itemType] ?? '';
        if ($newStatus === '') {
            continue;
        }
        $itemId = (int)$dependentItem['id'];
        $oldStatus = (string)$dependentItem['status'];
        $updateStmt->bind_param('si', $newStatus, $itemId);
        $updateStmt->execute();
        if ($updateStmt->affected_rows !== 1) {
            continue;
        }
        $historyStmt->bind_param('issi', $itemId, $oldStatus, $newStatus, $userId);
        $historyStmt->execute();
        $released[] = [
            'id' => $itemId,
            'item_type_code' => $itemType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ];
    }
    $historyStmt->close();
    $updateStmt->close();

    if ($released && function_exists('log_order_activity')) {
        log_order_activity(
            $conn, $orderId, $userId, 'plastics_stock_gate_released',
            'order', $orderId, ['released_items' => $released],
            'Dependent items released after all plastics were confirmed in stock'
        );
    }
    return $released;
}
