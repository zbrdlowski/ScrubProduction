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
                print '<div class="text-center">';

                if($is_admin_plus){
                  print '<a href="#edit_photo" data-toggle="modal" class="pull-right photo" data-id="'. $row['empid'].'">';
                }

                print '<img style="width:180px;" class="profile-user-img img-fluid img-circle" src="images/'.$row['photo'].' " alt="User profile picture">';

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
                $user_personal = $row['personal'];
                $user_permission = $row['permission'];
                $user_chat = $row['chat'];
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
            <form action="includes/employee_update.php" method="POST" class="p-4 rounded h-100">
              <input type="hidden" id="empid" name="empid" value="<? echo $empid; ?>">
              <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_GET['return_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

              <h4 class="mb-4">Edit Profile</h4>

              <? if($is_moderator){ ?>
                <div class="alert alert-warning">
                  Moderator access: you can edit only <b>Position</b> and <b>User Level</b>.
                </div>
              <? } ?>

              <div class="form-row">
                <!-- Left Column -->
                <div class="col-md-6">
                  <div class="form-group">
                    <label for="firstname">First Name</label>
                    <input type="text" class="form-control" id="firstname" name="firstname" value="<? echo $user_firstname; ?>" placeholder="Enter name" <? echo $input_readonly; ?>>
                  </div>

                  <div class="form-group">
                    <label for="lastname">Last Name</label>
                    <input type="text" class="form-control" id="lastname" name="lastname" value="<? echo $user_lastname; ?>" placeholder="Enter surname" <? echo $input_readonly; ?>>
                  </div>

                  <div class="form-group">
                    <label for="active">Active Y/N</label>
                    <select class="form-control" id="active" name="active" <? echo $select_disabled; ?>>
                      <option value="">Select Active Status</option>
                      <option value="Active"<? if ($user_active == 'Active'){echo ' selected';}?>>Active</option>
                      <option value="Inactive"<? if ($user_active == 'Inactive'){echo ' selected';}?>>Inactive</option>
                    </select>
                    <? if($is_moderator){ ?><input type="hidden" name="active" value="<? echo $user_active; ?>"><? } ?>
                  </div>

                  <div class="form-group">
                    <label for="address">Address</label>
                    <input type="text" class="form-control" id="address" name="address" value="<? echo $user_address; ?>" placeholder="Enter address" <? echo $input_readonly; ?>>
                  </div>

                  <div class="form-group">
                    <label for="birthdate">Birth Date</label>
                    <div class="input-group date" id="birthdate_picker" data-target-input="nearest">
                      <input type="text"
                             id="birthdate"
                             name="birthdate"
                             class="form-control datetimepicker-input"
                             data-target="#birthdate_picker"
                             value="<? echo $user_birthdate; ?>"
                             placeholder="YYYY-MM-DD"
                             <? echo $input_readonly; ?>>
                      <div class="input-group-append" data-target="#birthdate_picker" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                      </div>
                    </div>
                  </div>

                  <div class="form-group">
                    <label for="contact_info">Phone</label>
                    <input type="text" class="form-control" id="contact_info" name="contact_info" value="<? echo $user_phone; ?>" placeholder="Enter phone number" <? echo $input_readonly; ?>>
                  </div>

                  <div class="form-group">
                    <label for="created_on">In Scrub Since:</label>
                    <div class="input-group date" id="created_on_picker" data-target-input="nearest">
                      <input type="text"
                             id="created_on"
                             name="created_on"
                             class="form-control datetimepicker-input"
                             data-target="#created_on_picker"
                             value="<? echo $user_since; ?>"
                             placeholder="YYYY-MM-DD"
                             <? echo $input_readonly; ?>>
                      <div class="input-group-append" data-target="#created_on_picker" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Right Column -->
                <div class="col-md-6">
                  <div class="form-group">
                    <label for="gender">Gender</label>
                    <select class="form-control" id="gender" name="gender" <? echo $select_disabled; ?>>
                      <option value="">Select gender</option>
                      <option value="Male"<? if ($user_gender == 'Male'){echo ' selected';}?>>Male</option>
                      <option value="Female"<? if ($user_gender == 'Female'){echo ' selected';}?>>Female</option>
                    </select>
                    <? if($is_moderator){ ?><input type="hidden" name="gender" value="<? echo $user_gender; ?>"><? } ?>
                  </div>

                  <div class="form-group">
                    <label for="position_id">Position</label>
                    <select class="form-control" id="position_id" name="position_id">
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
                    <label for="schedule_id">Schedule</label>
                    <select class="form-control" id="schedule_id" name="schedule_id" <? echo $select_disabled; ?>>
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
                    <? if($is_moderator){ ?><input type="hidden" name="schedule_id" value="<? echo $user_schedule_id; ?>"><? } ?>
                  </div>

                  <div class="form-group">
                    <label for="personal">Personal Attendance Overview</label>
                    <select class="form-control" id="personal" name="personal" <? echo $select_disabled; ?>>
                      <option value="">Select category</option>
                      <option value="X"<? if ($user_personal == 'X'){echo ' selected';}?>>Display Nothing</option>
                      <option value="A"<? if ($user_personal == 'A'){echo ' selected';}?>>Display only daily overview</option>
                      <option value="B"<? if ($user_personal == 'B'){echo ' selected';}?>>Display monthly daily overview</option>
                      <option value="C"<? if ($user_personal == 'C'){echo ' selected';}?>>Display both overviews</option>
                    </select>
                    <? if($is_moderator){ ?><input type="hidden" name="personal" value="<? echo $user_personal; ?>"><? } ?>
                  </div>

                  <div class="form-group">
                    <label for="chat">Enable Chat ?</label>
                    <select class="form-control" id="chat" name="chat" <? echo $select_disabled; ?>>
                      <option value="">Select option</option>
                      <option value="yes"<? if ($user_chat == 'yes'){echo ' selected';}?>>Yes</option>
                      <option value="no"<? if ($user_chat == 'no'){echo ' selected';}?>>No</option>
                    </select>
                    <? if($is_moderator){ ?><input type="hidden" name="chat" value="<? echo $user_chat; ?>"><? } ?>
                  </div>

                  <div class="form-group">
                    <label for="permission">User Level</label>
                    <select class="form-control" id="permission" name="permission">
                      <option value="">Select Level</option>
                      <option value="1"<? if ($user_permission == '1'){echo ' selected';}?>>User</option>
                      <? if ($current_permission >= 300){ ?>
                        <option value="300"<? if ($user_permission == '300'){echo ' selected';}?>>Moderator</option>
                      <? } ?>
                      <? if ($current_permission >= 500){ ?>
                        <option value="500"<? if ($user_permission == '500'){echo ' selected';}?>>Administrator</option>
                      <? } ?>
                      <? if ($current_permission >= 900){ ?>
                        <option value="900"<? if ($user_permission == '900'){echo ' selected';}?>>Super Administrator</option>
                      <? } ?>
                    </select>
                  </div>

                  <div class="form-group">
                    <label for="password">Change Password</label>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter new password" <? echo $input_readonly; ?>>
                  </div>
                </div>
              </div>

              <div class="form-group mt-4 mb-0">
                <button type="submit" name="edit" value="edit" class="btn btn-primary btn-block">Save Changes</button>
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
