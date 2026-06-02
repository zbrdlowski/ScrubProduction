<?php

function seatCoverOptionIsPositive($value): bool {
    if (is_array($value)) {
        return false;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return false;
    }

    $negativeValues = ['no', 'nie', 'nein', 'non', 'false', '0', 'n/a', '-', 'x'];
    return !in_array(mb_strtolower($value), $negativeValues, true);
}

function seatCoverOperationsStateFromJsonStrings(?string $optionsJson, ?string $internalOptionsJson): array {
    $requiredMap = [
        'waterproof-seams' => 'WS',
        'enduro-pocket' => 'EP',
        'side-brand-patches' => 'SP',
    ];

    $options = json_decode((string)$optionsJson, true);
    $internal = json_decode((string)$internalOptionsJson, true);

    if (!is_array($options)) {
        $options = [];
    }
    if (!is_array($internal)) {
        $internal = [];
    }

    $confirmed = $internal['_seat_cover_ops_confirmed'] ?? [];
    if (!is_array($confirmed)) {
        $confirmed = [];
    }

    $required = [];
    foreach ($requiredMap as $optionKey => $shortCode) {
        if (seatCoverOptionIsPositive($options[$optionKey] ?? null)) {
            $required[$optionKey] = [
                'code' => $shortCode,
                'confirmed' => !empty($confirmed[$optionKey]),
            ];
        }
    }

    $allConfirmed = true;
    foreach ($required as $data) {
        if (empty($data['confirmed'])) {
            $allConfirmed = false;
            break;
        }
    }

    return [
        'required' => $required,
        'all_confirmed' => $allConfirmed,
    ];
}

function isOrderItemReady(string $type, string $status, ?string $optionsJson = null, ?string $internalOptionsJson = null): bool {
    $type = strtoupper($type);
    $status = strtoupper($status);

    if ($type === 'G') {
        return in_array($status, ['PRINTED', 'CUT', 'READY'], true);
    }

    if ($type === 'F') {
        return in_array($status, ['DONE', 'READY'], true);
    }

    if ($type === 'S') {
        if ($status !== 'READY') {
            return false;
        }

        $seatCoverState = seatCoverOperationsStateFromJsonStrings($optionsJson, $internalOptionsJson);
        return $seatCoverState['all_confirmed'];
    }

    return $status === 'READY';
}

function itemTrafficState(string $type, array $items): string {
    $total = count($items);
    $ready = 0;
    $started = 0;
    $waiting = 0;

    foreach ($items as $item) {
        $status = strtoupper((string)($item['status'] ?? ''));
        $optionsJson = (string)($item['options_json'] ?? '');
        $internalOptionsJson = (string)($item['internal_options_json'] ?? '');

        if ($status === 'WAITING') {
            $waiting++;
            $started++;
        }

        if (isOrderItemReady($type, $status, $optionsJson, $internalOptionsJson)) {
            $ready++;
            $started++;
        }

        if (in_array($status, ['PROCESSING', 'RTP', 'PRINT_QUEUE', 'PRINTED', 'CUT', 'DONE', 'READY'], true)) {
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
        SELECT item_type_code, status, options_json, internal_options_json
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

        $groups[$type][] = [
            'status' => $status,
            'options_json' => $item['options_json'] ?? null,
            'internal_options_json' => $item['internal_options_json'] ?? null,
        ];
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
