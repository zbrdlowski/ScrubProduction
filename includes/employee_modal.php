<!-- Add -->
<div class="modal fade" id="addnew">
    <div class="modal-dialog">
        <div class="modal-content">
          	<div class="modal-header">
            	             		
            	<h4 class="modal-title"><b>Pridať Zamestnanca</b></h4>
          	</div>
          	<div class="modal-body">
            	<form class="form-horizontal" method="POST" action="scripts/employee_add.php" enctype="multipart/form-data">
          		  <div class="form-group">
                  	<label for="firstname" class="col-sm-3 control-label">Meno</label>

                  	<div class="col-sm-9">
                    	<input type="text" class="form-control" id="firstname" name="firstname" required>
                  	</div>
                </div>

                    <script>
                    // Initialize Tempusdominus datepickers for this modal
                    $(function(){
                      if (typeof $.fn.datetimepicker !== 'undefined') {
                        $('#addnew').on('shown.bs.modal', function() {
                          $('#datepicker_add').datetimepicker({ format: 'YYYY-MM-DD' });
                        });
                        $('#edit').on('shown.bs.modal', function() {
                          $('#datepicker_edit').datetimepicker({ format: 'YYYY-MM-DD' });
                        });
                      }
                    });
                    </script>
                <div class="form-group">
                  	<label for="lastname" class="col-sm-3 control-label">Priezvisko</label>

                  	<div class="col-sm-9">
                    	<input type="text" class="form-control" id="lastname" name="lastname" required>
                  	</div>
                </div>
                <div class="form-group">
                  	<label for="address" class="col-sm-3 control-label">Adresa</label>

                  	<div class="col-sm-9">
                      <textarea class="form-control" name="address" id="address"></textarea>
                  	</div>
                </div>
                <div class="form-group">
                  	<label for="datepicker_add" class="col-sm-3 control-label">Dátum nar.</label>

                  	<div class="col-sm-9"> 
                        <div class="input-group date" id="datepicker_add" data-target-input="nearest">
                          <input type="text" class="form-control datetimepicker-input" data-target="#datepicker_add" id="datepicker_add_input" name="birthdate">
                          <div class="input-group-append" data-target="#datepicker_add" data-toggle="datetimepicker">
                            <div class="input-group-text"><i class="far fa-calendar-alt"></i></div>
                          </div>
                        </div>
                  	</div>
                </div>
                <div class="form-group">
                    <label for="contact" class="col-sm-3 control-label">Telefón</label>

                    <div class="col-sm-9">
                      <input type="text" class="form-control" id="contact" name="contact">
                    </div>
                </div>
                <div class="form-group">
                    <label for="gender" class="col-sm-3 control-label">Pohlavie</label>

                    <div class="col-sm-9"> 
                      <select class="form-control" name="gender" id="gender" required>
                        <option value="" selected>- Select -</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                      </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="position" class="col-sm-3 control-label">Prac. pozícia</label>

                    <div class="col-sm-9">
                      <select class="form-control" name="position" id="position" required>
                        <option value="" selected>- Select -</option>
                        <?php
                          $sql = "SELECT * FROM position";
                          $query = $conn->query($sql);
                          while($prow = $query->fetch_assoc()){
                            echo "
                              <option value='".$prow['id']."'>".$prow['description']."</option>
                            ";
                          }
                        ?>
                      </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="schedule" class="col-sm-3 control-label">Prac. Doba</label>

                    <div class="col-sm-9">
                      <select class="form-control" id="schedule" name="schedule" required>
                        <option value="" selected>- Select -</option>
                        <?php
                          $sql = "SELECT * FROM schedules";
                          $query = $conn->query($sql);
                          while($srow = $query->fetch_assoc()){
                            echo "
                              <option value='".$srow['id']."'>".$srow['time_in'].' - '.$srow['time_out']."</option>
                            ";
                          }
                        ?>
                      </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="active" class="col-sm-3 control-label">Aktívny</label>

                    <div class="col-sm-9">
                      <select class="form-control" id="active" name="active" required>
                        <option value="Active" selected>- Active -</option>
                        <option value="Inactive">- Inactive -</option>
                       
                      </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="pesonal" class="col-sm-3 control-label">Osobný prehľad</label>

                    <div class="col-sm-9">
                      <select class="form-control" id="personal" name="personal" required>
                        <option value="X" selected>- Nezobraziť nič -</option>
                        <option value="A">- Zobraziť iba denný prehľad -</option>
                        <option value="B">- Zobraziť iba mesačný prehľad -</option>
                        <option value="C">- Zobraziť oba prehľady -</option>
                       
                      </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="photo" class="col-sm-3 control-label">Foto</label>

                    <div class="col-sm-9">
                      <input type="file" name="photo" id="photo">
                    </div>
                </div>
          	</div>
          	<div class="modal-footer">
            	<button type="button" class="btn btn-default btn-flat pull-left" data-dismiss="modal"><i class="fa fa-close"></i> Zavrieť</button>
            	<button type="submit" class="btn btn-primary btn-flat" name="add"><i class="fa fa-save"></i> Uložiť</button>
            	</form>
          	</div>
        </div>
    </div>
</div>

<!-- Edit -->
<div class="modal fade" id="edit">
    <div class="modal-dialog">
        <div class="modal-content">
          	<div class="modal-header d-flex justify-content-between align-items-center">
  <h4 class="modal-title mb-0"><b><span class="del_employee_name"></span></b></h4>
  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
    <span aria-hidden="true">&times;</span>
  </button>
