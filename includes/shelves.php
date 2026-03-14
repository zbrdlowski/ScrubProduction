<h1>Shelves List / Edit</h1>
<script src="js/jquery-1.12.4.min.js"></script>
<style>.dataTables_filter {
  text-align: right !important;
}</style>
<?php

// Query to get the current stock and shelf locations
$stmt = $pdo->prepare("
    SELECT * FROM shelves;
");
$stmt->execute();

$stock_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>


<table width="100%" id="example1" class="table table-bordered table-striped">
    <thead>        
        <tr>            
            <th> <button class="btn btn-success" data-toggle="modal" data-target="#addShelfModal">
    + ADD NEW STORAGE LOCATION
</button></th>
            <th align='center'>Capacity</th>
            <th align='center'>Category</th>
            <th>Description</th>
            <th>Actions</th> 
        </tr>
    </thead>
    <tbody>
        <?php
        if (count($stock_data) > 0) {
            foreach ($stock_data as $row) {
    echo "<tr data-id='{$row['id']}'>                    
        <td class='location'>{$row['location']}</td>
        <td align='center' class='capacity'>{$row['capacity']}</td>
        <td align='center' class='category'>{$row['category']}</td>
        <td class='description'>{$row['description']}</td>
        <td>
            <button class='btn btn-primary edit-btn'>&nbsp;&nbsp;Edit&nbsp;&nbsp;</button>
            <button class='btn btn-success save-btn' style='display:none;'>&nbsp;&nbsp;Save&nbsp;&nbsp;</button>
        </td>
    </tr>";
}
        } else {
            echo "<tr><td colspan='3'>No data available</td></tr>";
        }
        ?>    
    <tbody>
</table>
    </div>
<!-- Add Shelf Modal -->
<div class="modal fade" id="addShelfModal" tabindex="-1" role="dialog" aria-labelledby="addShelfModalLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form id="addShelfForm">
        <div class="modal-header">
          <h4 class="modal-title" id="addShelfModalLabel">Add New Shelf Dopitchi !</h4>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label for="newLocation">Location</label>
            <input type="text" class="form-control" id="newLocation" name="location" required>
          </div>
          <div class="form-group">
            <label for="newCapacity">Capacity</label>
            <input type="number" class="form-control" id="newCapacity" name="capacity">
          </div>
          <div class="form-group">
            <label for="newCategory">Category</label>
            <input type="text" class="form-control" id="newCategory" name="category">
          </div>
          <div class="form-group">
            <label for="newDescription">Description</label>
            <textarea class="form-control" id="newDescription" name="description"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Shelf</button>
        </div>
      </form>
    </div>
  </div>

<script>
    $('#addShelfForm').submit(function(e) {
    e.preventDefault();

    var formData = {
        location: $('#newLocation').val(),
        capacity: $('#newCapacity').val(),
        category: $('#newCategory').val(),
        description: $('#newDescription').val()
    };

    $.post('scripts/add_shelf.php', formData, function(response) {
        // Optional: show success message
        $('#addShelfModal').modal('hide');
        location.reload(); // Reload to show new row
    });
});
</script>
<script>
$(document).ready(function() {
    $('.edit-btn').click(function() {
        var $row = $(this).closest('tr');

        $row.find('td').each(function() {
            var $cell = $(this);
            var name = $cell.attr('class');
            if (name && !$cell.find('input').length && name !== 'actions') {
                var text = $cell.text().trim();
                $cell.html('<input type="text" class="form-control input-sm" name="' + name + '" value="' + text + '">');
            }
        });
        
        $row.find('.edit-btn').hide();
        $row.find('.save-btn').show();
    });

    $('.save-btn').click(function() {
        var $row = $(this).closest('tr');
        var id = $row.data('id');
        var updatedData = {};

        $row.find('input').each(function() {
            var name = $(this).attr('name');
            var value = $(this).val();
            updatedData[name] = value;
        });

        $.post('scripts/update_shelf.php', { id: id, ...updatedData }, function(response) {
            $row.find('td').each(function() {
                var $cell = $(this);
                var input = $cell.find('input');
                if (input.length) {
                    $cell.text(input.val());
                }
            });
            $row.find('.save-btn').hide();
            $row.find('.edit-btn').show();
        });
    });
    const fields = ['location', 'capacity', 'category', 'description'];

fields.forEach(function(field) {
    var $cell = $row.find('td.' + field);
    var input = $cell.find('input');
    if (input.length) {
        $cell.text(input.val());
    }
});
});
</script>

