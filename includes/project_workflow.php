<?php
// Shared task hierarchy, completion review and durable project notifications.
require_once dirname(__DIR__) . '/scripts/orders/activity_helper.php';

function projectWorkflowToken(): string
{
  if (empty($_SESSION['project_workflow_csrf'])) $_SESSION['project_workflow_csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['project_workflow_csrf'];
}
function projectWorkflowValidToken($token): bool
{
  return is_string($token) && !empty($_SESSION['project_workflow_csrf'])
    && hash_equals($_SESSION['project_workflow_csrf'], $token);
}
function projectWorkflowQuery(mysqli $conn, string $sql, string $types = '', array $values = []): mysqli_stmt
{
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new RuntimeException('Could not prepare project query.');
  if ($types !== '') $stmt->bind_param($types, ...$values);
  if (!$stmt->execute()) throw new RuntimeException('Could not execute project query.');
  return $stmt;
}
function projectWorkflowRows(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
  $stmt = projectWorkflowQuery($conn, $sql, $types, $values);
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $rows;
}
function projectWorkflowExec(mysqli $conn, string $sql, string $types = '', array $values = []): void
{
  projectWorkflowQuery($conn, $sql, $types, $values)->close();
}
function projectWorkflowInstalled(mysqli $conn): bool
{
  try {
    $result = $conn->query('SELECT parent_task_id, requires_review, reviewed_by, review_requested_at, reviewed_at FROM project_tasks LIMIT 0');
    if (!$result) return false;
    $result->free();
    $result = $conn->query('SELECT id FROM project_notifications LIMIT 0');
    if (!$result) return false;
    $result->free();
    return true;
  } catch (mysqli_sql_exception $error) {
    if (in_array($error->getCode(), [1054, 1146], true)) return false;
    throw $error;
  }
}
function projectWorkflowOwner(array $project): int
{
  return (int)($project['assigned_to'] ?: $project['created_by']);
}
function projectWorkflowNotify(mysqli $conn, array $project, array $task, int $recipient, string $kind): void
{
  if ($recipient <= 0) return;
  projectWorkflowExec($conn, 'INSERT INTO project_notifications (project_id, task_id, recipient_id, kind) VALUES (?,?,?,?)',
    'iiis', [(int)$project['id'], (int)$task['id'], $recipient, $kind]);
}
function projectWorkflowResolveNotices(mysqli $conn, int $taskId, string $kind): void
{
  projectWorkflowExec($conn, 'UPDATE project_notifications SET read_at=COALESCE(read_at,NOW()) WHERE task_id=? AND kind=?', 'is', [$taskId, $kind]);
}
function projectWorkflowSync(mysqli $conn, array $project, int $actor, bool $activity): void
{
  $counts = projectWorkflowRows($conn, "SELECT COUNT(*) total, SUM(status='done') done FROM project_tasks WHERE project_id=?", 'i', [(int)$project['id']])[0];
  $status = $project['status'];
  if ($status !== 'cancelled') {
    if ((int)$counts['total'] > 0 && (int)$counts['done'] === (int)$counts['total']) $status = 'done';
    elseif ($status === 'done' || ($status === 'waiting' && $activity)) $status = 'in_progress';
  }
  if ($status !== $project['status']) {
    projectWorkflowExec($conn, 'UPDATE projects SET status=? WHERE id=?', 'si', [$status, (int)$project['id']]);
    log_order_activity($conn, null, $actor, 'project_status_autoupdated', 'project', (int)$project['id'],
      ['old_status' => $project['status'], 'new_status' => $status, 'reason' => 'task_completion_sync']);
  }
}
/** All mutations lock the project first: concurrent moves/completions cannot create cycles or duplicate alerts. */
function projectWorkflowMutate(mysqli $conn, int $actor, string $action, array $input): int
{
  if ($actor <= 0) throw new DomainException('Please sign in.');
  $pid = (int)($input['project_id'] ?? 0);
  $tid = (int)($input['task_id'] ?? 0);
  $conn->begin_transaction();
  try {
    $project = projectWorkflowRows($conn, 'SELECT * FROM projects WHERE id=? FOR UPDATE', 'i', [$pid])[0] ?? null;
    if (!$project) throw new DomainException('Project not found.');
    $tasks = [];
    foreach (projectWorkflowRows($conn, 'SELECT * FROM project_tasks WHERE project_id=? ORDER BY sort_order,id FOR UPDATE', 'i', [$pid]) as $row) $tasks[(int)$row['id']] = $row;
    $old = $action === 'add_task' ? null : ($tasks[$tid] ?? null);
    if ($action !== 'add_task' && !$old) throw new DomainException('Task not found in this project.');
    $children = array_filter($tasks, fn($t) => (int)$t['parent_task_id'] === $tid && $tid > 0);
    $owner = projectWorkflowOwner($project);
    if ($action === 'delete_task') {
      if ($children) throw new DomainException('Move or delete the subtasks before deleting their parent task.');
      projectWorkflowExec($conn, 'DELETE FROM project_notifications WHERE task_id=? AND project_id=?', 'ii', [$tid, $pid]);
      projectWorkflowExec($conn, 'DELETE FROM project_tasks WHERE id=? AND project_id=?', 'ii', [$tid, $pid]);
      log_order_activity($conn, null, $actor, 'task_deleted', 'project_task', $tid, ['project_id' => $pid]);
      projectWorkflowSync($conn, $project, $actor, false);
      $conn->commit();
      return $tid;
    }
    $task = $old ?? ['id' => 0, 'project_id' => $pid, 'title' => '', 'assigned_to' => null, 'status' => 'waiting',
      'priority' => 'medium', 'due_date' => null, 'notes' => '', 'parent_task_id' => null, 'requires_review' => 0,
      'review_requested_at' => null, 'reviewed_at' => null, 'reviewed_by' => null];
    $reviewAction = in_array($action, ['approve_task', 'reject_task'], true);
    if ($reviewAction) {
      if ($actor !== $owner) throw new DomainException('Only the project owner can review this task.');
      if ($task['status'] !== 'pending_review') throw new DomainException('This task is no longer waiting for review.');
      $task['status'] = $action === 'approve_task' ? 'done' : 'in_progress';
      $task['reviewed_at'] = $action === 'approve_task' ? date('Y-m-d H:i:s') : null;
      $task['reviewed_by'] = $action === 'approve_task' ? $actor : null;
      $task['review_requested_at'] = null;
    } else {
      if (in_array($action, ['add_task', 'edit_task'], true)) {
        $task['title'] = trim((string)($input['task_title'] ?? ''));
        if ($task['title'] === '' || mb_strlen($task['title']) > 255) throw new DomainException('Enter a task title (up to 255 characters).');
        $task['assigned_to'] = (int)($input['assigned_to'] ?? 0) ?: null;
        if ($task['assigned_to'] && !projectWorkflowRows($conn, 'SELECT id FROM employees WHERE id=?', 'i', [$task['assigned_to']])) throw new DomainException('Employee not found.');
        $task['priority'] = $input['task_priority'] ?? 'medium';
        if (!in_array($task['priority'], ['low', 'medium', 'high', 'urgent'], true)) throw new DomainException('Invalid priority.');
        $task['due_date'] = ($input['task_due_date'] ?? '') ?: null;
        if ($task['due_date']) {
          $date = DateTimeImmutable::createFromFormat('!Y-m-d', $task['due_date']);
          if (!$date || $date->format('Y-m-d') !== $task['due_date']) throw new DomainException('Invalid due date.');
        }
        $task['notes'] = trim((string)($input['task_notes'] ?? ''));
        $task['requires_review'] = empty($input['requires_review']) ? 0 : 1;
        if ($old && $old['requires_review'] && !$task['requires_review'] && $actor !== $owner) throw new DomainException('Only the project owner can remove a required review.');
      }
      if (in_array($action, ['add_task', 'edit_task', 'move_task'], true)) {
        $task['parent_task_id'] = (int)($input['parent_task_id'] ?? 0) ?: null;
      }
      $requestedStatus = $action === 'move_task' ? $task['status'] : ($input['task_status'] ?? 'waiting');
      if (!in_array($requestedStatus, ['waiting', 'in_progress', 'stuck', 'done', 'pending_review'], true)) throw new DomainException('Invalid task status.');
      if ($requestedStatus === 'pending_review' && (!$old || $old['status'] !== 'pending_review')) throw new DomainException('Mark the task done to request a review.');
      $task['status'] = $requestedStatus;
      // Saving a pending review or repeatedly clicking Done must not submit it again.
      if ($task['requires_review'] && in_array($requestedStatus, ['done', 'pending_review'], true)) {
        $alreadyApproved = $old && $old['status'] === 'done' && $old['requires_review'] && $old['reviewed_by'];
        $task['status'] = $alreadyApproved ? 'done' : 'pending_review';
        $task['review_requested_at'] = $alreadyApproved ? null : ($old['review_requested_at'] ?? date('Y-m-d H:i:s'));
      } elseif ($requestedStatus === 'pending_review') {
        $task['status'] = 'done'; // Owner explicitly removed the review requirement.
        $task['review_requested_at'] = null;
      }
      if (!in_array($task['status'], ['done', 'pending_review'], true)) {
        $task['review_requested_at'] = null;
        $task['reviewed_at'] = null;
        $task['reviewed_by'] = null;
      }
    }
    $parentId = (int)$task['parent_task_id'];
    if ($parentId) {
      $parent = $tasks[$parentId] ?? null;
      if (!$parent || $parentId === $tid || $parent['parent_task_id'] || $children) throw new DomainException('Only two levels are allowed: a task and its subtasks in the same project.');
      if ($parent['status'] !== 'done' && in_array($task['status'], ['in_progress', 'done', 'pending_review'], true)) {
        // Existing active work remains editable after a parent is reopened; new activity must wait.
        $reopening = $old && in_array($old['status'], ['done','pending_review'], true)
          && $task['status'] === 'in_progress' && (int)$old['parent_task_id'] === $parentId;
        if (!$reopening && $action !== 'reject_task' && (!$old || $old['status'] !== $task['status'] || (int)$old['parent_task_id'] !== $parentId || $action === 'approve_task')) {
          throw new DomainException('Complete and, if required, approve the parent task first.');
        }
      }
    }
    if ($task['requires_review'] && $owner <= 0) throw new DomainException('Assign a project owner before requesting a review.');
    $fields = ['title','assigned_to','status','priority','due_date','notes','parent_task_id','requires_review','review_requested_at','reviewed_at','reviewed_by'];
    $changed = !$old;
    foreach ($fields as $field) if ($old && (string)$old[$field] !== (string)$task[$field]) $changed = true;
    if (!$changed) { $conn->commit(); return $tid; }
    $values = array_map(fn($field) => $task[$field], $fields);
    if (!$old) {
      projectWorkflowExec($conn, 'INSERT INTO project_tasks (title,assigned_to,status,priority,due_date,notes,parent_task_id,requires_review,review_requested_at,reviewed_at,reviewed_by,project_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', 'sissssiissii', array_merge($values, [$pid]));
      $tid = (int)$conn->insert_id;
      $task['id'] = $tid;
    } else {
      projectWorkflowExec($conn, 'UPDATE project_tasks SET title=?,assigned_to=?,status=?,priority=?,due_date=?,notes=?,parent_task_id=?,requires_review=?,review_requested_at=?,reviewed_at=?,reviewed_by=? WHERE id=? AND project_id=?', 'sissssiissiii', array_merge($values, [$tid, $pid]));
    }
    $statusChanged = !$old || $old['status'] !== $task['status'];
    if ($statusChanged) {
      projectWorkflowResolveNotices($conn, $tid, 'task_review');
      projectWorkflowResolveNotices($conn, $tid, 'task_ready');
      projectWorkflowResolveNotices($conn, $tid, 'task_done');
      projectWorkflowResolveNotices($conn, $tid, 'task_rejected');
      if ($task['status'] === 'pending_review') projectWorkflowNotify($conn, $project, $task, $owner, 'task_review');
      if ($task['status'] === 'done') projectWorkflowNotify($conn, $project, $task, $owner, 'task_done');
      if ($action === 'reject_task') projectWorkflowNotify($conn, $project, $task, (int)$task['assigned_to'], 'task_rejected');
      if (!$parentId) {
        foreach ($children as $child) {
          projectWorkflowResolveNotices($conn, (int)$child['id'], 'task_ready');
          if ($task['status'] === 'done' && !in_array($child['status'], ['done','pending_review'], true)) {
            projectWorkflowNotify($conn, $project, $child, (int)$child['assigned_to'], 'task_ready');
          }
        }
      }
    }
    $assignmentChanged = !$old || (int)$old['assigned_to'] !== (int)$task['assigned_to'] || (int)$old['parent_task_id'] !== $parentId;
    if ($assignmentChanged) {
      projectWorkflowResolveNotices($conn, $tid, 'task_ready');
      if ($parentId && $tasks[$parentId]['status'] === 'done' && !in_array($task['status'], ['done','pending_review'], true)) {
        projectWorkflowNotify($conn, $project, $task, (int)$task['assigned_to'], 'task_ready');
      }
    }
    $event = ['add_task'=>'task_created','edit_task'=>'task_updated','update_task_status'=>'task_status_changed',
      'move_task'=>'task_moved','approve_task'=>'task_approved','reject_task'=>'task_rejected'][$action];
    log_order_activity($conn, null, $actor, $event, 'project_task', $tid, ['project_id'=>$pid, 'title'=>$task['title'],
      'old_status'=>$old['status'] ?? null, 'new_status'=>$task['status'], 'parent_task_id'=>$task['parent_task_id'], 'requires_review'=>$task['requires_review']]);
    projectWorkflowSync($conn, $project, $actor, $task['status'] !== 'waiting');
    $conn->commit();
    return $tid;
  } catch (Throwable $error) {
    $conn->rollback();
    throw $error;
  }
}
/** Pending reviews follow the current project owner, including after reassignment. */
function projectWorkflowUnread(mysqli $conn, int $userId): array
{
  return projectWorkflowRows($conn, "SELECT n.id,n.project_id,n.task_id,n.kind,p.title project_title,t.title task_title
    FROM project_notifications n JOIN projects p ON p.id=n.project_id
    JOIN project_tasks t ON t.id=n.task_id AND t.project_id=p.id
    LEFT JOIN project_tasks parent ON parent.id=t.parent_task_id
    WHERE n.read_at IS NULL AND (
      (n.kind='task_review' AND t.status='pending_review' AND COALESCE(NULLIF(p.assigned_to,0),p.created_by)=?)
      OR (n.kind<>'task_review' AND n.recipient_id=? AND (
        (n.kind='task_ready' AND t.assigned_to=n.recipient_id AND parent.status='done' AND t.status NOT IN ('done','pending_review'))
        OR (n.kind='task_done' AND t.status='done') OR (n.kind='task_rejected' AND t.status='in_progress' AND t.assigned_to=n.recipient_id)
      ))) ORDER BY n.id DESC", 'ii', [$userId, $userId]);
}
function projectWorkflowMarkRead(mysqli $conn, int $userId, int $id): void
{
  // Review notices stay visible until the owner approves, rejects or reopens the task.
  projectWorkflowExec($conn, "UPDATE project_notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND recipient_id=? AND kind<>'task_review'", 'ii', [$id, $userId]);
}
