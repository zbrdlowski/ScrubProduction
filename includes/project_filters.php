<?php

/**
 * Active responsibility for the current employee. Uses the projects alias "p"
 * and two employee ID parameters: project owner, then task assignee.
 */
function projectActiveForEmployeeWhere(): string
{
  return "p.status NOT IN ('done', 'cancelled')
    AND (
      p.assigned_to = ?
      OR EXISTS (
        SELECT 1
        FROM project_tasks employee_task
        WHERE employee_task.project_id = p.id
          AND employee_task.assigned_to = ?
          AND employee_task.status <> 'done'
      )
    )";
}

function projectCountActiveForEmployee(mysqli $conn, int $employeeId): int
{
  if ($employeeId <= 0) {
    return 0;
  }

  $where = projectActiveForEmployeeWhere();
  $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM projects p WHERE {$where}");
  if (!$stmt) {
    return 0;
  }

  try {
    $stmt->bind_param('ii', $employeeId, $employeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return intval($row['cnt'] ?? 0);
  } finally {
    $stmt->close();
  }
}

/** Project links for the navbar, using the same responsibility as the badges. */
function projectGetActiveForEmployee(mysqli $conn, int $employeeId): array
{
  if ($employeeId <= 0) {
    return [];
  }

  $where = projectActiveForEmployeeWhere();
  $stmt = $conn->prepare("SELECT p.id, p.title, p.status FROM projects p WHERE {$where}
    ORDER BY FIELD(p.status, 'in_progress', 'stuck', 'waiting'),
             FIELD(p.priority, 'urgent', 'high', 'medium', 'low'), p.id DESC");
  if (!$stmt) {
    return [];
  }

  try {
    $stmt->bind_param('ii', $employeeId, $employeeId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  } finally {
    $stmt->close();
  }
}
