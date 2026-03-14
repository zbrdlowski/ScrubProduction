<div class="modal fade" id="editPositionModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <form method="POST" action="position_edit.php">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Edit Position</h5>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="posid">
          <div class="form-group">
            <label for="edit_title">Position Name</label>
            <input type="text" class="form-control" name="description" id="edit_title" required>
          </div>
          <div class="form-group">
            <label for="edit_rate">Hourly Rate</label>
            <input type="number" step="0.01" class="form-control" name="rate" id="edit_rate" required>
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