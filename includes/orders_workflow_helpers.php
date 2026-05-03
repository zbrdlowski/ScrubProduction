<?php

function isOrderItemReady(string $type, string $status): bool {
    $type = strtoupper($type);
    $status = strtoupper($status);

    if ($type === 'G') {
        return in_array($status, ['PRINTED', 'CUT', 'READY'], true);
    }

    if ($type === 'F') {
        return in_array($status, ['DONE', 'READY'], true);
    }

    return $status === 'READY';
}

function itemTrafficState(string $type, array $statuses): string {
    $total = count($statuses);
    $ready = 0;
    $started = 0;
    $waiting = 0;

    foreach ($statuses as $status) {
        $status = strtoupper((string)$status);

        if ($status === 'WAITING') {
            $waiting++;
            $started++;
        }

        if (isOrderItemReady($type, $status)) {
            $ready++;
            $started++;
        }

        if (in_array($status, ['PROCESSING', 'RTP', 'PRINT_QUEUE', 'PRINTED'], true)) {
            $started++;
        }
    }

    if ($total > 0 && $ready === $total) {
        return 'GREEN';
    }

    if ($waiting > 0 || $started > 0) {
        return 'ORANGE';
    }

    return 'RED';
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

    $groups = [];

    while ($item = $res->fetch_assoc()) {
        $type = strtoupper((string)$item['item_type_code']);
        $status = strtoupper((string)($item['status'] ?? 'NEW'));

        if (!isset($groups[$type])) {
            $groups[$type] = [];
        }

        $groups[$type][] = $status;
    }

    $stmt->close();

    $summary = [];
    $allGreen = true;
    $hasOrange = false;
    $hasGreen = false;
    $firstBlocker = '';

            foreach ($groups as $type => $statuses) {
                $state = itemTrafficState($type, $statuses);
                $summary[$type] = $state;

                if ($state === 'GREEN') {
                    $hasGreen = true;
                }

                if ($state !== 'GREEN') {
                    $allGreen = false;
                    if ($firstBlocker === '') {
                        $firstBlocker = $type;
                    }
                }

                if ($state === 'ORANGE') {
                    $hasOrange = true;
                }
            }

            if (!$groups) {
                $orderStatus = 'NEW';
                $traffic = 'RED';
            } elseif ($allGreen) {
                $orderStatus = 'READY_TO_INVOICE';
                $traffic = 'GREEN';
            } elseif ($hasOrange || $hasGreen) {
                $orderStatus = 'IN_PROGRESS';
                $traffic = 'ORANGE';
            } else {
                $orderStatus = 'NEW';
                $traffic = 'RED';
            }

    $summaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("
        UPDATE orders
        SET status = ?,
            traffic_light = ?,
            traffic_blocker = ?,
            traffic_summary_json = ?
        WHERE id = ?
    ");
    $stmt->bind_param('ssssi', $orderStatus, $traffic, $firstBlocker, $summaryJson, $orderId);
    $stmt->execute();
    $stmt->close();
}
function addOrderActivity(
    mysqli $conn,
    int $orderId,
    ?int $actorEmployeeId,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    array $payload = [],
    ?string $note = null
): void {
    $payloadJson = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $stmt = $conn->prepare("
        INSERT INTO order_activity
        (order_id, actor_employee_id, action, entity_type, entity_id, payload, note)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'iississ',
        $orderId,
        $actorEmployeeId,
        $action,
        $entityType,
        $entityId,
        $payloadJson,
        $note
    );
    $stmt->execute();
    $stmt->close();
}