<?php
declare(strict_types=1);
require_once __DIR__ . '/conn.php';

$dpt = (int)($_SESSION['dpt'] ?? 0);
$allAccess = in_array($dpt, [1,3,4,5,7], true);

// --- Filters (GET) ---
$page = 'orders';
$fDept = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
$fCat  = isset($_GET['cat']) ? trim((string)$_GET['cat']) : '';
$fType = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$fQ    = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowedCats = ['GRAPHICS','PLASTICS','SEATCOVER','FITTING'];
if ($fCat !== '' && !in_array($fCat, $allowedCats, true)) $fCat = '';

$allowedTypes = ['G','T','M','P','S','F','(NULL)'];
if ($fType !== '' && !in_array($fType, $allowedTypes, true)) $fType = '';

$deptFilter = [
  2 => ['GRAPHICS'],
  6 => ['PLASTICS'],  // T/M pridávajú PLASTICS, takže netreba špeciál
  8 => ['SEATCOVER'],
  9 => ['FITTING'],
];

$effectiveDept = $dpt;
$deptCodeMap = [ 2=>'GRAPHICS', 6=>'PLASTICS', 8=>'SEATCOVER', 9=>'FITTING' ];
$uiDept = $effectiveDept;                 // keď admin vyberie dept filter, reaguje to naň
$uiDeptCode = $deptCodeMap[$uiDept] ?? null;
$rolePrimaryUI = $uiDeptCode ? ('PRIMARY_' . $uiDeptCode) : null;
$meUserId = (int)($_SESSION['user_id'] ?? 0);
$perm = (int)($_SESSION['permission'] ?? 0);
if ($allAccess && $fDept > 0) $effectiveDept = $fDept;

$aclCats = [];
if (!$allAccess) {
  $aclCats = $deptFilter[$dpt] ?? ['__NONE__'];
} else {
  $aclCats = $deptFilter[$effectiveDept] ?? [];
}

$where = [];
$types = '';
$params = [];

if (!empty($aclCats)) {
  $ph = implode(',', array_fill(0, count($aclCats), '?'));
  $where[] = "EXISTS (
    SELECT 1
    FROM order_categories ocx
    JOIN categories cx ON cx.id = ocx.category_id
    WHERE ocx.order_id = o.id AND cx.code IN ($ph)
  )";
  $types .= str_repeat('s', count($aclCats));
  foreach ($aclCats as $c) $params[] = $c;
}

if ($fCat !== '') {
  $where[] = "EXISTS (
    SELECT 1
    FROM order_categories ocf
    JOIN categories cf ON cf.id = ocf.category_id
    WHERE ocf.order_id = o.id AND cf.code = ?
  )";
  $types .= 's';
  $params[] = $fCat;
}

if ($fType !== '') {
  if ($fType === '(NULL)') {
    $where[] = "EXISTS (SELECT 1 FROM order_items oit WHERE oit.order_id=o.id AND oit.item_type_code IS NULL)";
  } else {
    $where[] = "EXISTS (SELECT 1 FROM order_items oit WHERE oit.order_id=o.id AND oit.item_type_code = ?)";
    $types .= 's';
    $params[] = $fType;
  }
}

if ($fQ !== '') {
  $where[] = "(o.order_number LIKE CONCAT('%', ?, '%')
    OR o.external_order_id LIKE CONCAT('%', ?, '%')
    OR (cu.name LIKE CONCAT('%', ?, '%') OR cu.email LIKE CONCAT('%', ?, '%'))
  )";
  $types .= 'ssss';
  array_push($params, $fQ, $fQ, $fQ, $fQ);
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = " SELECT
    o.id,
    o.order_number,
    o.external_order_id,
    o.order_date,
    o.imported_at,
    o.status,
    o.payment_method,
    o.shipping_method,
    os.code AS source_code,
    cu.name AS customer_name,
    cu.email AS customer_email,

    (SELECT GROUP_CONCAT(DISTINCT c.code ORDER BY c.code SEPARATOR ', ')
     FROM order_categories oc
     JOIN categories c ON c.id = oc.category_id
     WHERE oc.order_id = o.id
    ) AS categories,

    (SELECT GROUP_CONCAT(DISTINCT oi.item_type_code ORDER BY oi.item_type_code SEPARATOR ', ')
    FROM order_items oi
    WHERE oi.order_id = o.id
      AND oi.item_type_code IS NOT NULL
      AND oi.item_type_code <> ''
    ) AS item_types,

    EXISTS (
      SELECT 1 FROM order_items oi2
      WHERE oi2.order_id = o.id AND oi2.item_type_code IN ('T','M')
    ) AS has_tm,
(SELECT oa.employee_id
 FROM order_assignments oa
 WHERE oa.order_id = o.id
   AND oa.role = ?
   AND oa.removed_at IS NULL
 LIMIT 1) AS primary_emp_id,

