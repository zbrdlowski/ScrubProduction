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
