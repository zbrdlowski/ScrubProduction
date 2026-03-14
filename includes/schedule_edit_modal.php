<div class="modal fade" id="editScheduleModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <form method="POST" action="schedule_edit.php">
      <div class="modal-content">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title">Edit Schedule</h5>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="timeid">
          <div class="form-group">
            <label for="edit_time_in">Start Time</label>
            <input type="time" class="form-control" name="time_in" id="edit_time_in" required>
          </div>
          <div class="form-group">
            <label for="edit_time_out">End Time</label>
            <input type="time" class="form-control" name="time_out" id="edit_time_out" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Save Changes</button>
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        </div>
      </div>
    </form>
  </div>
</div>
