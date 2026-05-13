<?php
declare(strict_types=1);

require_once __DIR__ . '/conn.php';

$dpt = (int) ($_SESSION['dpt'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

// zobrazenie orders ztial len pre grafikov. Neskor mozno pridat aj pre dalsie oddelenia, ale treba to spravit tak, aby sa tam nezobrazovali objednavky, ktore nepatria danym oddeleniam.
$personalOrdersEnabled = (int)($_SESSION['personal_orders'] ?? 0);

if ($personalOrdersEnabled !== 1) {
    $uid = (int)($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("SELECT personal_orders FROM employees WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $personalOrdersEnabled = (int)($row['personal_orders'] ?? 0);
    $_SESSION['personal_orders'] = $personalOrdersEnabled;
}

if ($personalOrdersEnabled !== 1) {
    echo '<div class="alert alert-warning">Profile Orders are not enabled for this user.</div>';
    return;
}

/* SEM PRIDA%T MAPOVANIE ROLÍ */

$roleMap = [
    2 => ['PRIMARY_GRAPHICS', 'COLLAB_GRAPHICS'],
    6 => ['PRIMARY_PLASTICS', 'COLLAB_PLASTICS'],
    8 => ['PRIMARY_SEATCOVER', 'COLLAB_SEATCOVER'],
    9 => ['PRIMARY_FITTING', 'COLLAB_FITTING'],
];

$profileRoles = $roleMap[$dpt] ?? [];

if (empty($profileRoles)) {
    echo '<div class="alert alert-warning">Profile Orders are not configured for your department.</div>';
    return;
}

$rolePlaceholders = implode(',', array_fill(0, count($profileRoles), '?'));

/* AŽ POTOM NASLEDUJE $sql = "SELECT ..." */

$sql = "SELECT 
    o.id,
    o.order_number,
    o.external_order_id,
    o.status,
    o.order_date,
    o.imported_at,
    o.traffic_light,
    o.traffic_summary_json,
    cu.name AS customer_name,
    cu.email AS customer_email,
    os.code AS source_code,
    COALESCE(oa_ship.country, oa_bill.country) AS country_code,
    oa.role,

    (
      SELECT GROUP_CONCAT(DISTINCT oi.item_type_code ORDER BY oi.item_type_code SEPARATOR ',')
      FROM order_items oi
      WHERE oi.order_id = o.id
        AND oi.deleted_at IS NULL
        AND oi.item_type_code IS NOT NULL
        AND oi.item_type_code <> ''
    ) AS fallback_item_types,

    (
      SELECT GROUP_CONCAT(
        CONCAT(
          oax.id, '|',
          e.id, '|',
          e.firstname, ' ', e.lastname, '|',
          oax.role, '|',
          oax.state, '|',
          COALESCE(e.photo, '')
        )
        ORDER BY
          CASE oax.role
            WHEN 'PRIMARY_GRAPHICS' THEN 10
            WHEN 'COLLAB_GRAPHICS' THEN 11
            WHEN 'PRIMARY_PLASTICS' THEN 20
            WHEN 'COLLAB_PLASTICS' THEN 21
            WHEN 'PRIMARY_SEATCOVER' THEN 30
            WHEN 'COLLAB_SEATCOVER' THEN 31
            WHEN 'PRIMARY_FITTING' THEN 40
            WHEN 'COLLAB_FITTING' THEN 41
            ELSE 99
          END,
          e.firstname,
          e.lastname
        SEPARATOR ';;'
      )
      FROM order_assignments oax
      JOIN employees e ON e.id = oax.employee_id
      WHERE oax.order_id = o.id
        AND oax.removed_at IS NULL
        AND oax.role IN ($rolePlaceholders)
    ) AS assigned_users

FROM orders o
JOIN order_assignments oa ON oa.order_id = o.id
JOIN order_sources os ON os.id = o.source_id
LEFT JOIN customers cu ON cu.id = o.customer_id
LEFT JOIN order_addresses oa_ship ON oa_ship.order_id = o.id AND UPPER(oa_ship.type) = 'SHIPPING'
LEFT JOIN order_addresses oa_bill ON oa_bill.order_id = o.id AND UPPER(oa_bill.type) = 'BILLING'

WHERE 
    oa.employee_id = ?
    AND oa.removed_at IS NULL
    AND oa.role IN ($rolePlaceholders)
    AND UPPER(o.status) != 'SHIPPED'

ORDER BY o.order_date ASC
";

$stmt = $conn->prepare($sql);
$types = str_repeat('s', count($profileRoles)) . 'i' . str_repeat('s', count($profileRoles));
$params = array_merge($profileRoles, [$userId], $profileRoles);

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
?>
<?php
function profileStatusButtonClass(string $status): string
{
    $status = strtoupper(trim($status));

    switch ($status) {
        case 'NEW':
        case 'NEED_INFO':
            return 'btn-outline-danger';

        case 'IN_PROGRESS':
        case 'WAITING_PARTS':
        case 'READY_TO_INVOICE':
            return 'btn-outline-warning';

        case 'DONE':
        case 'COMPLETED':
        case 'READY':
        case 'READY_TO_SHIP':
        case 'SHIPPED':
            return 'btn-outline-success';

        case 'HOLD':
        case 'CANCELLED':
            return 'btn-outline-secondary';

        default:
            return 'btn-outline-secondary';
    }
}

function profileRoleBadge(string $role): string
{
    $role = strtoupper($role);

    return strpos($role, 'PRIMARY_') === 0
        ? '<span class="badge badge-info">Mine</span>'
        : '<span class="badge badge-secondary">Collab</span>';
}
?>

<style>
.profile-orders-table td,
.profile-orders-table th {
    vertical-align: middle !important;
    padding: .45rem .55rem !important;
}

.profile-order-row {
    cursor: pointer;
}

.profile-order-row.order-row-open {
    background: rgba(23, 162, 184, 0.18) !important;
    box-shadow: inset 4px 0 0 #17a2b8;
}

.profile-order-in-progress {
    background: rgba(23, 162, 184, 0.12) !important;
    box-shadow: inset 4px 0 0 #17a2b8;
}

.profile-order-need-info {
    background: rgba(220, 53, 69, 0.10) !important;
    box-shadow: inset 4px 0 0 #dc3545;
}

.profile-order-detail-row td {
    padding: 0 !important;
    border-top: none !important;
}

.profile-assigned-users {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    white-space: nowrap;
}

.profile-assigned-avatar,
.profile-assigned-more {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .72rem;
    font-weight: 700;
    border: 1px solid rgba(255,255,255,.22);
}

.profile-assigned-primary {
    background: rgba(23, 162, 184, .35);
    color: #fff;
}

.profile-assigned-collab {
    background: rgba(108, 117, 125, .45);
    color: #fff;
}

.profile-assigned-photo {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid rgba(255,255,255,.22);
}

.profile-assigned-photo.profile-assigned-primary {
    border-color: #17a2b8;
}

.profile-assigned-photo.profile-assigned-collab {
    border-color: rgba(255,255,255,.35);
}
</style>
<table class="table table-bordered table-hover table-sm profile-orders-table">
    <thead>
        <tr>
            <th width="7%">Date</th>
            <th width="7%">Source</th>
            <th width="6%">Country</th>
            <th width="12%">Order</th>
            <th>Customer</th>
            <th class="text-center" width="12%">Semafor</th>
            <th class="text-center" width="12%">Status</th>
            <th width="8%">Role</th>
            <th width="12%">Assigned</th>
            <th width="7%"></th>
        </tr>
    </thead>

    <tbody>
        <?php while ($row = $res->fetch_assoc()): ?>
            <?php
            $orderId = (int)$row['id'];
            $statusUpper = strtoupper((string)($row['status'] ?? ''));

            $rowClass = '';
            if ($statusUpper === 'IN_PROGRESS') {
                $rowClass = 'profile-order-in-progress';
            } elseif ($statusUpper === 'NEED_INFO') {
                $rowClass = 'profile-order-need-info';
            }

            $customer = trim((string)($row['customer_name'] ?? ''));
            if ($customer === '') {
                $customer = (string)($row['customer_email'] ?? '-');
            }

            $cc = strtoupper(trim((string)($row['country_code'] ?? '')));
            if ($cc === 'UM') {
                $cc = 'US';
            }

            $summaryRaw = (string)($row['traffic_summary_json'] ?? '');
            $summary = json_decode($summaryRaw, true);
            if (!is_array($summary)) {
                $summary = [];
                    }
                    if (empty($summary)) {
            $fallbackTypes = array_filter(array_map('trim', explode(',', (string)($row['fallback_item_types'] ?? ''))));

            foreach ($fallbackTypes as $type) {
                $type = strtoupper($type);

                if ($type === 'T' || $type === 'M') {
                    $summary['G'] = 'ORANGE';
                    $summary['P'] = 'ORANGE';
                    continue;
                }

                if (in_array($type, ['G', 'F', 'P', 'S'], true)) {
                    $summary[$type] = 'ORANGE';
                }
            }
        }
            ?>

            <tr class="profile-order-row order-row <?= $rowClass ?>" data-order-id="<?= $orderId ?>">
                <td>
                    <?php
                    if (!empty($row['order_date'])) {
                        echo date('d.m.Y', strtotime((string)$row['order_date']));
                    } else {
                        echo '—';
                    }
                    ?>
                </td>

                <td><?= htmlspecialchars((string)($row['source_code'] ?? '')) ?></td>

                <td>
                    <?php if ($cc !== ''): ?>
                        <span style="white-space:nowrap;">
                            <img src="https://flagcdn.com/16x12/<?= htmlspecialchars(strtolower($cc)) ?>.png"
                                 alt="<?= htmlspecialchars($cc) ?>"
                                 style="margin-right:5px; vertical-align:-1px;">
                            <?= htmlspecialchars($cc) ?>
                        </span>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>

                <td>
                    <b><?= htmlspecialchars((string)($row['order_number'] ?? $row['external_order_id'] ?? '')) ?></b>
                    <?php if (!empty($row['external_order_id']) && $row['external_order_id'] !== $row['order_number']): ?>
                        <br><small class="text-muted">Ext: <?= htmlspecialchars((string)$row['external_order_id']) ?></small>
                    <?php endif; ?>
                </td>

                <td><?= htmlspecialchars($customer) ?></td>

                <td class="text-center">
                    <?php foreach (['G', 'F', 'P', 'S'] as $type): ?>
                        <?php
                        if (!isset($summary[$type])) {
                            continue;
                        }

                        $state = strtoupper((string)$summary[$type]);
                        $badge = ($state === 'GREEN')
                            ? 'badge-success'
                            : (($state === 'ORANGE') ? 'badge-warning' : 'badge-danger');
                        ?>
                        <span class="badge <?= $badge ?> mr-1"
                              style="font-size:1rem; padding:.5em .7em;"
                              title="<?= htmlspecialchars($type . ' ' . $state) ?>">
                            <?= htmlspecialchars($type) ?>
                        </span>
                    <?php endforeach; ?>
                </td>

                <td class="text-center">
                    <button class="btn btn-xs <?= profileStatusButtonClass($statusUpper) ?>" style="pointer-events:none;">
                        <?= htmlspecialchars(str_replace('_', ' ', $statusUpper) ?: '-') ?>
                    </button>
                </td>

                <td><?= profileRoleBadge((string)$row['role']) ?></td>

                <td class="text-center">
                    <div class="profile-assigned-users">
                        <?php
                        $assignedRaw = (string)($row['assigned_users'] ?? '');
                        $assigned = [];

                        if ($assignedRaw !== '') {
                            foreach (explode(';;', $assignedRaw) as $part) {
                                $bits = explode('|', $part);
                                if (count($bits) >= 6) {
                                    $assigned[] = [
                                        'name' => $bits[2],
                                        'role' => $bits[3],
                                        'photo' => $bits[5],
                                    ];
                                }
                            }
                        }

                        foreach (array_slice($assigned, 0, 5) as $a) {
                            $name = trim((string)$a['name']);
                            $initials = '';

                            foreach (preg_split('/\s+/', $name) as $namePart) {
                                if ($namePart !== '') {
                                    $initials .= mb_substr($namePart, 0, 1);
                                }
                            }

                            $initials = mb_substr($initials, 0, 2);
                            $roleClass = strtoupper((string)$a['role']) === 'PRIMARY_GRAPHICS'
                                ? 'profile-assigned-primary'
                                : 'profile-assigned-collab';

                            $photo = trim((string)$a['photo']);

                            if ($photo !== '') {
                               $photoSrc = $photo;

                        if ($photoSrc !== '' && !preg_match('~^(https?:)?//|^/~', $photoSrc)) {
                            $photoSrc = 'images/' . ltrim($photoSrc, '/');
                        }

                        echo '<img class="profile-assigned-photo ' . htmlspecialchars($roleClass) . '" src="' . htmlspecialchars($photoSrc) . '" title="' . htmlspecialchars($name) . '" alt="' . htmlspecialchars($name) . '">';
                            } else {
                                echo '<span class="profile-assigned-avatar ' . htmlspecialchars($roleClass) . '" title="' . htmlspecialchars($name) . '">' . htmlspecialchars($initials ?: '?') . '</span>';
                            }
                        }

                        if (count($assigned) > 5) {
                            echo '<span class="profile-assigned-more">+' . (count($assigned) - 5) . '</span>';
                        }
                        ?>
                    </div>
                </td>

                <td>
                    <button type="button"
                            class="btn btn-info btn-xs btn-profile-order-detail"
                            data-order-id="<?= $orderId ?>">
                        Open
                    </button>
                </td>
            </tr>

            <tr class="profile-order-detail-row order-detail-row" data-detail-for="<?= $orderId ?>" style="display:none;">
                <td colspan="10"></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
<div class="modal fade" id="optionsModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content bg-dark text-light">

      <div class="modal-header border-secondary">
        <h5 class="modal-title">
          <i class="fas fa-list-alt mr-1"></i> Product Options
        </h5>

        <button type="button" class="close text-light" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">

        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="text-muted mb-0">
              <i class="fas fa-download mr-1"></i> Imported Product Options
            </h6>
          </div>

          <div id="optionsModalBody"></div>
        </div>

        <hr class="border-secondary my-3">

        <div class="mb-2">
          <div class="d-flex justify-content-between align-items-center mb-2">

            <h6 class="text-muted mb-0">
              <i class="fas fa-tools mr-1"></i> Internal Production Blocks
            </h6>
            <?php if ((int) ($_SESSION['permission'] ?? 0) >= 300): ?>
              <button type="button" class="btn btn-sm btn-outline-warning" id="btnEditInternalOptions">
                <i class="fas fa-edit mr-1"></i> Edit internal
              </button>
            <?php endif; ?>
          </div>

          <div id="internalOptionsView" class="mb-2"></div>

          <div id="internalOptionsEditBox" style="display:none;">

            <div class="d-flex justify-content-between align-items-center mb-2">
              <small class="text-muted">Add production information as blocks and fields.</small>

              <button type="button" class="btn btn-sm btn-outline-info" id="btnAddInternalBlock">
                <i class="fas fa-plus mr-1"></i> Add block
              </button>
            </div>

            <div id="internalBlocksEditor"></div>

            <textarea id="internalOptionsJson"
              class="form-control form-control-sm bg-dark text-light border-warning mt-2" rows="6" spellcheck="false"
              style="display:none;"></textarea>

            <div class="d-flex justify-content-end mt-2">
              <button type="button" class="btn btn-sm btn-success" id="btnSaveInternalOptions">
                <i class="fas fa-save mr-1"></i> Save internal blocks
              </button>
            </div>

          </div>
        </div>

      </div>

      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" data-bs-dismiss="modal">
          Close
        </button>
      </div>

    </div>
  </div>
</div>
<script>
$(document)
  .off('click.profileOrders', '.profile-order-row')
  .on('click.profileOrders', '.profile-order-row', function (e) {
    if ($(e.target).closest('button, a, input, select, textarea, .modal').length) {
      return;
    }

    openProfileOrderDetail($(this).data('order-id'));
  });

$(document)
  .off('click.profileOrdersBtn', '.btn-profile-order-detail')
  .on('click.profileOrdersBtn', '.btn-profile-order-detail', function (e) {
    e.preventDefault();
    e.stopPropagation();

    openProfileOrderDetail($(this).data('order-id'));
  });

function openProfileOrderDetail(orderId) {
  const $row = $('.profile-order-row[data-order-id="' + orderId + '"]');
  const $detailRow = $('.profile-order-detail-row[data-detail-for="' + orderId + '"]');
  const $cell = $detailRow.find('td');

  if ($detailRow.is(':visible')) {
    $detailRow.hide();
    $row.removeClass('order-row-open');
    return;
  }

  $('.profile-order-detail-row').hide();
  $('.profile-order-row').removeClass('order-row-open');

  $row.addClass('order-row-open');
  $detailRow.show();
  $cell.html('<div class="p-3 text-muted"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');

  $.ajax({
    url: 'scripts/orders/get_order_detail.php',
    method: 'POST',
    dataType: 'json',
    data: { order_id: orderId },
    success: function (resp) {
      if (!resp || !resp.ok) {
        $cell.html('<div class="alert alert-danger m-3">' + (resp.error || 'Detail load failed') + '</div>');
        return;
      }

      $cell.html(resp.html);
    },
    error: function () {
      $cell.html('<div class="alert alert-danger m-3">Detail load failed.</div>');
    }
  });
}
window.refreshProfileOrdersList = function (reopenOrderId) {
  $('#profileOrdersContainer').load(
    'includes/profile.php?ajax=1&section=orders&_=' + Date.now(),
    function () {
      if (reopenOrderId) {
        setTimeout(function () {
          openProfileOrderDetail(reopenOrderId);
        }, 150);
      }
    }
  );
};
</script>

<script src="scripts/orders/order_detail_actions.js?v=<?= filemtime(__DIR__ . '/../scripts/orders/order_detail_actions.js') ?>"></script>