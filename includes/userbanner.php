<style>
/* BLUE EMPLOYEE BANNER */
.emp-banner{
  display:flex;
  gap:16px;
  align-items:stretch;
  padding:14px 16px;
  margin: 0 0 15px 0;
  background: linear-gradient(135deg, #3c8dbc, #367fa9); /* AdminLTE info */
  color:#fff;
  border-radius:6px;
}

/* left block */
.emp-left{
  display:flex;
  gap:12px;
  align-items:center;
  flex: 0 0 auto;
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
  transition: 
      transform .25s ease,
      box-shadow .25s ease;
}

/* Hover lift effect */
.emp-avatar:hover{
  transform: translateY(-4px) scale(1.03);
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

/* RIGHT AREA takes most space */
.emp-right{
  flex: 1 1 auto;
  display:flex;
  justify-content:flex-end;
}

/* BOX STRIP ≈95% of banner width (of right area) */
.emp-infobox-wrap{
  width:95%;
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  align-content:flex-start;
}

/* Dark mode friendly box */
.emp-infobox{
  flex: 1 1 220px;                 /* grows to fill row */
  min-width: 220px;
  display:flex;
  align-items:center;
  border-radius:8px;
  background: rgba(0,0,0,.28);      /* darker, not bright */
  border: 1px solid rgba(255,255,255,.18);
  box-shadow:0 2px 8px rgba(0,0,0,.22); /* regular shadow */
  overflow:hidden;
}

/* Icon with padding & breathing room */
.emp-infobox .emp-icon{
  flex: 0 0 auto;
  display:flex;
  align-items:center;
  justify-content:center;
  padding: 0 12px;                 /* <-- space around icon */
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
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.18);
}

/* Text */
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

/* bubble colors (still visible on dark) */
.bubble-status-active{ background:#00a65a; }
.bubble-status-inactive{ background:#dd4b39; }
.bubble-schedule{ background:#3c8dbc; }
.bubble-phone{ background:#00a65a; }
.bubble-address{ background:#605ca8; }
.bubble-date{ background:#f39c12; }

/* responsive */
@media(max-width:768px){
  .emp-banner{ flex-direction:column; }
  .emp-right{ width:100%; }
  .emp-infobox-wrap{ width:100%; }
  .emp-infobox{ min-width: 100%; } /* stack full width on mobile */
}
.emp-banner.placeholder {
  opacity: .75;
  filter: grayscale(.15);
}
</style>

<?php
switch ($_GET['page'] ?? '') {
  case 'profile':
    $eno = $_SESSION['user_id'] ?? '';
    break;
    default:
    $eno = mysqli_real_escape_string($conn, $_GET['eno'] ?? '');
}


$urow = null;
if ($eno !== '') {
  $usersql = "SELECT 
      employees.*,
      employees.id AS emp_pk,
      employees.employee_id AS emp_code,
      position.description AS position_name,
      schedules.time_in AS sched_in,
      schedules.time_out AS sched_out
    FROM employees
    LEFT JOIN position ON position.id = employees.position_id
    LEFT JOIN schedules ON schedules.id = employees.schedule_id
    WHERE employees.id = '$eno'
    LIMIT 1";
  $uquery = $conn->query($usersql);
  $urow = $uquery ? $uquery->fetch_assoc() : null;
}

$isPlaceholder = (!$urow);

// --- Defaults (placeholder-safe) ---
$photoPath = 'images/male.png';
$empName   = 'Vyberte zamestnanca';
$empCode   = '';
$position  = 'Žiadne údaje';
$username  = '';
$phone     = '';
$address   = '';
$createdOn = '';
$schedule  = '';
$isActive  = false;
$isFemale = false; // default

// --- If employee exists, fill real data ---
if ($urow) {
  $photo  = trim($urow['photo'] ?? '');
  $gender = strtolower(trim($urow['gender'] ?? ''));
  $isFemale = ($gender === 'female');
  $defaultPhoto = ($gender === 'female') ? 'images/female.png' : 'images/male.png';

  $tmpPath = 'images/' . $photo;
  $tmpDisk = __DIR__ . '/../' . $tmpPath;

  if ($photo !== '' && file_exists($tmpDisk)) $photoPath = $tmpPath;
  else $photoPath = $defaultPhoto;

  $empName   = trim(($urow['firstname'] ?? '') . ' ' . ($urow['lastname'] ?? ''));
  if ($empName === '') $empName = 'Neznámy zamestnanec';

  $empCode   = $urow['emp_code'] ?? '';
  $position  = $urow['position_name'] ?? '';
  $username  = $urow['username'] ?? '';
  $phone     = $urow['contact_info'] ?? '';
  $address   = $urow['address'] ?? '';
  $createdOn = $urow['created_on'] ?? '';

  $activeValue = trim($urow['active'] ?? '');
  $isActive = (strcasecmp($activeValue, 'Active') === 0);

  $schedIn  = $urow['sched_in'] ?? '';
  $schedOut = $urow['sched_out'] ?? '';
  $schedule = ($schedIn && $schedOut) ? ($schedIn . ' - ' . $schedOut) : '';

  $statusActiveText   = $isFemale ? 'Aktívna' : 'Aktívny';
  $statusInactiveText = $isFemale ? 'Neaktívna' : 'Neaktívny';
  $employedSinceLabel = $isFemale ? 'Zamestnaná od' : 'Zamestnaný od';
}
?>

<div class="emp-banner <?php echo $isPlaceholder ? 'placeholder' : ''; ?>">
  <div class="emp-left">
    <img class="emp-avatar" src="<?php echo htmlspecialchars($photoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="photo">
    <div class="emp-main">
      <p class="emp-name">
        <?php echo htmlspecialchars($empName, ENT_QUOTES, 'UTF-8'); ?>
        <?php if ($empCode !== ''): ?>
          <span style="margin-left:10px; font-size:12px; opacity:.95;">
            <i class="fa fa-id-badge"></i>&nbsp;&nbsp;<?php echo htmlspecialchars($empCode, ENT_QUOTES, 'UTF-8'); ?>
          </span>
        <?php endif; ?>
      </p>

      <p class="emp-sub">
        <?php
          $sub = [];
          if (!empty($position)) $sub[] = $position;
          if (!empty($username)) $sub[] = '@' . $username;
          echo htmlspecialchars(implode(' • ', $sub), ENT_QUOTES, 'UTF-8');
        ?>
      </p>
    </div>
  </div>

  <div class="emp-right">
    <div class="emp-infobox-wrap">

      <!-- STATUS -->
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

      <!-- SMENA (always show, with placeholder) -->
      <div class="emp-infobox">
        <div class="emp-icon">
          <div class="icon-bubble bubble-schedule">
            <i class="fa fa-business-time"></i>
          </div>
        </div>
        <div class="emp-content">
          <div class="emp-title">Pracovná Doba</div>
          <div class="emp-value">
            <?php echo $schedule !== '' ? htmlspecialchars($schedule, ENT_QUOTES, 'UTF-8') : '—'; ?>
          </div>
        </div>
      </div>

      <!-- TELEFON -->
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

      <!-- ADRESA -->
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

      <!-- ZAMESTNANY OD -->
      <div class="emp-infobox">
        <div class="emp-icon">
          <div class="icon-bubble bubble-date">
            <i class="fa fa-calendar"></i>
          </div>
        </div>
        <div class="emp-content">
          <div class="emp-title"><?php echo $employedSinceLabel; ?></div>
          <div class="emp-value">
            <?php echo $createdOn !== '' ? htmlspecialchars(date('d.m.Y', strtotime($createdOn)), ENT_QUOTES, 'UTF-8') : '—'; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>