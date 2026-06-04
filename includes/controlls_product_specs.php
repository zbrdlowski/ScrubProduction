<!-- pracovne departmenty -->
<div class="container-fluid">
  <div class="row">
    <div class="col-md-6">
      <table class="table table-bordered table-striped position-table">
        <thead>
          <tr>
            <th style="background-color:gray;">ID</th>
            <th style="background-color:gray;">Position <button class="btn bg-gradient-success btn-xs ml-2 add-btn"><i
                  class="fa fa-plus"></i> Add Position </button></th>
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
                <button class='btn bg-gradient-success btn-sm save-btn' style='display:none;'><i class='fa fa-save'></i>
                  Save Changes</button>
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
            <th style="background-color:gray;"> Schedule <button
                class="btn bg-gradient-success btn-xs  ml-2 add-schedule-btn"> <i class="fa fa-plus"></i> Add Schedule
              </button></th>
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
                <button class='btn bg-gradient-success btn-sm save-btn' style='display:none;'><i class='fa fa-save'></i>
                  Save Changes</button>
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




  <?php
  $productSpecTypes = [
    'graphics_material' => 'G - Material',
    'graphics_finish' => 'G - Finish',
    'graphics_grip' => 'G - Grip',
    'graphics_tr_swingarms' => 'G - Tr. Swingarms',
    'graphics_printer' => 'G - Printer',
    'seat_waterproof_seams' => 'S - Waterproof Seams',
    'seat_enduro_pocket' => 'S - Enduro Pocket',
    'seat_side_brand_patches' => 'S - Side Brand Patches',
  ];
  $currentSpecKey = isset($_GET['spec_key']) ? (string) $_GET['spec_key'] : 'graphics_material';
  if (!isset($productSpecTypes[$currentSpecKey])) {
    $currentSpecKey = 'graphics_material';
  }
  ?>

  <hr class="my-4">

  <div class="row">
    <div class="col-md-12">
      <div class="card card-dark border-info">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
          <h3 class="card-title mb-0">Product Specification Dropdowns</h3>
          <div class="d-flex align-items-center flex-nowrap" style="gap:8px;">
            <select class="form-control form-control-sm product-spec-key-filter" style="min-width:260px;">
              <?php foreach ($productSpecTypes as $key => $label): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" <?= $currentSpecKey === $key ? 'selected' : ''; ?>>
                  <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn bg-gradient-success btn-xs add-product-spec-option d-flex align-items-center"
              style="white-space: nowrap;">
              <i class="fa fa-plus mr-1"></i>
            </button>
          </div>
        </div>
        <div class="card-body p-0">
          <table class="table table-bordered table-striped mb-0 product-spec-options-table"
            data-spec-key="<?= htmlspecialchars($currentSpecKey, ENT_QUOTES, 'UTF-8'); ?>">
            <thead>
              <tr>
                <th style="background-color:gray; width:70px;">ID</th>
                <th style="background-color:gray; width:220px;">Dropdown</th>
                <th style="background-color:gray;">Label</th>
                <th style="background-color:gray;">Value</th>
                <th style="background-color:gray; width:110px;">Order</th>
                <th style="background-color:gray; width:90px;">Active</th>
                <th style="background-color:gray; width:210px;">Tools</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $stmt = $conn->prepare("SELECT id, spec_key, label, value, sort_order, active FROM product_spec_options WHERE spec_key = ? ORDER BY sort_order ASC, id ASC");
              if ($stmt) {
                $stmt->bind_param('s', $currentSpecKey);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()):
                  ?>
                  <tr data-id="<?= (int) $row['id']; ?>"
                    data-spec-key="<?= htmlspecialchars($row['spec_key'], ENT_QUOTES, 'UTF-8'); ?>">
                    <td><?= (int) $row['id']; ?></td>
                    <td class="spec-name-cell">
                      <?= htmlspecialchars($productSpecTypes[$row['spec_key']] ?? $row['spec_key'], ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td class="spec-label-cell"><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="spec-value-cell"><?= htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="spec-sort-cell"><?= (int) $row['sort_order']; ?></td>
                    <td class="spec-active-cell"><?= ((int) $row['active'] === 1 ? 'Yes' : 'No'); ?></td>
                    <td>
                      <button class="btn bg-gradient-primary btn-sm edit-product-spec-option"><i class="fa fa-edit"></i>
                        Edit</button>
                      <button class="btn bg-gradient-success btn-sm save-product-spec-option" style="display:none;"><i
                          class="fa fa-save"></i> Save</button>
                      <button class="btn bg-gradient-danger btn-sm delete-product-spec-option"><i
                          class="fa fa-trash"></i></button>
                    </td>
                  </tr>
                  <?php
                endwhile;
                $stmt->close();
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      'use strict';

      const specLabels = <?= json_encode($productSpecTypes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

      function escapeHtml(value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      $('.product-spec-key-filter').on('change', function () {
        const key = $(this).val();
        const url = new URL(window.location.href);
        url.searchParams.set('spec_key', key);
        window.location.href = url.toString();
      });

      $('.add-product-spec-option').on('click', function () {
        const specKey = $('.product-spec-options-table').data('spec-key');
        const specName = specLabels[specKey] || specKey;

        const newRow = `
      <tr class="new-product-spec-row" data-spec-key="${escapeHtml(specKey)}">
        <td>—</td>
        <td>${escapeHtml(specName)}</td>
        <td><input type="text" class="form-control form-control-sm new-spec-label" placeholder="Option label"></td>
        <td><input type="text" class="form-control form-control-sm new-spec-value" placeholder="Saved value"></td>
        <td><input type="number" class="form-control form-control-sm new-spec-sort" value="0" step="1"></td>
        <td>
          <select class="form-control form-control-sm new-spec-active">
            <option value="1" selected>Yes</option>
            <option value="0">No</option>
          </select>
        </td>
        <td>
          <button class="btn bg-gradient-success btn-sm confirm-product-spec-add"><i class="fa fa-check"></i> Confirm</button>
          <button class="btn bg-gradient-secondary btn-sm cancel-product-spec-add"><i class="fa fa-times"></i> Cancel</button>
        </td>
      </tr>`;

        $('.product-spec-options-table tbody').prepend(newRow);
      });

      $('.product-spec-options-table').on('click', '.cancel-product-spec-add', function () {
        $(this).closest('tr').remove();
      });

      $('.product-spec-options-table').on('click', '.confirm-product-spec-add', function () {
        const $row = $(this).closest('tr');
        const specKey = $row.data('spec-key');
        const label = $row.find('.new-spec-label').val().trim();
        const value = $row.find('.new-spec-value').val().trim();
        const sortOrder = parseInt($row.find('.new-spec-sort').val(), 10) || 0;
        const active = parseInt($row.find('.new-spec-active').val(), 10) || 0;

        if (label === '' || value === '') {
          alert('Label and value are required.');
          return;
        }

        $.ajax({
          url: 'scripts/settings/insert_product_spec_option.php',
          method: 'POST',
          dataType: 'json',
          data: { spec_key: specKey, label: label, value: value, sort_order: sortOrder, active: active },
          success: function (data) {
            if (!data || !data.ok) {
              alert(data && data.error ? data.error : 'Insert failed.');
              return;
            }
            const specName = specLabels[specKey] || specKey;
            $row.replaceWith(`
          <tr data-id="${data.id}" data-spec-key="${escapeHtml(specKey)}">
            <td>${data.id}</td>
            <td class="spec-name-cell">${escapeHtml(specName)}</td>
            <td class="spec-label-cell">${escapeHtml(label)}</td>
            <td class="spec-value-cell">${escapeHtml(value)}</td>
            <td class="spec-sort-cell">${sortOrder}</td>
            <td class="spec-active-cell">${active === 1 ? 'Yes' : 'No'}</td>
            <td>
              <button class="btn bg-gradient-primary btn-sm edit-product-spec-option"><i class="fa fa-edit"></i> Edit</button>
              <button class="btn bg-gradient-success btn-sm save-product-spec-option" style="display:none;"><i class="fa fa-save"></i> Save</button>
              <button class="btn bg-gradient-danger btn-sm delete-product-spec-option"><i class="fa fa-trash"></i></button>
            </td>
          </tr>`);
          }
        });
      });

      $('.product-spec-options-table').on('click', '.edit-product-spec-option', function () {
        const $row = $(this).closest('tr');
        const label = $row.find('.spec-label-cell').text().trim();
        const value = $row.find('.spec-value-cell').text().trim();
        const sortOrder = $row.find('.spec-sort-cell').text().trim();
        const active = $row.find('.spec-active-cell').text().trim() === 'Yes' ? '1' : '0';

        $row.find('.spec-label-cell').html(`<input type="text" class="form-control form-control-sm spec-label-input" value="${escapeHtml(label)}">`);
        $row.find('.spec-value-cell').html(`<input type="text" class="form-control form-control-sm spec-value-input" value="${escapeHtml(value)}">`);
        $row.find('.spec-sort-cell').html(`<input type="number" class="form-control form-control-sm spec-sort-input" value="${escapeHtml(sortOrder)}" step="1">`);
        $row.find('.spec-active-cell').html(`
      <select class="form-control form-control-sm spec-active-input">
        <option value="1" ${active === '1' ? 'selected' : ''}>Yes</option>
        <option value="0" ${active === '0' ? 'selected' : ''}>No</option>
      </select>`);

        $(this).hide();
        $row.find('.save-product-spec-option').show();
      });

      $('.product-spec-options-table').on('click', '.save-product-spec-option', function () {
        const $row = $(this).closest('tr');
        const id = parseInt($row.data('id'), 10) || 0;
        const label = $row.find('.spec-label-input').val().trim();
        const value = $row.find('.spec-value-input').val().trim();
        const sortOrder = parseInt($row.find('.spec-sort-input').val(), 10) || 0;
        const active = parseInt($row.find('.spec-active-input').val(), 10) || 0;

        if (!id || label === '' || value === '') {
          alert('Label and value are required.');
          return;
        }

        $.ajax({
          url: 'scripts/settings/update_product_spec_option.php',
          method: 'POST',
          dataType: 'json',
          data: { id: id, label: label, value: value, sort_order: sortOrder, active: active },
          success: function (data) {
            if (!data || !data.ok) {
              alert(data && data.error ? data.error : 'Save failed.');
              return;
            }
            $row.find('.spec-label-cell').text(label);
            $row.find('.spec-value-cell').text(value);
            $row.find('.spec-sort-cell').text(sortOrder);
            $row.find('.spec-active-cell').text(active === 1 ? 'Yes' : 'No');
            $row.find('.save-product-spec-option').hide();
            $row.find('.edit-product-spec-option').show();
          }
        });
      });

      $('.product-spec-options-table').on('click', '.delete-product-spec-option', function () {
        if (!confirm('Delete this dropdown option?')) return;

        const $row = $(this).closest('tr');
        const id = parseInt($row.data('id'), 10) || 0;
        if (!id) return;

        $.ajax({
          url: 'scripts/settings/delete_product_spec_option.php',
          method: 'POST',
          dataType: 'json',
          data: { id: id },
          success: function (data) {
            if (!data || !data.ok) {
              alert(data && data.error ? data.error : 'Delete failed.');
              return;
            }
            $row.remove();
          }
        });
      });
    })();
  </script>