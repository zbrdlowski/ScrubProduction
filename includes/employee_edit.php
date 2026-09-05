<style>
.employee-edit-form {
  background: #2f343a;
  color: #e9ecef;
  border-radius: 16px;
}

.employee-edit-form .section-card {
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.10);
  border-radius: 14px;
  padding: 14px;
  margin-bottom: 14px;
}

.employee-edit-form .section-title {
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .4px;
  margin-bottom: 12px;
}

.employee-edit-form label {
  font-size: 12px;
  color: #adb5bd;
  margin-bottom: 4px;
}

.employee-edit-form .form-control {
  background: rgba(0,0,0,.20);
  border-color: rgba(255,255,255,.18);
  color: #fff;
}

.employee-edit-form .form-control:focus {
  background: rgba(0,0,0,.30);
  color: #fff;
  border-color: #17a2b8;
  box-shadow: 0 0 0 .1rem rgba(23,162,184,.25);
}

.employee-switch-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 10px;
}

.employee-switch-card {
  background: rgba(0,0,0,.18);
  border: 1px solid rgba(255,255,255,.10);
  border-radius: 12px;
  padding: 10px 12px;
}

.employee-switch-card small {
  display: block;
  color: #adb5bd;
  line-height: 1.2;
  margin-top: 3px;
}

.employee-summary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.attendance-summary-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.attendance-summary-head .section-title {
  margin-bottom: 0;
}

.attendance-period-controls {
  display: flex;
  align-items: center;
  gap: 6px;
}

.attendance-period-controls .form-control {
  width: auto;
  min-width: 92px;
  height: 30px;
  padding: 3px 8px;
  font-size: 12px;
}

.attendance-period-controls .attendance-month-select {
  min-width: 112px;
}

.attendance-summary-note {
  display: none;
  margin-top: 9px;
  color: #adb5bd;
  font-size: 11px;
}

.attendance-summary-card.is-loading .employee-summary-grid {
  opacity: .45;
  pointer-events: none;
}

.employee-summary-item {
  display: flex;
  align-items: center;
  gap: 11px;
  min-height: 72px;
  padding: 11px 12px;
  background: rgba(0,0,0,.18);
  border: 1px solid rgba(255,255,255,.10);
  border-left: 3px solid #6c757d;
  border-radius: 12px;
  transition: border-color .2s ease, background-color .2s ease;
}

.employee-summary-icon {
  width: 34px;
  height: 34px;
  flex: 0 0 34px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: rgba(108,117,125,.22);
  color: #adb5bd;
}

.employee-summary-label {
  display: block;
  color: #adb5bd;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .35px;
  text-transform: uppercase;
}

.employee-summary-value {
  display: block;
  margin-top: 2px;
  color: #fff;
  font-size: 13px;
  line-height: 1.2;
}

