<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/includes/project_filters.php';

// These connection-local temporary tables shadow the application tables.
// All fixture changes below affect only temporary projects and tasks.
if (!$conn->query('CREATE TEMPORARY TABLE projects (
  id INT PRIMARY KEY, assigned_to INT NULL, status VARCHAR(32) NOT NULL
)') || !$conn->query('CREATE TEMPORARY TABLE project_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL,
  assigned_to INT NULL, status VARCHAR(32) NOT NULL
)')) {
  throw new RuntimeException('Could not create isolated project fixtures.');
}

try {
  $conn->query("INSERT INTO projects (id, assigned_to, status) VALUES
    (1, NULL, 'in_progress'), (2, NULL, 'in_progress'), (3, NULL, 'waiting'),
    (4, 7, 'in_progress'), (5, 7, 'waiting'), (6, 7, 'in_progress'),
    (7, 7, 'done'), (8, 7, 'cancelled'),
    (9, 8, 'in_progress'), (10, 8, 'in_progress'), (11, NULL, 'waiting'),
    (12, NULL, 'done'), (13, NULL, 'cancelled'), (14, 8, 'waiting')");
  $conn->query("INSERT INTO project_tasks (project_id, assigned_to, status) VALUES
    (1, 7, 'waiting'), (1, 7, 'in_progress'), (1, 7, 'done'), (1, 8, 'waiting'),
    (2, 7, 'done'), (2, 8, 'stuck'), (3, 8, 'waiting'),
    (4, 7, 'done'), (4, 8, 'waiting'), (6, 7, 'done'),
    (7, 7, 'waiting'), (8, 7, 'waiting'),
    (9, 7, 'stuck'), (9, 7, 'waiting'), (9, 8, 'done'),
    (10, 7, 'done'), (10, 8, 'waiting'),
    (12, 7, 'waiting'), (13, 7, 'waiting')");

  $assertProjects = static function (int $employeeId, array $expected, string $label) use ($conn): void {
    $where = projectActiveForEmployeeWhere();
    $stmt = $conn->prepare("SELECT p.id FROM projects p WHERE {$where} ORDER BY p.id");
    $stmt->bind_param('ii', $employeeId, $employeeId);
    $stmt->execute();
    $actual = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
    $stmt->close();
    if ($actual !== $expected) {
      throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    if (projectCountActiveForEmployee($conn, $employeeId) !== count($expected)) {
      throw new RuntimeException($label . ': badge count does not match the active project list.');
    }
  };

  // Count each project once. Owners remain responsible even with no tasks or
  // all tasks done until the project itself is complete. Other contributors
  // count only while at least one of their own tasks is unfinished.
  $assertProjects(7, [1, 4, 5, 6, 9], 'Owner and task contribution rules');
  $assertProjects(8, [1, 2, 3, 4, 9, 10, 14], 'Another employee has independent responsibility');
  $assertProjects(999, [], 'Unrelated employee');
  if (projectCountActiveForEmployee($conn, 0) !== 0 || projectCountActiveForEmployee($conn, -1) !== 0) {
    throw new RuntimeException('Invalid employees must have no badge count.');
  }

  $conn->query("UPDATE project_tasks SET status = 'done' WHERE assigned_to = 7 AND project_id IN (1, 9)");
  $assertProjects(7, [4, 5, 6], 'Finishing own tasks removes general and other-owned projects');
  $assertProjects(8, [1, 2, 3, 4, 9, 10, 14], 'Other contributors and owners are unaffected');

  $conn->query("UPDATE project_tasks SET status = 'stuck' WHERE assigned_to = 7 AND project_id = 1");
  $assertProjects(7, [1, 4, 5, 6], 'Reopening own tasks adds the project back');

  $conn->query("UPDATE projects SET assigned_to = 7 WHERE id = 2");
  $assertProjects(7, [1, 2, 4, 5, 6], 'New owner is responsible even after finishing own tasks');

  $conn->query("UPDATE projects SET status = 'done' WHERE id = 4");
  $conn->query("UPDATE projects SET status = 'cancelled' WHERE id = 5");
  $assertProjects(7, [1, 2, 6], 'Completing or cancelling a project removes owner responsibility');

  $conn->query("UPDATE projects SET status = 'in_progress' WHERE id = 4");
  $assertProjects(7, [1, 2, 4, 6], 'Reopening the project restores owner responsibility');

  echo "project_employee_filters: OK\n";
} finally {
  $conn->query('DROP TEMPORARY TABLE IF EXISTS project_tasks');
  $conn->query('DROP TEMPORARY TABLE IF EXISTS projects');
}
