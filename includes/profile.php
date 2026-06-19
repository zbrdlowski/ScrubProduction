<style>
  .profile-top-row {
    align-items: stretch;
  }

  .profile-top-row>[class*="col-"] {
    display: flex;
    flex-direction: column;
  }

  .profile-top-row .card {
    flex: 1 1 auto;
  }

  /* Zarovnanie ovládacích prvkov dochádzky */
  .attendance-controls .btn,
  .attendance-controls .form-control {
    height: 30px;
    /* rovnaké ako input-sm */
    padding-top: 4px;
    padding-bottom: 4px;
    line-height: 1.2;
    vertical-align: middle;
  }

  .attendance-controls .btn i {
    vertical-align: middle;
  }

  /* Oprav vertikálne zarovnanie textu v tlačidlách navigácie dochádzky */
  .attendance-controls .btn {
    height: 30px;
    /* rovnaké ako input-sm */
    display: inline-flex;
    /* moderné vertikálne centrovanie */
    align-items: center;
    /* vertikálne vycentrované */
    justify-content: center;
    /* horizontálne vycentrované */
    padding-top: 0;
    padding-bottom: 0;
  }

  .attendance-controls .btn i {
    margin: 0 4px;
  }

  /* Pozadie detailného riadku (vhodné pre tmavý režim) */
  .order-details-cell {
    padding: 0 !important;
    border-top: 0 !important;
  }

  .order-details-panel {
    background: rgba(60, 141, 188, 0.15);
    /* modrasté */
    border: 1px solid rgba(60, 141, 188, 0.35);
    border-left: 4px solid rgba(60, 141, 188, 0.85);
    color: #eaeaea;
    border-radius: 6px;
    padding: 12px 14px;
    margin: 6px 0 10px 0;
    position: relative;
    overflow: hidden;
  }

  /* Malá stužka */
  .order-ribbon {
    position: absolute;
    top: 10px;
    right: -40px;
    transform: rotate(45deg);
    background: rgba(90, 90, 90, 0.9);
    /* sivé */
    color: #fff;
    padding: 6px 44px;
    font-size: 11px;
    letter-spacing: .3px;
    text-transform: uppercase;
    border: 1px solid rgba(255, 255, 255, .12);
  }

  .order-details-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 16px;
    margin-top: 8px;
  }

  .order-details-item {
    min-width: 220px;
    flex: 1 1 220px;
  }

  .order-details-label {
    font-size: 11px;
    opacity: .75;
    text-transform: uppercase;
    letter-spacing: .3px;
  }

  .order-details-value {
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* Pulzná bodka pre "V práci" */
  .pulse-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 6px;
    vertical-align: middle;
    position: relative;
  }

  .pulse-dot::after {
    content: "";
    position: absolute;
    left: 50%;
    top: 50%;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    transform: translate(-50%, -50%);
    opacity: 0.75;
    animation: pulseRing 1.2s ease-out infinite;
  }

  @keyframes pulseRing {
    0% {
      transform: translate(-50%, -50%) scale(1);
      opacity: 0.7;
    }

    100% {
      transform: translate(-50%, -50%) scale(2.6);
      opacity: 0;
    }
  }
