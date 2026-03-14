<!-- Content Wrapper -->

  <!-- Page Header -->
<div class="container-fluid mt-4">
  <div class="row">
    <!-- Departments Column -->
    <div class="col-md-6 mb-4">
      <div class="card shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Departments</h5>
          <a href="#addnew" data-toggle="modal" class="btn btn-dark btn-sm"><i class="fa fa-plus"></i> Add</a>
        </div>
        <div class="card-body">
          <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <strong>Error!</strong> <?= $_SESSION['error']; ?>
              <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
            <?php unset($_SESSION['error']); endif; ?>

          <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
              <strong>Success!</strong> <?= $_SESSION['success']; ?>
              <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
            <?php unset($_SESSION['success']); endif; ?>

          <table class="table table-bordered table-hover">
            <thead class="thead-dark">
              <tr>
                <th>Position Name</th>
                <th>Hourly Rate</th>
                <th>Tools</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $sql = "SELECT * FROM position";
              $query = $conn->query($sql);
              while($row = $query->fetch_assoc()):
              ?>
              <tr>
                <td><?= $row['description']; ?></td>
                <td><?= number_format($row['rate'], 2); ?></td>
                <td>
                  <button class="btn btn-success btn-sm edit-position" data-id="<?= $row['id']; ?>"><i class="fa fa-edit"> Edit</i></button>
                  <button class="btn btn-danger btn-sm delete-position" data-id="<?= $row['id']; ?>"><i class="fa fa-trash"> Delete</i></button>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Schedules Column -->
    <div class="col-md-6 mb-4">
      <div class="card shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Time Schedules</h5>
          <a href="#addnew" data-toggle="modal" class="btn btn-dark btn-sm"><i class="fa fa-plus"></i> Add</a>
        </div>
        <div class="card-body">
          <table class="table table-bordered table-hover">
            <thead class="thead-dark">
              <tr>
                <th>Start</th>
                <th>End</th>
                <th>Tools</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $sql = "SELECT * FROM schedules";
              $query = $conn->query($sql);
              while($row = $query->fetch_assoc()):
              ?>
              <tr>
                <td><?= date('h:i A', strtotime($row['time_in'])); ?></td>
                <td><?= date('h:i A', strtotime($row['time_out'])); ?></td>
                <td>
                  <button class="btn btn-success btn-sm edit-schedule" data-id="<?= $row['id']; ?>"><i class="fa fa-edit"> Edit</i></button>
                  <button class="btn btn-danger btn-sm delete-schedule" data-id="<?= $row['id']; ?>"><i class="fa fa-trash"> Delete</i></button>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>



<!-- Include modals (already created separately) -->
 <? 
 include 'position_edit_modal.php'; 
 include 'schedule_edit_modal.php';
 ?>
<script>
$(function(){
  // Position handlers
  $('.edit-position').click(function(e){
    e.preventDefault();
    $('#editPositionModal').modal('show');
    getPositionRow($(this).data('id'));
  });

  $('.delete-position').click(function(e){
    e.preventDefault();
    $('#deletePositionModal').modal('show');
    getPositionRow($(this).data('id'));
  });

  // Schedule handlers
  $('.edit-schedule').click(function(e){
    e.preventDefault();
    $('#editScheduleModal').modal('show');
    getScheduleRow($(this).data('id'));
  });

  $('.delete-schedule').click(function(e){
    e.preventDefault();
    $('#deleteScheduleModal').modal('show');
    getScheduleRow($(this).data('id'));
  });

  function getPositionRow(id){
    $.post('position_row.php', {id: id}, function(response){
      $('#posid').val(response.id);
      $('#edit_title').val(response.description);
      $('#edit_rate').val(response.rate);
      $('#del_posid').val(response.id);
      $('#del_position').html(response.description);
    }, 'json');
  }

  function getScheduleRow(id){
    $.post('schedule_row.php', {id: id}, function(response){
      $('#timeid').val(response.id);
      $('#edit_time_in').val(response.time_in);
      $('#edit_time_out').val(response.time_out);
      $('#del_timeid').val(response.id);
      $('#del_schedule').html(response.time_in + ' - ' + response.time_out);
    }, 'json');
  }
});
</script>

