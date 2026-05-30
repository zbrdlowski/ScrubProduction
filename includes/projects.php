<?php
// includes/projects.php
// Requires: $conn (mysqli), $_SESSION['permission'], $_SESSION['user_id']

$isAdmin = isset($_SESSION['permission']) && intval($_SESSION['permission']) >= 400;
$empId = intval($_SESSION['user_id'] ?? 0);

// ---------- helpers ----------
function projectStatusBadge(string $s): string
{
  $map = [
    'waiting' => ['secondary', 'Waiting'],
    'in_progress' => ['primary', 'In Progress'],
    'stuck' => ['danger', 'Stuck'],
    'done' => ['success', 'Done'],
    'cancelled' => ['dark', 'Cancelled'],
  ];
  [$cls, $label] = $map[$s] ?? ['light', ucfirst($s)];
  return "<span class=\"badge badge-{$cls}\">{$label}</span>";
}
function priorityBadge(string $p): string
{
  $map = [
    'low' => ['secondary', 'Low'],
    'medium' => ['info', 'Medium'],
    'high' => ['warning', 'High'],
    'urgent' => ['danger', 'Urgent'],
  ];
  [$cls, $label] = $map[$p] ?? ['light', ucfirst($p)];
  return "<span class=\"badge badge-{$cls}\">{$label}</span>";
}
function projectAvatarSrc(?string $photo): string
{
  $photo = trim((string) $photo);
  if ($photo === '') {
    return 'images/profile.jpg';
  }
  if (preg_match('~^(https?:)?//|^/|^images/~i', $photo)) {
    return $photo;
  }
  return 'images/' . ltrim($photo, '/');
}
function projectAvatarImg(?string $photo, string $name = '', string $class = 'project-avatar'): string
{
  $src = htmlspecialchars(projectAvatarSrc($photo), ENT_QUOTES, 'UTF-8');
  $alt = htmlspecialchars($name !== '' ? $name : 'User', ENT_QUOTES, 'UTF-8');
  $cls = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
  return "<img src=\"{$src}\" alt=\"{$alt}\" title=\"{$alt}\" class=\"{$cls}\" onerror=\"this.src='images/profile.jpg';\">";
}
function projectRedirect(string $url): void
{
  if (!headers_sent()) {
    header('Location: ' . $url, true, 303);
    exit;
  }

  $jsonUrl = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $htmlUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

  echo "<script>window.location.replace({$jsonUrl});</script>";
  echo "<noscript><meta http-equiv=\"refresh\" content=\"0;url={$htmlUrl}\"></noscript>";
  exit;
}
// ---------- POST handlers ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $act = $_POST['action'] ?? '';

  // ── Create project ──────────────────────────────────────
  if ($act === 'create_project' && $isAdmin) {
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'waiting';
    $priority = $_POST['priority'] ?? 'medium';
    $start = $_POST['start_date'] ?: null;
    $due = $_POST['due_date'] ?: null;
    $assignedTo = intval($_POST['assigned_to'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $color = $_POST['color'] ?? '#3a7bd5';

    if ($title !== '' && $assignedTo > 0) {
      $stmt = $conn->prepare("INSERT INTO projects
                (title, description, status, priority, start_date, due_date, assigned_to, created_by, notes, color)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
      $stmt->bind_param(
        'ssssssiiss',
        $title,
        $desc,
        $status,
        $priority,
        $start,
        $due,
        $assignedTo,
        $empId,
        $notes,
        $color
      );
      $stmt->execute();
      $newId = (int) $conn->insert_id;
      $stmt->close();

      // log to order_activity
      require_once dirname(__DIR__) . '/scripts/orders/activity_helper.php';
      log_order_activity(
        $conn,
        null,
        $empId,
        'project_created',
        'project',
        $newId,
        ['title' => $title, 'status' => $status, 'priority' => $priority, 'assigned_to' => $assignedTo]
      );
    }
    projectRedirect($_SERVER['PHP_SELF'] . '?page=projects');
  }

  // ── Edit project ─────────────────────────────────────────
  if ($act === 'edit_project' && $isAdmin) {
    $pid = intval($_POST['project_id']);
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'waiting';
    $priority = $_POST['priority'] ?? 'medium';
    $start = $_POST['start_date'] ?: null;
    $due = $_POST['due_date'] ?: null;
    $assignedTo = intval($_POST['assigned_to'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $color = $_POST['color'] ?? '#3a7bd5';

    if ($title !== '' && $pid > 0 && $assignedTo > 0) {
      $oldAssignedTo = null;
      $oldStmt = $conn->prepare("SELECT assigned_to FROM projects WHERE id=?");
      if ($oldStmt) {
        $oldStmt->bind_param('i', $pid);
        $oldStmt->execute();
        $oldProject = $oldStmt->get_result()->fetch_assoc();
        $oldAssignedTo = isset($oldProject['assigned_to']) ? intval($oldProject['assigned_to']) : null;
        $oldStmt->close();
      }

      $stmt = $conn->prepare("UPDATE projects SET
                title=?, description=?, status=?, priority=?, start_date=?, due_date=?, assigned_to=?, notes=?, color=?
                WHERE id=?");
      $stmt->bind_param(
        'ssssssissi',
        $title,
        $desc,
        $status,
        $priority,
        $start,
        $due,
        $assignedTo,
        $notes,
        $color,
        $pid
      );
      $stmt->execute();
      $stmt->close();

      require_once dirname(__DIR__) . '/scripts/orders/activity_helper.php';
      log_order_activity(
        $conn,
        null,
        $empId,
        'project_updated',
        'project',
        $pid,
        ['title' => $title, 'status' => $status, 'priority' => $priority, 'assigned_to' => $assignedTo]
      );

      if ($oldAssignedTo !== $assignedTo) {
        log_order_activity(
          $conn,
          null,
          $empId,
          'project_assignment_changed',
          'project',
          $pid,
          ['old_assigned_to' => $oldAssignedTo, 'new_assigned_to' => $assignedTo]
        );
      }
    }
    projectRedirect($_SERVER['PHP_SELF'] . '?page=projects');
  }

  // Change project owner / responsible employee
  if ($act === 'assign_project' && $isAdmin) {
    $pid = intval($_POST['project_id']);
    $assignedTo = intval($_POST['assigned_to'] ?? 0);

    if ($pid > 0 && $assignedTo > 0) {
      $oldAssignedTo = null;
      $oldStmt = $conn->prepare("SELECT assigned_to FROM projects WHERE id=?");
      if ($oldStmt) {
        $oldStmt->bind_param('i', $pid);
        $oldStmt->execute();
        $oldProject = $oldStmt->get_result()->fetch_assoc();
        $oldAssignedTo = isset($oldProject['assigned_to']) ? intval($oldProject['assigned_to']) : null;
        $oldStmt->close();
      }

      $stmt = $conn->prepare("UPDATE projects SET assigned_to=? WHERE id=?");
      $stmt->bind_param('ii', $assignedTo, $pid);
      $stmt->execute();
      $stmt->close();

      if ($oldAssignedTo !== $assignedTo) {
        require_once dirname(__DIR__) . '/scripts/orders/activity_helper.php';
        log_order_activity(
          $conn,
          null,
          $empId,
          'project_assignment_changed',
          'project',
          $pid,
          ['old_assigned_to' => $oldAssignedTo, 'new_assigned_to' => $assignedTo]
        );
      }
    }

    projectRedirect($_SERVER['PHP_SELF'] . '?page=projects&view=' . $pid);
  }

  // ── Delete project ────────────────────────────────────────
  if ($act === 'delete_project' && $isAdmin) {
    $pid = intval($_POST['project_id']);
    if ($pid > 0) {
      $stmt = $conn->prepare("DELETE FROM projects WHERE id=?");
      $stmt->bind_param('i', $pid);
      $stmt->execute();
      $stmt->close();

      require_once dirname(__DIR__) . '/scripts/orders/activity_helper.php';
      log_order_activity($conn, null, $empId, 'project_deleted', 'project', $pid);
    }
    projectRedirect($_SERVER['PHP_SELF'] . '?page=projects');
  }

  // ── Add task ──────────────────────────────────────────────
  if ($act === 'add_task') {
    $pid = intval($_POST['project_id']);
    $taskTitle = trim($_POST['task_title'] ?? '');
    $assignedTo = intval($_POST['assigned_to'] ?? 0) ?: null;
    $taskStatus = $_POST['task_status'] ?? 'waiting';
    $taskPrio = $_POST['task_priority'] ?? 'medium';
    $taskDue = $_POST['task_due_date'] ?: null;
    $taskNotes = trim($_POST['task_notes'] ?? '');

    if ($taskTitle !== '' && $pid > 0) {
      $stmt = $conn->prepare("INSERT INTO project_tasks
                (project_id, title, assigned_to, status, priority, due_date, notes)
                VALUES (?,?,?,?,?,?,?)");
      $stmt->bind_param('iisssss', $pid, $taskTitle, $assignedTo, $taskStatus, $taskPrio, $taskDue, $taskNotes);
      $stmt->execute();
      $tid = (int) $conn->insert_id;
      $stmt->close();

      require_once dirname(__DIR__) . '/scripts/orders/activity_helper.php';
      log_order_activity(
        $conn,
        null,
        $empId,
        'task_created',
        'project_task',
        $tid,
        ['project_id' => $pid, 'title' => $taskTitle, 'assigned_to' => $assignedTo]
      );
    }
    projectRedirect($_SERVER['PHP_SELF'] . '?page=projects&view=' . $pid);
  }

  // ── Update task status (quick toggle) ─────────────────────
  if ($act === 'update_task_status') {
    $tid = intval($_POST['task_id']);
    $status = $_POST['task_status'] ?? 'waiting';
    $pid = intval($_POST['project_id']);
    if ($tid > 0) {
      $stmt = $conn->prepare("UPDATE project_tasks SET status=? WHERE id=?");
      $stmt->bind_param('si', $status, $tid);
      $stmt->execute();
      $stmt->close();

      require_once dirname(__DIR__) . '/scripts/orders/activity_helper.php';
      log_order_activity(
        $conn,
        null,
        $empId,
        'task_status_changed',
        'project_task',
        $tid,
        ['project_id' => $pid, 'new_status' => $status]
      );
    }
    projectRedirect($_SERVER['PHP_SELF'] . '?page=projects&view=' . $pid);
  }

  // ── Delete task ───────────────────────────────────────────
  if ($act === 'delete_task' && $isAdmin) {
    $tid = intval($_POST['task_id']);
    $pid = intval($_POST['project_id']);
    if ($tid > 0) {
      $stmt = $conn->prepare("DELETE FROM project_tasks WHERE id=?");
      $stmt->bind_param('i', $tid);
      $stmt->execute();
      $stmt->close();

      require_once dirname(__DIR__) . '/scripts/orders/activity_helper.php';
      log_order_activity(
        $conn,
        null,
        $empId,
        'task_deleted',
        'project_task',
        $tid,
        ['project_id' => $pid]
      );
    }
    projectRedirect($_SERVER['PHP_SELF'] . '?page=projects&view=' . $pid);
  }

  // ── Log time ──────────────────────────────────────────────
  if ($act === 'log_time') {
    $pid = intval($_POST['project_id']);
    $tid = intval($_POST['task_id'] ?? 0) ?: null;
    $hours = floatval($_POST['hours'] ?? 0);
    $date = $_POST['logged_date'] ?: date('Y-m-d');
    $note = trim($_POST['log_note'] ?? '');

    if ($pid > 0 && $hours > 0) {
      $stmt = $conn->prepare("INSERT INTO project_time_log
                (project_id, task_id, employee_id, logged_date, hours, note)
                VALUES (?,?,?,?,?,?)");
      $stmt->bind_param('iiisds', $pid, $tid, $empId, $date, $hours, $note);
      $stmt->execute();
      $logId = (int) $conn->insert_id;
      $stmt->close();

      require_once dirname(__DIR__) . '/scripts/orders/activity_helper.php';
      log_order_activity(
        $conn,
        null,
        $empId,
        'time_logged',
        'project',
        $pid,
        ['hours' => $hours, 'date' => $date, 'task_id' => $tid],
        $note
      );
    }
    projectRedirect($_SERVER['PHP_SELF'] . '?page=projects&view=' . $pid);
  }
}
?>
<style>
  .project-avatar,
  .project-avatar-sm,
  .project-avatar-lg {
    border-radius: 50%;
    object-fit: cover;
    flex: 0 0 auto;
    background: #343a40;
    border: 1px solid rgba(255, 255, 255, .2);
  }

  .project-avatar {
    width: 32px;
    height: 32px;
  }

  .project-avatar-sm {
    width: 24px;
    height: 24px;
  }

  .project-avatar-lg {
    width: 44px;
    height: 44px;
  }

  .project-user-line {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
  }

  .project-user-line .project-user-text {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .project-activity-list {
    padding: 10px;
  }

  .project-activity-item {
    display: grid;
    grid-template-columns: 76px 36px minmax(0, 1fr);
    gap: 8px;
    align-items: start;
    padding: 6px 0;
  }

  .project-activity-time {
    font-size: 11px;
    font-weight: 700;
    line-height: 1.2;
    color: #fff;
    background: #6c757d;
    border-radius: 4px;
    padding: 5px 6px;
    text-align: center;
    white-space: nowrap;
  }

  .project-activity-body {
    min-width: 0;
    border: 1px solid rgba(255, 255, 255, .24);
    border-radius: 4px;
    padding: 8px 10px;
    line-height: 1.25;
    overflow-wrap: anywhere;
  }

  .project-activity-note {
    font-style: italic;
    color: #f8f9fa;
  }

  @media (max-width: 575.98px) {
    .project-activity-item {
      grid-template-columns: 64px 32px minmax(0, 1fr);
      gap: 6px;
    }
  }
</style>
<?php

// ---------- fetch employees for dropdowns ----------
$employees = [];
$res = $conn->query("SELECT id, CONCAT(firstname,' ',lastname) AS name, photo FROM employees WHERE active='Active' ORDER BY firstname, lastname");
if ($res) {
  while ($r = $res->fetch_assoc())
    $employees[] = $r;
}

// ================================================================
// DETAIL VIEW  ?page=projects&view=N
// ================================================================
$viewId = intval($_GET['view'] ?? 0);
if ($viewId > 0) {
  $stmt = $conn->prepare("SELECT p.*, CONCAT(e.firstname,' ',e.lastname) AS assigned_name, e.photo AS assigned_photo
        FROM projects p
        LEFT JOIN employees e ON e.id = p.assigned_to
        WHERE p.id=?");
  $stmt->bind_param('i', $viewId);
  $stmt->execute();
  $project = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$project) {
    echo '<div class="alert alert-danger">Project not found.</div>';
    return;
  }

  // tasks
  $tasks = [];
  $res = $conn->query("SELECT pt.*, CONCAT(e.firstname,' ',e.lastname) as employee_name, e.photo AS employee_photo
        FROM project_tasks pt
        LEFT JOIN employees e ON e.id = pt.assigned_to
        WHERE pt.project_id = {$viewId}
        ORDER BY pt.sort_order, pt.id");
  if ($res)
    while ($r = $res->fetch_assoc())
      $tasks[] = $r;

  $totalTasks = count($tasks);
  $doneTasks = count(array_filter($tasks, fn($t) => $t['status'] === 'done'));
  $progress = $totalTasks > 0 ? round(($doneTasks / $totalTasks) * 100) : 0;

  // time logs
  $timeLogs = [];
  $totalHours = 0;
  $res = $conn->query("SELECT ptl.*, CONCAT(e.firstname,' ',e.lastname) as employee_name, e.photo AS employee_photo
        FROM project_time_log ptl
        LEFT JOIN employees e ON e.id = ptl.employee_id
        WHERE ptl.project_id = {$viewId}
        ORDER BY ptl.logged_date DESC LIMIT 50");
  if ($res)
    while ($r = $res->fetch_assoc()) {
      $timeLogs[] = $r;
      $totalHours += floatval($r['hours']);
    }

  // activity log
  $actLogs = [];
  $res = $conn->query("SELECT oa.*, CONCAT(e.firstname,' ',e.lastname) as actor_name, e.photo AS actor_photo
        FROM order_activity oa
        LEFT JOIN employees e ON e.id = oa.actor_employee_id
        WHERE (oa.entity_type = 'project' AND oa.entity_id = {$viewId})
           OR (
                oa.entity_type = 'project_task'
                AND (
                    oa.entity_id IN (SELECT id FROM project_tasks WHERE project_id = {$viewId})
                    OR JSON_UNQUOTE(JSON_EXTRACT(oa.payload, '$.project_id')) = '{$viewId}'
                )
           )
        ORDER BY oa.created_at DESC LIMIT 30");
  if ($res)
    while ($r = $res->fetch_assoc())
      $actLogs[] = $r;

  switch ($project['status']) {
    case 'done':
      $barColor = 'bg-success';
      break;

    case 'in_progress':
      $barColor = 'bg-primary';
      break;

    case 'stuck':
      $barColor = 'bg-danger';
      break;

    case 'cancelled':
      $barColor = 'bg-dark';
      break;

    default:
      $barColor = 'bg-secondary';
      break;
  }
  ?>
  <!-- ===== DETAIL PAGE ===== -->
  <div class="container-fluid">

    <!-- Breadcrumb back -->
    <div class="mb-3">
      <a href="?page=projects" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left"></i> All Projects
      </a>
    </div>

    <!-- Header row -->
    <div class="row mb-3">
      <div class="col-md-8">
        <h3 style="color:#e4e6eb;">
          <span
            style="display:inline-block;width:14px;height:14px;border-radius:50%;background:<?= htmlspecialchars($project['color']) ?>;margin-right:8px;vertical-align:middle;"></span>
          <?= htmlspecialchars($project['title']) ?>
          &nbsp;<?= projectStatusBadge($project['status']) ?>
          &nbsp;<?= priorityBadge($project['priority']) ?>
        </h3>
        <div class="project-user-line text-muted mb-2" style="font-size:13px;">
          <?= projectAvatarImg($project['assigned_photo'] ?? '', $project['assigned_name'] ?? 'Unassigned', 'project-avatar-lg') ?>
          <span class="project-user-text">
            Assigned to:
            <strong style="color:#e4e6eb;"><?= htmlspecialchars($project['assigned_name'] ?? 'Unassigned') ?></strong>
          </span>
        </div>
        <?php if ($project['description']): ?>
          <p class="text-muted"><?= nl2br(htmlspecialchars($project['description'])) ?></p>
        <?php endif; ?>
      </div>
      <div class="col-md-4 text-right">
        <?php if ($isAdmin): ?>
          <button class="btn btn-sm btn-warning" onclick="openEditProject(<?= $viewId ?>)">
            <i class="fas fa-pencil-alt"></i> Edit
          </button>
          <button class="btn btn-sm btn-danger"
            onclick="confirmDelete(<?= $viewId ?>, '<?= addslashes($project['title']) ?>')">
            <i class="fas fa-trash"></i> Delete
          </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Stats cards -->
    <div class="row mb-3">
      <div class="col-sm-3">
        <div class="info-box bg-primary">
          <span class="info-box-icon"><i class="fas fa-tasks"></i></span>
          <div class="info-box-content">
            <span class="info-box-text">Total Tasks</span>
            <span class="info-box-number"><?= $totalTasks ?></span>
          </div>
        </div>
      </div>
      <div class="col-sm-3">
        <div class="info-box bg-success">
          <span class="info-box-icon"><i class="fas fa-check"></i></span>
          <div class="info-box-content">
            <span class="info-box-text">Done</span>
            <span class="info-box-number"><?= $doneTasks ?></span>
          </div>
        </div>
      </div>
      <div class="col-sm-3">
        <div class="info-box bg-info">
          <span class="info-box-icon"><i class="fas fa-clock"></i></span>
          <div class="info-box-content">
            <span class="info-box-text">Total Hours</span>
            <span class="info-box-number"><?= number_format($totalHours, 1) ?></span>
          </div>
        </div>
      </div>
      <div class="col-sm-3">
        <div class="info-box bg-warning">
          <span class="info-box-icon"><i class="fas fa-percent"></i></span>
          <div class="info-box-content">
            <span class="info-box-text">Progress</span>
            <span class="info-box-number"><?= $progress ?>%</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Progress bar -->
    <div class="row mb-4">
      <div class="col-12">
        <div class="card card-outline card-primary">
          <div class="card-body py-2">
            <label class="mb-1" style="font-size:13px;">Overall completion (tasks done)</label>
            <div class="progress" style="height:22px;border-radius:6px;">
              <div class="progress-bar <?= $barColor ?> progress-bar-striped progress-bar-animated" role="progressbar"
                style="width:<?= $progress ?>%;font-size:13px;font-weight:bold;" data-target="<?= $progress ?>">
                <?= $progress ?>%
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row">
      <!-- Tasks column -->
      <div class="col-md-8">
        <div class="card card-primary card-outline">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0"><i class="fas fa-list-check mr-2"></i>Tasks</h5>
            <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#addTaskModal">
              <i class="fas fa-plus"></i> Add Task
            </button>
          </div>
          <div class="card-body p-0">
            <?php if (empty($tasks)): ?>
              <p class="text-muted text-center py-4">No tasks yet. Add the first one!</p>
            <?php else: ?>
              <table class="table table-sm table-hover mb-0">
                <thead>
                  <tr>
                    <th style="width:36px;"></th>
                    <th>Task</th>
                    <th>Assigned</th>
                    <th>Priority</th>
                    <th>Due</th>
                    <th>Status</th>
                    <?php if ($isAdmin): ?>
                      <th></th><?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($tasks as $t): ?>
                    <tr class="<?= $t['status'] === 'done' ? 'table-success' : '' ?>">
                      <td>
                        <!-- Quick toggle done/undone -->
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="action" value="update_task_status">
                          <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                          <input type="hidden" name="project_id" value="<?= $viewId ?>">
                          <input type="hidden" name="task_status"
                            value="<?= $t['status'] === 'done' ? 'in_progress' : 'done' ?>">
                          <button type="submit"
                            class="btn btn-xs <?= $t['status'] === 'done' ? 'btn-success' : 'btn-outline-secondary' ?>"
                            title="<?= $t['status'] === 'done' ? 'Mark undone' : 'Mark done' ?>">
                            <i class="fas fa-check"></i>
                          </button>
                        </form>
                      </td>
                      <td>
                        <span class="<?= $t['status'] === 'done' ? 'text-muted' : '' ?>"
                          style="<?= $t['status'] === 'done' ? 'text-decoration:line-through;' : '' ?>">
                          <?= htmlspecialchars($t['title']) ?>
                        </span>
                        <?php if ($t['notes']): ?>
                          <br><small class="text-muted"><?= htmlspecialchars(mb_strimwidth($t['notes'], 0, 60, '…')) ?></small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($t['employee_name']): ?>
                          <div class="project-user-line">
                            <?= projectAvatarImg($t['employee_photo'] ?? '', $t['employee_name'], 'project-avatar-sm') ?>
                            <span class="project-user-text"><?= htmlspecialchars($t['employee_name']) ?></span>
                          </div>
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </td>
                      <td><?= priorityBadge($t['priority']) ?></td>
                      <td><?= $t['due_date'] ? date('d.m.Y', strtotime($t['due_date'])) : '—' ?></td>
                      <td><?= projectStatusBadge($t['status']) ?></td>
                      <?php if ($isAdmin): ?>
                        <td>
                          <form method="POST" onsubmit="return confirm('Delete task?');" style="display:inline;">
                            <input type="hidden" name="action" value="delete_task">
                            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                            <input type="hidden" name="project_id" value="<?= $viewId ?>">
                            <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                          </form>
                        </td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- Time log -->
        <div class="card card-info card-outline">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0"><i class="fas fa-stopwatch mr-2"></i>Time Log</h5>
            <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#logTimeModal">
              <i class="fas fa-plus"></i> Log Time
            </button>
          </div>
          <div class="card-body p-0">
            <?php if (empty($timeLogs)): ?>
              <p class="text-muted text-center py-3">No time logged yet.</p>
            <?php else: ?>
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>Employee</th>
                    <th>Date</th>
                    <th>Hours</th>
                    <th>Note</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($timeLogs as $tl): ?>
                    <tr>
                      <td>
                        <div class="project-user-line">
                          <?= projectAvatarImg($tl['employee_photo'] ?? '', $tl['employee_name'] ?? 'User', 'project-avatar-sm') ?>
                          <span class="project-user-text"><?= htmlspecialchars($tl['employee_name'] ?? '—') ?></span>
                        </div>
                      </td>
                      <td><?= date('d.m.Y', strtotime($tl['logged_date'])) ?></td>
                      <td><strong><?= number_format($tl['hours'], 1) ?>h</strong></td>
                      <td><?= htmlspecialchars($tl['note'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="table-secondary">
                    <td colspan="2"><strong>Total</strong></td>
                    <td><strong><?= number_format($totalHours, 1) ?>h</strong></td>
                    <td></td>
                  </tr>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Info sidebar -->
      <div class="col-md-4">
        <div class="card card-outline card-secondary">
          <div class="card-header">
            <h5 class="card-title mb-0">Project Info</h5>
          </div>
          <div class="card-body">
            <?php if ($isAdmin): ?>
              <form method="POST" class="mb-3">
                <input type="hidden" name="action" value="assign_project">
                <input type="hidden" name="project_id" value="<?= $viewId ?>">
                <label class="mb-1" style="font-size:12px;">Responsible Employee</label>
                <div class="input-group input-group-sm">
                  <select name="assigned_to" class="form-control" required>
                    <?php foreach ($employees as $e): ?>
                      <option value="<?= $e['id'] ?>" <?= intval($project['assigned_to'] ?? 0) === intval($e['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="input-group-append">
                    <button type="submit" class="btn btn-outline-primary">Change</button>
                  </div>
                </div>
              </form>
              <hr>
            <?php endif; ?>
            <table class="table table-sm table-borderless mb-0">
              <tr>
                <td class="text-muted" style="width:100px;">Assigned</td>
                <td>
                  <div class="project-user-line">
                    <?= projectAvatarImg($project['assigned_photo'] ?? '', $project['assigned_name'] ?? 'Unassigned', 'project-avatar') ?>
                    <span
                      class="project-user-text"><?= htmlspecialchars($project['assigned_name'] ?? 'Unassigned') ?></span>
                  </div>
                </td>
              </tr>
              <tr>
                <td class="text-muted" style="width:100px;">Start</td>
                <td><?= $project['start_date'] ? date('d.m.Y', strtotime($project['start_date'])) : '—' ?></td>
              </tr>
              <tr>
                <td class="text-muted">Due</td>
                <td><?= $project['due_date'] ? date('d.m.Y', strtotime($project['due_date'])) : '—' ?></td>
              </tr>
              <tr>
                <td class="text-muted">Status</td>
                <td><?= projectStatusBadge($project['status']) ?></td>
              </tr>
              <tr>
                <td class="text-muted">Priority</td>
                <td><?= priorityBadge($project['priority']) ?></td>
              </tr>
              <tr>
                <td class="text-muted">Created</td>
                <td><?= date('d.m.Y', strtotime($project['created_at'])) ?></td>
              </tr>
            </table>
            <?php if ($project['notes']): ?>
              <hr>
              <p class="text-muted mb-0" style="font-size:13px;white-space:pre-wrap;">
                <?= htmlspecialchars($project['notes']) ?></p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Activity log -->
        <?php if (!empty($actLogs)): ?>
          <div class="card card-outline card-secondary">
            <div class="card-header">
              <h5 class="card-title mb-0"><i class="fas fa-history mr-1"></i>Activity</h5>
            </div>
            <div class="card-body p-0">
              <div class="project-activity-list">
                <?php foreach (array_slice($actLogs, 0, 10) as $al): ?>
                  <div class="project-activity-item">
                    <div class="project-activity-time"><?= date('d.m H:i', strtotime($al['created_at'])) ?></div>
                    <?= projectAvatarImg($al['actor_photo'] ?? '', $al['actor_name'] ?? 'System', 'project-avatar') ?>
                    <div class="project-activity-body" style="font-size:12px;">
                      <strong><?= htmlspecialchars($al['actor_name'] ?? 'System') ?></strong>:
                      <?= htmlspecialchars(str_replace('_', ' ', $al['action'])) ?>
                      <?php if ($al['note']): ?>
                        <span class="project-activity-note"> - <?= htmlspecialchars($al['note']) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Add Task Modal -->
  <div class="modal fade" id="addTaskModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="POST">
          <input type="hidden" name="action" value="add_task">
          <input type="hidden" name="project_id" value="<?= $viewId ?>">
          <div class="modal-header bg-primary">
            <h5 class="modal-title text-white">Add Task</h5>
            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label>Task Title *</label>
              <input type="text" name="task_title" class="form-control" required>
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label>Assigned To</label>
                <select name="assigned_to" class="form-control form-control-sm">
                  <option value="">— nobody —</option>
                  <?php foreach ($employees as $e): ?>
                    <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-6">
                <label>Due Date</label>
                <input type="date" name="task_due_date" class="form-control form-control-sm">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label>Priority</label>
                <select name="task_priority" class="form-control form-control-sm">
                  <option value="low">Low</option>
                  <option value="medium" selected>Medium</option>
                  <option value="high">High</option>
                  <option value="urgent">Urgent</option>
                </select>
              </div>
              <div class="form-group col-md-6">
                <label>Status</label>
                <select name="task_status" class="form-control form-control-sm">
                  <option value="waiting">Waiting</option>
                  <option value="in_progress">In Progress</option>
                  <option value="stuck">Stuck</option>
                  <option value="done">Done</option>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label>Notes</label>
              <textarea name="task_notes" class="form-control form-control-sm" rows="2"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Add Task</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Log Time Modal -->
  <div class="modal fade" id="logTimeModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
      <div class="modal-content">
        <form method="POST">
          <input type="hidden" name="action" value="log_time">
          <input type="hidden" name="project_id" value="<?= $viewId ?>">
          <div class="modal-header bg-info">
            <h5 class="modal-title text-white">Log Time</h5>
            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label>Date</label>
              <input type="date" name="logged_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
              <label>Hours</label>
              <input type="number" name="hours" class="form-control" step="0.25" min="0.25" max="24"
                placeholder="e.g. 2.5" required>
            </div>
            <div class="form-group">
              <label>Task (optional)</label>
              <select name="task_id" class="form-control form-control-sm">
                <option value="">— project level —</option>
                <?php foreach ($tasks as $t): ?>
                  <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Note</label>
              <input type="text" name="log_note" class="form-control form-control-sm" placeholder="What did you work on?">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-info btn-sm">Log Time</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php
  return; // stop, don't render list view
} // end detail view
?>

<!-- ================================================================
     LIST VIEW  ?page=projects
     ================================================================ -->
<?php
// Fetch all projects with task stats
$projects = [];
$sql = "
    SELECT p.*,
           CONCAT(e.firstname,' ',e.lastname) AS assigned_name,
           e.photo AS assigned_photo,
           COALESCE(ts.total_tasks, 0)        AS total_tasks,
           COALESCE(ts.done_tasks, 0)         AS done_tasks,
           COALESCE(tl.total_hours, 0)        AS total_hours
    FROM projects p
    LEFT JOIN employees e ON e.id = p.assigned_to
    LEFT JOIN (
        SELECT project_id,
               COUNT(*) AS total_tasks,
               SUM(status = 'done') AS done_tasks
        FROM project_tasks
        GROUP BY project_id
    ) ts ON ts.project_id = p.id
    LEFT JOIN (
        SELECT project_id,
               SUM(hours) AS total_hours
        FROM project_time_log
        GROUP BY project_id
    ) tl ON tl.project_id = p.id
    ORDER BY
        FIELD(p.status,'in_progress','stuck','waiting','done','cancelled'),
        FIELD(p.priority,'urgent','high','medium','low'),
        p.id DESC
";
$res = $conn->query($sql);
if ($res)
  while ($r = $res->fetch_assoc())
    $projects[] = $r;

$employeeStats = [];
foreach ($projects as $p) {
  $assigneeId = intval($p['assigned_to'] ?? 0);
  $assigneeName = $p['assigned_name'] ?: 'Unassigned';

  if (!isset($employeeStats[$assigneeId])) {
    $employeeStats[$assigneeId] = [
      'id' => $assigneeId,
      'name' => $assigneeName,
      'photo' => $p['assigned_photo'] ?? '',
      'total_projects' => 0,
      'active_projects' => 0,
      'done_projects' => 0,
      'stuck_projects' => 0,
      'urgent_projects' => 0,
      'total_tasks' => 0,
      'done_tasks' => 0,
      'total_hours' => 0.0,
    ];
  }

  $employeeStats[$assigneeId]['total_projects']++;
  if (!in_array($p['status'], ['done', 'cancelled'], true)) {
    $employeeStats[$assigneeId]['active_projects']++;
  }
  if ($p['status'] === 'done') {
    $employeeStats[$assigneeId]['done_projects']++;
  }
  if ($p['status'] === 'stuck') {
    $employeeStats[$assigneeId]['stuck_projects']++;
  }
  if ($p['priority'] === 'urgent') {
    $employeeStats[$assigneeId]['urgent_projects']++;
  }
  $employeeStats[$assigneeId]['total_tasks'] += intval($p['total_tasks']);
  $employeeStats[$assigneeId]['done_tasks'] += intval($p['done_tasks']);
  $employeeStats[$assigneeId]['total_hours'] += floatval($p['total_hours']);
}
usort($employeeStats, fn($a, $b) => $b['active_projects'] <=> $a['active_projects'] ?: strcasecmp($a['name'], $b['name']));
?>

<div class="container-fluid">
  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0" style="color:#e4e6eb;"><i class="fas fa-project-diagram mr-2"></i>Projects</h4>
    <?php if ($isAdmin): ?>
      <button class="btn btn-primary" data-toggle="modal" data-target="#createProjectModal">
        <i class="fas fa-plus"></i> New Project
      </button>
    <?php endif; ?>
  </div>

  <?php if (!empty($employeeStats)): ?>
    <div class="card card-outline card-secondary mb-3">
      <div class="card-header py-2">
        <h5 class="card-title mb-0"><i class="fas fa-users mr-1"></i>Projects by Employee</h5>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead>
              <tr>
                <th>Employee</th>
                <th class="text-center">Active</th>
                <th class="text-center">Total</th>
                <th class="text-center">Stuck</th>
                <th class="text-center">Urgent</th>
                <th class="text-center">Tasks</th>
                <th class="text-right">Hours</th>
                <th style="width:80px;"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($employeeStats as $stat): ?>
                <tr>
                  <td>
                    <div class="project-user-line">
                      <?= projectAvatarImg($stat['photo'] ?? '', $stat['name'], 'project-avatar-sm') ?>
                      <strong class="project-user-text"><?= htmlspecialchars($stat['name']) ?></strong>
                    </div>
                  </td>
                  <td class="text-center"><?= intval($stat['active_projects']) ?></td>
                  <td class="text-center"><?= intval($stat['total_projects']) ?></td>
                  <td class="text-center"><?= intval($stat['stuck_projects']) ?></td>
                  <td class="text-center"><?= intval($stat['urgent_projects']) ?></td>
                  <td class="text-center"><?= intval($stat['done_tasks']) ?>/<?= intval($stat['total_tasks']) ?></td>
                  <td class="text-right"><?= number_format(floatval($stat['total_hours']), 1) ?>h</td>
                  <td class="text-right">
                    <button type="button" class="btn btn-xs btn-outline-primary employee-filter-btn"
                      data-employee="<?= intval($stat['id']) ?>">
                      Show
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Filter bar -->
  <div class="row mb-3">
    <div class="col-md-6">
      <div class="btn-group btn-group-sm" id="statusFilter">
        <button class="btn btn-outline-secondary active" data-filter="all">All</button>
        <button class="btn btn-outline-primary" data-filter="in_progress">In Progress</button>
        <button class="btn btn-outline-danger" data-filter="stuck">Stuck</button>
        <button class="btn btn-outline-secondary" data-filter="waiting">Waiting</button>
        <button class="btn btn-outline-success" data-filter="done">Done</button>
      </div>
    </div>
    <div class="col-md-3">
      <select id="employeeFilter" class="form-control form-control-sm">
        <option value="all">All employees</option>
        <?php foreach ($employeeStats as $stat): ?>
          <option value="<?= intval($stat['id']) ?>"><?= htmlspecialchars($stat['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <input type="text" id="projectSearch" class="form-control form-control-sm" placeholder="Search projects…">
    </div>
  </div>

  <!-- Cards grid -->
  <div class="row" id="projectsGrid">
    <?php if (empty($projects)): ?>
      <div class="col-12">
        <div class="alert alert-info">No projects yet. Click "New Project" to create the first one.</div>
      </div>
    <?php endif; ?>
    <?php foreach ($projects as $p):
      $total = intval($p['total_tasks']);
      $done = intval($p['done_tasks']);
      $pct = $total > 0 ? round(($done / $total) * 100) : 0;
      switch ($p['status']) {
        case 'done':
          $barCls = 'bg-success';
          break;

        case 'in_progress':
          $barCls = 'bg-primary';
          break;

        case 'stuck':
          $barCls = 'bg-danger';
          break;

        case 'cancelled':
          $barCls = 'bg-dark';
          break;

        default:
          $barCls = 'bg-secondary';
          break;
      }
      $dueStr = $p['due_date'] ? date('d.m.Y', strtotime($p['due_date'])) : null;
      $isOverdue = $p['due_date'] && $p['status'] !== 'done' && strtotime($p['due_date']) < time();
      ?>
      <div class="col-md-4 col-sm-6 mb-4 project-card-wrap" data-status="<?= $p['status'] ?>"
        data-employee="<?= intval($p['assigned_to'] ?? 0) ?>"
        data-title="<?= strtolower(htmlspecialchars($p['title'])) ?>"
        data-assignee="<?= strtolower(htmlspecialchars($p['assigned_name'] ?? 'Unassigned')) ?>">
        <div class="card h-100 shadow-sm" style="border-top: 4px solid <?= htmlspecialchars($p['color']) ?>;">
          <div class="card-body">
            <!-- Title row -->
            <div class="d-flex justify-content-between align-items-start mb-1">
              <h6 class="card-title mb-0" style="color:#e4e6eb;font-size:15px;font-weight:600;">
                <?= htmlspecialchars($p['title']) ?>
              </h6>
              <?= priorityBadge($p['priority']) ?>
            </div>
            <div class="mb-2"><?= projectStatusBadge($p['status']) ?></div>
            <div class="project-user-line text-muted mb-2" style="font-size:12px;">
              <?= projectAvatarImg($p['assigned_photo'] ?? '', $p['assigned_name'] ?? 'Unassigned', 'project-avatar-sm') ?>
              <span class="project-user-text"><?= htmlspecialchars($p['assigned_name'] ?? 'Unassigned') ?></span>
            </div>

            <?php if ($p['description']): ?>
              <p class="text-muted mb-2" style="font-size:12px;">
                <?= htmlspecialchars(mb_strimwidth($p['description'], 0, 90, '…')) ?>
              </p>
            <?php endif; ?>

            <!-- Progress -->
            <div class="mb-1 d-flex justify-content-between" style="font-size:12px;">
              <span class="text-muted"><?= $done ?>/<?= $total ?> tasks</span>
              <span style="color:#e4e6eb;font-weight:600;"><?= $pct ?>%</span>
            </div>
            <div class="progress mb-2" style="height:8px;border-radius:4px;">
              <div class="progress-bar <?= $barCls ?>" role="progressbar"
                style="width:<?= $pct ?>%;transition:width 0.6s ease;" data-target="<?= $pct ?>">
              </div>
            </div>

            <!-- Meta row -->
            <div class="d-flex justify-content-between" style="font-size:11px;color:#888;">
              <span>
                <?php if ($dueStr): ?>
                  <i class="far fa-calendar-alt"></i>
                  <span class="<?= $isOverdue ? 'text-danger font-weight-bold' : '' ?>">
                    <?= $isOverdue ? '⚠ ' : '' ?>    <?= $dueStr ?>
                  </span>
                <?php endif; ?>
              </span>
              <span><i class="fas fa-clock"></i> <?= number_format(floatval($p['total_hours']), 1) ?>h logged</span>
            </div>
          </div>
          <div class="card-footer bg-transparent border-0 pt-0">
            <a href="?page=projects&view=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary w-100">
              <i class="fas fa-folder-open"></i> Open
            </a>
            <?php if ($isAdmin): ?>
              <div class="mt-1 d-flex" style="gap:4px;">
                <button class="btn btn-xs btn-warning flex-fill" onclick="openEditProject(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode([
                    'title' => $p['title'],
                    'description' => $p['description'],
                    'status' => $p['status'],
                    'priority' => $p['priority'],
                    'assigned_to' => $p['assigned_to'],
                    'start_date' => $p['start_date'],
                    'due_date' => $p['due_date'],
                    'notes' => $p['notes'],
                    'color' => $p['color'],
                  ]), ENT_QUOTES) ?>)">
                  <i class="fas fa-pencil-alt"></i> Edit
                </button>
                <button class="btn btn-xs btn-danger flex-fill"
                  onclick="confirmDelete(<?= $p['id'] ?>, '<?= addslashes($p['title']) ?>')">
                  <i class="fas fa-trash"></i> Delete
                </button>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div><!-- /projectsGrid -->
</div>

<!-- ===== Create Project Modal ===== -->
<?php if ($isAdmin): ?>
  <div class="modal fade" id="createProjectModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <form method="POST">
          <input type="hidden" name="action" value="create_project">
          <div class="modal-header bg-primary">
            <h5 class="modal-title text-white"><i class="fas fa-plus mr-1"></i> New Project</h5>
            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label>Project Title *</label>
              <input type="text" name="title" class="form-control" required placeholder="e.g. KTM Plastics 2025">
            </div>
            <div class="form-group">
              <label>Responsible Employee *</label>
              <select name="assigned_to" class="form-control" required>
                <option value="">Select employee</option>
                <?php foreach ($employees as $e): ?>
                  <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Description</label>
              <textarea name="description" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-row">
              <div class="form-group col-md-4">
                <label>Status</label>
                <select name="status" class="form-control form-control-sm">
                  <option value="waiting">Waiting</option>
                  <option value="in_progress">In Progress</option>
                  <option value="stuck">Stuck</option>
                  <option value="done">Done</option>
                  <option value="cancelled">Cancelled</option>
                </select>
              </div>
              <div class="form-group col-md-4">
                <label>Priority</label>
                <select name="priority" class="form-control form-control-sm">
                  <option value="low">Low</option>
                  <option value="medium" selected>Medium</option>
                  <option value="high">High</option>
                  <option value="urgent">Urgent</option>
                </select>
              </div>
              <div class="form-group col-md-4">
                <label>Color</label>
                <input type="color" name="color" class="form-control form-control-sm" value="#3a7bd5"
                  style="height:38px;">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label>Start Date</label>
                <input type="date" name="start_date" class="form-control form-control-sm">
              </div>
              <div class="form-group col-md-6">
                <label>Due Date</label>
                <input type="date" name="due_date" class="form-control form-control-sm">
              </div>
            </div>
            <div class="form-group">
              <label>Notes</label>
              <textarea name="notes" class="form-control form-control-sm" rows="3"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Create Project</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Edit Project Modal -->
  <div class="modal fade" id="editProjectModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <form method="POST">
          <input type="hidden" name="action" value="edit_project">
          <input type="hidden" name="project_id" id="editProjectId">
          <div class="modal-header bg-warning">
            <h5 class="modal-title"><i class="fas fa-pencil-alt mr-1"></i> Edit Project</h5>
            <button type="button" class="close" data-dismiss="modal">&times;</button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label>Project Title *</label>
              <input type="text" name="title" id="editTitle" class="form-control" required>
            </div>
            <div class="form-group">
              <label>Responsible Employee *</label>
              <select name="assigned_to" id="editAssignedTo" class="form-control" required>
                <option value="">Select employee</option>
                <?php foreach ($employees as $e): ?>
                  <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Description</label>
              <textarea name="description" id="editDesc" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-row">
              <div class="form-group col-md-4">
                <label>Status</label>
                <select name="status" id="editStatus" class="form-control form-control-sm">
                  <option value="waiting">Waiting</option>
                  <option value="in_progress">In Progress</option>
                  <option value="stuck">Stuck</option>
                  <option value="done">Done</option>
                  <option value="cancelled">Cancelled</option>
                </select>
              </div>
              <div class="form-group col-md-4">
                <label>Priority</label>
                <select name="priority" id="editPriority" class="form-control form-control-sm">
                  <option value="low">Low</option>
                  <option value="medium">Medium</option>
                  <option value="high">High</option>
                  <option value="urgent">Urgent</option>
                </select>
              </div>
              <div class="form-group col-md-4">
                <label>Color</label>
                <input type="color" name="color" id="editColor" class="form-control form-control-sm" style="height:38px;">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label>Start Date</label>
                <input type="date" name="start_date" id="editStart" class="form-control form-control-sm">
              </div>
              <div class="form-group col-md-6">
                <label>Due Date</label>
                <input type="date" name="due_date" id="editDue" class="form-control form-control-sm">
              </div>
            </div>
            <div class="form-group">
              <label>Notes</label>
              <textarea name="notes" id="editNotes" class="form-control form-control-sm" rows="3"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-warning">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Delete confirmation modal -->
  <div class="modal fade" id="deleteProjectModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
      <div class="modal-content">
        <form method="POST">
          <input type="hidden" name="action" value="delete_project">
          <input type="hidden" name="project_id" id="deleteProjectId">
          <div class="modal-header bg-danger">
            <h5 class="modal-title text-white">Delete Project</h5>
            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
          </div>
          <div class="modal-body">
            Are you sure you want to delete <strong id="deleteProjectName"></strong>?
            All tasks and time logs will be deleted.
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
  // ── Filter buttons ──────────────────────────────────────────
  $('#statusFilter .btn').on('click', function () {
    $('#statusFilter .btn').removeClass('active');
    $(this).addClass('active');
    var filter = $(this).data('filter');
    filterProjects();
  });

  $('#projectSearch').on('input', filterProjects);
  $('#employeeFilter').on('change', filterProjects);

  $('.employee-filter-btn').on('click', function () {
    $('#employeeFilter').val(String($(this).data('employee')));
    filterProjects();
  });

  function filterProjects() {
    var filter = $('#statusFilter .btn.active').data('filter');
    var employee = $('#employeeFilter').val() || 'all';
    var search = $('#projectSearch').val().toLowerCase();
    $('.project-card-wrap').each(function () {
      var statusMatch = filter === 'all' || $(this).data('status') === filter;
      var employeeMatch = employee === 'all' || String($(this).data('employee')) === employee;
      var title = String($(this).data('title') || '');
      var assignee = String($(this).data('assignee') || '');
      var searchMatch = !search || title.indexOf(search) !== -1 || assignee.indexOf(search) !== -1;
      $(this).toggle(statusMatch && employeeMatch && searchMatch);
    });
  }

  // ── Edit modal ──────────────────────────────────────────────
  function openEditProject(id, data) {
    if (data) {
      $('#editProjectId').val(id);
      $('#editTitle').val(data.title || '');
      $('#editDesc').val(data.description || '');
      $('#editStatus').val(data.status || 'waiting');
      $('#editPriority').val(data.priority || 'medium');
      $('#editAssignedTo').val(data.assigned_to || '');
      $('#editStart').val(data.start_date ? data.start_date.substring(0, 10) : '');
      $('#editDue').val(data.due_date ? data.due_date.substring(0, 10) : '');
      $('#editNotes').val(data.notes || '');
      $('#editColor').val(data.color || '#3a7bd5');
    } else {
      // called from detail page without data - just set id
      $('#editProjectId').val(id);
    }
    $('#editProjectModal').modal('show');
  }

  // ── Delete modal ────────────────────────────────────────────
  function confirmDelete(id, name) {
    $('#deleteProjectId').val(id);
    $('#deleteProjectName').text(name);
    $('#deleteProjectModal').modal('show');
  }

  // ── Animate progress bars on load ──────────────────────────
  $(document).ready(function () {
    // Small delay so CSS transition is visible
    setTimeout(function () {
      $('.progress-bar[data-target]').each(function () {
        var target = $(this).data('target');
        $(this).css('width', target + '%');
      });
    }, 100);
  });
</script>