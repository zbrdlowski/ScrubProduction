
<!-- pracovne departmenty -->
<div class="container-fluid">
    <div class="row">
        <div class="col-md-6">
<table class="table table-bordered table-striped position-table">
    <thead>
      <tr>
        <th style="background-color:gray;">ID</th>
        <th style="background-color:gray;">Position  <button class="btn bg-gradient-success btn-xs ml-2 add-btn"><i class="fa fa-plus"></i> Add Position </button></th>
        <th style="background-color:gray;">Tools</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $sql = "SELECT * FROM position";
      $query = $conn->query($sql);
      while ($row = $query->fetch_assoc()):
      ?>
      <tr data-id="<?= $row['id']; ?>">
        <td style='width:0.1em;'><?= $row['id']; ?></td>
        <td class="desc-cell"><?= htmlspecialchars($row['description']); ?></td>
        <td style='width:12em;'>
          <button class='btn bg-gradient-primary btn-sm edit-btn'><i class='fa fa-edit'></i> Edit</button>
          <button class='btn bg-gradient-success btn-sm save-btn' style='display:none;'><i class='fa fa-save'></i> Save Changes</button>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<!-- script na inline edit -->
<script src="js/jquery-3.6.0.min.js"></script>
<script>
$('.edit-btn').on('click', function () {
  var $row = $(this).closest('tr');
  var descText = $row.find('.desc-cell').text().trim();

  $row.find('.desc-cell').html(
    `<input type='text' class='form-control desc-input' value='${descText}'>`
  );

  $(this).hide();
  $row.find('.save-btn').show();
});

$('.save-btn').on('click', function () {
  var $row = $(this).closest('tr');
  var id = $row.data('id');
  var newDesc = $row.find('.desc-input').val();

  $.ajax({
    url: 'update_position.php',
    method: 'POST',
    data: { id: id, description: newDesc },
    success: function (response) {
      $row.find('.desc-cell').text(newDesc);
      $row.find('.save-btn').hide();
      $row.find('.edit-btn').show();
    }
  });
});
$('.position-table .add-btn').on('click', function () {
  const newRow = `
    <tr class="new-row">
      <td style='width:0.1em;'>—</td>
      <td class="desc-cell">
        <input type='text' class='form-control new-desc' placeholder='Enter new position'>
      </td>
      <td style='width:12em;'>
        <button class='btn bg-gradient-success btn-sm confirm-add'><i class='fa fa-check'></i> Confirm</button>
        <button class='btn bg-gradient-secondary btn-sm cancel-add'><i class='fa fa-times'></i> Cancel</button>
      </td>
    </tr>
  `;
  $('.position-table tbody').prepend(newRow);
});

$('table').on('click', '.cancel-add', function () {
  $(this).closest('tr').remove();
});

$('table').on('click', '.confirm-add', function () {
  const $row = $(this).closest('tr');
  const newDesc = $row.find('.new-desc').val().trim();

  if (newDesc === '') return alert('Description cannot be empty.');

  $.ajax({
    url: 'insert_position.php',
    method: 'POST',
    data: { description: newDesc },
    success: function (response) {
  const data = JSON.parse(response); // Parse the JSON string

  $row.replaceWith(`
    <tr data-id="${data.id}">
      <td style='width:0.1em;'>${data.id}</td>
      <td class="desc-cell">${newDesc}</td>
      <td style='width:12em;'>
        <button class='btn bg-gradient-primary btn-sm edit-btn'><i class='fa fa-edit'></i> Edit</button>        
      </td>
    </tr>
  `);
}

  });
});
</script>

<div class="col-md-6">
  <table class="table table-bordered table-striped schedule-table">
    <thead>
      <tr>
        <th style="background-color:gray;">ID</th>
        <th style="background-color:gray;">  Schedule  <button class="btn bg-gradient-success btn-xs  ml-2 add-schedule-btn">    <i class="fa fa-plus"></i> Add Schedule </button></th>
        <th style="background-color:gray;">Tools</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $sql = "SELECT * FROM schedules";
      $query = $conn->query($sql);
      while ($row = $query->fetch_assoc()):
      ?>
      <tr data-id="<?= $row['id']; ?>">
        <td class="id-cell" style='width:0.1em;'><?= $row['id']; ?></td>
        <td class="schedule-cell">
          <?= date('H:i', strtotime($row['time_in'])); ?> - <?= date('H:i', strtotime($row['time_out'])); ?>
        </td>
        <td style='width:12em;'>
          <button class='btn bg-gradient-primary btn-sm edit-btn'><i class='fa fa-edit'></i> Edit</button>
          <button class='btn bg-gradient-success btn-sm save-btn' style='display:none;'><i class='fa fa-save'></i> Save Changes</button>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<script src="js/jquery-3.6.0.min.js"></script>
<script>
$('.edit-btn').on('click', function () {
  var $row = $(this).closest('tr');
  var scheduleText = $row.find('.schedule-cell').text().trim();
  var times = scheduleText.split(' - ');
  var timeIn = times[0];
  var timeOut = times[1];

  $row.find('.schedule-cell').html(
    `<input type='time' class='form-control time-in' value='${timeIn}'>
     <input type='time' class='form-control time-out' value='${timeOut}'>`
  );

  $(this).hide();
  $row.find('.save-btn').show();
});

$('.save-btn').on('click', function () {
  var $row = $(this).closest('tr');
  var id = $row.data('id');
  var timeIn = $row.find('.time-in').val();
  var timeOut = $row.find('.time-out').val();

  $.ajax({
    url: 'update_schedule.php',
    method: 'POST',
    data: { id: id, time_in: timeIn, time_out: timeOut },
    success: function (response) {
      $row.find('.schedule-cell').text(`${timeIn} - ${timeOut}`);
      $row.find('.save-btn').hide();
      $row.find('.edit-btn').show();
    }
  });
});
$('.schedule-table .add-schedule-btn').on('click', function () {
  const newRow = `
    <tr class="new-schedule-row">
      <td style='width:0.1em;'>—</td>
      <td class="schedule-cell">
        <input type='time' class='form-control new-time-in' placeholder='Start'>
        <input type='time' class='form-control new-time-out' placeholder='End'>
      </td>
      <td style='width:12em;'>
        <button class='btn bg-gradient-success btn-sm confirm-schedule-add'><i class='fa fa-check'></i> Confirm</button> 
        <button class='btn bg-gradient-secondary btn-sm cancel-schedule-add'><i class='fa fa-times'></i> Cancel</button>       
      </td>
    </tr>
  `;
  $('.schedule-table tbody').prepend(newRow);
});

$('table').on('click', '.cancel-schedule-add', function () {
  $(this).closest('tr').remove();
});

$('table').on('click', '.confirm-schedule-add', function () {
  const $row = $(this).closest('tr');
  const timeIn = $row.find('.new-time-in').val().trim();
  const timeOut = $row.find('.new-time-out').val().trim();

  if (!timeIn || !timeOut) return alert('Both time fields are required.');

  $.ajax({
    url: 'insert_schedule.php',
    method: 'POST',
    dataType: 'json',
    data: { time_in: timeIn, time_out: timeOut },
    success: function (data) {
      $row.replaceWith(`
        <tr data-id="${data.id}">
          <td style='width:0.1em;'>${data.id}</td>
          <td class="schedule-cell">${timeIn} - ${timeOut}</td>
          <td style='width:12em;'>
            <button class='btn bg-gradient-primary btn-sm edit-btn'><i class='fa fa-edit'></i> Edit</button>            
          </td>
        </tr>
      `);
    }
  });
});
</script>
</div>

    

