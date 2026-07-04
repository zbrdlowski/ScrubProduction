<style>
  sekcie toto CSS: <style>.project-user-line {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
  }

  .project-user-text,
  .project-participant-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .project-avatar {
    width: 24px !important;
    height: 24px !important;
    min-width: 24px !important;
    border-radius: 50%;
    object-fit: cover;
  }

  .project-avatar-sm {
    width: 20px !important;
    height: 20px !important;
    min-width: 20px !important;
    border-radius: 50%;
    object-fit: cover;
  }

  .project-participants {
    margin: 8px 0 10px 0;
  }

  .project-participant-row {
    display: grid;
    grid-template-columns: 20px 1fr auto;
    align-items: center;
    gap: 7px;
    margin-bottom: 6px;
  }

  .project-participant-progress {
    height: 4px;
    border-radius: 3px;
    margin-top: 2px;
  }

  .project-participant-count {
    font-size: 11px;
    color: #aaa;
    white-space: nowrap;
  }

  .project-participant-empty {
    font-size: 12px;
    color: #888;
    margin: 8px 0;
  }

  .min-w-0 {
    min-width: 0;
  }
</style>

<?php
if (session_status() === PHP_SESSION_NONE)
  session_start();
if (!isset($conn))
  include __DIR__ . '/conn.php';

$userId = intval($_SESSION['user_id'] ?? 0);

function ppStatusBadge(string $s): string
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

function ppPriorityBadge(string $p): string
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

function ppAvatarSrc(?string $photo): string
{
  $photo = trim((string) $photo);
  if ($photo === '')
    return 'images/profile.jpg';
  if (preg_match('~^(https?:)?//|^/|^images/~i', $photo))
    return $photo;
  return 'images/' . ltrim($photo, '/');
}

function ppAvatarImg(?string $photo, string $name = '', string $class = 'project-avatar-sm'): string
{
  $src = htmlspecialchars(ppAvatarSrc($photo), ENT_QUOTES, 'UTF-8');
  $alt = htmlspecialchars($name ?: 'User', ENT_QUOTES, 'UTF-8');
  $cls = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
  return "<img src=\"{$src}\" alt=\"{$alt}\" title=\"{$alt}\" class=\"{$cls}\" onerror=\"this.src='images/profile.jpg';\">";
}

function ppParseParticipants(?string $packed): array
{
  $participants = [];

  foreach (explode(';;', (string) $packed) as $row) {
    if ($row === '')
      continue;

    [$id, $name, $photo, $totalTasks, $doneTasks, $hours] = array_pad(explode('|', $row, 6), 6, '');

    $id = intval($id);
    if ($id <= 0)
      continue;

    $totalTasks = intval($totalTasks);
    $doneTasks = intval($doneTasks);

    $participants[$id] = [
      'id' => $id,
      'name' => $name !== '' ? $name : 'Employee #' . $id,
      'photo' => $photo,
      'total_tasks' => $totalTasks,
      'done_tasks' => $doneTasks,
      'hours' => floatval($hours),
      'progress' => $totalTasks > 0 ? round(($doneTasks / $totalTasks) * 100) : 0,
    ];
  }

  return array_values($participants);
}

$projects = [];

