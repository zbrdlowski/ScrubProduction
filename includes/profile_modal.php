<?
if(isset($_SESSION['user_id'])){
  $sql = "SELECT * FROM employees WHERE id = ".$_SESSION['user_id']."";       
$query = $conn->query($sql); 
while($user = $query->fetch_array()){
 
?>

<!-- Add -->
<div class="modal fade" id="profile">
    <div class="modal-dialog">
        <div class="modal-content">
          	<div class="modal-header">
            <h4 class="modal-title"><?php echo ($_SESSION['name']); ?></h4>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
          	</div>
          	<div class="modal-body">
            	<form class="form-horizontal" method="POST" action="includes/profile_update.php?return=logout.php" enctype="multipart/form-data">
          		  <div class="form-group">
                  	<label for="username" class="col-sm-3 control-label">Username</label>

                  	<div class="col-sm-9">
                    	<input type="text" class="form-control" id="username" name="username" value="<?php echo $_SESSION['username']; ?>">
                  	</div>
                </div>
                <div class="form-group">
                    <label for="password" class="col-sm-3 control-label">Password</label>

                    <div class="col-sm-9"> 
                      <input type="password" class="form-control" id="password" name="password" value="<?php echo $user['password']; ?>">
                    </div>
                </div>
                <div class="form-group">
                  	<label for="firstname" class="col-sm-3 control-label">Firstname</label>

                  	<div class="col-sm-9">
                    <?  if($_SESSION['permission']>'300'){
                    	print '<input type="text" class="form-control" id="firstname" name="firstname" value="'.$user['firstname'].'">'; 
                    }else{
                      print '<input type="hidden" class="form-control" id="firstname" name="firstname" value="'.$user['firstname'].'">'.$user['firstname'].''; 
                    }
                    ?>
                  	</div>
                </div>
                <div class="form-group">
                  	<label for="lastname" class="col-sm-3 control-label">Lastname</label>

                  	<div class="col-sm-9">
                <?  if($_SESSION['permission']>'300'){
                  print '<input type="text" class="form-control" id="lastname" name="lastname" value="'.$user['lastname'].' ">';
                }
                else{
                  print '<input type="hidden" class="form-control" id="lastname" name="lastname" value="'.$user['lastname'].' ">'.$user['lastname'].''; 
                }
                ?>                    	
                  	</div>
                </div>
                <?
                if($_SESSION['permission']>'300'){
                  print '<div class="form-group">'; 
                    print '<label for="exampleInputFile">Photo:</label>'; 
                   print '<div class="input-group">'; 
                      print '<div class="custom-file">'; 
                        print '<input type="file" class="custom-file-input" id="photo" name="photo">'; 
                        print '<label class="custom-file-label" for="exampleInputFile">Choose file</label>'; 
                      print '</div>';                      
                    print '</div>'; 
                 print ' </div>'; 
                  }   
                ?>
                <hr>
                <div class="form-group">
                    <label for="curr_password">Current Password:</label>

                    <div class="col-sm-9">
                      <input type="password" class="form-control" id="curr_password" name="curr_password" placeholder="input current password to save changes" required>
                    </div>
                </div>
          	</div>
          	<div class="modal-footer">
            	<button type="button" class="btn btn-default btn-flat pull-left" data-dismiss="modal"><i class="fa fa-close"></i> Close</button>
            	<button type="submit" class="btn btn-success btn-flat" name="save"><i class="fa fa-check-square-o"></i> Save</button>
            	</form>
          	</div>
        </div>
    </div>
</div>
<?
}}
?>