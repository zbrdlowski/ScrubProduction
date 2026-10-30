<?php
declare(strict_types=1);

// Run each real page request in a child process because successful POST handlers
// redirect and exit. All writes, including audit entries, use temporary tables.
$cases = [
  'create', 'create_admin', 'edit_project', 'assign_project', 'delete_project',
  'add_task', 'edit_task', 'delete_task', 'task_start', 'task_complete',
  'task_reopen', 'task_noop', 'task_wrong_project', 'task_stuck', 'task_cancelled',
  'log_start', 'log_invalid', 'log_stuck', 'log_done', 'log_cancelled', 'log_active',
  'edit_time_log', 'delete_time_log', 'list_ui', 'detail_ui', 'guest_post', 'guest_view',
];
$case = $argv[1] ?? '';
if ($case === '') {
  foreach ($cases as $name) {
    $process = proc_open([PHP_BINARY, __FILE__, $name], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start request test.');
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0 || trim($output) !== $name . ': OK' || trim($errors) !== '') {
      throw new RuntimeException($name . ': ' . $output . $errors);
    }
  }
  echo 'project_access_workflow: ', count($cases), " request scenarios passed\n";
  exit;
}
if (!in_array($case, $cases, true)) throw new InvalidArgumentException('Unknown scenario.');
require_once dirname(__DIR__) . '/includes/conn.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$definitions = [
  'employees' => "id INT PRIMARY KEY, firstname VARCHAR(64), lastname VARCHAR(64), photo VARCHAR(255), active VARCHAR(20)",
  'projects' => "id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255), description TEXT, status VARCHAR(32),
    priority VARCHAR(32), start_date DATE NULL, due_date DATE NULL, assigned_to INT NULL, created_by INT,
    notes TEXT, color VARCHAR(16), created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
  'project_tasks' => "id INT AUTO_INCREMENT PRIMARY KEY, project_id INT, title VARCHAR(255), assigned_to INT NULL,
    status VARCHAR(32), priority VARCHAR(32), due_date DATE NULL, notes TEXT, sort_order INT DEFAULT 0",
  'project_time_log' => "id INT AUTO_INCREMENT PRIMARY KEY, project_id INT, task_id INT NULL, employee_id INT,
    logged_date DATE, hours DECIMAL(8,2), note TEXT",
  'order_activity' => "id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NULL, actor_employee_id INT NULL,
    action VARCHAR(64), entity_type VARCHAR(64), entity_id INT NULL, payload TEXT, note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
];
foreach ($definitions as $table => $columns) {
  $conn->query("CREATE TEMPORARY TABLE {$table} ({$columns})");
  $schema = $conn->query("SHOW CREATE TABLE {$table}")->fetch_row()[1];
  if (strpos($schema, 'CREATE TEMPORARY TABLE') !== 0) throw new RuntimeException('Fixtures must be temporary.');
}
$conn->query("INSERT INTO employees VALUES (7,'Test','User','','Active'), (8,'Other','User','','Active'), (9,'Third','User','','Active')");
$conn->query("INSERT INTO projects (id,title,description,status,priority,assigned_to,created_by,notes,color) VALUES
  (1,'Existing project','','waiting','medium',8,8,'','#123456'),
  (2,'Unrelated project','','waiting','medium',9,9,'','#123456')");
$conn->query("INSERT INTO project_tasks (id,project_id,title,assigned_to,status,priority,notes) VALUES
  (11,1,'First task',8,'waiting','medium','First note'),
  (12,1,'Second task',9,'waiting','medium','Second note'),
  (21,2,'Other project task',9,'waiting','medium','Other note')");
$conn->query("INSERT INTO project_time_log VALUES (31,1,11,8,'2026-10-01',1,'Original time')");
$_SESSION = ['user_id' => 7, 'permission' => $case === 'create_admin' ? 900 : 0];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['PHP_SELF'] = '/index.php';
$_GET = ['page' => 'projects'];
$_POST = ['project_id' => 1];
$expectedStatus = 'waiting';

if (in_array($case, ['create', 'create_admin', 'guest_post', 'edit_project'], true)) {
  $_POST += ['action' => $case === 'edit_project' ? 'edit_project' : 'create_project',
    'title' => 'Changed project', 'description' => 'New description', 'status' => 'stuck',
    'priority' => 'high', 'start_date' => '', 'due_date' => '', 'assigned_to' => 9, 'notes' => 'New notes'];
  if ($case === 'edit_project') $expectedStatus = 'stuck';
} elseif ($case === 'assign_project') {
  $_POST += ['action' => 'assign_project', 'assigned_to' => 7];
} elseif ($case === 'delete_project') {
  $_POST += ['action' => 'delete_project'];
  $expectedStatus = null;
} elseif (in_array($case, ['add_task', 'edit_task'], true)) {
  $_POST += ['action' => $case, 'task_id' => 11, 'task_title' => 'Changed task', 'assigned_to' => 9,
    'task_status' => 'waiting', 'task_priority' => 'high', 'task_due_date' => '', 'task_notes' => 'Changed notes'];
} elseif ($case === 'delete_task') {
  $_POST += ['action' => 'delete_task', 'task_id' => 11];
} elseif (strpos($case, 'task_') === 0) {
  $_POST += ['action' => 'update_task_status', 'task_id' => 11, 'task_status' => 'done'];
  $expectedStatus = 'in_progress';
  if ($case === 'task_complete') {
    $conn->query("UPDATE project_tasks SET status='done' WHERE id=12");
    $expectedStatus = 'done';
  } elseif ($case === 'task_reopen') {
    $conn->query("UPDATE projects SET status='done' WHERE id=1");
    $conn->query("UPDATE project_tasks SET status='done' WHERE project_id=1");
    $_POST['task_status'] = 'in_progress';
  } elseif ($case === 'task_noop') {
    $_POST['task_status'] = 'waiting';
    $expectedStatus = 'waiting';
  } elseif ($case === 'task_wrong_project') {
    $_POST['task_id'] = 21;
    $expectedStatus = 'waiting';
  } elseif (in_array($case, ['task_stuck', 'task_cancelled'], true)) {
    $expectedStatus = substr($case, 5);
    $conn->query("UPDATE projects SET status='{$expectedStatus}' WHERE id=1");
  }
} elseif (strpos($case, 'log_') === 0) {
  $_POST += ['action' => 'log_time', 'task_id' => 11, 'hours' => 2, 'logged_date' => '2026-10-30', 'log_note' => 'Worked'];
  $expectedStatus = 'in_progress';
  if ($case === 'log_invalid') {
    $_POST['hours'] = 0;
    $expectedStatus = 'waiting';
  } elseif (in_array($case, ['log_stuck', 'log_done', 'log_cancelled', 'log_active'], true)) {
    $expectedStatus = $case === 'log_active' ? 'in_progress' : substr($case, 4);
    $conn->query("UPDATE projects SET status='{$expectedStatus}' WHERE id=1");
  }
} elseif ($case === 'edit_time_log') {
  $_POST += ['action' => 'edit_time_log', 'time_log_id' => 31, 'task_id' => 12,
    'employee_id' => 8, 'hours' => 3, 'logged_date' => '2026-10-30', 'log_note' => 'Edited by teammate'];
} elseif ($case === 'delete_time_log') {
  $_POST += ['action' => 'delete_time_log', 'time_log_id' => 31];
} elseif (in_array($case, ['list_ui', 'detail_ui', 'guest_view'], true)) {
  $_SERVER['REQUEST_METHOD'] = 'GET';
  if ($case === 'detail_ui') $_GET['view'] = 1;
}
if (strpos($case, 'guest_') === 0) $_SESSION = [];

ob_start();
register_shutdown_function(static function () use ($conn, $case, $expectedStatus, $definitions): void {
  $html = ob_get_clean();
  try {
    $fatal = error_get_last();
    if ($fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) throw new RuntimeException($fatal['message']);
    $assert = static function (bool $ok, string $message): void {
      if (!$ok) throw new RuntimeException($message);
    };
    $project = $conn->query('SELECT * FROM projects WHERE id=1')->fetch_assoc();
    $assert(($project['status'] ?? null) === $expectedStatus, 'Unexpected project status');
    $task = $conn->query('SELECT * FROM project_tasks WHERE id=11')->fetch_assoc();
    $log = $conn->query('SELECT * FROM project_time_log WHERE id=31')->fetch_assoc();
    if (in_array($case, ['create', 'create_admin'], true)) {
      $created = $conn->query('SELECT * FROM projects WHERE id > 2')->fetch_assoc();
      $assert($created && $created['status'] === 'waiting' && (int) $created['created_by'] === 7, 'New projects must start Waiting for all roles');
    } elseif ($case === 'edit_project') {
      $assert($project['title'] === 'Changed project' && (int) $project['assigned_to'] === 9, 'Ordinary users can edit other projects');
    } elseif ($case === 'assign_project') {
      $assert((int) $project['assigned_to'] === 7, 'Ordinary users can change responsibility');
    } elseif ($case === 'add_task') {
      $assert((int) $conn->query("SELECT COUNT(*) FROM project_tasks WHERE title='Changed task'")->fetch_row()[0] === 1, 'Ordinary users can add tasks');
    } elseif ($case === 'edit_task') {
      $assert($task['title'] === 'Changed task' && (int) $task['assigned_to'] === 9, 'Ordinary users can edit other employees tasks');
    } elseif ($case === 'delete_task') {
      $assert($task === null, 'Ordinary users can delete other employees tasks');
    } elseif (strpos($case, 'task_') === 0) {
      $assert($task['status'] === $_POST['task_status'] || $case === 'task_wrong_project', 'Ordinary users can update other employees task statuses');
      if ($case === 'task_wrong_project') {
        $assert($conn->query('SELECT status FROM project_tasks WHERE id=21')->fetch_row()[0] === 'waiting', 'Task must belong to submitted project');
      }
    } elseif (strpos($case, 'log_') === 0) {
      $count = (int) $conn->query('SELECT COUNT(*) FROM project_time_log')->fetch_row()[0];
      $assert($count === ($case === 'log_invalid' ? 1 : 2), 'Time must be stored only for valid entries');
    } elseif ($case === 'edit_time_log') {
      $assert((float) $log['hours'] === 3.0 && (int) $log['employee_id'] === 8 && (int) $log['task_id'] === 12, 'Ordinary users can edit another employees log');
    } elseif ($case === 'delete_time_log') {
      $assert($log === null, 'Ordinary users can delete another employees log');
    } elseif ($case === 'list_ui') {
      $dom = new DOMDocument();
      @$dom->loadHTML($html);
      $xpath = new DOMXPath($dom);
      $assert($xpath->query('//*[@id="createProjectModal"]')->length === 1, 'Create project is visible to ordinary users');
      $assert($xpath->query('//*[@id="createProjectModal"]//select[@name="status"]')->length === 0, 'Create form cannot choose another initial status');
      $assert($xpath->query('//*[@id="createProjectModal"]//input[@readonly and @value="Waiting"]')->length === 1, 'Create form displays Waiting');
    } elseif ($case === 'detail_ui') {
      foreach (['detailEditProjectModal', 'editTaskModal', 'editTimeLogModal', 'detailDeleteProjectModal'] as $id) {
        $assert(strpos($html, 'id="' . $id . '"') !== false, 'Missing edit UI: ' . $id);
      }
      foreach (['update_task_status', 'assign_project', 'edit_time_log', 'delete_time_log'] as $action) {
        $assert(strpos($html, 'value="' . $action . '"') !== false, 'Missing action: ' . $action);
      }
      $assert(strpos($html, 'Only assigned user') === false, 'Other employees tasks are editable');
    } elseif (strpos($case, 'guest_') === 0) {
      $assert(http_response_code() === 403 && $html === '', 'Anonymous access stays blocked');
      $assert((int) $conn->query('SELECT COUNT(*) FROM projects')->fetch_row()[0] === 2, 'Anonymous request must not create a project');
    }
    if (in_array($case, ['task_start', 'task_complete', 'task_reopen', 'log_start'], true)) {
      $event = $conn->query("SELECT * FROM order_activity WHERE action='project_status_autoupdated'")->fetch_assoc();
      $assert($event && (int) $event['actor_employee_id'] === 7, 'Automatic transition is audited');
      $payload = json_decode($event['payload'], true);
      $assert($payload['new_status'] === $expectedStatus, 'Audit reflects the resulting status');
    }
    if (in_array($case, ['task_noop', 'task_wrong_project', 'task_stuck', 'task_cancelled', 'log_invalid', 'log_stuck', 'log_done', 'log_cancelled', 'log_active'], true)) {
      $assert((int) $conn->query("SELECT COUNT(*) FROM order_activity WHERE action='project_status_autoupdated'")->fetch_row()[0] === 0, 'No spurious automatic status transition');
    }
    echo $case, ": OK\n";
  } catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
  } finally {
    foreach (array_reverse(array_keys($definitions)) as $table) $conn->query("DROP TEMPORARY TABLE IF EXISTS {$table}");
  }
});
require dirname(__DIR__) . '/includes/projects.php';