(SELECT CONCAT(e.firstname,' ',e.lastname)
 FROM order_assignments oa
 JOIN employees e ON e.id = oa.employee_id
 WHERE oa.order_id = o.id
   AND oa.role = ?
   AND oa.removed_at IS NULL
 LIMIT 1) AS primary_emp_name

  FROM orders o
  JOIN order_sources os ON os.id = o.source_id
  LEFT JOIN customers cu ON cu.id = o.customer_id
  $whereSql
  ORDER BY o.id DESC
  LIMIT 500
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  echo '<div class="alert alert-danger">SQL prepare error: ' . htmlspecialchars($conn->error) . '</div>';
  return;
}
// Bind params in correct placeholder order:
// 1) role placeholders in SELECT (2x) come FIRST
// 2) then all WHERE params ($types/$params built above)
$roleVal = $rolePrimaryUI ? $rolePrimaryUI : '__NO_ROLE__';
$bindTypes = 'ss' . $types;
$bindParams = array_merge([$roleVal, $roleVal], $params);
$stmt->bind_param($bindTypes, ...$bindParams);

$stmt->execute();
$res = $stmt->get_result();

$deptOptions = [
  0 => 'Auto (By my department)',
  2 => 'Graphics',
  6 => 'Plastics',
  8 => 'Seat Covers',
  9 => 'Fitting',
];
?>
<style>
.tm-highlight { background: rgba(255,193,7,0.12) !important; }
.badge-type { font-size: 0.85rem; padding: .35em .55em; }
.order-detail-row td { padding: 0 !important; border-top: none !important; }
.detail-wrap { display:none; }
/* Detail table - force "air" */
.detail-wrap table.table-detail > thead > tr > th,
.detail-wrap table.table-detail > tbody > tr > td{
  padding: .75rem 1rem !important;
  line-height: 1.35 !important;
  vertical-align: middle !important;
}

/* trochu väčšie riadky aj vizuálne */
.detail-wrap table.table-detail > tbody > tr{
  height: 44px;
}

/* dark mode borders + head bg */
.dark-mode .detail-wrap table.table-detail{
  color: #e9ecef;
}

.dark-mode .detail-wrap table.table-detail th,
.dark-mode .detail-wrap table.table-detail td{
  border-color: rgba(255,255,255,.12) !important;
}

.dark-mode .detail-wrap table.table-detail thead th{
  background: rgba(255,255,255,.06) !important;
}
/* Väčšie badge iba v order detail */
.detail-wrap .badge{
  font-size: 1rem !important;      /* väčší text */
  padding: .55em .9em !important;  /* viac priestoru */
  border-radius: 10px;
  font-weight: 600;
}
.btn-order-action {
  min-width: 72px;
}
</style>

