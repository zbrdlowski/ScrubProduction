<style>
  .profile-top-row { align-items: stretch; }
  .profile-top-row > [class*="col-"] { display:flex; flex-direction:column; }
  .profile-top-row .card { flex:1 1 auto; }
  /* Align attendance controls */
.attendance-controls .btn,
.attendance-controls .form-control {
    height: 30px;              /* same as input-sm */
    padding-top: 4px;
    padding-bottom: 4px;
    line-height: 1.2;
    vertical-align: middle;
}

.attendance-controls .btn i {
    vertical-align: middle;
}
/* Fix vertical alignment of text inside attendance nav buttons */
.attendance-controls .btn {
    height: 30px;                 /* same as input-sm */
    display: inline-flex;         /* modern vertical centering */
    align-items: center;          /* vertical center */
    justify-content: center;      /* horizontal center */
    padding-top: 0;
    padding-bottom: 0;
}

.attendance-controls .btn i {
    margin: 0 4px;
}
/* Detail row background (dark-mode friendly) */
  .order-details-cell{
    padding: 0 !important;
    border-top: 0 !important;
  }
  .order-details-panel{
    background: rgba(60, 141, 188, 0.15);          /* bluish */
    border: 1px solid rgba(60, 141, 188, 0.35);
    border-left: 4px solid rgba(60, 141, 188, 0.85);
    color: #eaeaea;
    border-radius: 6px;
    padding: 12px 14px;
    margin: 6px 0 10px 0;
    position: relative;
    overflow: hidden;
  }

  /* Small ribbon */
  .order-ribbon{
    position:absolute;
    top: 10px;
    right: -40px;
    transform: rotate(45deg);
    background: rgba(90, 90, 90, 0.9);             /* grey */
    color:#fff;
    padding: 6px 44px;
    font-size: 11px;
    letter-spacing: .3px;
    text-transform: uppercase;
    border: 1px solid rgba(255,255,255,.12);
  }

  .order-details-grid{
    display: flex;
    flex-wrap: wrap;
    gap: 10px 16px;
    margin-top: 8px;
  }
  .order-details-item{
    min-width: 220px;
    flex: 1 1 220px;
  }
  .order-details-label{
    font-size: 11px;
    opacity: .75;
    text-transform: uppercase;
    letter-spacing: .3px;
  }
  .order-details-value{
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  /* Pulse dot for "At Work" */
.pulse-dot {
  display:inline-block;
  width:10px;
  height:10px;
  border-radius:50%;
  margin-right:6px;
  vertical-align:middle;
  position:relative;
}
.pulse-dot::after{
  content:"";
  position:absolute;
  left:50%;
  top:50%;
  width:10px;
  height:10px;
  border-radius:50%;
  transform:translate(-50%,-50%);
  opacity:0.75;
  animation:pulseRing 1.2s ease-out infinite;
}
@keyframes pulseRing {
  0%   { transform:translate(-50%,-50%) scale(1);   opacity:0.7; }
  100% { transform:translate(-50%,-50%) scale(2.6); opacity:0;   }
}
</style>
<?php
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {

  // ✅ keď sa profile.php volá priamo cez AJAX, index.php sa nespustí -> treba session + conn
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
  if (!isset($conn)) {
    include __DIR__ . '/conn.php';  // includes/conn.php
  }

  $section = $_GET['section'] ?? '';

  switch ($section) {
    case 'attendance':
      include __DIR__ . '/personal_attendance.php';
      break;
    case 'orders':
      include __DIR__ . '/profile_orders.php';
      break;
    case 'online':
      include __DIR__ . '/profile_online_grid.php';
      break;
    default:
      include __DIR__ . '/personal_attendance.php';
      break;
  }
  exit;
}
?>
   <!-- Main content -->
<section class="content">
  <div class="container-fluid">

    <!-- TOP: USER BANNER (full width) -->
    <div class="row">
      <div class="col-12">
        <?php include 'includes/userbanner.php'; ?>
      </div>
    </div>

    <?php
      // which tab is active by default
      $activeTab = ($_GET['tab'] ?? 'attendance'); // attendance | orders | online
    ?>

    <!-- TABS + CONTENT (full width under banner) -->
    <div class="row">
      <div class="col-12">

        <div class="card">
          <div class="card-header p-2">
            <ul class="nav nav-pills">
              <li class="nav-item">
                <a class="nav-link <?php echo ($activeTab==='attendance') ? 'active' : ''; ?>"
                   href="?page=profile&tab=attendance">
                  Dochádzka
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link <?php echo ($activeTab==='orders') ? 'active' : ''; ?>"
                   href="?page=profile&tab=orders">
                  Orders
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link <?php echo ($activeTab==='online') ? 'active' : ''; ?>"
                   href="?page=profile&tab=online">
                  Online status
                </a>
              </li>
            </ul>
          </div>

          <div class="card-body">

            <?php if ($activeTab === 'attendance'): ?>

              <!-- ATTENDANCE (you will provide template later) -->
              <?php
  // Slovak month names (move to top so we can use them for parsing)
  $mesiace = [
    'januar'=>'Január','februar'=>'Február','marec'=>'Marec','april'=>'Apríl','maj'=>'Máj','jun'=>'Jún',
    'jul'=>'Júl','august'=>'August','september'=>'September','oktober'=>'Október','november'=>'November','december'=>'December'
  ];

  // Read selected month/year (fallback current)
$Year  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$monthInput = isset($_GET['month']) ? (string)$_GET['month'] : date('m'); // could be text or numeric

// Strip any Tempusdominus time suffix
$monthInput = preg_replace('/:.*/i', '', $monthInput);

// Convert text month name to number if needed
if (!ctype_digit($monthInput)) {
  // It's a text month name like "oktober" - convert to numeric
  $monthNum = array_search(strtolower($monthInput), array_map('strtolower', array_keys($mesiace)));
  $Month = ($monthNum !== false) ? str_pad((string)($monthNum + 1), 2, '0', STR_PAD_LEFT) : date('m');
} else {
  $Month = str_pad($monthInput, 2, '0', STR_PAD_LEFT); // numeric, ensure "01".."12"
}

// (debugging removed)

// Determine which option key should be selected in the month <select>
$selectedMonthKey = null;
// If we have a numeric month, map it to the corresponding key in $mesiace
if (ctype_digit($Month)) {
  $mIdx = (int)$Month - 1; // 0-based index
  $keys = array_keys($mesiace);
  if (isset($keys[$mIdx])) $selectedMonthKey = $keys[$mIdx];
}
// If original input was a textual month, prefer that key (normalized)
if (!ctype_digit($monthInput)) {
  $normalized = strtolower($monthInput);
  // find matching key case-insensitively
  foreach (array_keys($mesiace) as $k) {
    if (strtolower($k) === $normalized) { $selectedMonthKey = $k; break; }
  }
}

  // Prev/Next month calculation
  $cur = DateTime::createFromFormat('Y-m-d', $Year.'-'.$Month.'-01');
  $prev = (clone $cur)->modify('-1 month');
  $next = (clone $cur)->modify('+1 month');

  // Build available years from attendance tables (attdn_YYYY)
  $years = [];
  $yearsql = "SHOW TABLES";
  $yearquery = $conn->query($yearsql);
  if ($yearquery) {
    while ($yr = $yearquery->fetch_array()) {
      $t = $yr[0];
      if (strpos($t, 'attdn_') === 0) {
        $y = substr($t, -4);
        if (ctype_digit($y)) $years[] = $y;
      }
    }
  }
  $years = array_values(array_unique($years));
  rsort($years);
  if (empty($years)) $years = [date('Y')]; // fallback
?>

<div class="attendance-controls d-flex flex-wrap align-items-center"
     style="gap:8px; margin-bottom:10px;">

  <!-- Prev -->
  <a class="btn btn-primary btn-sm"
     href="?page=profile&tab=attendance&year=<?php echo $prev->format('Y'); ?>&month=<?php echo $prev->format('m'); ?>">
     <i class="fa fa-chevron-left"></i> Predošlý
  </a>

  <!-- Next -->
  <a class="btn btn-primary btn-sm"
     href="?page=profile&tab=attendance&year=<?php echo $next->format('Y'); ?>&month=<?php echo $next->format('m'); ?>">
    Nasledujúci <i class="fa fa-chevron-right"></i>
  </a>

  <!-- Month / Year -->
  <div style="margin:0; display:flex; gap:8px; align-items:center;">
    <select id="monthSelect" class="form-control input-sm" data-notp="true"
            style="width:140px;">      
      <?php foreach ($mesiace as $key => $label): ?>
        <option value="<?php echo $key; ?>" <?php echo (isset($selectedMonthKey) && $key===$selectedMonthKey)?'selected':''; ?>>
          <?php echo $label; ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select id="yearSelect" class="form-control input-sm" data-notp="true"
            style="width:95px;">
      <option value="">-- Rok --</option>
      <?php foreach ($years as $y): ?>
        <option value="<?php echo htmlspecialchars($y); ?>" <?php echo ((string)$y===(string)$Year)?'selected':''; ?>>
          <?php echo htmlspecialchars($y); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <script>
    document.getElementById('monthSelect').addEventListener('change', function(e) {
      e.stopPropagation();
      navigateToMonthYear();
    }, true);
    
    document.getElementById('yearSelect').addEventListener('change', function(e) {
      e.stopPropagation();
      navigateToMonthYear();
    }, true);
    
    function navigateToMonthYear() {
      var month = document.getElementById('monthSelect').value;
      var year = document.getElementById('yearSelect').value;
      
      // Strip Tempusdominus time suffix (e.g., "10:18" -> "10")
      month = month.replace(/:.*/g, '').trim();
      year = year.replace(/:.*/g, '').trim();
      
      if (month && year) {
        var url = '?page=profile&tab=attendance&year=' + encodeURIComponent(year) + '&month=' + encodeURIComponent(month);
        setTimeout(function() {
          window.location.href = url;
        }, 50);
      }
    }
  </script>

</div>

<?php // debug removed ?>

              <?php
                // later you will replace this include when ready
               include 'includes/personal_attendance.php';
              ?>

            <?php elseif ($activeTab === 'orders'): ?>

              <!-- ORDERS DATATABLE -->
              <?php
  $selectedOrder = $_GET['order_nr'] ?? '';

  switch ($_SESSION['dpt']) {
    case '2': $column = 'assign_g'; break;
    case '6': $column = 'assign_p'; break;
    case '8': $column = 'assign_s'; break;
    default:  $column = 'assign_g'; break;
  }

  $ordersql = "SELECT * FROM orders_$append
              WHERE ".$column." = '".$_SESSION['user_id']."'
              ORDER BY orders_$append.date ASC";

  $orderquery = $conn->query($ordersql);

  print '<table id="example3" class="table table-striped table-valign-middle">';
  print '<thead><tr>
          <th>Date</th>
          <th>Order No.</th>
          <th>Status</th>
          <th>Customer</th>
          <th>Country</th>
          <th>Order Type</th>
          <th>Description</th>
        </tr></thead><tbody>';

  while ($orderrow = $orderquery->fetch_array()) {

    $isSelected = ((string)$orderrow['order_nr'] === (string)$selectedOrder);
    $rowStyle = $isSelected ? ' style="background: rgba(90,90,90,0.25);"' : '';

    print '<tr'.$rowStyle.'>';
    print '<td>' . htmlspecialchars($orderrow['date']) . '</td>';

    print '<td>
            <a href="?page=profile&order_nr=' . urlencode($orderrow['order_nr']) . '&tab=orders">
              <button type="button" class="btn btn-block bg-gradient-info btn-sm">' . htmlspecialchars($orderrow['order_nr']) . '</button>
            </a>
          </td>';

    print '<td>' . htmlspecialchars($orderrow['status']) . '</td>';
    print '<td>' . htmlspecialchars($orderrow['customer']) . '</td>';
    print '<td>' . htmlspecialchars($orderrow['country']) . '</td>';
    print '<td>' . htmlspecialchars($orderrow['gfp']) . '</td>';
    print '<td>' . htmlspecialchars($orderrow['product_name']) . '</td>';
    print '</tr>';

    // ---- EXPANDED DETAILS ROW ----
    if ($isSelected) {

      // Example: show more fields from the same row
      // Add/remove fields based on your orders table columns
      $fields = [
        'Order No.'     => $orderrow['order_nr'] ?? '',
        'Date'          => $orderrow['date'] ?? '',
        'Status'        => $orderrow['status'] ?? '',
        'Customer'      => $orderrow['customer'] ?? '',
        'Country'       => $orderrow['country'] ?? '',
        'Order Type'    => $orderrow['gfp'] ?? '',
        'Description'   => $orderrow['product_name'] ?? '',
        // Add more if exist in your table:
        'Email'         => $orderrow['email'] ?? '',
        'Phone'         => $orderrow['phone'] ?? '',
        'Address'       => $orderrow['address'] ?? '',
        'Notes'         => $orderrow['notes'] ?? '',
      ];

      print '<tr>';
      print '<td colspan="7" class="order-details-cell">';
      print '  <div class="order-details-panel">';
      print '    <div class="order-ribbon">'.htmlspecialchars($orderrow['status']).'</div>';
      print '    <div style="font-size:14px; font-weight:700;">
                  <i class="fa fa-info-circle"></i> Order details
                </div>';

      print '    <div class="order-details-grid">';
      foreach ($fields as $label => $value) {
        if ($value === '' || $value === null) continue;
        print '      <div class="order-details-item">';
        print '        <div class="order-details-label">'.htmlspecialchars($label).'</div>';
        print '        <div class="order-details-value">'.htmlspecialchars((string)$value).'</div>';
        print '      </div>';
      }
      print '    </div>';

      print '  </div>';
      print '</td>';
      print '</tr>';
    }
  }

  print '</tbody></table>';
?>

            <?php else: ?>

            <!-- ONLINE STATUS GRID -->
            <div id="onlineGridContainer">
            <?php include 'includes/profile_online_grid.php'; ?>
          </div>

          <?php endif; ?>

          </div>

        </div>
      </div>
    </div>

  </div>
</section>
<script>
$(function () {

  const REFRESH_MS = 10000;

  function isOnlineTab() {
    const p = new URLSearchParams(window.location.search);
    return p.get('page') === 'profile' && p.get('tab') === 'online';
  }

  function refreshOnlineGrid() {
    if (!isOnlineTab()) return;

    // ✅ načítame iba grid HTML (bez wrapperu stránky)
    $("#onlineGridContainer").load(
      "includes/profile.php?ajax=1&section=online&_=" + Date.now()
    );
  }

  // initial + interval
  refreshOnlineGrid();
  setInterval(refreshOnlineGrid, REFRESH_MS);

});
</script>