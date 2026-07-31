<style>
  .project-user-line {
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
    background: #343a40;
    border: 1px solid rgba(255, 255, 255, .2);
  }

  .project-general-icon {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    font-size: 10px;
    color: #adb5bd;
    background: rgba(108, 117, 125, .2);
    border: 1px solid rgba(255, 255, 255, .18);
  }

  .project-participant-empty {
    font-size: 12px;
    color: #888;
  }

  .min-w-0 {
    min-width: 0;
  }

  /* ── Row-based project list (table), matches projects.php ─────────── */
  #profileProjectsTable td,
  #profileProjectsTable th {
    vertical-align: middle !important;
  }

  .pp-row {
    cursor: pointer;
  }

  .pp-row-urgent {
    background: rgba(220, 53, 69, 0.10) !important;
    box-shadow: inset 4px 0 0 rgba(220, 53, 69, 0.65);
  }

  .pp-row-stuck {
    background: rgba(220, 53, 69, 0.14) !important;
  }

  .project-avatar-group {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .project-avatar-stacked {
    margin-left: -8px;
    box-shadow: 0 0 0 2px #23272b;
  }

  .project-avatar-stacked:first-child {
    margin-left: 0;
  }

  .project-avatar-more-count {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: 700;
    background: rgba(255, 255, 255, .12);
    color: #ddd;
    border: 1px solid rgba(255, 255, 255, .2);
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

<?php if (empty($projects)): ?>
  <div class="alert alert-info mb-0">Nemáš aktuálne rozpracované projekty.</div>
<?php else: ?>
  <div class="table-responsive">
    <table id="profileProjectsTable" class="table table-bordered table-hover table-sm">
      <thead>
        <tr style="background:#343a40;color:#fff;">
          <th>Project</th>
          <th class="text-center" width="13%">Responsible</th>
          <th class="text-center" width="12%">Team</th>
          <th class="text-center" width="8%">Priority</th>
          <th class="text-center" width="8%">Status</th>
          <th width="15%">Progress</th>
          <th class="text-center" width="7%">Due</th>
          <th class="text-center" width="6%">Hours</th>
          <th class="text-center" width="8%">Actions</th>
        </tr>
      </thead>
      <tbody>
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
          $participants = $p['_participants'] ?? [];

          $rowClasses = ['pp-row'];
          if ($p['status'] === 'stuck') {
            $rowClasses[] = 'pp-row-stuck';
          } elseif ($p['priority'] === 'urgent') {
            $rowClasses[] = 'pp-row-urgent';
          }
          ?>
          <tr class="<?= implode(' ', $rowClasses) ?>" data-href="?page=projects&view=<?= intval($p['id']) ?>">
            <td style="border-left:4px solid <?= htmlspecialchars($p['color'] ?: '#3a7bd5') ?>;">
              <a href="?page=projects&view=<?= intval($p['id']) ?>" class="d-block" style="color:#e4e6eb;font-weight:600;font-size:14px;text-decoration:none;">
                <?= htmlspecialchars($p['title']) ?>
              </a>
              <?php if (!empty($p['description'])): ?>
                <div class="text-muted" style="font-size:12px;">
                  <?= htmlspecialchars(mb_strimwidth($p['description'], 0, 90, '…')) ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if (intval($p['assigned_to'] ?? 0) > 0 && !empty($p['assigned_name'])): ?>
                <div class="project-user-line text-muted justify-content-center" style="font-size:12px;">
                  <?= ppAvatarImg($p['assigned_photo'] ?? '', $p['assigned_name'], 'project-avatar-sm') ?>
                  <span class="project-user-text"><?= htmlspecialchars($p['assigned_name']) ?></span>
                </div>
              <?php else: ?>
                <div class="project-user-line text-muted justify-content-center" style="font-size:12px;">
                  <span class="project-general-icon"><i class="fas fa-layer-group"></i></span>
                  <span class="project-user-text">General</span>
                </div>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if (!empty($participants)): ?>
                <div class="project-avatar-group">
                  <?php foreach (array_slice($participants, 0, 5) as $participant): ?>
                    <?= ppAvatarImg($participant['photo'] ?? '', $participant['name'] . ' — ' . intval($participant['done_tasks']) . '/' . intval($participant['total_tasks']) . ' tasks', 'project-avatar-sm project-avatar-stacked') ?>
                  <?php endforeach; ?>
                  <?php if (count($participants) > 5): ?>
                    <span class="project-avatar-sm project-avatar-stacked project-avatar-more-count"
                      title="+<?= count($participants) - 5 ?> more people">+<?= count($participants) - 5 ?></span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span class="project-participant-empty">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center"><?= ppPriorityBadge($p['priority']) ?></td>
            <td class="text-center"><?= ppStatusBadge($p['status']) ?></td>
            <td>
              <div class="d-flex justify-content-between mb-1" style="font-size:11px;">
                <span class="text-muted"><?= $done ?>/<?= $total ?> tasks</span>
                <span style="color:#e4e6eb;font-weight:600;"><?= $pct ?>%</span>
              </div>
              <div class="progress" style="height:7px;border-radius:4px;">
                <div class="progress-bar <?= $barCls ?>" style="width:<?= $pct ?>%;transition:width 0.6s ease;"></div>
              </div>
            </td>
            <td class="text-center" style="font-size:12px;">
              <?php if ($dueStr): ?>
                <span class="<?= $isOverdue ? 'text-danger font-weight-bold' : 'text-muted' ?>">
                  <?= $isOverdue ? '⚠ ' : '' ?><?= $dueStr ?>
                </span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center text-muted" style="font-size:12px;">
              <?= number_format(floatval($p['total_hours']), 1) ?>h
            </td>
            <td class="text-center text-nowrap">
              <a href="?page=projects&view=<?= intval($p['id']) ?>" class="btn btn-xs btn-primary" title="Open">
                <i class="fas fa-folder-open"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<script>
  // klik na celý riadok otvorí detail projektu
  $(document).on('click', '.pp-row', function (e) {
    if ($(e.target).closest('button, a, .btn').length) {
      return;
    }

    var href = $(this).data('href');
    if (href) {
      window.location.href = href;
    }
  });
</script>