.employee-summary-item.is-success { border-left-color: #28a745; }
.employee-summary-item.is-success .employee-summary-icon { background: rgba(40,167,69,.22); color: #55d477; }
.employee-summary-item.is-warning { border-left-color: #f39c12; }
.employee-summary-item.is-warning .employee-summary-icon { background: rgba(243,156,18,.22); color: #ffc04d; }
.employee-summary-item.is-info { border-left-color: #17a2b8; }
.employee-summary-item.is-info .employee-summary-icon { background: rgba(23,162,184,.22); color: #43c7dc; }
.employee-summary-item.is-muted { border-left-color: #6c757d; opacity: .82; }
.employee-summary-item.is-planned { border-left-color: #6f42c1; border-style: dashed; }
.employee-summary-item.is-planned .employee-summary-icon { background: rgba(111,66,193,.22); color: #a98eda; }

@media (max-width: 575.98px) {
  .employee-summary-grid {
    grid-template-columns: 1fr;
  }

  .attendance-summary-head {
    align-items: stretch;
    flex-direction: column;
  }

  .attendance-period-controls .form-control {
    flex: 1 1 auto;
    width: 1%;
  }
}

@media (min-width: 992px) {
  .employee-edit-columns > [class*="col-lg-"] {
    display: flex;
    flex-direction: column;
  }

  .employee-edit-columns .employee-column-bottom {
    margin-top: auto;
  }
}

.btn-outline-pink {
  color: #ff7eb6;
  border-color: #ff7eb6;
}

.btn-outline-pink:hover,
.btn-outline-pink.active {
  background: #ff7eb6;
  color: #fff;
  border-color: #ff7eb6;
}
</style>
<!-- Main content -->
<section class="content">
  <div class="container-fluid">
    <?php
    $current_permission = isset($_SESSION['permission']) ? (int)$_SESSION['permission'] : 0;

    if ($current_permission < 300) {
      echo '
        <div class="alert alert-danger">
          You do not have permission to access this page.
        </div>
      ';
      return;
    }

    $is_moderator = ($current_permission == 300);
    $is_admin_plus = ($current_permission >= 500);

    $input_readonly = $is_moderator ? 'readonly' : '';
    $select_disabled = $is_moderator ? 'disabled' : '';
    ?>

    <div class="row align-items-stretch">
      <div class="col-md-3 d-flex">

        <!-- Left/Profile column -->
        <div class="card card-primary card-outline w-100 h-100">
          <div class="card-body box-profile d-flex flex-column">

            <div class="text-center">
              <? 
              $usersql = "SELECT *, employees.id as empid 
                          FROM employees 
                          LEFT JOIN position ON position.id=employees.position_id 
                          LEFT JOIN schedules ON schedules.id=employees.schedule_id 
                          WHERE employees.id = '".$_GET['user-id']."'";
              $query = $conn->query($usersql);

              while($row = $query->fetch_array()){
                $genderKey = strtolower(trim($row['gender'] ?? ''));
                $defaultPhoto = ($genderKey === 'female') ? 'images/female.png' : 'images/male.png';
                $photoFile = basename(trim($row['photo'] ?? ''));
                $profilePhoto = $defaultPhoto;

                if ($photoFile !== '' && is_file(__DIR__ . '/../images/' . $photoFile)) {
                  $profilePhoto = 'images/' . $photoFile;
                }

                print '<div class="text-center">';

                if($is_admin_plus){
                  print '<a href="#edit_photo" data-toggle="modal" class="pull-right photo" data-id="'. $row['empid'].'">';
                }

                print '<img style="width:180px;" class="profile-user-img img-fluid img-circle" src="'.htmlspecialchars($profilePhoto, ENT_QUOTES, 'UTF-8').'" alt="User profile picture">';

                if($is_admin_plus){
                  print '</a>';
                }

                print '</div>';

                print '<h3 class="profile-username text-center">'.$row['firstname'].' '. $row['lastname'] .'</h3>';
                print '<p class="text-muted text-center">'.$row['description'].'</p>';

                $empid = $row['empid'];
                $user_lastname = $row['lastname'];
                $user_firstname = $row['firstname'];
                $user_username = $row['username'];
                $user_address = $row['address'];
                $user_birthdate = $row['birthdate'];
                $user_phone = $row['contact_info'];
                $user_schedule_id = $row['schedule_id'];
                $user_schedule = $row['time_in'].' - '.$row['time_out'];
                $user_gender = $row['gender'];
                $user_dpt = $row['position_id'];
                $user_since = $row['created_on'];
                $user_active = $row['active'];
                $user_worker_type = (($row['worker_type'] ?? 'employee') === 'contractor') ? 'contractor' : 'employee';
                $user_personal = $row['personal'];
                $user_permission = $row['permission'];
                $user_chat = $row['chat'];
                $user_grid = (int)($row['grid'] ?? 0);
                $user_attendance_enabled = (int)($row['attendance_enabled'] ?? 1);
                $user_personal_orders = (int)($row['personal_orders'] ?? 0);
              }
              ?>
            </div>

            <div class="card card-primary mt-3 mb-0 flex-fill">
              <div class="card-header">
                <h3 class="card-title">About <? echo $user_firstname .' '. $user_lastname; ?></h3>
              </div>

              <div class="card-body">
                <strong><i class="fas fa-user-alt mr-1"></i> Username</strong>
                <p class="text-muted"><? echo $user_username; ?></p>
                <hr>

                <strong><i class="fas fa-map-marker-alt mr-1"></i> Adress</strong>
                <p class="text-muted"><? echo $user_address; ?></p>
                <hr>

                <strong><i class="fas fa-phone-alt mr-1"></i> Phone</strong>
                <p class="text-muted"><? echo $user_phone; ?></p>
                <hr>

                <strong><i class="fas fa-laptop mr-1"></i> Working Schedule</strong>
                <p class="text-muted"><? echo $user_schedule; ?></p>
              </div>
            </div>

          </div>
        </div>
      </div>
      <!-- /.col -->

      <div class="col-md-9 d-flex">
        <div class="card card-primary card-outline w-100 h-100">
          <div class="card-body">
            <form action="scripts/employee_update.php" method="POST" class="employee-edit-form p-4 rounded h-100">
              <input type="hidden" id="empid" name="empid" value="<? echo $empid; ?>">
              <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_GET['return_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

              <h4 class="mb-4">
  <i class="fas fa-user-edit mr-2 text-info"></i>Edit Profile
</h4>

<?php if($is_moderator): ?>
  <div class="alert alert-warning">
    Moderator access: you can edit only <b>Position</b> and <b>User Level</b>.
  </div>
<?php endif; ?>

<div class="row employee-edit-columns">

  <div class="col-lg-6">

    <div class="section-card">
      <div class="section-title text-info">
        <i class="fas fa-id-card mr-1"></i> Basic info
      </div>

      <div class="form-row">
        <div class="form-group col-md-6">
          <label>First Name</label>
          <input type="text" class="form-control" name="firstname"
                 value="<?= htmlspecialchars($user_firstname) ?>"
                 <?= $input_readonly ?>>
        </div>

        <div class="form-group col-md-6">
          <label>Last Name</label>
          <input type="text" class="form-control" name="lastname"
                 value="<?= htmlspecialchars($user_lastname) ?>"
                 <?= $input_readonly ?>>
        </div>
      </div>

      <div class="form-group">
        <label>Address</label>
        <input type="text" class="form-control" name="address"
               value="<?= htmlspecialchars($user_address) ?>"
               <?= $input_readonly ?>>
      </div>

      <div class="form-row">
        <div class="form-group col-md-6">
          <label>Birth Date</label>
          <div class="input-group date" id="birthdate_picker" data-target-input="nearest">
            <input type="text"
                   name="birthdate"
                   class="form-control datetimepicker-input"
                   data-target="#birthdate_picker"
                   value="<?= htmlspecialchars($user_birthdate) ?>"
                   placeholder="YYYY-MM-DD"
                   <?= $input_readonly ?>>
            <div class="input-group-append" data-target="#birthdate_picker" data-toggle="datetimepicker">
              <div class="input-group-text"><i class="fa fa-calendar"></i></div>
            </div>
          </div>
        </div>

        <div class="form-group col-md-6">
          <label>Phone</label>
          <input type="text" class="form-control" name="contact_info"
                 value="<?= htmlspecialchars($user_phone) ?>"
                 <?= $input_readonly ?>>
        </div>
      </div>

      <div class="form-group mb-0">
        <label class="d-block">Gender</label>

        <div class="btn-group btn-group-toggle d-flex" data-toggle="buttons">
          <label class="btn btn-outline-info w-50 <?= ($user_gender === 'Male') ? 'active' : '' ?> <?= $is_moderator ? 'disabled' : '' ?>">
            <input type="radio"
                   name="gender"
                   value="Male"
                   autocomplete="off"
                   <?= ($user_gender === 'Male') ? 'checked' : '' ?>
                   <?= $is_moderator ? 'disabled' : '' ?>>
            <i class="fas fa-mars mr-1"></i> Male
          </label>

          <label class="btn btn-outline-pink w-50 <?= ($user_gender === 'Female') ? 'active' : '' ?> <?= $is_moderator ? 'disabled' : '' ?>">
            <input type="radio"
                   name="gender"
                   value="Female"
                   autocomplete="off"
                   <?= ($user_gender === 'Female') ? 'checked' : '' ?>
                   <?= $is_moderator ? 'disabled' : '' ?>>
            <i class="fas fa-venus mr-1"></i> Female
          </label>
        </div>

        <?php if($is_moderator): ?>
          <input type="hidden" name="gender" value="<?= htmlspecialchars($user_gender) ?>">
        <?php endif; ?>
      </div>
    </div>

    <div class="section-card">
      <div class="section-title text-primary">
        <i class="fas fa-calendar-alt mr-1"></i> Dates
      </div>

      <div class="form-group mb-0">
        <label>In Scrub Since</label>
        <div class="input-group date" id="created_on_picker" data-target-input="nearest">
          <input type="text"
                 name="created_on"
                 class="form-control datetimepicker-input"
                 data-target="#created_on_picker"
                 value="<?= htmlspecialchars($user_since) ?>"
                 placeholder="YYYY-MM-DD"
                 <?= $input_readonly ?>>
          <div class="input-group-append" data-target="#created_on_picker" data-toggle="datetimepicker">
            <div class="input-group-text"><i class="fa fa-calendar"></i></div>
          </div>
        </div>
      </div>
    </div>

    <?php
      require_once __DIR__ . '/attendance_summary_service.php';

      $previousMonth = new DateTime('first day of last month');
      $attendanceYear = (int)($_GET['attendance_year'] ?? $previousMonth->format('Y'));
      $attendanceMonth = (int)($_GET['attendance_month'] ?? $previousMonth->format('m'));
      if ($attendanceMonth < 1 || $attendanceMonth > 12) {
        $attendanceMonth = (int)$previousMonth->format('m');
      }
      if ($attendanceYear < 2000 || $attendanceYear > 2100) {
        $attendanceYear = (int)$previousMonth->format('Y');
      }

      $attendanceMonths = [
        1 => 'Január', 2 => 'Február', 3 => 'Marec', 4 => 'Apríl',
        5 => 'Máj', 6 => 'Jún', 7 => 'Júl', 8 => 'August',
        9 => 'September', 10 => 'Október', 11 => 'November', 12 => 'December'
      ];
      $attendanceYears = attendanceSummaryAvailableYears($conn);
      if (!in_array($attendanceYear, $attendanceYears, true)) {
        $attendanceYears[] = $attendanceYear;
        rsort($attendanceYears, SORT_NUMERIC);
      }
      $attendanceSummary = attendanceSummaryCalculate($conn, $empid, $attendanceYear, $attendanceMonth);
    ?>

    <div class="section-card employee-column-bottom attendance-summary-card" id="attendance_summary_card">
      <div class="attendance-summary-head">
        <div class="section-title text-info">
          <i class="fas fa-chart-pie mr-1"></i> Attendance Overview
        </div>

        <div class="attendance-period-controls">
          <select class="form-control attendance-month-select" id="attendance_summary_month" aria-label="Attendance month">
            <?php foreach ($attendanceMonths as $monthNumber => $monthName): ?>
              <option value="<?= $monthNumber ?>" <?= $attendanceMonth === $monthNumber ? 'selected' : '' ?>>
                <?= htmlspecialchars($monthName) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select class="form-control" id="attendance_summary_year" aria-label="Attendance year">
            <?php foreach ($attendanceYears as $yearOption): ?>
              <option value="<?= (int)$yearOption ?>" <?= $attendanceYear === (int)$yearOption ? 'selected' : '' ?>>
                <?= (int)$yearOption ?>
              </option>
            <?php endforeach; ?>
          </select>

          <a class="btn btn-info btn-sm" id="attendance_summary_report" target="_blank"
             href="scripts/attendance_print.php?eno=<?= (int)$empid ?>&amp;year=<?= $attendanceYear ?>&amp;month=<?= str_pad((string)$attendanceMonth, 2, '0', STR_PAD_LEFT) ?>"
             title="Otvoriť celý výkaz">
            <i class="fas fa-external-link-alt"></i>
          </a>
        </div>
      </div>

      <div class="employee-summary-grid">
        <div class="employee-summary-item is-warning">
          <span class="employee-summary-icon"><i class="fas fa-business-time"></i></span>
          <span>
            <span class="employee-summary-label">Nadčas</span>
            <strong class="employee-summary-value" data-attendance-value="overtime"><?= htmlspecialchars($attendanceSummary['overtime']) ?></strong>
          </span>
        </div>

        <div class="employee-summary-item is-info">
          <span class="employee-summary-icon"><i class="fas fa-user-md"></i></span>
          <span>
            <span class="employee-summary-label">Lekár</span>
            <strong class="employee-summary-value" data-attendance-value="doctor"><?= htmlspecialchars($attendanceSummary['doctor']) ?></strong>
          </span>
        </div>

        <div class="employee-summary-item is-info">
          <span class="employee-summary-icon"><i class="fas fa-star"></i></span>
          <span>
            <span class="employee-summary-label">Sviatky</span>
            <strong class="employee-summary-value" data-attendance-value="holidays"><?= htmlspecialchars($attendanceSummary['holidays']) ?></strong>
          </span>
        </div>

        <div class="employee-summary-item is-muted">
          <span class="employee-summary-icon"><i class="fas fa-calendar-week"></i></span>
          <span>
            <span class="employee-summary-label">Weekendy</span>
            <strong class="employee-summary-value" data-attendance-value="weekends"><?= htmlspecialchars($attendanceSummary['weekends']) ?></strong>
          </span>
        </div>

        <div class="employee-summary-item is-success">
          <span class="employee-summary-icon"><i class="fas fa-umbrella-beach"></i></span>
          <span>
            <span class="employee-summary-label">Dovolenka</span>
            <strong class="employee-summary-value">
              <span data-attendance-value="vacation_days"><?= htmlspecialchars($attendanceSummary['vacation_days']) ?></span> dní
              · <span data-attendance-value="vacation"><?= htmlspecialchars($attendanceSummary['vacation']) ?></span>
            </strong>
          </span>
        </div>

        <div class="employee-summary-item is-planned">
          <span class="employee-summary-icon"><i class="fas fa-hourglass-half"></i></span>
          <span>
            <span class="employee-summary-label">Zostatok dovolenky</span>
            <strong class="employee-summary-value">Zatiaľ neaktívne</strong>
          </span>
        </div>
      </div>

      <div class="attendance-summary-note" id="attendance_summary_note"
           style="<?= $attendanceSummary['has_data'] ? '' : 'display:block;' ?>">
        <i class="fas fa-info-circle mr-1"></i>
        <span><?= $attendanceSummary['has_data'] ? '' : 'Pre zvolené obdobie nie sú evidované žiadne záznamy.' ?></span>
      </div>
    </div>

  </div>

  <div class="col-lg-6">

    <div class="section-card">
      <div class="section-title text-warning">
        <i class="fas fa-briefcase mr-1"></i> Work settings
      </div>

      <div class="form-group">
        <label>Worker Type</label>
        <select class="form-control" name="worker_type" id="edit_worker_type" <?= $select_disabled ?>>
          <option value="employee" <?= ($user_worker_type === 'employee') ? 'selected' : '' ?>>Employee</option>
          <option value="contractor" <?= ($user_worker_type === 'contractor') ? 'selected' : '' ?>>Subcontractor</option>
        </select>
        <small class="form-text text-muted">Contract type is independent from system activity and permissions.</small>
        <?php if($is_moderator): ?>
          <input type="hidden" name="worker_type" value="<?= htmlspecialchars($user_worker_type) ?>">
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label>Position</label>
        <select class="form-control" name="position_id">
          <option value="">Select position</option>
          <?php
          $sql = "SELECT * FROM position";
          $query = $conn->query($sql);
          while($prow = $query->fetch_assoc()){
            echo "<option value='".$prow['id']."'";
            if($prow['id'] == $user_dpt) { echo " selected"; }
            echo ">".$prow['description']."</option>";
          }
          ?>
        </select>
      </div>

      <div class="form-group">
        <label>Schedule</label>
        <select class="form-control" name="schedule_id" <?= $select_disabled ?>>
          <option value="">Select schedule</option>
          <?php
          $sql = "SELECT * FROM schedules";
          $query = $conn->query($sql);
          while($srow = $query->fetch_assoc()){
            echo "<option value='".$srow['id']."'";
            if($srow['id'] == $user_schedule_id) { echo " selected"; }
            echo ">".$srow['time_in'].' - '.$srow['time_out']."</option>";
          }
          ?>
        </select>
        <?php if($is_moderator): ?>
          <input type="hidden" name="schedule_id" value="<?= htmlspecialchars($user_schedule_id) ?>">
        <?php endif; ?>
      </div>

    </div>

    <div class="section-card">
      <div class="section-title text-success">
        <i class="fas fa-toggle-on mr-1"></i> System switches
      </div>

      <div class="employee-switch-grid">

        <div class="employee-switch-card">
          <div class="custom-control custom-switch">
            <input type="checkbox"
                   class="custom-control-input"
                   id="edit_active_switch"
                   name="active"
                   value="Active"
                   <?= ($user_active === 'Active') ? 'checked' : '' ?>
                   <?= $is_moderator ? 'disabled' : '' ?>>
            <label class="custom-control-label" for="edit_active_switch">Active</label>
          </div>
          <small>User can sign in and use permitted parts of the system.</small>
          <?php if($is_moderator): ?>
            <input type="hidden" name="active" value="<?= htmlspecialchars($user_active) ?>">
          <?php endif; ?>
        </div>

        <div class="employee-switch-card">
          <div class="custom-control custom-switch">
            <input type="checkbox"
                   class="custom-control-input"
                   id="edit_grid"
                   name="grid"
                   value="1"
                   <?= !empty($user_grid) ? 'checked' : '' ?>
                   <?= $is_moderator ? 'disabled' : '' ?>>
            <label class="custom-control-label" for="edit_grid">Show in grid</label>
          </div>
          <small>Tablet grid, online status, breaks and lunch.</small>
          <?php if($is_moderator): ?>
            <input type="hidden" name="grid" value="<?= (int)$user_grid ?>">
          <?php endif; ?>
        </div>

        <div class="employee-switch-card">
          <div class="custom-control custom-switch">
            <input type="checkbox"
                   class="custom-control-input"
                   id="edit_attendance_enabled"
                   name="attendance_enabled"
                   value="1"
                   <?= !empty($user_attendance_enabled) ? 'checked' : '' ?>
                   <?= $is_moderator ? 'disabled' : '' ?>>
            <label class="custom-control-label" for="edit_attendance_enabled">Attendance reports</label>
          </div>
          <small>Show in the HR calendar and allow monthly attendance reports.</small>
          <?php if($is_moderator): ?>
            <input type="hidden" name="attendance_enabled" value="<?= (int)$user_attendance_enabled ?>">
          <?php endif; ?>
        </div>

        <div class="employee-switch-card">
          <div class="custom-control custom-switch">
            <input type="checkbox"
                   class="custom-control-input"
                   id="edit_personal_orders"
                   name="personal_orders"
                   value="1"
                   <?= !empty($user_personal_orders) ? 'checked' : '' ?>
                   <?= $is_moderator ? 'disabled' : '' ?>>
            <label class="custom-control-label" for="edit_personal_orders">Profile Orders</label>
          </div>
          <small>Shows Orders tab in employee profile.</small>
          <?php if($is_moderator): ?>
            <input type="hidden" name="personal_orders" value="<?= (int)$user_personal_orders ?>">
          <?php endif; ?>
        </div>

        <div class="employee-switch-card">
          <div class="custom-control custom-switch">
            <input type="checkbox"
                   class="custom-control-input"
                   id="edit_chat"
                   name="chat"
                   value="yes"
                   <?= ($user_chat === 'yes') ? 'checked' : '' ?>
                   <?= $is_moderator ? 'disabled' : '' ?>>
            <label class="custom-control-label" for="edit_chat">Chat</label>
          </div>
          <small>Employee can be visible in chat.</small>
          <?php if($is_moderator): ?>
            <input type="hidden" name="chat" value="<?= htmlspecialchars($user_chat) ?>">
          <?php endif; ?>
        </div>

      </div>
    </div>

    <div class="section-card employee-column-bottom">
      <div class="section-title text-danger">
        <i class="fas fa-user-shield mr-1"></i> Access
      </div>

      <div class="form-group">
        <label>User Level</label>
        <select class="form-control" name="permission">
          <option value="1" <?= ($user_permission == '1') ? 'selected' : '' ?>>User</option>

          <?php if ($current_permission >= 300): ?>
            <option value="300" <?= ($user_permission == '300') ? 'selected' : '' ?>>Moderator</option>
          <?php endif; ?>

          <?php if ($current_permission >= 500): ?>
            <option value="500" <?= ($user_permission == '500') ? 'selected' : '' ?>>Administrator</option>
          <?php endif; ?>

          <?php if ($current_permission >= 900): ?>
            <option value="900" <?= ($user_permission == '900') ? 'selected' : '' ?>>Super Administrator</option>
          <?php endif; ?>
        </select>
      </div>

      <div class="form-group mb-0">
        <label>Change Password</label>
        <input type="password"
               class="form-control"
               name="password"
               placeholder="Enter new password"
               <?= $input_readonly ?>>
      </div>
    </div>

  </div>

</div>

<div class="form-group mt-4 mb-0">
  <button type="submit" name="edit" value="edit" class="btn btn-info btn-block">
    <i class="fas fa-save mr-1"></i> Save Changes
  </button>
</div>
            </form>
          </div>
        </div>
      </div>
      <!-- /.col -->
    </div>
  </div>
</section>

<script>
$(function () {
  $('#created_on_picker').datetimepicker({
    format: 'YYYY-MM-DD'
  });

  $('#birthdate_picker').datetimepicker({
    format: 'YYYY-MM-DD'
  });

  $('#edit_worker_type').on('change', function () {
    if (this.value === 'contractor') {
      $('#edit_grid').prop('checked', false);
      $('#edit_attendance_enabled').prop('checked', false);
    }
  });

  $('#attendance_summary_month, #attendance_summary_year').on('change', function () {
    var employeeId = <?= (int)$empid ?>;
    var month = $('#attendance_summary_month').val();
    var year = $('#attendance_summary_year').val();
    var $card = $('#attendance_summary_card');
    var $note = $('#attendance_summary_note');

    $card.addClass('is-loading');
    $note.hide().find('span').text('');

    $.ajax({
      url: 'scripts/attendance_summary.php',
      method: 'GET',
      dataType: 'json',
      data: {
        employee_id: employeeId,
        month: month,
        year: year
      }
    }).done(function (response) {
      if (!response || !response.success) {
        return;
      }

      var summary = response.summary;
      $('[data-attendance-value="overtime"]').text(summary.overtime);
      $('[data-attendance-value="doctor"]').text(summary.doctor);
      $('[data-attendance-value="holidays"]').text(summary.holidays);
      $('[data-attendance-value="weekends"]').text(summary.weekends);
      $('[data-attendance-value="vacation"]').text(summary.vacation);
      $('[data-attendance-value="vacation_days"]').text(summary.vacation_days);

      $('#attendance_summary_report').attr(
        'href',
        'scripts/attendance_print.php?eno=' + employeeId + '&year=' + year + '&month=' + String(month).padStart(2, '0')
      );

      if (!summary.has_data) {
        $note.show().find('span').text('Pre zvolené obdobie nie sú evidované žiadne záznamy.');
      }
    }).fail(function () {
      $note.show().find('span').text('Súhrn dochádzky sa nepodarilo načítať.');
    }).always(function () {
      $card.removeClass('is-loading');
    });
  });
});
</script>

<div class="modal fade" id="edit_photo">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header d-flex justify-content-between align-items-center">
        <h4 class="modal-title mb-0"><b><span class="del_employee_name"></span></b></h4>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <form class="form-horizontal" method="POST" action="includes/employee_edit_photo.php" enctype="multipart/form-data">
          <input type="hidden" name="empid" value="<?php echo $empid; ?>">
          <div class="form-group">
            <label for="photo" class="col-sm-3 control-label">Photo</label>
            <div class="col-sm-9">
              <input type="file" id="photo" name="photo" required>
            </div>
          </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default btn-flat pull-left" data-dismiss="modal">
          <i class="fa fa-close"></i> Zavrieť
        </button>
        <button type="submit" class="btn btn-success btn-flat" name="upload">
          <i class="fa fa-check-square-o"></i> Upraviť
        </button>
        </form>
      </div>
    </div>
  </div>
</div>