<div class="card card-dark">
  <div class="card-header">
    <h3 class="card-title">
      Orders
      <?php if ($dpt === 6): ?><span class="badge badge-warning ml-2">T/M highlighted</span><?php endif; ?>
    </h3>
  </div>

  <div class="card-body">

    <form method="get" class="mb-3">
      <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>"/>

      <div class="form-row">
        <div class="form-group col-md-3">
          <label>Department</label>
          <?php if ($allAccess): ?>
            <select class="form-control" name="dept">
              <?php foreach ($deptOptions as $k => $label): ?>
                <option value="<?= (int)$k ?>" <?= ($fDept === (int)$k ? 'selected' : '') ?>>
                  <?= htmlspecialchars($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input class="form-control" value="<?= htmlspecialchars((string)($_SESSION['dpt_name'] ?? ('dpt '.$dpt))) ?>" disabled />
          <?php endif; ?>
        </div>

        <div class="form-group col-md-3">
          <label>Category</label>
          <select class="form-control" name="cat">
            <option value="" <?= ($fCat===''?'selected':'') ?>>All</option>
            <?php foreach (['GRAPHICS','PLASTICS','SEATCOVER','FITTING'] as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>" <?= ($fCat===$c?'selected':'') ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group col-md-3">
          <label>Item Type</label>
          <select class="form-control" name="type">
            <option value="" <?= ($fType===''?'selected':'') ?>>All</option>
            <?php foreach (['G','T','M','P','S','F','(NULL)'] as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>" <?= ($fType===$t?'selected':'') ?>><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group col-md-3">
          <label>Search</label>
          <input class="form-control" name="q" value="<?= htmlspecialchars($fQ) ?>" placeholder="Order #, customer, email..." />
        </div>
      </div>

      <div class="d-flex">
        <button class="btn btn-primary mr-2" type="submit"><i class="fas fa-filter"></i> Filter</button>
        <a class="btn btn-secondary" href="index.php?page=orders"><i class="fas fa-times"></i> Reset</a>
      </div>
    </form>

    <div class="table-responsive">
      <table id="example1" class="table table-bordered table-hover table-sm">
        <thead>
          <tr>
            <th width="5%">Date</th>
            <th width="5%">Source</th>
            <th width="5%">Order #</th>            
            <th>Customer</th>
            
            <th>Status</th>
            <th>Category</th>
            <th>Types</th>
            <th>Detail</th>
          </tr>
        </thead>
        <tbody>
        <?php while ($row = $res->fetch_assoc()): ?>
          <?php
            $orderId = (int)$row['id'];
            $hasTM = (int)($row['has_tm'] ?? 0) === 1;
            $rowClass = ($dpt === 6 && $hasTM) ? 'tm-highlight' : '';
            $typesStr = (string)($row['item_types'] ?? '');
            $customer = trim((string)($row['customer_name'] ?? ''));
            if ($customer === '') $customer = (string)($row['customer_email'] ?? '-');
          ?>
          <tr class="<?= $rowClass ?>" data-order-id="<?= $orderId ?>">
          <td>
            <?php
            $dateRaw = $row['order_date'] ?? null;
            if (!empty($dateRaw)) {
                $dt = new DateTime($dateRaw);
                echo $dt->format('d.m.Y');
            } else {
                echo '—';
            }
            ?>
            </td>
            <td><?= htmlspecialchars((string)$row['source_code']) ?></td>
            <td>
              <div><b><?= htmlspecialchars((string)($row['order_number'] ?? $row['external_order_id'] ?? '')) ?></b></div>
            
              <?php if (!empty($row['external_order_id']) && $row['external_order_id'] !== $row['order_number']): ?>
                <small class="text-muted">Ext: <?= htmlspecialchars((string)$row['external_order_id']) ?></small>

              <?php endif; ?>
              
            </td>
            
            <td><?= htmlspecialchars($customer) ?></td>
            
            <td><?= htmlspecialchars((string)($row['status'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($row['categories'] ?? '')) ?></td>
            <td>
              <?php
                $types = array_filter(array_map('trim', explode(',', $typesStr)));
                if (!$types) $types = ['NULL'];
                foreach ($types as $t):
                  $tClean = strtoupper($t);
                  $badge = 'badge-secondary';
                  if (in_array($tClean, ['T','M'], true)) $badge = 'badge-warning';
                  elseif ($tClean === 'G') $badge = 'badge-info';
                  elseif ($tClean === 'P') $badge = 'badge-primary';
                  elseif ($tClean === 'S') $badge = 'badge-success';
                  elseif ($tClean === 'F') $badge = 'badge-danger';
              ?>
                <span class="badge <?= $badge ?> badge-type mr-1"><?= htmlspecialchars($tClean) ?></span>
              <?php endforeach; ?>
            </td>
            <td class="text-nowrap">
  <button type="button"
          class="btn btn-sm btn-outline-light btn-toggle-detail mr-1"
          data-order-id="<?= $orderId ?>">
    <i class="fas fa-search"></i>
  </button>

  <?php
    $primaryId = isset($row['primary_emp_id']) ? (int)$row['primary_emp_id'] : 0;
    $primaryName = (string)($row['primary_emp_name'] ?? '');
    $canUseDeptButtons = !empty($uiDeptCode);
  ?>

  <?php if ($canUseDeptButtons): ?>

    <?php if ($primaryId <= 0): ?>
      <button type="button"
              class="btn btn-sm btn-success btn-take-order"
              data-order-id="<?= $orderId ?>">
        TAKE
      </button>

    <?php else: ?>
      <?php if ($primaryId === $meUserId): ?>
        <span class="badge badge-warning mr-1 px-3 py-2" style="font-size:0.85rem;">
  MINE
</span>
      <?php else: ?>
        <span class="badge badge-warning mr-1">
          Taken<?= $primaryName ? ': '.htmlspecialchars($primaryName) : '' ?>
        </span>
      <?php endif; ?>

      <?php
        $canInvite = ($perm >= 400) || ($primaryId === $meUserId);
      ?>
      <button type="button"
              class="btn btn-sm btn-primary btn-invite-collab"
              data-order-id="<?= $orderId ?>"
              <?= $canInvite ? '' : 'disabled' ?>
              title="<?= $canInvite ? 'Invite collaborator' : 'Only owner or admin can invite' ?>">
        INVITE
      </button>

    <?php endif; ?>

  <?php endif; ?>
</td>
          </tr>

          <!-- Detail row (hidden, will be filled via AJAX) -->
          <tr class="order-detail-row">
            <td colspan="9">
              <div id="detail-<?= $orderId ?>" class="detail-wrap"></div>
            </td>
          </tr>

        <?php endwhile; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<script>
$(function () {

  function escapeHtml(s){
    return (''+s).replace(/[&<>"']/g, (m)=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
  }

  $('.btn-toggle-detail').on('click', function(){
    const orderId = $(this).data('order-id');
    const $wrap = $('#detail-' + orderId);

    // toggle if already loaded
    if ($wrap.data('loaded')) {
      $wrap.slideToggle(120);
      return;
    }

    $wrap.html('<div class="p-3 text-muted"><span class="spinner-border spinner-border-sm"></span> Načítavam detail…</div>');
    $wrap.show();

    $.ajax({
      url: 'scripts/orders/get_order_detail.php',
      method: 'POST',
      dataType: 'json',
      data: { order_id: orderId },
      success: function(resp){
        if (!resp || !resp.ok) {
          $wrap.html('<div class="p-3"><div class="alert alert-danger mb-0">Chyba: ' + escapeHtml(resp && resp.error ? resp.error : 'unknown') + '</div></div>');
          return;
        }
        $wrap.html(resp.html);
        $wrap.data('loaded', true);
      },
      error: function(xhr){
        $wrap.html('<div class="p-3"><div class="alert alert-danger mb-0">Chyba pri načítaní detailu</div></div>');
      }
    });
  });
});
// TAKE order
$(document).on('click', '.btn-take-order', function(){
  const orderId = $(this).data('order-id');
  const $btn = $(this);
  $btn.prop('disabled', true).text('...');

  $.ajax({
    url: 'scripts/orders/take_order.php',
    method: 'POST',
    dataType: 'json',
    data: { order_id: orderId },
    success: function(resp){
      if (!resp || !resp.ok) {
        alert('TAKE error: ' + (resp && resp.error ? resp.error : 'unknown'));
        $btn.prop('disabled', false).text('TAKE');
        return;
      }
      // najjednoduchšie: refresh page (aby sa načítali badges)
      location.reload();
    },
    error: function(){
      alert('TAKE error (request failed)');
      $btn.prop('disabled', false).text('TAKE');
    }
  });
});

// Open invite modal
$(document).on('click', '.btn-invite-collab', function(){
  const orderId = $(this).data('order-id');
  $('#inviteOrderId').val(orderId);
  $('#empSearch').val('');
  $('#empResults').html('');
  $('#inviteModal').modal('show');
});

// Debounced employee search
let empTimer = null;

$('#empSearch').on('input', function(){
  const q = $(this).val().trim();
  clearTimeout(empTimer);

  empTimer = setTimeout(function(){
    if (q.length < 2) {
      $('#empResults').html('');
      return;
    }

    $('#empResults').html('<div class="text-muted p-2"><span class="spinner-border spinner-border-sm"></span> Searching…</div>');

    $.ajax({
      url: 'scripts/employees/employees_search.php',
      method: 'GET',
      dataType: 'json',
      data: { q: q },
      success: function(resp){
        if (!resp || !resp.ok) {
          $('#empResults').html('<div class="text-danger p-2">Search error</div>');
          return;
        }

        const items = resp.items || [];
        if (!items.length) {
          $('#empResults').html('<div class="text-muted p-2">No results</div>');
          return;
        }

        let html = '';
        items.forEach(function(it){
          html += `
            <button type="button"
                    class="list-group-item list-group-item-action bg-dark text-light d-flex justify-content-between align-items-center btn-emp-pick"
                    data-emp-id="${it.id}">
              <span>${it.name}</span>
              <span class="btn btn-primary btn-sm">Invite</span>
            </button>
          `;
        });
        $('#empResults').html(html);
      },
      error: function(){
        $('#empResults').html('<div class="text-danger p-2">Search request failed</div>');
      }
    });

  }, 220);
});

// Click on employee -> invite
$(document).on('click', '.btn-emp-pick', function(){
  const empId = $(this).data('emp-id');
  const orderId = $('#inviteOrderId').val();

  $.ajax({
    url: 'scripts/orders/invite_collab.php',
    method: 'POST',
    dataType: 'json',
    data: { order_id: orderId, employee_id: empId },
    success: function(resp){
      if (!resp || !resp.ok) {
        alert('Invite error: ' + (resp && resp.error ? resp.error : 'unknown'));
        return;
      }
      $('#inviteModal').modal('hide');
      location.reload();
    },
    error: function(){
      alert('Invite error (request failed)');
    }
  });
});
</script>
<div class="modal fade" id="inviteModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content bg-dark">
      <div class="modal-header">
        <h5 class="modal-title">Invite collaborator</h5>
        <button type="button" class="close text-light" data-bs-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <input type="hidden" id="inviteOrderId" value="">

        <div class="form-group">
          <label>Search employee</label>
          <input type="text" class="form-control" id="empSearch" placeholder="Meno / priezvisko / username...">
          <small class="text-muted">Vyber zamestnanca a klikni Invite.</small>
        </div>

        <div id="empResults" class="list-group"></div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>