if ($userId > 0) {
  $stmt = $conn->prepare("
    SELECT DISTINCT
      p.*,
      CONCAT_WS(' ', e.firstname, e.lastname) AS assigned_name,
      e.photo AS assigned_photo,
      COALESCE(ts.total_tasks, 0) AS total_tasks,
      COALESCE(ts.done_tasks, 0) AS done_tasks,
      COALESCE(tl.total_hours, 0) AS total_hours,
      COALESCE(pa.participants, '') AS participants
    FROM projects p
    LEFT JOIN employees e ON e.id = p.assigned_to
    LEFT JOIN project_tasks user_pt ON user_pt.project_id = p.id
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
    LEFT JOIN (
      SELECT project_id,
             GROUP_CONCAT(
               CONCAT_WS('|', employee_id, employee_name, photo, total_tasks, done_tasks, total_hours)
               ORDER BY employee_name SEPARATOR ';;'
             ) AS participants
      FROM (
        SELECT pt.project_id,
               emp.id AS employee_id,
               REPLACE(REPLACE(CONCAT_WS(' ', emp.firstname, emp.lastname), '|', ' '), ';;', ' ') AS employee_name,
               REPLACE(REPLACE(COALESCE(emp.photo, ''), '|', ' '), ';;', ' ') AS photo,
               COUNT(*) AS total_tasks,
               SUM(pt.status = 'done') AS done_tasks,
               COALESCE(th.total_hours, 0) AS total_hours
        FROM project_tasks pt
        JOIN employees emp ON emp.id = pt.assigned_to
        LEFT JOIN (
          SELECT project_id, employee_id, SUM(hours) AS total_hours
          FROM project_time_log
          GROUP BY project_id, employee_id
        ) th ON th.project_id = pt.project_id AND th.employee_id = emp.id
        GROUP BY pt.project_id, emp.id, emp.firstname, emp.lastname, emp.photo, th.total_hours
      ) participant_rows
      GROUP BY project_id
    ) pa ON pa.project_id = p.id
    WHERE p.status NOT IN ('done','cancelled')
      AND (p.assigned_to = ? OR user_pt.assigned_to = ?)
    ORDER BY
      FIELD(p.status,'in_progress','stuck','waiting','done','cancelled'),
      FIELD(p.priority,'urgent','high','medium','low'),
      p.id DESC
  ");

  if ($stmt) {
    $stmt->bind_param('ii', $userId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
      $row['_participants'] = ppParseParticipants($row['participants'] ?? '');
      $projects[] = $row;
    }

    $stmt->close();
  }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0" style="color:#e4e6eb;">
    <i class="fas fa-project-diagram mr-2"></i>My active projects
    <span class="badge badge-warning ml-1"><?= count($projects) ?></span>
  </h5>

  <a href="?page=projects" class="btn btn-sm btn-primary">
    <i class="fas fa-folder-open"></i> Open Projects
  </a>
</div>

<div class="row">
  <?php if (empty($projects)): ?>
    <div class="col-12">
      <div class="alert alert-info mb-0">Nemáš aktuálne rozpracované projekty.</div>
    </div>
  <?php endif; ?>

  <?php foreach ($projects as $p): ?>
    <?php
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
    $participants = $p['_participants'] ?? [];
    ?>

    <div class="col-md-4 col-sm-6 mb-4 project-card-wrap">
      <div class="card h-100 shadow-sm" style="border-top:4px solid <?= htmlspecialchars($p['color'] ?: '#3a7bd5') ?>;">
        <div class="card-body">

          <div class="d-flex justify-content-between align-items-start mb-1">
            <h6 class="card-title mb-0" style="color:#e4e6eb;font-size:15px;font-weight:600;">
              <?= htmlspecialchars($p['title']) ?>
            </h6>
            <?= ppPriorityBadge($p['priority']) ?>
          </div>

          <div class="mb-2"><?= ppStatusBadge($p['status']) ?></div>

          <?php if (intval($p['assigned_to'] ?? 0) > 0 && !empty($p['assigned_name'])): ?>
            <div class="project-user-line text-muted mb-2" style="font-size:12px;">
              <?= ppAvatarImg($p['assigned_photo'] ?? '', $p['assigned_name'], 'project-avatar') ?>
              <span class="project-user-text">Responsible: <?= htmlspecialchars($p['assigned_name']) ?></span>
            </div>
          <?php endif; ?>

          <?php if (!empty($p['description'])): ?>
            <p class="text-muted mb-2" style="font-size:12px;">
              <?= htmlspecialchars(mb_strimwidth($p['description'], 0, 90, '…')) ?>
            </p>
          <?php endif; ?>

          <?php if (!empty($participants)): ?>
            <div class="project-participants">
              <?php foreach (array_slice($participants, 0, 4) as $participant): ?>
                <?php $participantPct = intval($participant['progress']); ?>
                <div class="project-participant-row">
                  <?= ppAvatarImg($participant['photo'] ?? '', $participant['name'], 'project-avatar-sm') ?>

                  <div class="min-w-0">
                    <div class="project-participant-name">
                      <?= htmlspecialchars($participant['name']) ?>
                    </div>
                    <div class="progress project-participant-progress">
                      <div class="progress-bar bg-success" style="width:<?= $participantPct ?>%;"></div>
                    </div>
                  </div>

                  <div class="project-participant-count">
                    <?= intval($participant['done_tasks']) ?>/<?= intval($participant['total_tasks']) ?>
                  </div>
                </div>
              <?php endforeach; ?>

              <?php if (count($participants) > 4): ?>
                <div class="project-participant-empty">
                  +<?= count($participants) - 4 ?> more people
                </div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="project-participant-empty">No assigned task users yet</div>
          <?php endif; ?>

          <div class="mb-1 d-flex justify-content-between" style="font-size:12px;">
            <span class="text-muted"><?= $done ?>/<?= $total ?> tasks</span>
            <span style="color:#e4e6eb;font-weight:600;"><?= $pct ?>%</span>
          </div>

          <div class="progress mb-2" style="height:8px;border-radius:4px;">
            <div class="progress-bar <?= $barCls ?>" style="width:<?= $pct ?>%;transition:width 0.6s ease;"></div>
          </div>

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
          <a href="?page=projects&view=<?= intval($p['id']) ?>" class="btn btn-sm btn-primary w-100">
            <i class="fas fa-folder-open"></i> Open
          </a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>