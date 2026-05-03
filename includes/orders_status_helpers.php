<?php

function isItemReady($itemType, $status) {
    if ($itemType === 'G') {
        return in_array($status, ['READY', 'CUT']);
    }

    if ($itemType === 'F') {
        return in_array($status, ['DONE', 'READY']);
    }

    return $status === 'READY';
}

function recalculateOrderStatus(PDO $pdo, int $orderId) {
    $stmt = $pdo->prepare("
        SELECT item_type_code, status
        FROM order_items
        WHERE order_id = ?
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        return;
    }

    $hasWaiting = false;
    $hasInProgress = false;
    $allReady = true;

    foreach ($items as $item) {
        $type = $item['item_type_code'];
        $status = $item['status'] ?: 'NEW';

        if ($status === 'WAITING') {
            $hasWaiting = true;
        }

        if (!isItemReady($type, $status)) {
            $allReady = false;
        }

        if (!in_array($status, ['NEW', 'WAITING', 'READY', 'CUT', 'DONE'])) {
            $hasInProgress = true;
        }
    }

    if ($hasWaiting) {
        $orderStatus = 'WAITING_PARTS';
        $traffic = 'ORANGE';
    } elseif ($allReady) {
        $orderStatus = 'READY';
        $traffic = 'GREEN';
    } elseif ($hasInProgress) {
        $orderStatus = 'IN_PROGRESS';
        $traffic = 'ORANGE';
    } else {
        $orderStatus = 'NEW';
        $traffic = 'RED';
    }

    $update = $pdo->prepare("
        UPDATE orders
        SET status = ?, traffic_light = ?
        WHERE id = ?
    ");
    $update->execute([$orderStatus, $traffic, $orderId]);
}

function trafficBadge($traffic) {
    switch ($traffic) {
        case 'GREEN':
            return '<span class="badge badge-success">All Green</span>';
        case 'ORANGE':
            return '<span class="badge badge-warning">Waiting / In progress</span>';
        default:
            return '<span class="badge badge-danger">Not ready</span>';
    }
}