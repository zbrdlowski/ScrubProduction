<?php
declare(strict_types=1);

/**
 * Assignment rules shared by item-status actions.
 *
 * The caller must hold a transaction and lock the parent order followed by the
 * order item. That lock serializes changes to the two workflow positions.
 */

function orderItemDepartmentCode(string $itemType): string
{
    $map = [
        'G' => 'GRAPHICS',
        'P' => 'PLASTICS',
        'T' => 'PLASTICS',
        'M' => 'PLASTICS',
        'S' => 'SEATCOVER',
        'F' => 'FITTING',
    ];

    return $map[strtoupper(trim($itemType))] ?? '';
}

function orderItemDepartmentTypeCodes(string $departmentCode): array
{
    $map = [
        'GRAPHICS' => ['G'],
        'PLASTICS' => ['P', 'T', 'M'],
        'SEATCOVER' => ['S'],
        'FITTING' => ['F'],
    ];

    return $map[strtoupper(trim($departmentCode))] ?? [];
}

function orderItemWorkflowAssignmentState(
    mysqli $conn,
    int $orderId,
    string $departmentCode,
    int $employeeId = 0
): array {
    $typeCodes = orderItemDepartmentTypeCodes($departmentCode);
    if (!$typeCodes) {
        return [
            'items' => [],
            'free_items' => [],
            'own_items' => [],
            'other_items' => [],
            'assignments_by_item' => [],
        ];
    }

    $typePlaceholders = implode(',', array_fill(0, count($typeCodes), '?'));
    $types = 'i' . str_repeat('s', count($typeCodes));
    $params = array_merge([$orderId], $typeCodes);

    $stmt = $conn->prepare("
        SELECT id, item_type_code, sku, title
        FROM order_items
        WHERE order_id = ?
          AND deleted_at IS NULL
          AND UPPER(item_type_code) IN ($typePlaceholders)
        ORDER BY COALESCE(line_no, 999999), id
        FOR UPDATE
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    $itemIds = [];
    while ($row = $res->fetch_assoc()) {
        $itemId = (int) ($row['id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        $row['id'] = $itemId;
        $items[$itemId] = $row;
        $itemIds[] = $itemId;
    }
    $stmt->close();

    $assignmentsByItem = [];
    if ($itemIds) {
        $itemPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));
        $itemTypes = str_repeat('i', count($itemIds));

        $stmt = $conn->prepare("
            SELECT
                oia.item_id,
                oia.employee_id,
                COALESCE(oia.assignment_role, 'WORKER') AS assignment_role,
                TRIM(CONCAT(e.firstname, ' ', e.lastname)) AS emp_name
            FROM order_item_assignments oia
            JOIN employees e ON e.id = oia.employee_id
            WHERE oia.item_id IN ($itemPlaceholders)
              AND oia.assignment_role IN ('PREPARED', 'CHECKED')
              AND oia.removed_at IS NULL
            ORDER BY
                CASE oia.assignment_role
                    WHEN 'PREPARED' THEN 1
                    WHEN 'CHECKED' THEN 2
                    ELSE 3
                END,
                oia.id
            FOR UPDATE
        ");
        $stmt->bind_param($itemTypes, ...$itemIds);
        $stmt->execute();
        $assignmentRes = $stmt->get_result();
        while ($row = $assignmentRes->fetch_assoc()) {
            $assignmentItemId = (int) ($row['item_id'] ?? 0);
            if ($assignmentItemId <= 0) {
                continue;
            }
            $assignmentsByItem[$assignmentItemId][] = [
                'employee_id' => (int) ($row['employee_id'] ?? 0),
                'employee_name' => trim((string) ($row['emp_name'] ?? '')),
                'assignment_role' => strtoupper((string) ($row['assignment_role'] ?? 'WORKER')),
            ];
        }
        $stmt->close();
    }

    $freeItems = [];
    $ownItems = [];
    $otherItems = [];

    foreach ($items as $itemId => $item) {
        $assignments = $assignmentsByItem[$itemId] ?? [];
        if (!$assignments) {
            $freeItems[$itemId] = $item;
            continue;
        }

        $otherAssignment = null;
        foreach ($assignments as $assignment) {
            if ($employeeId <= 0 || (int) $assignment['employee_id'] !== $employeeId) {
                $otherAssignment = $assignment;
                break;
            }
        }

        if ($otherAssignment !== null) {
            $item['blocking_assignment'] = $otherAssignment;
            $otherItems[$itemId] = $item;
            continue;
        }

        $ownItems[$itemId] = $item;
    }

    return [
        'items' => $items,
        'free_items' => $freeItems,
        'own_items' => $ownItems,
        'other_items' => $otherItems,
        'assignments_by_item' => $assignmentsByItem,
    ];
}

/**
 * Creates the department PRIMARY assignment only when the department has not
 * already been taken. Returns true when a row was inserted or reactivated.
 */
function orderItemEnsurePrimaryAssignment(
    mysqli $conn,
    int $orderId,
    int $employeeId,
    string $departmentCode
): bool {
    if ($departmentCode === '') {
        return false;
    }

    $primaryRole = 'PRIMARY_' . $departmentCode;

    $stmt = $conn->prepare("
        SELECT id
        FROM order_assignments
        WHERE order_id = ?
          AND role = ?
          AND removed_at IS NULL
        LIMIT 1
    ");
    $stmt->bind_param('is', $orderId, $primaryRole);
    $stmt->execute();
    $alreadyTaken = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    if ($alreadyTaken) {
        return false;
    }

    // The current schema also has uq_order_employee. Reuse that employee's
    // soft-deleted row when possible, but never overwrite another active role.
    $stmt = $conn->prepare("
        SELECT id, role, removed_at
        FROM order_assignments
        WHERE order_id = ?
          AND employee_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param('ii', $orderId, $employeeId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $existingRole = (string) ($existing['role'] ?? '');
        $isActive = empty($existing['removed_at']);

        if ($isActive && $existingRole !== $primaryRole) {
            return false;
        }

        $assignmentId = (int) $existing['id'];
        $stmt = $conn->prepare("
            UPDATE order_assignments
            SET role = ?,
                state = 'ASSIGNED',
                assigned_by = ?,
                invited_by = NULL,
                assigned_at = NOW(),
                accepted_at = NULL,
                removed_at = NULL
            WHERE id = ?
        ");
        $stmt->bind_param('sii', $primaryRole, $employeeId, $assignmentId);
        $stmt->execute();
        $stmt->close();
        return true;
    }

    $stmt = $conn->prepare("
        INSERT INTO order_assignments
            (order_id, employee_id, role, state, assigned_by)
        VALUES
            (?, ?, ?, 'ASSIGNED', ?)
    ");
    $stmt->bind_param('iisi', $orderId, $employeeId, $primaryRole, $employeeId);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * Fills one of the two workflow positions on an item. A position has exactly
 * one active row, but the same employee may fill PREPARED and CHECKED.
 */
function orderItemSetRoleAssignment(
    mysqli $conn,
    int $orderId,
    int $itemId,
    int $employeeId,
    string $assignmentRole,
    int $assignedBy = 0
): bool {
    $assignmentRole = strtoupper(trim($assignmentRole));
    if (!in_array($assignmentRole, ['PREPARED', 'CHECKED'], true)) {
        throw new InvalidArgumentException('Unsupported item assignment role');
    }
    if ($assignedBy <= 0) {
        $assignedBy = $employeeId;
    }

    $stmt = $conn->prepare("
        SELECT id, employee_id
        FROM order_item_assignments
        WHERE item_id = ?
          AND assignment_role = ?
          AND removed_at IS NULL
        ORDER BY id
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param('is', $itemId, $assignmentRole);
    $stmt->execute();
    $active = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($active && (int) $active['employee_id'] === $employeeId) {
        return false;
    }

    // Replacing CHECKED (for example after reopening and checking again) must
    // close the previous role row before the new one is activated.
    $stmt = $conn->prepare("
        UPDATE order_item_assignments
        SET removed_at = NOW()
        WHERE item_id = ?
          AND assignment_role = ?
          AND removed_at IS NULL
    ");
    $stmt->bind_param('is', $itemId, $assignmentRole);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO order_item_assignments
            (order_id, item_id, employee_id, assignment_role, assigned_by)
        VALUES
            (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            order_id = VALUES(order_id),
            assigned_by = VALUES(assigned_by),
            assigned_at = NOW(),
            removed_at = NULL
    ");
    $stmt->bind_param('iiisi', $orderId, $itemId, $employeeId, $assignmentRole, $assignedBy);
    $stmt->execute();
    $stmt->close();

    return true;
}
