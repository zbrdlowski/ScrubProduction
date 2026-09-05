<style>
  /* PAGE HELPERS */
  .employee-list-wrap {
    display: flex;
    flex-direction: column;
    gap: 15px;
  }

  .employee-photo-link {
    display: inline-block;
    border-radius: 50%;
  }

  .employee-photo-link:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(60, 141, 188, .35);
    border-radius: 50%;
  }

  .emp-actions {
    margin-top: 10px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  /* BLUE EMPLOYEE BANNER */
  .emp-banner {
    display: flex;
    gap: 16px;
    align-items: stretch;
    padding: 14px 16px;
    margin: 0;
    background: linear-gradient(135deg, #3c8dbc, #367fa9);
    color: #fff;
    border-radius: 6px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, .18);
  }

  .emp-left {
    display: flex;
    gap: 12px;
    align-items: center;
    flex: 0 0 320px;
    /* pevná šírka */
    width: 320px;
  }

  .emp-avatar {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255, 255, 255, .6);
    box-shadow:
      0 4px 10px rgba(0, 0, 0, .35),
      0 0 0 2px rgba(255, 255, 255, .15);
    transition: transform .25s ease, box-shadow .25s ease;
  }

  .emp-avatar:hover {
    transform: translateY(-4px) scale(1.03);
    box-shadow:
      0 10px 22px rgba(0, 0, 0, .45),
      0 0 0 3px rgba(255, 255, 255, .25);
  }

  .emp-main {
    min-width: 0;
  }

  .emp-name {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .emp-sub {
    font-size: 13px;
    opacity: .95;
    margin-top: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .emp-right {
    flex: 1 1 auto;
    display: flex;
    justify-content: flex-end;
  }

  .emp-infobox-wrap {
    width: 95%;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-content: flex-start;
  }

  .emp-infobox {
    flex: 1 1 220px;
    min-width: 220px;
    display: flex;
    align-items: center;
    border-radius: 8px;
    background: rgba(0, 0, 0, .28);
    border: 1px solid rgba(255, 255, 255, .18);
    box-shadow: 0 2px 8px rgba(0, 0, 0, .22);
    overflow: hidden;
  }

  .emp-infobox .emp-icon {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 12px;
    height: 70px;
  }

  .emp-infobox .emp-icon .icon-bubble {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .18);
  }

  .emp-infobox .emp-content {
    padding: 8px 12px 8px 2px;
    color: #fff;
    min-width: 0;
  }

  .emp-title {
    font-weight: 700;
    font-size: 11px;
    letter-spacing: .3px;
    text-transform: uppercase;
    opacity: .85;
  }

  .emp-value {
    font-weight: 600;
    font-size: 13px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .bubble-status-active {
    background: #00a65a;
  }

  .bubble-status-inactive {
    background: #dd4b39;
  }

  .bubble-worker-employee {
    background: #00a65a;
  }

  .bubble-worker-contractor {
    background: #f39c12;
  }

  .bubble-schedule {
    background: #3c8dbc;
  }

  .bubble-phone {
    background: #00a65a;
  }

  .bubble-address {
    background: #605ca8;
  }

  .bubble-date {
    background: #f39c12;
  }

  .bubble-role {
    background: #222d32;
  }

  @media(max-width: 768px) {
    .emp-banner {
      flex-direction: column;
    }

    .emp-left {
      min-width: 100%;
    }

    .emp-right {
      width: 100%;
    }

    .emp-infobox-wrap {
      width: 100%;
    }

    .emp-infobox {
      min-width: 100%;
    }
  }

  .bubble-user {
    background: #3c8dbc;
    /* modrá */
  }

  .bubble-moderator {
    background: #f39c12;
    /* oranžová */
  }

  .bubble-admin {
    background: #dd4b39;
    /* červená */
  }

  .bubble-god {
    background: linear-gradient(135deg, #ffd700, #ff8c00);
    /* gold */
  }

  .bubble-default {
    background: #6c757d;
    /* šedá */
  }
  .emp-name-row{
  display:flex;
  align-items:center;
  gap:10px;
  min-width:0;
}

.emp-name{
  flex:1 1 auto;
  min-width:0;
  font-size:18px;
  font-weight:700;
  margin:0;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}

.emp-age{
  flex:0 0 auto;
  font-size:12px;
  opacity:.95;
  white-space:nowrap;
}
</style>

<section class="content">
  <div class="container-fluid">
    <div class="row">
      <div class="col-12">

        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Zamestnanci</h3>
          </div>

          <div class="card-body">
            <?php
            if (isset($_SESSION['error'])) {
              echo "
                  <div class='alert alert-danger alert-dismissible'>
                    <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
                    <h4><i class='icon fa fa-warning'></i> Nie je to dobré!</h4>
                    " . $_SESSION['error'] . "
                  </div>
                ";
              unset($_SESSION['error']);
            }

            if (isset($_SESSION['success'])) {
              echo "
                  <div class='alert alert-success alert-dismissible'>
                    <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
                    <h4><i class='icon fa fa-check'></i> Podarilo sa!</h4>
                    " . $_SESSION['success'] . "
                  </div>
                ";
              unset($_SESSION['success']);
            }
            ?>

            <div class="box-header with-border" style="display:flex; align-items:center; flex-wrap:wrap; gap:8px;">
              <a href="#addnew" data-toggle="modal" class="btn btn-primary btn-sm btn-flat">
                <i class="fa fa-plus"></i> Pridaj
              </a>

              <div class="input-group input-group-sm" style="width:200px;">
                <span class="input-group-addon"
                  style="background:#3c8dbc; border-color:#3c8dbc; color:#fff; padding:0 12px; line-height:28px; text-align:center;">
                  <i class="fa fa-search"></i>
                </span>
                <input type="text" id="empInstantSearch" class="form-control" placeholder="Hľadaj zamestnanca…"
                  autocomplete="off" style="border-color:#3c8dbc;">
              </div>

              <?php
              $allowedEmployeeViews = ['employees', 'contractors', 'inactive', 'all'];
              $ActiveDisp = $_GET['activedisp'] ?? 'employees';
              if (!in_array($ActiveDisp, $allowedEmployeeViews, true)) {
                $ActiveDisp = 'employees';
              }

              $whereByView = [
                'employees' => "employees.active = 'Active' AND employees.worker_type = 'employee'",
                'contractors' => "employees.active = 'Active' AND employees.worker_type = 'contractor'",
                'inactive' => "employees.active = 'Inactive'",
                'all' => '1 = 1'
              ];

              $sql2 = "SELECT
                          employees.*,
                          employees.id AS empid,
                          position.description AS position_name,
                          schedules.time_in,
                          schedules.time_out
                       FROM employees
                       LEFT JOIN position ON position.id = employees.position_id
                       LEFT JOIN schedules ON schedules.id = employees.schedule_id
                       WHERE " . $whereByView[$ActiveDisp] . "
                       ORDER BY employees.lastname ASC";

              $employeeViews = [
                'employees' => ['icon' => 'fa-id-badge', 'label' => 'Aktívni zamestnanci'],
                'contractors' => ['icon' => 'fa-handshake-o', 'label' => 'Aktívni subdodávatelia'],
                'inactive' => ['icon' => 'fa-user-times', 'label' => 'Neaktívni'],
                'all' => ['icon' => 'fa-users', 'label' => 'Všetci pracovníci']
              ];

              $employeeCounts = ['employees' => 0, 'contractors' => 0, 'inactive' => 0, 'all' => 0];
              $countQuery = $conn->query("SELECT
                  SUM(active = 'Active' AND worker_type = 'employee') AS employees_count,
                  SUM(active = 'Active' AND worker_type = 'contractor') AS contractors_count,
                  SUM(active = 'Inactive') AS inactive_count,
                  COUNT(1) AS all_count
                FROM employees");
              if ($countQuery && ($countRow = $countQuery->fetch_assoc())) {
                $employeeCounts = [
                  'employees' => (int)$countRow['employees_count'],
                  'contractors' => (int)$countRow['contractors_count'],
                  'inactive' => (int)$countRow['inactive_count'],
                  'all' => (int)$countRow['all_count']
                ];
              }

              foreach ($employeeViews as $viewKey => $view) {
                $buttonType = ($ActiveDisp === $viewKey) ? 'warning' : 'default';
                echo '<a href="index.php?page=employee&amp;activedisp=' . $viewKey . '" class="btn btn-' . $buttonType . ' btn-sm btn-flat">';
                echo '<i class="fa ' . $view['icon'] . '"></i>&nbsp;&nbsp;' . $view['label'];
                echo ' <span class="badge">' . $employeeCounts[$viewKey] . '</span></a>';
              }
              ?>
            </div>

            <br>

            <div id="empNoResults"
              style="display:none; color:#fff; background:#3c8dbc; border-radius:6px; padding:14px 18px; font-size:15px;">
              <i class="fa fa-search"></i>&nbsp; Žiadny zamestnanec nezodpovedá hľadaniu.
            </div>

            <div class="employee-list-wrap">
              <?php
              $query = $conn->query($sql2);
              $employeeCount = 0;

              while ($row = $query->fetch_assoc()) {
                $employeeCount++;

                $gender = strtolower(trim($row['gender'] ?? ''));
                $isFemale = ($gender === 'female');

                $defaultPhoto = $isFemale ? 'images/female.png' : 'images/male.png';
                $photoFile = trim($row['photo'] ?? '');
                $photoPath = $defaultPhoto;

                if ($photoFile !== '' && file_exists(__DIR__ . '/../images/' . $photoFile)) {
                  $photoPath = 'images/' . $photoFile;
                }

                $empName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
                if ($empName === '') {
                  $empName = 'Neznámy zamestnanec';
                }

                $birthdate = $row['birthdate'] ?? null;
                $age = '';

                if (!empty($birthdate) && $birthdate != '0000-00-00') {
                  try {
                    $birth = new DateTime($birthdate);
                    $today = new DateTime();
                    $age = $today->diff($birth)->y;
                  } catch (Exception $e) {
                    $age = '';
                  }
                }
                $position = $row['position_name'] ?? '';
                $username = $row['username'] ?? '';
                $phone = $row['contact_info'] ?? '';
                $address = $row['address'] ?? '';
                $createdOn = $row['created_on'] ?? '';

                $schedule = '';
                if (!empty($row['time_in']) && !empty($row['time_out'])) {
                  $schedule = $row['time_in'] . ' - ' . $row['time_out'];
                }

                $isActive = (strcasecmp(trim($row['active'] ?? ''), 'Active') === 0);
                $workerType = (($row['worker_type'] ?? 'employee') === 'contractor') ? 'contractor' : 'employee';
                $workerTypeLabel = $workerType === 'contractor' ? 'Subdodávateľ' : 'Zamestnanec';
                $workerTypeClass = $workerType === 'contractor' ? 'bubble-worker-contractor' : 'bubble-worker-employee';
                $workerTypeIcon = $workerType === 'contractor' ? 'fa-handshake-o' : 'fa-id-badge';
                $statusActiveText = $isFemale ? 'Aktívna' : 'Aktívny';
                $statusInactiveText = $isFemale ? 'Neaktívna' : 'Neaktívny';
                $employedSinceLabel = $isFemale ? 'Zamestnaná od' : 'Zamestnaný od';

                switch ((int) ($row['permission'] ?? 0)) {
                  case 1:
                    $userpermitions = 'User';
                    $permClass = 'bubble-user';
                    $permIcon = 'fa-user';
                    break;

                  case 300:
                    $userpermitions = 'Moderator';
                    $permClass = 'bubble-moderator';
                    $permIcon = 'fa-user-shield';
                    break;

                  case 500:
                    $userpermitions = 'Administrator';
                    $permClass = 'bubble-admin';
                    $permIcon = 'fa-user-cog';
                    break;

                  case 900:
                    $userpermitions = 'Godlike';
                    $permClass = 'bubble-god';
                    $permIcon = 'fa-crown';
                    break;

                  default:
                    $userpermitions = '—';
                    $permClass = 'bubble-default';
                    $permIcon = 'fa-question';
                    break;
                }
                ?>
                <div class="emp-banner" data-search="<?php echo htmlspecialchars(
                  strtolower(implode(' ', array_filter([
                    $row['firstname'] ?? '',
                    $row['lastname'] ?? '',
                    $position,
                    $username,
                    $phone,
                    $address,
                    $userpermitions,
                    $workerTypeLabel
                  ])))
                  ,
                  ENT_QUOTES,
                  'UTF-8'
                ); ?>">
                  <div class="emp-left">
                    <a href="index.php?page=employee_edit&user-id=<?php echo (int) $row['empid']; ?>"
                      class="employee-photo-link" title="Upraviť zamestnanca">
                      <img class="emp-avatar" src="<?php echo htmlspecialchars($photoPath, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="<?php echo htmlspecialchars($empName, ENT_QUOTES, 'UTF-8'); ?>">
                    </a>

                    <div class="emp-main">
                      <div class="emp-name-row">
                        <p class="emp-name">
                          <?php echo htmlspecialchars($empName, ENT_QUOTES, 'UTF-8'); ?>
                        </p>

                        <?php if ($age !== ''): ?>
                          <span class="emp-age">
                            <i class="fa fa-birthday-cake"></i>&nbsp;&nbsp;<?php echo (int) $age; ?> r.
                          </span>
                        <?php endif; ?>
                      </div>

                      <p class="emp-sub">
                        <?php
                        $sub = [];
                        if ($position !== '')
                          $sub[] = $position;
                        if ($username !== '')
                          $sub[] = '@' . $username;
                        echo htmlspecialchars(implode(' • ', $sub), ENT_QUOTES, 'UTF-8');
                        ?>
                      </p>


                    </div>
                  </div>

                  <div class="emp-right">
                    <div class="emp-infobox-wrap">

                      <div class="emp-infobox">
                        <div class="emp-icon">
                          <div
                            class="icon-bubble <?php echo $isActive ? 'bubble-status-active' : 'bubble-status-inactive'; ?>">
                            <i class="fa fa-user"></i>
                          </div>
                        </div>
                        <div class="emp-content">
                          <div class="emp-title">Status</div>
                          <div class="emp-value">
                            <?php echo $isActive ? $statusActiveText : $statusInactiveText; ?>
                          </div>
                        </div>
                      </div>

                      <div class="emp-infobox">
                        <div class="emp-icon">
                          <div class="icon-bubble <?php echo $workerTypeClass; ?>">
                            <i class="fa <?php echo $workerTypeIcon; ?>"></i>
                          </div>
                        </div>
                        <div class="emp-content">
                          <div class="emp-title">Typ pracovníka</div>
                          <div class="emp-value"><?php echo $workerTypeLabel; ?></div>
                        </div>
                      </div>

                      <div class="emp-infobox">
                        <div class="emp-icon">
                          <div class="icon-bubble bubble-schedule">
                            <i class="fa fa-business-time"></i>
                          </div>
                        </div>
                        <div class="emp-content">
                          <div class="emp-title">Pracovná doba</div>
                          <div class="emp-value">
                            <?php echo $schedule !== '' ? htmlspecialchars($schedule, ENT_QUOTES, 'UTF-8') : '—'; ?>
                          </div>
                        </div>
                      </div>

                      <div class="emp-infobox">
                        <div class="emp-icon">
                          <div class="icon-bubble bubble-phone">
                            <i class="fa fa-phone"></i>
                          </div>
                        </div>
                        <div class="emp-content">
                          <div class="emp-title">Telefón</div>
                          <div class="emp-value">
                            <?php echo $phone !== '' ? htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') : '—'; ?>
                          </div>
                        </div>
                      </div>

                      <div class="emp-infobox">
                        <div class="emp-icon">
                          <div class="icon-bubble bubble-address">
                            <i class="fa fa-map-marker"></i>
                          </div>
                        </div>
                        <div class="emp-content">
                          <div class="emp-title">Adresa</div>
                          <div class="emp-value">
                            <?php echo $address !== '' ? htmlspecialchars($address, ENT_QUOTES, 'UTF-8') : '—'; ?>
                          </div>
                        </div>
                      </div>



                      <div class="emp-infobox">
                        <div class="emp-icon">
                          <div class="icon-bubble <?php echo $permClass; ?>">
                            <i class="fa <?php echo $permIcon; ?>"></i>
                          </div>
                        </div>
                        <div class="emp-content">
                          <div class="emp-title">Permission</div>
                          <div class="emp-value">
                            <?php echo htmlspecialchars($userpermitions, ENT_QUOTES, 'UTF-8'); ?>
                          </div>
                        </div>
                      </div>

                    </div>
                  </div>
                </div>
              <?php } ?>

              <?php if ($employeeCount === 0): ?>
                <div class="alert alert-info mb-0">
                  <i class="fa fa-info-circle"></i>
                  V tejto kategórii zatiaľ nie sú žiadni pracovníci.
                </div>
              <?php endif; ?>
            </div>

          </div>
        </div>

      </div>
    </div>
  </div>
</section>

<?php include 'includes/employee_modal.php'; ?>

<script>
  (function () {
    var input = document.getElementById('empInstantSearch');
    var noResult = document.getElementById('empNoResults');
    if (!input) return;

    input.addEventListener('input', function () {
      var q = this.value.toLowerCase().trim();
      var banners = document.querySelectorAll('.employee-list-wrap .emp-banner');
      var visible = 0;

      banners.forEach(function (el) {
        var haystack = el.getAttribute('data-search') || '';
        var show = (q === '' || haystack.indexOf(q) !== -1);
        el.style.display = show ? '' : 'none';
        if (show) visible++;
      });

      noResult.style.display = (visible === 0 && q !== '') ? 'block' : 'none';
    });
  })();
</script>
