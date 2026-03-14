<div class="container-fluid">
  <div class="row">
    <div class="col-md-12">
      <table class="table table-bordered table-striped template-table">
        <thead>
          <tr>
            <th style="background-color:gray;">ID</th>
            <th style="background-color:gray;">
              Name
              <button class="btn btn-success btn-xs ml-2 add-template-btn">
                <i class="fa fa-plus"></i> Add
              </button>
            </th>
            <th style="background-color:gray;">Tools</th>
          </tr>
        </thead>
        <tbody>
          <?php
          include 'includes/conn.php';
          $sql = "SELECT * FROM your_table"; // Replace with your actual table name
          $query = $conn->query($sql);
          while ($row = $query->fetch_assoc()):
          ?>
          <tr data-id="<?= $row['id']; ?>">
            <td style='width:0.1em;'><?= $row['id']; ?></td>
            <td class="name-cell"><?= htmlspecialchars($row['name']); ?></td>
            <td style='width:10em;'>
              <button class='btn btn-primary btn-sm edit-btn'><i class='fa fa-edit'></i> Edit</button>
              <button class='btn btn-success btn-sm save-btn' style='display:none;'><i class='fa fa-save'></i> Save</button>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="js/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function () {
  $('.template-table').on('click', '.edit-btn', function () {
    const $row = $(this).closest('tr');
    const nameText = $row.find('.name-cell').text().trim();

    $row.find('.name-cell').html(
      `<input type='text' class='form-control name-input' value='${nameText}'>`
    );

    $(this).hide();
    $row.find('.save-btn').show();
  });

  $('.template-table').on('click', '.save-btn', function () {
    const $row = $(this).closest('tr');
    const id = $row.data('id');
    const newName = $row.find('.name-input').val().trim();

    $.ajax({
      url: 'update_template.php', // Replace with your update script
      method: 'POST',
      data: { id: id, name: newName },
      success: function () {
        $row.find('.name-cell').text(newName);
        $row.find('.save-btn').hide();
        $row.find('.edit-btn').show();
      }
    });
  });

  $('.add-template-btn').on('click', function () {
    const newRow = `
      <tr class="new-template-row">
        <td style='width:0.1em;'>—</td>
        <td class="name-cell">
          <input type='text' class='form-control new-name' placeholder='Enter name'>
        </td>
        <td style='width:10em;'>
          <button class='btn btn-success btn-sm confirm-add'><i class='fa fa-check'></i> Confirm</button>
          <button class='btn btn-secondary btn-sm cancel-add'><i class='fa fa-times'></i> Cancel</button>
        </td>
      </tr>
    `;
    $('.template-table tbody').prepend(newRow);
  });

  $('.template-table').on('click', '.cancel-add', function () {
    $(this).closest('tr').remove();
  });

  $('.template-table').on('click', '.confirm-add', function () {
    const $row = $(this).closest('tr');
    const newName = $row.find('.new-name').val().trim();

    if (!newName) return alert('Name cannot be empty.');

    $.ajax({
      url: 'insert_template.php', // Replace with your insert script
      method: 'POST',
      dataType: 'json',
      data: { name: newName },
      success: function (data) {
        $row.replaceWith(`
          <tr data-id="${data.id}">
            <td style='width:0.1em;'>${data.id}</td>
            <td class="name-cell">${newName}</td>
            <td style='width:10em;'>
              <button class='btn btn-primary btn-sm edit-btn'><i class='fa fa-edit'></i> Edit</button>
              <button class='btn btn-danger btn-sm delete-btn'><i class='fa fa-trash'></i> Delete</button>
            </td>
          </tr>
        `);
      }
    });
  });
});
</script>

<?php
include 'includes/conn.php';
//vkladací script
$description = $conn->real_escape_string($_POST['description']);
$sql = "INSERT INTO position (description) VALUES ('$description')";
$conn->query($sql);
echo json_encode(['id' => $conn->insert_id]);
?>