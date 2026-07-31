<?php
declare(strict_types=1);

/**
 * Vyrenderuje presne ten istý HTML fragment, aký sa zobrazuje v orders.php
 * v bunke "Assigned" (div.assigned-users s avatarmi/iniciálami a x-remove
 * tlačidlom). Používa sa jednak pri prvotnom vykreslení zoznamu objednávok,
 * jednak z AJAX endpointov (take_order.php, invite_collab.php,
 * remove_order_assignment.php), aby po assign/take/remove akcii mohol JS
 * len nahradiť obsah bunky bez potreby location.reload().
 *
 * Očakáva, že session_start() už prebehol (číta $_SESSION['user_id'] a
 * $_SESSION['permission']).
 */
function parse_assigned_users_raw(string $assignedRaw): array
{
  $assigned = [];
  if ($assignedRaw !== '') {
    foreach (explode(';;', $assignedRaw) as $part) {
      $bits = explode('|', $part);
      if (count($bits) >= 6) {
        $assigned[] = [
          'assignment_id' => (int) $bits[0],
          'id' => (int) $bits[1],
          'name' => $bits[2],
          'role' => $bits[3],
          'state' => $bits[4],
          'photo' => $bits[5],
        ];
      }
    }
  }
  return $assigned;
}

function fetch_assigned_users_raw(mysqli $conn, int $orderId): string
{
  $stmt = $conn->prepare("
    SELECT GROUP_CONCAT(
        CONCAT(
          oa.id, '|',
          e.id, '|',
          e.firstname, ' ', e.lastname, '|',
          oa.role, '|',
          oa.state, '|',
          COALESCE(e.photo, '')
        )
        ORDER BY
          CASE oa.role
            WHEN 'PRIMARY_GRAPHICS' THEN 10
            WHEN 'COLLAB_GRAPHICS' THEN 11
            WHEN 'PRIMARY_FITTING' THEN 20
            WHEN 'COLLAB_FITTING' THEN 21
            WHEN 'PRIMARY_PLASTICS' THEN 30
            WHEN 'COLLAB_PLASTICS' THEN 31
            WHEN 'PRIMARY_SEATCOVER' THEN 40
            WHEN 'COLLAB_SEATCOVER' THEN 41
            ELSE 99
          END,
          e.firstname,
          e.lastname
        SEPARATOR ';;'
      ) AS assigned_users
    FROM order_assignments oa
    JOIN employees e ON e.id = oa.employee_id
    WHERE oa.order_id = ?
      AND oa.removed_at IS NULL
  ");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $raw = (string) ($stmt->get_result()->fetch_assoc()['assigned_users'] ?? '');
  $stmt->close();
  return $raw;
}

function render_assigned_users_html(mysqli $conn, int $orderId, ?string $assignedRawOverride = null): string
{
  $meUserId = (int) ($_SESSION['user_id'] ?? 0);
  $perm = (int) ($_SESSION['permission'] ?? 0);

  $assignedRaw = $assignedRawOverride !== null
    ? $assignedRawOverride
    : fetch_assigned_users_raw($conn, $orderId);

  $assigned = parse_assigned_users_raw($assignedRaw);

  if (!$assigned) {
    return '<span class="text-muted"></span>';
  }

  $maxVisible = 12;
  $visible = array_slice($assigned, 0, $maxVisible);
  $hiddenCount = max(0, count($assigned) - $maxVisible);

  ob_start();
  ?>
  <div class="assigned-users">
    <?php foreach ($visible as $a): ?>
      <?php
      $name = trim((string) $a['name']);
      $role = trim((string) $a['role']);
      $photo = trim((string) ($a['photo'] ?? ''));

      $initials = '';
      foreach (preg_split('/\s+/', $name) as $p) {
        if ($p !== '') {
          $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        }
      }
      $initials = mb_substr($initials, 0, 2);

      $roleLabel = str_replace(
        ['PRIMARY_', 'COLLAB_', '_'],
        ['', 'Collab ', ' '],
        $role
      );

      $roleClass = (strpos($role, 'PRIMARY_') === 0) ? 'assigned-primary' : 'assigned-collab';
      ?>

      <?php if ($photo !== ''): ?>
        <span class="assigned-avatar-wrap">

          <?php if ($photo !== ''): ?>
            <img src="images/<?= htmlspecialchars($photo) ?>"
              class="assigned-photo <?= htmlspecialchars($roleClass) ?>"
              title="<?= htmlspecialchars($name . ' — ' . $roleLabel) ?>">
          <?php else: ?>
            <span class="assigned-avatar <?= htmlspecialchars($roleClass) ?>"
              title="<?= htmlspecialchars($name . ' — ' . $roleLabel) ?>">
              <?= htmlspecialchars($initials ?: '?') ?>
            </span>
          <?php endif; ?>

          <?php
          $canRemoveThisAssignment = (
            !empty($a['assignment_id'])
            && (
              $perm >= 300
              || (int) $a['id'] === $meUserId
            )
          );
          ?>

          <?php if ($canRemoveThisAssignment): ?>
            <button type="button" class="btn-remove-assignment"
              data-assignment-id="<?= (int) $a['assignment_id'] ?>"
              title="<?= ((int) $a['id'] === $meUserId ? 'Remove my assignment' : 'Remove assignment') ?>">
              ×
            </button>
          <?php endif; ?>

        </span>
      <?php else: ?>
        <span class="assigned-avatar <?= htmlspecialchars($roleClass) ?>"
          title="<?= htmlspecialchars($name . ' — ' . $roleLabel) ?>">
          <?= htmlspecialchars($initials ?: '?') ?>
        </span>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($hiddenCount > 0): ?>
      <span class="assigned-more" title="<?= htmlspecialchars($assignedRaw) ?>">
        +<?= (int) $hiddenCount ?>
      </span>
    <?php endif; ?>
  </div>
  <?php
  return (string) ob_get_clean();
}

/**
 * Vyrenderuje presne ten istý HTML fragment ako blok TAKE / Assign To / Taken
 * v stĺpci "Detail" v orders.php. Nezávisí na $detailStatusDateRule (dátumové
 * badge namiesto TAKE/Assign zostávajú riešené priamo v orders.php, keďže sa
 * netýkajú assign/take/remove akcií).
 *
 * $uiDeptCode je aktuálne zvolený department kontext (napr. 'GRAPHICS') —
 * na strane orders.php je to filter z UI, na strane AJAX endpointov je to
 * department, s ktorým sa akcia reálne vykonala (dept_code z POST / role
 * odstráneného assignmentu).
 */
function render_order_take_assign_html(
  mysqli $conn,
  int $orderId,
  string $uiDeptCode,
  int $perm,
  int $meUserId,
  ?string $assignedRawOverride = null
): string {
  if ($uiDeptCode === '') {
    if ($perm >= 400) {
      return '<span class="badge badge-info ml-2" title="Select department filter first">Select dept</span>';
    }
    return '';
  }

  $assignedRaw = $assignedRawOverride !== null
    ? $assignedRawOverride
    : fetch_assigned_users_raw($conn, $orderId);

  $assigned = parse_assigned_users_raw($assignedRaw);

  $currentPrimaryRole = 'PRIMARY_' . $uiDeptCode;

  $isTakenForDept = false;
  $takenByMeForDept = false;
  $takenNameForDept = '';
  $primaryId = 0;

  foreach ($assigned as $a) {
    if ($a['role'] === $currentPrimaryRole) {
      $isTakenForDept = true;
      $primaryId = (int) $a['id'];

      if ($primaryId === $meUserId) {
        $takenByMeForDept = true;
      }
      if ($takenNameForDept === '') {
        $takenNameForDept = $a['name'];
      }
    }
  }

  ob_start();
  ?>
  <?php if (!$isTakenForDept): ?>

    <button type="button" class="btn btn-sm btn-success btn-take-order mr-1" data-order-id="<?= $orderId ?>"
      data-dept-code="<?= htmlspecialchars($uiDeptCode) ?>" title="Take order">
      TAKE
    </button>
    <?php if ($perm >= 400): ?>
      <button type="button" class="btn btn-sm btn-info btn-invite-collab" data-order-id="<?= $orderId ?>"
        data-dept-code="<?= htmlspecialchars($uiDeptCode) ?>" data-mode="assign">
        Assign To
      </button>
    <?php endif; ?>

  <?php else: ?>

    <button type="button" class="btn btn-sm btn-secondary btn-take-order mr-1" data-order-id="<?= $orderId ?>"
      data-dept-code="<?= htmlspecialchars($uiDeptCode) ?>" disabled title="Already assigned">
      TAKE
    </button>

    <?php if ($takenByMeForDept): ?>
      <span class="badge badge-warning mr-1 px-3 py-2" style="font-size:0.85rem;">
        MINE
      </span>
    <?php else: ?>
      <span class="badge badge-warning mr-1">
        Taken<?= $takenNameForDept ? ': ' . htmlspecialchars($takenNameForDept) : '' ?>
      </span>
    <?php endif; ?>

    <?php
    $canInvite = ($perm >= 400) || ($primaryId === $meUserId);
    ?>
    <button type="button" class="btn btn-sm btn-info btn-invite-collab" data-order-id="<?= $orderId ?>"
      data-dept-code="<?= htmlspecialchars($uiDeptCode) ?>"
      data-mode="<?= ($perm >= 400 ? 'assign' : 'invite') ?>" <?= $canInvite ? '' : 'disabled' ?>
      title="<?= ($perm >= 400 ? 'Zmeniť primary priradenie' : 'Pozvať kolaboranta') ?>">
      <?= ($perm >= 400 ? 'Reassign' : 'Invite') ?>
    </button>

  <?php endif; ?>
  <?php
  return (string) ob_get_clean();
}