</style>
<?php
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {

  // ✅ keď sa profile.php volá priamo cez AJAX, index.php sa nespustí -> treba session + conn
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
  if (!isset($conn)) {
    include __DIR__ . '/conn.php';  // súbor includes/conn.php
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
    case 'projects':
      include __DIR__ . '/profile_projects.php';
      break;
  }
  exit;
}
?>
<!-- Hlavný obsah -->
<section class="content">
  <div class="container-fluid">

    <!-- HORNÁ ČASŤ: UŽÍVATEĽSKÝ BANNER (celá šírka) -->
    <div class="row">
      <div class="col-12">
        <?php include 'includes/userbanner.php'; ?>
      </div>
    </div>

    <?php
    // ktorý panel je predvolene aktívny
    $activeTab = ($_GET['tab'] ?? 'attendance'); // možné hodnoty: attendance | orders | online
    $showPersonalOrders = !empty($_SESSION['personal_orders']);
    $profileProjectCount = 0;
    $profileUserId = intval($_SESSION['user_id'] ?? 0);

    $profileOrdersCount = 0;

    if ($profileUserId > 0) {
      $stmt = $conn->prepare("
    SELECT COUNT(DISTINCT p.id) AS cnt
    FROM projects p
    LEFT JOIN project_tasks pt ON pt.project_id = p.id
    WHERE p.status NOT IN ('done','cancelled')
      AND (p.assigned_to = ? OR pt.assigned_to = ?)
  ");
      if ($stmt) {
        $stmt->bind_param('ii', $profileUserId, $profileUserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $profileProjectCount = intval($row['cnt'] ?? 0);
        $stmt->close();
      }

      $stmt = $conn->prepare("
  SELECT COUNT(DISTINCT o.id) AS cnt
  FROM orders o
  JOIN order_assignments oa ON oa.order_id = o.id
  WHERE oa.employee_id = ?
    AND oa.removed_at IS NULL
    AND UPPER(o.status) != 'SHIPPED'
");

      if ($stmt) {
        $stmt->bind_param('i', $profileUserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $profileOrdersCount = intval($row['cnt'] ?? 0);
        $stmt->close();
      }
    }

    if ($activeTab === 'projects' && $profileProjectCount <= 0) {
      $activeTab = 'attendance';
    }
    ?>

    <!-- ZÁLOŽKY + OBSAH (celá šírka pod bannerom) -->
    <div class="row">
      <div class="col-12">

        <div class="card">
          <div class="card-header p-2">
            <ul class="nav nav-pills">
              <li class="nav-item">
                <a class="nav-link <?php echo ($activeTab === 'attendance') ? 'active' : ''; ?>"
                  href="?page=profile&tab=attendance">
                  Dochádzka
                </a>
              </li>
              <?php if ($showPersonalOrders): ?>
                <li class="nav-item">
                  <a class="nav-link <?php echo ($activeTab === 'orders') ? 'active' : ''; ?>"
                    href="?page=profile&tab=orders">
                    Orders
                    <?php if ($profileOrdersCount > 0): ?>
                      <span class="badge badge-warning ml-1">
                        <?php echo $profileOrdersCount; ?>
                      </span>
                    <?php endif; ?>
                  </a>
                </li>
              <?php endif; ?>
              <?php if ($profileProjectCount > 0): ?>
                <li class="nav-item">
                  <a class="nav-link <?php echo ($activeTab === 'projects') ? 'active' : ''; ?>"
                    href="?page=profile&tab=projects">
                    Projects
                    <span class="badge badge-warning ml-1">
                      <?php echo $profileProjectCount; ?>
                    </span>
                  </a>
                </li>
              <?php endif; ?>
              <li class="nav-item">
                <a class="nav-link <?php echo ($activeTab === 'online') ? 'active' : ''; ?>"
                  href="?page=profile&tab=online">
                  Online status
                </a>
              </li>
            </ul>
          </div>

          <div class="card-body">

            <?php if ($activeTab === 'attendance'): ?>

              <!-- DOCHÁDZKA (šablónu doplníte neskôr) -->
              <?php
              // Slovenské názvy mesiacov (presunúť hore, aby sme ich mohli použiť pri parsovaní)
              $mesiace = [
                'januar' => 'Január',
                'februar' => 'Február',
                'marec' => 'Marec',
                'april' => 'Apríl',
                'maj' => 'Máj',
                'jun' => 'Jún',
                'jul' => 'Júl',
                'august' => 'August',
                'september' => 'September',
                'oktober' => 'Október',
                'november' => 'November',
                'december' => 'December'
              ];

              // Načítať vybraný mesiac/rok (ak nie, použiť aktuálny)
              $Year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
              $monthInput = isset($_GET['month']) ? (string) $_GET['month'] : date('m'); // môže byť text alebo číslo
            
              // Odstrániť prípadnú časovú príponu Tempusdominus
              $monthInput = preg_replace('/:.*/i', '', $monthInput);

              // Prevod textového názvu mesiaca na číslo, ak je to potrebné
              if (!ctype_digit($monthInput)) {
                // Je to textový názov mesiaca ako "oktober" - prekonvertovať na číslo
                $monthNum = array_search(strtolower($monthInput), array_map('strtolower', array_keys($mesiace)));
                $Month = ($monthNum !== false) ? str_pad((string) ($monthNum + 1), 2, '0', STR_PAD_LEFT) : date('m');
              } else {
                $Month = str_pad($monthInput, 2, '0', STR_PAD_LEFT); // číslo, zabezpečiť formát "01".."12"
              }

              // (ladenie odstránené)
            
              // Určiť, ktorý kľúč bude vybraný v month <select>
              $selectedMonthKey = null;
              // Ak máme číselný mesiac, namapovať ho na zodpovedajúci kľúč v $mesiace
              if (ctype_digit($Month)) {
                $mIdx = (int) $Month - 1; // index s nulovou základňou
                $keys = array_keys($mesiace);
                if (isset($keys[$mIdx]))
                  $selectedMonthKey = $keys[$mIdx];
              }
              // Ak bol pôvodný vstup textový názov mesiaca, uprednostniť tento kľúč (normalizovaný)
              if (!ctype_digit($monthInput)) {
                $normalized = strtolower($monthInput);
                // nájsť zodpovedajúci kľúč bez ohľadu na veľkosť písmen
                foreach (array_keys($mesiace) as $k) {
                  if (strtolower($k) === $normalized) {
                    $selectedMonthKey = $k;
                    break;
                  }
                }
              }

              // Výpočet predchádzajúceho/následujúceho mesiaca
              $cur = DateTime::createFromFormat('Y-m-d', $Year . '-' . $Month . '-01');
              $prev = (clone $cur)->modify('-1 month');
              $next = (clone $cur)->modify('+1 month');

              // Zostaviť dostupné roky z tabuliek dochádzky (attdn_YYYY)
              $years = [];
              $yearsql = "SHOW TABLES";
              $yearquery = $conn->query($yearsql);
              if ($yearquery) {
                while ($yr = $yearquery->fetch_array()) {
                  $t = $yr[0];
                  if (strpos($t, 'attdn_') === 0) {
                    $y = substr($t, -4);
                    if (ctype_digit($y))
                      $years[] = $y;
                  }
                }
              }
              $years = array_values(array_unique($years));
              rsort($years);
              if (empty($years))
                $years = [date('Y')]; // záloha
              ?>

              <div class="attendance-controls d-flex flex-wrap align-items-center" style="gap:8px; margin-bottom:10px;">

                <!-- Predošlý -->
                <a class="btn btn-primary btn-sm"
                  href="?page=profile&tab=attendance&year=<?php echo $prev->format('Y'); ?>&month=<?php echo $prev->format('m'); ?>">
                  <i class="fa fa-chevron-left"></i> Predošlý
                </a>

                <!-- Nasledujúci -->
                <a class="btn btn-primary btn-sm"
                  href="?page=profile&tab=attendance&year=<?php echo $next->format('Y'); ?>&month=<?php echo $next->format('m'); ?>">
                  Nasledujúci <i class="fa fa-chevron-right"></i>
                </a>

                <!-- Mesiac / Rok -->
                <div style="margin:0; display:flex; gap:8px; align-items:center;">
                  <select id="monthSelect" class="form-control input-sm" data-notp="true" style="width:140px;">
                    <?php foreach ($mesiace as $key => $label): ?>
                      <option value="<?php echo $key; ?>" <?php echo (isset($selectedMonthKey) && $key === $selectedMonthKey) ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <select id="yearSelect" class="form-control input-sm" data-notp="true" style="width:95px;">
                    <option value="">-- Rok --</option>
                    <?php foreach ($years as $y): ?>
                      <option value="<?php echo htmlspecialchars($y); ?>" <?php echo ((string) $y === (string) $Year) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($y); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <script>
                  document.getElementById('monthSelect').addEventListener('change', function (e) {
                    e.stopPropagation();
                    navigateToMonthYear();
                  }, true);

                  document.getElementById('yearSelect').addEventListener('change', function (e) {
                    e.stopPropagation();
                    navigateToMonthYear();
                  }, true);

                  function navigateToMonthYear() {
                    var month = document.getElementById('monthSelect').value;
                    var year = document.getElementById('yearSelect').value;

                    // Odstrániť časovú príponu Tempusdominus (napr. "10:18" -> "10")
                    month = month.replace(/:.*/g, '').trim();
                    year = year.replace(/:.*/g, '').trim();

                    if (month && year) {
                      var url = '?page=profile&tab=attendance&year=' + encodeURIComponent(year) + '&month=' + encodeURIComponent(month);
                      setTimeout(function () {
                        window.location.href = url;
                      }, 50);
                    }
                  }
                </script>

              </div>

              <?php // ladenie odstránené ?>

              <?php
              // neskôr toto include nahradiť, keď to bude pripravené
              include 'includes/personal_attendance.php';
              ?>

            <?php elseif ($activeTab === 'orders'): ?>

              <div id="profileOrdersContainer" class="detail-wrap">
                <?php include 'includes/profile_orders.php'; ?>
              </div>
            <?php elseif ($activeTab === 'projects'): ?>
              <div id="profileProjectsContainer" class="detail-wrap">
                <?php include 'includes/profile_projects.php'; ?>
              </div>

            <?php else: ?>

              <!-- ONLINE STAV (mriežka) -->
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

      // ✅ načítame iba HTML mriežky (bez obalu stránky)
      $("#onlineGridContainer").load(
        "includes/profile.php?ajax=1&section=online&_=" + Date.now()
      );
    }

    // počiatočné načítanie + interval
    refreshOnlineGrid();
    setInterval(refreshOnlineGrid, REFRESH_MS);

  });
</script>