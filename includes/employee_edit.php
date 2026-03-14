
    <!-- Main content -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-md-3">

            <!-- Profile Image -->
            <div class="card card-primary card-outline">
              <div class="card-body box-profile">
                
                <div class="text-center">
                  
                <? $usersql = "SELECT *, employees.id as empid FROM employees LEFT JOIN position ON position.id=employees.position_id LEFT JOIN schedules ON schedules.id=employees.schedule_id WHERE employees.id = '".$_GET['user-id']."'";  
                $query = $conn->query($usersql);   
                while($row = $query->fetch_array()){
                    print'<div class="text-center">';

                    print '<a href="#edit_photo" data-toggle="modal" class="pull-right photo" data-id="'. $row['empid'].'">';
                    print'<img style="width:180px;" class="profile-user-img img-fluid img-circle" src="images/'.$row['photo'].' " alt="User profile picture">';
                    print'</a>';

                    print'</div>';
                    print '<h3 class="profile-username text-center">'.$row['firstname'].' '. $row['lastname'] .'</h3>';                
                    print'<p class="text-muted text-center">'.$row['description'].'</p>';

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
                  

                  print'</div>';
                }        
                ?>
                <div class="card card-primary">
              <div class="card-header">
                <h3 class="card-title">About <? echo $user_firstname .' '. $user_lastname; ?></h3>
              </div>
              <!-- /.card-header -->
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
              <!-- /.card-body -->
            </div>
            <!-- /.card -->

            <!-- About Me Box -->
            
                                     
              </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
          <div class="col-md-9">
            <div class="card">
              
                <!-- Include Bootstrap 4 CSS if not already loaded -->

                <div class="card card-primary card-outline">
                <form action="includes/employee_update.php" method="POST" class="p-4 rounded">
                <input type="hidden" id="empid" name="empid" value="<? echo $empid; ?>">
  <h4 class="mb-4">Edit Profile</h4>

  <div class="form-row">
    <!-- Left Column -->
    <div class="col-md-6">
      <div class="form-group">
        <label for="firstname">First Name</label>
        <input type="text" class="form-control" id="firstname" name="firstname" value="<? echo $user_firstname; ?>" placeholder="Enter name">
      </div>

      <div class="form-group">
        <label for="lastname">Last Name</label>
        <input type="text" class="form-control" id="lastname" name="lastname" value="<? echo $user_lastname; ?>"placeholder="Enter surname">
      </div>

      <div class="form-group">
        <label for="active">Active Y/N</label>
        <select class="form-control" id="active" name="active">
          <option value="">Select Active Status</option>
          <option value="Active"<? if ($user_active == 'Active'){echo ' selected';}?>>Active</option >
          <option value="Inactive"<? if ($user_active == 'Inactive'){echo ' selected';}?>>Inactive</option>
        </select>
      </div>

      <div class="form-group">
        <label for="address">Address</label>
        <input type="text" class="form-control" id="address" name="address" value="<? echo $user_address; ?>"placeholder="Enter address">
      </div>

      <div class="form-group">
        <label for="birthdate">Birth Date</label>
        <input type="text" class="form-control" id="birthdate" name="birthdate" value="<? echo $user_birthdate; ?>" placeholder="YYYY-MM-DD">
      </div>

      <div class="form-group">
        <label for="contact_info">Phone</label>
        <input type="text" class="form-control" id="contact_info" name="contact_info" value="<? echo $user_phone; ?>" placeholder="Enter phone number">
      </div>
    </div>

    <!-- Right Column -->
    <div class="col-md-6">
      <div class="form-group">
        <label for="gender">Gender</label>
        <select class="form-control" id="gender" name="gender">
          <option value="">Select gender</option>
          <option value="Male"<? if ($user_gender == 'Male'){echo ' selected';}?>>Male</option >
          <option value="Female"<? if ($user_gender == 'Female'){echo ' selected';}?>>Female</option>
        </select>
      </div>

      <div class="form-group">
        <label for="position_id">Position</label>
        <select class="form-control" id="position_id" name="position_id">
          <option value="">Select position</option>
          
          <?php
          $sql = "SELECT * FROM position";
          $query = $conn->query($sql);
          while($prow = $query->fetch_assoc()){
            echo "
              <option value='".$prow['id']."'"; if($prow['id'] == $user_dpt) {echo " selected";} echo">".$prow['description']."</option>
            ";
          }
        ?>          
        </select>
      </div>

      <div class="form-group">
        <label for="schedule_id">Schedule</label>
        <select class="form-control" id="schedule_id" name="schedule_id">
          <option value="">Select schedule</option>
          <?php
          $sql = "SELECT * FROM schedules";
            $query = $conn->query($sql);
              while($srow = $query->fetch_assoc()){echo "<option value='".$srow['id']."'"; if($srow['id'] == $user_schedule_id) {echo " selected";} echo">".$srow['time_in'].' - '.$srow['time_out']."</option>";}
                        ?>
        </select>
      </div>

      <div class="form-group">
        <label for="personal">Personal Attendance Overview</label>
        <select class="form-control" id="personal" name="personal">
          <option value="">Select category</option>
          <option value="X"<? if ($user_personal == 'X'){echo ' selected';}?>>Display Nothing</option>
          <option value="A"<? if ($user_personal == 'A'){echo ' selected';}?>>Display only daily overview</option>
          <option value="B"<? if ($user_personal == 'B'){echo ' selected';}?>>Display monthly daily overview</option>
          <option value="C"<? if ($user_personal == 'C'){echo ' selected';}?>>Display both overviews</option>
        </select>
      </div>

      <div class="form-group">
        <label for="permission">User Level</label>
        <select class="form-control" id="permission" name="permission">
          <option value="">Select Level</option>
          <option value="1" <? if ($user_permission == '1'){echo ' selected';}?>>User</option>
          <option value="300"<? if ($user_permission == '300'){echo ' selected';}?>>Moderator</option>
          <option value="500"<? if ($user_permission == '500'){echo ' selected';}?>>Administrator</option>
        </select>
      </div>

      <div class="form-group">
        <label for="password">Change Password</label>
        <input type="password" class="form-control" id="password" name="password" placeholder="Enter new password">
      </div>
    </div>
  </div>

  <!-- Full-width Save Button -->
  <div class="form-group mt-4">
    <button type="submit" name="edit" value="edit" class="btn btn-primary btn-block">Save Changes</button>
  </div>
</form>
            </div>
                  <!-- /.tab-pane -->

                  
                  <!-- /.tab-pane -->
                
                <!-- /.tab-content -->
              </div><!-- /.card-body -->
            </div>
            
            <!-- /.card -->
          </div>
          <!-- /.col -->
        
        <!-- /.row -->
      </div><!-- /.container-fluid -->
    </section>
    <!-- /.content -->
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
 
   
