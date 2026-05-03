<?php

function isOrderItemReady(string $type, string $status): bool {
    $type = strtoupper($type);
    $status = strtoupper($status);

    if ($type === 'G') {
        return in_array($status, ['CUT', 'READY'], true);
    }

    if ($type === 'F') {
        return in_array($status, ['DONE', 'READY'], true);
    }

    return $status === 'READY';
}

function recalculateOrderWorkflow(mysqli $conn, int $orderId): void {
    $stmt = $conn->prepare("
        SELECT item_type_code, status
        FROM order_items
        WHERE order_id = ?
          AND deleted_at IS NULL
          AND item_type_code IS NOT NULL
          AND item_type_code <> ''
        ORDER BY id ASC
    ");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $res = $stmt->get_result();

    $hasWaiting = false;
    $hasInProgress = false;
    $allReady = true;
    $blocker = null;

    while ($item = $res->fetch_assoc()) {
        $type = strtoupper((string)$item['item_type_code']);
        $status = strtoupper((string)($item['status'] ?? 'NEW'));

        if ($status === 'WAITING') {
            $hasWaiting = true;
            if ($blocker === null) $blocker = $type;
        }

        if (!isOrderItemReady($type, $status)) {
            $allReady = false;
            if ($blocker === null) $blocker = $type;
        }

        if (!in_array($status, ['NEW', 'WAITING', 'READY', 'CUT', 'DONE'], true)) {
            $hasInProgress = true;
        }
    }

    $stmt->close();

    if ($blocker === null) {
        $blocker = '';
    }

    if ($hasWaiting) {
        $orderStatus = 'WAITING_PARTS';
        $traffic = 'ORANGE';
    } elseif ($allReady) {
        $orderStatus = 'READY_TO_INVOICE';
        $traffic = 'GREEN';
    } elseif ($hasInProgress) {
        $orderStatus = 'IN_PROGRESS';
        $traffic = 'ORANGE';
    } else {
        $orderStatus = 'NEW';
        $traffic = 'RED';
    }

    $stmt = $conn->prepare("
        UPDATE orders
        SET status = ?,
            traffic_light = ?,
            traffic_blocker = ?
        WHERE id = ?
    ");
    $stmt->bind_param('sssi', $orderStatus, $traffic, $blocker, $orderId);
    $stmt->execute();
    $stmt->close();
}