</div>
          	<div class="modal-body">
            	<form class="form-horizontal" method="POST" action="employee_edit.php">
            		<input type="hidden" class="empid" name="id">
                <div class="form-group">
                    <label for="edit_firstname" class="col-sm-3 control-label">Meno</label>

                    <div class="col-sm-9">
                      <input type="text" class="form-control" id="edit_firstname" name="firstname">
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_lastname" class="col-sm-3 control-label">Priezvisko</label>

                    <div class="col-sm-9">
                      <input type="text" class="form-control" id="edit_lastname" name="lastname">
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_address" class="col-sm-3 control-label">Adresa</label>

                    <div class="col-sm-9">
                      <textarea class="form-control" name="address" id="edit_address"></textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label for="datepicker_edit" class="col-sm-3 control-label">Dátum nar.</label>

                    <div class="col-sm-9"> 
                        <div class="input-group date" id="datepicker_edit" data-target-input="nearest">
                          <input type="text" class="form-control datetimepicker-input" data-target="#datepicker_edit" id="datepicker_edit_input" name="birthdate">
                          <div class="input-group-append" data-target="#datepicker_edit" data-toggle="datetimepicker">
                            <div class="input-group-text"><i class="far fa-calendar-alt"></i></div>
                          </div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_contact" class="col-sm-3 control-label">Telefón</label>

                    <div class="col-sm-9">
                      <input type="text" class="form-control" id="edit_contact" name="contact">
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_gender" class="col-sm-3 control-label">Pohlavie</label>

                    <div class="col-sm-9"> 
                      <select class="form-control" name="gender" id="edit_gender">
                        <option selected id="gender_val"></option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                      </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_position" class="col-sm-3 control-label">Prac. pozícia</label>

                    <div class="col-sm-9">
                      <select class="form-control" name="position" id="edit_position">
                        <option selected id="position_val"></option>
                        <?php
                          $sql = "SELECT * FROM position";
                          $query = $conn->query($sql);
                          while($prow = $query->fetch_assoc()){
                            echo "
                              <option value='".$prow['id']."'>".$prow['description']."</option>
                            ";
                          }
                        ?>
                      </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="active" class="col-sm-3 control-label">Aktívny</label>

                    <div class="col-sm-9">
                    <select class="form-control" name="active" id="edit_active">
                        <option selected id="active_val"></option>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                      </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_schedule" class="col-sm-3 control-label">Prac. doba</label>

                    <div class="col-sm-9">
                      <select class="form-control" id="edit_schedule" name="schedule">
                        <option selected id="schedule_val"></option>
                        <?php
                          $sql = "SELECT * FROM schedules";
                          $query = $conn->query($sql);
                          while($srow = $query->fetch_assoc()){
                            echo "
                              <option value='".$srow['id']."'>".$srow['time_in'].' - '.$srow['time_out']."</option>
                            ";
                          }
                        ?>
                      </select>
                    </div>
                  </div>

                  <div class="form-group">
                    <label for="pesonal" class="col-sm-3 control-label">Osobný prehľad</label>

                    <div class="col-sm-9">
                      <select class="form-control"  name="personal" id="edit_personal" required>                        
                        <option selected id="personal_val"></option>                   
                        <option value="X">X - Nezobraziť nič -</option>
                        <option value="A">A - Zobraziť iba denný prehľad -</option>
                        <option value="B">B - Zobraziť iba mesačný prehľad -</option>
                        <option value="C">C - Zobraziť oba prehľady -</option>
                       
                      </select>
                    </div>
                </div>


          	</div>
          	<div class="modal-footer">
            	<button type="button" class="btn btn-default btn-flat pull-left" data-dismiss="modal"><i class="fa fa-close"></i> Zavrieť</button>
            	<button type="submit" class="btn btn-success btn-flat" name="edit"><i class="fa fa-check-square-o"></i> Upraviť</button>
            	</form>
          	</div>
        </div>
    </div>
</div>

<!-- Delete -->
<div class="modal fade" id="delete">
    <div class="modal-dialog">
        <div class="modal-content">
          	<div class="modal-header">
            	<button type="button" class="close" data-dismiss="modal" aria-label="Close">
              		<span aria-hidden="true">&times;</span></button>            	
          	</div>
          	<div class="modal-body">
            	<form class="form-horizontal" method="POST" action="employee_delete.php">
            		<input type="hidden" class="empid" name="id">
            		<div class="text-center">
	                	<p>DELETE EMPLOYEE</p>
	                	<h2 class="bold del_employee_name"></h2>
	            	</div>
          	</div>
          	<div class="modal-footer">
            	<button type="button" class="btn btn-default btn-flat pull-left" data-dismiss="modal"><i class="fa fa-close"></i> Zavrieť</button>
            	<button type="submit" class="btn btn-danger btn-flat" name="delete"><i class="fa fa-trash"></i> Odstrániť</button>
            	</form>
          	</div>
        </div>
    </div>
</div>

<!-- Update Photo -->

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
                <input type="hidden" class="empid" name="id">
                <div class="form-group">
                    <label for="photo" class="col-sm-3 control-label">Photo</label>

                    <div class="col-sm-9">
                      <input type="file" id="photo" name="photo" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-default btn-flat pull-left" data-dismiss="modal"><i class="fa fa-close"></i> Zavrieť</button>
              <button type="submit" class="btn btn-success btn-flat" name="upload"><i class="fa fa-check-square-o"></i> Upraviť</button>
              </form>
            </div>
        </div>
    </div>
</div>    