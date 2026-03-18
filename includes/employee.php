<style>
  /* PAGE HELPERS */
  .employee-list-wrap{
    display:flex;
    flex-direction:column;
    gap:15px;
  }

  .employee-photo-link{
    display:inline-block;
    border-radius:50%;
  }

  .employee-photo-link:focus{
    outline:none;
    box-shadow:0 0 0 3px rgba(60,141,188,.35);
    border-radius:50%;
  }

  .emp-actions{
    margin-top:10px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
  }

  /* BLUE EMPLOYEE BANNER */
  .emp-banner{
    display:flex;
    gap:16px;
    align-items:stretch;
    padding:14px 16px;
    margin:0;
    background: linear-gradient(135deg, #3c8dbc, #367fa9);
    color:#fff;
    border-radius:6px;
    box-shadow:0 2px 10px rgba(0,0,0,.18);
  }

.emp-left{
  display:flex;
  gap:12px;
  align-items:center;
  flex:0 0 320px; /* pevná šírka */
  width:320px;
}

  .emp-avatar{
    width:58px;
    height:58px;
    border-radius:50%;
    object-fit:cover;
    border:3px solid rgba(255,255,255,.6);
    box-shadow:
      0 4px 10px rgba(0,0,0,.35),
      0 0 0 2px rgba(255,255,255,.15);
    transition:transform .25s ease, box-shadow .25s ease;
  }

  .emp-avatar:hover{
    transform:translateY(-4px) scale(1.03);
    box-shadow:
      0 10px 22px rgba(0,0,0,.45),
      0 0 0 3px rgba(255,255,255,.25);
  }

  .emp-main{ min-width:0; }

  .emp-name{
    font-size:18px;
    font-weight:700;
    margin:0;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }

  .emp-sub{
    font-size:13px;
    opacity:.95;
    margin-top:3px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }

  .emp-right{
    flex:1 1 auto;
    display:flex;
    justify-content:flex-end;
  }

  .emp-infobox-wrap{
    width:95%;
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-content:flex-start;
  }

  .emp-infobox{
    flex:1 1 220px;
    min-width:220px;
    display:flex;
    align-items:center;
    border-radius:8px;
    background:rgba(0,0,0,.28);
    border:1px solid rgba(255,255,255,.18);
    box-shadow:0 2px 8px rgba(0,0,0,.22);
    overflow:hidden;
  }

  .emp-infobox .emp-icon{
    flex:0 0 auto;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:0 12px;
    height:70px;
  }

  .emp-infobox .emp-icon .icon-bubble{
    width:34px;
    height:34px;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.18);
  }

  .emp-infobox .emp-content{
    padding:8px 12px 8px 2px;
    color:#fff;
    min-width:0;
  }

  .emp-title{
    font-weight:700;
    font-size:11px;
    letter-spacing:.3px;
    text-transform:uppercase;
    opacity:.85;
  }

  .emp-value{
    font-weight:600;
    font-size:13px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }

  .bubble-status-active{ background:#00a65a; }
  .bubble-status-inactive{ background:#dd4b39; }
  .bubble-schedule{ background:#3c8dbc; }
  .bubble-phone{ background:#00a65a; }
  .bubble-address{ background:#605ca8; }
  .bubble-date{ background:#f39c12; }
  .bubble-role{ background:#222d32; }

  @media(max-width: 768px){
    .emp-banner{ flex-direction:column; }
    .emp-left{ min-width:100%; }
    .emp-right{ width:100%; }
    .emp-infobox-wrap{ width:100%; }
    .emp-infobox{ min-width:100%; }
  }
  .bubble-user{
  background:#3c8dbc; /* modrá */
}

.bubble-moderator{
  background:#f39c12; /* oranžová */
}

.bubble-admin{
  background:#dd4b39; /* červená */
}

.bubble-god{
  background:linear-gradient(135deg,#ffd700,#ff8c00); /* gold */
}

.bubble-default{
  background:#6c757d; /* šedá */
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
              if(isset($_SESSION['error'])){
                echo "
                  <div class='alert alert-danger alert-dismissible'>
                    <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
                    <h4><i class='icon fa fa-warning'></i> Nie je to dobré!</h4>
                    ".$_SESSION['error']."
                  </div>
                ";
                unset($_SESSION['error']);
              }

              if(isset($_SESSION['success'])){
                echo "
                  <div class='alert alert-success alert-dismissible'>
                    <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
                    <h4><i class='icon fa fa-check'></i> Podarilo sa!</h4>
                    ".$_SESSION['success']."
                  </div>
                ";
                unset($_SESSION['success']);
              }
            ?>

            <div class="box-header with-border">
              <a href="#addnew" data-toggle="modal" class="btn btn-primary btn-sm btn-flat">
                <i class="fa fa-plus"></i> Pridaj
              </a>

              <?php
                if(empty($_GET['activedisp'])){
                  $ActiveDisp = 'active';
                } else {
                  $ActiveDisp = $_GET['activedisp'];
                }

                switch($ActiveDisp){
                  case 'all':
                    $sql2 = "SELECT 
                                employees.*, 
                                employees.id AS empid,
                                position.description AS position_name,
                                schedules.time_in,
                                schedules.time_out
                             FROM employees
                             LEFT JOIN position ON position.id = employees.position_id
                             LEFT JOIN schedules ON schedules.id = employees.schedule_id
                             ORDER BY employees.lastname ASC";
                    $allgombik = 'warning';
                    $activegombik = 'default';
                    $inactivegombik = 'default';
                  break;

                  case 'inactive':
                    $sql2 = "SELECT 
                                employees.*, 
                                employees.id AS empid,
                                position.description AS position_name,
                                schedules.time_in,
                                schedules.time_out
                             FROM employees
                             LEFT JOIN position ON position.id = employees.position_id
                             LEFT JOIN schedules ON schedules.id = employees.schedule_id
                             WHERE employees.active = 'Inactive'
                             ORDER BY employees.lastname ASC";
                    $allgombik = 'default';
                    $activegombik = 'default';
                    $inactivegombik = 'warning';
                  break;

                  case 'active':
                  default:
                    $sql2 = "SELECT 
                                employees.*, 
                                employees.id AS empid,
                                position.description AS position_name,
                                schedules.time_in,
                                schedules.time_out
                             FROM employees
                             LEFT JOIN position ON position.id = employees.position_id
                             LEFT JOIN schedules ON schedules.id = employees.schedule_id
                             WHERE employees.active = 'Active'
                             ORDER BY employees.lastname ASC";
                    $allgombik = 'default';
                    $activegombik = 'warning';
                    $inactivegombik = 'default';
                  break;
                }

                echo '&nbsp;&nbsp;';
                echo '<a href="index.php?page=employee&activedisp=active" class="btn btn-'.$activegombik.' btn-sm btn-flat"><span class="glyphicon glyphicon-user"></span>&nbsp;&nbsp;Aktívny zamestnanci</a>';
                echo '&nbsp;&nbsp;';
                echo '<a href="index.php?page=employee&activedisp=inactive" class="btn btn-'.$inactivegombik.' btn-sm btn-flat"><span class="glyphicon glyphicon-user"></span>&nbsp;&nbsp;Neaktívny zamestnanci</a>';
                echo '&nbsp;&nbsp;';
                echo '<a href="index.php?page=employee&activedisp=all" class="btn btn-'.$allgombik.' btn-sm btn-flat"><span class="glyphicon glyphicon-user"></span>&nbsp;&nbsp;Všetci zamestnanci</a>';
              ?>
            </div>

            <br>

            <div class="employee-list-wrap">
              <?php
                $query = $conn->query($sql2);

                while($row = $query->fetch_assoc()){

                  $gender = strtolower(trim($row['gender'] ?? ''));
                  $isFemale = ($gender === 'female');

                  $defaultPhoto = $isFemale ? 'images/female.png' : 'images/male.png';
                  $photoFile = trim($row['photo'] ?? '');
                  $photoPath = $defaultPhoto;

                  if($photoFile !== '' && file_exists(__DIR__ . '/../images/' . $photoFile)){
                    $photoPath = 'images/' . $photoFile;
                  }

                  $empName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
                  if($empName === ''){
                    $empName = 'Neznámy zamestnanec';
                  }

                  $birthdate = $row['birthdate'] ?? null;
                  $age = '';

                  if(!empty($birthdate) && $birthdate != '0000-00-00'){
                      try {
                          $birth = new DateTime($birthdate);
                          $today = new DateTime();
                          $age = $today->diff($birth)->y;
                      } catch(Exception $e){
                          $age = '';
                      }
                  }
                  $position = $row['position_name'] ?? '';
                  $username = $row['username'] ?? '';
                  $phone = $row['contact_info'] ?? '';
                  $address = $row['address'] ?? '';
                  $createdOn = $row['created_on'] ?? '';

                  $schedule = '';
                  if(!empty($row['time_in']) && !empty($row['time_out'])){
                    $schedule = $row['time_in'].' - '.$row['time_out'];
                  }

                  $isActive = (strcasecmp(trim($row['active'] ?? ''), 'Active') === 0);
                  $statusActiveText = $isFemale ? 'Aktívna' : 'Aktívny';
                  $statusInactiveText = $isFemale ? 'Neaktívna' : 'Neaktívny';
                  $employedSinceLabel = $isFemale ? 'Zamestnaná od' : 'Zamestnaný od';

                  switch((int)($row['permission'] ?? 0)){
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
                <div class="emp-banner">
                  <div class="emp-left">
                    <a
                      href="index.php?page=employee_edit&user-id=<?php echo (int)$row['empid']; ?>"
                      class="employee-photo-link"
                      title="Upraviť zamestnanca"
                    >
                      <img
                        class="emp-avatar"
                        src="<?php echo htmlspecialchars($photoPath, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="<?php echo htmlspecialchars($empName, ENT_QUOTES, 'UTF-8'); ?>"
                      >
                    </a>

                    <div class="emp-main">
                      <p class="emp-name">
                        <?php echo htmlspecialchars($empName, ENT_QUOTES, 'UTF-8'); ?>
                        <?php if($age !== ''): ?>
                          <span style="margin-left:10px; font-size:12px; opacity:.95;">
                            <i class="fa fa-birthday-cake"></i>&nbsp;&nbsp;<?php echo (int)$age; ?> r.
                          </span>
                        <?php endif; ?>
                      </p>

                      <p class="emp-sub">
                        <?php
                          $sub = [];
                          if($position !== '') $sub[] = $position;
                          if($username !== '') $sub[] = '@' . $username;
                          echo htmlspecialchars(implode(' • ', $sub), ENT_QUOTES, 'UTF-8');
                        ?>
                      </p>

                      
                    </div>
                  </div>

                  <div class="emp-right">
                    <div class="emp-infobox-wrap">

                      <div class="emp-infobox">
                        <div class="emp-icon">
                          <div class="icon-bubble <?php echo $isActive ? 'bubble-status-active' : 'bubble-status-inactive'; ?>">
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
            </div>

          </div>
        </div>

      </div>
    </div>
  </div>
</section>

<?php include 'includes/employee_modal.php'; ?>