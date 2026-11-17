<?php
declare(strict_types=1);

/** All unfinished cards owned by this employee, including Backlog and unloaded pages. */
function kb_active_card_count(PDO $pdo, int $employeeId): int
{
    if ($employeeId <= 0) return 0;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM kanban_boards b
        JOIN kanban_columns col ON col.board_id = b.id
        JOIN kanban_cards card ON card.column_id = col.id
        WHERE b.employee_id = ? AND col.is_done = 0');
    $stmt->execute([$employeeId]);
    return (int) $stmt->fetchColumn();
}
