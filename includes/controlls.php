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
      <td style='width:0.1em;'>â€”</td>
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
      <td style='width:0.1em;'>â€”</td>
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
  $departmentNames = [
    'G' => 'Graphics',
    'S' => 'Seat Cover',
    'P' => 'Plastics',
    'F' => 'Fitting',
  ];
  $productSpecDefaults = [
    'graphics_material' => ['department' => 'G', 'label' => 'Material'],
    'graphics_finish' => ['department' => 'G', 'label' => 'Finish'],
    'graphics_grip' => ['department' => 'G', 'label' => 'Grip'],
    'graphics_tr_swingarms' => ['department' => 'G', 'label' => 'Tr. Swingarms'],
    'graphics_printer' => ['department' => 'G', 'label' => 'Printer'],
    'seat_waterproof_seams' => ['department' => 'S', 'label' => 'Waterproof Seams'],
    'seat_enduro_pocket' => ['department' => 'S', 'label' => 'Enduro Pocket'],
    'seat_side_brand_patches' => ['department' => 'S', 'label' => 'Side Brand Patches'],
  ];
  $departmentPrefixes = [
    'G' => 'graphics',
    'S' => 'seat',
    'P' => 'plastics',
    'F' => 'fitting',
  ];
  $normalizeProductSpecDepartment = static function (?string $code) use ($productSpecDefaults): string {
    $code = strtoupper(trim((string) $code));
    if (isset(['G' => true, 'S' => true, 'P' => true, 'F' => true][$code])) {
      return $code;
    }

    return '';
  };
  $humanizeProductSpecKey = static function (string $value): string {
    $value = str_replace(['_', '-'], ' ', trim($value));
    $value = preg_replace('/\s+/', ' ', $value);
    return $value !== '' ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : 'Custom Dropdown';
  };
  $buildProductSpecGroupLabel = static function (string $specKey, string $department) use ($departmentNames, $departmentPrefixes, $productSpecDefaults, $humanizeProductSpecKey): string {
    if (isset($productSpecDefaults[$specKey])) {
      $label = (string) ($productSpecDefaults[$specKey]['label'] ?? $specKey);
    } else {
      $label = $specKey;
      if ($department !== '' && isset($departmentPrefixes[$department])) {
        $prefix = $departmentPrefixes[$department] . '_';
        if (strpos($label, $prefix) === 0) {
          $label = substr($label, strlen($prefix));
        }
      }
      $label = $humanizeProductSpecKey($label);
    }

    $deptLabel = $departmentNames[$department] ?? $department;
    return ($deptLabel !== '' ? $department . ' - ' . $label : $label);
  };

  $productSpecGroups = [];
  foreach ($productSpecDefaults as $specKey => $meta) {
    $department = $normalizeProductSpecDepartment($meta['department'] ?? '');
    $groupKey = $specKey . '|' . $department;
    $productSpecGroups[$groupKey] = $buildProductSpecGroupLabel($specKey, $department);
  }

  $productSpecGroupStmt = $conn->prepare("
    SELECT DISTINCT spec_key, department
    FROM product_spec_options
    ORDER BY spec_key ASC, department ASC
  ");
  if ($productSpecGroupStmt && $productSpecGroupStmt->execute()) {
    $productSpecGroupResult = $productSpecGroupStmt->get_result();
    while ($groupRow = $productSpecGroupResult->fetch_assoc()) {
      $specKey = trim((string) ($groupRow['spec_key'] ?? ''));
      if ($specKey === '') {
        continue;
      }
      $department = $normalizeProductSpecDepartment($groupRow['department'] ?? ($productSpecDefaults[$specKey]['department'] ?? ''));
      $groupKey = $specKey . '|' . $department;
      $productSpecGroups[$groupKey] = $buildProductSpecGroupLabel($specKey, $department);
    }
    $productSpecGroupStmt->close();
  }
  asort($productSpecGroups, SORT_NATURAL | SORT_FLAG_CASE);

  $requestedSpecGroup = isset($_GET['spec_group']) ? trim((string) $_GET['spec_group']) : '';
  if ($requestedSpecGroup === '' && isset($_GET['spec_key'])) {
    $legacySpecKey = trim((string) $_GET['spec_key']);
    foreach ($productSpecGroups as $groupKey => $_groupLabel) {
      if (strpos($groupKey, $legacySpecKey . '|') === 0) {
        $requestedSpecGroup = $groupKey;
        break;
      }
    }
  }
  if ($requestedSpecGroup === '' || !isset($productSpecGroups[$requestedSpecGroup])) {
    $requestedSpecGroup = (string) array_key_first($productSpecGroups);
  }
  [$currentSpecKey, $currentSpecDepartment] = array_pad(explode('|', $requestedSpecGroup, 2), 2, '');
  $currentSpecDepartment = $normalizeProductSpecDepartment($currentSpecDepartment);

  $productSpecSourceKeys = [];
  $productSpecSourceStmt = $conn->prepare("
    SELECT item_type_code, options_json
    FROM order_items
    WHERE deleted_at IS NULL
      AND options_json IS NOT NULL
      AND options_json <> ''
      AND options_json <> '{}'
    ORDER BY id DESC
    LIMIT 1500
  ");
  if ($productSpecSourceStmt && $productSpecSourceStmt->execute()) {
    $productSpecSourceResult = $productSpecSourceStmt->get_result();
    while ($sourceRow = $productSpecSourceResult->fetch_assoc()) {
      $itemType = strtoupper(trim((string) ($sourceRow['item_type_code'] ?? '')));
      if ($itemType === 'T' || $itemType === 'M') {
        $itemType = 'P';
      }
      $itemType = $normalizeProductSpecDepartment($itemType);
      if ($itemType === '') {
        continue;
      }

      $decoded = json_decode((string) ($sourceRow['options_json'] ?? ''), true);
      if (!is_array($decoded)) {
        continue;
      }

      foreach ($decoded as $rawKey => $rawValue) {
        $sourceKey = trim((string) $rawKey);
        if ($sourceKey === '' || $sourceKey[0] === '_') {
          continue;
        }
        if (!is_scalar($rawValue) && $rawValue !== null) {
          continue;
        }

        if (!isset($productSpecSourceKeys[$sourceKey])) {
          $productSpecSourceKeys[$sourceKey] = [
            'label' => $humanizeProductSpecKey($sourceKey),
            'departments' => [],
          ];
        }

        $productSpecSourceKeys[$sourceKey]['departments'][$itemType] = true;
      }
    }
    $productSpecSourceStmt->close();
  }
  ksort($productSpecSourceKeys, SORT_NATURAL | SORT_FLAG_CASE);

  $statusDropdownGroups = [
    'order|' => 'Order - Overall',
    'item|G' => 'Item - Graphics (G)',
    'item|S' => 'Item - Seat Cover (S)',
    'item|P' => 'Item - Plastics (P)',
    'item|F' => 'Item - Fitting (F)',
  ];
  $currentStatusGroup = isset($_GET['status_group']) ? (string) $_GET['status_group'] : 'order|';
  if (!isset($statusDropdownGroups[$currentStatusGroup])) {
    $currentStatusGroup = 'order|';
  }
  [$currentStatusScope, $currentStatusDepartment] = array_pad(explode('|', $currentStatusGroup, 2), 2, '');
  $currentStatusDepartmentOrNull = $currentStatusDepartment !== '' ? $currentStatusDepartment : null;
  ?>

  <hr class="my-4">

  <style>
    .settings-compact-card .card-header {
      padding: .55rem .75rem;
    }

    .settings-compact-card .card-title {
      font-size: 1rem;
    }

    .settings-compact-card .table th,
    .settings-compact-card .table td {
      padding: .4rem .45rem;
      vertical-align: middle;
      font-size: .875rem;
    }

    .settings-compact-card .card-body-scroll {
      max-height: none;
      overflow: visible;
    }

    .status-color-preview {
      display: inline-block;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      border: 1px solid rgba(0, 0, 0, .25);
      vertical-align: middle;
      margin-right: 6px;
      background: transparent;
    }

    .product-spec-create-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: .75rem;
      align-items: end;
    }

    .product-spec-create-grid .form-group {
      margin-bottom: 0;
    }

    .product-spec-create-panel {
      background: rgba(255, 255, 255, .04);
      border: 1px solid rgba(255, 255, 255, .08);
      border-radius: .35rem;
      padding: .85rem;
    }
  </style>

  <div class="row">
    <div class="col-lg-6">
      <div class="card card-dark border-info settings-compact-card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
          <h3 class="card-title mb-0">Product Specification Dropdowns</h3>
          <div class="d-flex align-items-center flex-nowrap" style="gap:8px;">
            <select class="form-control form-control-sm product-spec-key-filter" style="min-width:260px;">
              <?php foreach ($productSpecGroups as $key => $label): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" <?= $requestedSpecGroup === $key ? 'selected' : ''; ?>>
                  <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn bg-gradient-info btn-xs add-product-spec-dropdown"
              style="white-space: nowrap; min-width: 122px;">
              <i class="fa fa-plus"></i> New Dropdown
            </button>
            <button class="btn bg-gradient-success btn-xs add-product-spec-option"
              style="white-space: nowrap; min-width: 110px;">
              <i class="fa fa-plus"></i> Add Option
            </button>
          </div>
        </div>
        <div class="card-body p-0 card-body-scroll">
          <table class="table table-bordered table-striped mb-0 product-spec-options-table"
            data-spec-key="<?= htmlspecialchars($currentSpecKey, ENT_QUOTES, 'UTF-8'); ?>"
            data-spec-group="<?= htmlspecialchars($requestedSpecGroup, ENT_QUOTES, 'UTF-8'); ?>"
            data-department="<?= htmlspecialchars($currentSpecDepartment, ENT_QUOTES, 'UTF-8'); ?>">
            <thead>
              <tr>
                <th style="background-color:gray; width:70px;">ID</th>
                <th style="background-color:gray; width:90px;">Dept</th>
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
              $stmt = $conn->prepare("
                SELECT id, spec_key, department, label, value, sort_order, active
                FROM product_spec_options
                WHERE spec_key = ?
                  AND (? = '' OR department = ? OR department IS NULL)
                ORDER BY sort_order ASC, id ASC
              ");
              if ($stmt) {
                $stmt->bind_param('sss', $currentSpecKey, $currentSpecDepartment, $currentSpecDepartment);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()):
                  $rowDepartment = $normalizeProductSpecDepartment($row['department'] ?? ($productSpecDefaults[$row['spec_key']]['department'] ?? ''));
                  $rowGroupKey = (string) ($row['spec_key'] ?? '') . '|' . $rowDepartment;
                  ?>
                  <tr data-id="<?= (int) $row['id']; ?>"
                    data-spec-key="<?= htmlspecialchars($row['spec_key'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-department="<?= htmlspecialchars($rowDepartment, ENT_QUOTES, 'UTF-8'); ?>"
                    data-spec-group="<?= htmlspecialchars($rowGroupKey, ENT_QUOTES, 'UTF-8'); ?>">
                    <td><?= (int) $row['id']; ?></td>
                    <td class="spec-department-cell"><?= htmlspecialchars($rowDepartment, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="spec-name-cell">
                      <?= htmlspecialchars($productSpecGroups[$rowGroupKey] ?? $buildProductSpecGroupLabel((string) $row['spec_key'], $rowDepartment), ENT_QUOTES, 'UTF-8'); ?>
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
    <div class="col-lg-6">
      <div class="card card-dark border-warning settings-compact-card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
          <h3 class="card-title mb-0">Status Dropdowns</h3>
          <div class="d-flex align-items-center flex-nowrap" style="gap:8px;">
            <select class="form-control form-control-sm status-definition-group-filter" style="min-width:240px;">
              <?php foreach ($statusDropdownGroups as $key => $label): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" <?= $currentStatusGroup === $key ? 'selected' : ''; ?>>
                  <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn bg-gradient-success btn-xs add-status-definition" style="white-space: nowrap; min-width: 110px;">
              <i class="fa fa-plus"></i> Add Status
            </button>
          </div>
        </div>
        <div class="card-body p-0 card-body-scroll">
          <table class="table table-bordered table-striped mb-0 status-definitions-table" data-group-key="<?= htmlspecialchars($currentStatusGroup, ENT_QUOTES, 'UTF-8'); ?>">
            <thead>
              <tr>
                <th style="background-color:gray; width:60px;">ID</th>
                <th style="background-color:gray; width:150px;">Dropdown</th>
                <th style="background-color:gray; width:120px;">Code</th>
                <th style="background-color:gray;">Label</th>
                <th style="background-color:gray; width:110px;">Color</th>
                <th style="background-color:gray; width:85px;">Order</th>
                <th style="background-color:gray; width:75px;">Active</th>
                <th style="background-color:gray; width:180px;">Tools</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $stmt = $conn->prepare("
                SELECT id, scope, department, code, label, color, sort_order, active
                FROM status_definitions
                WHERE scope = ?
                  AND ((? IS NULL AND department IS NULL) OR department = ?)
                ORDER BY sort_order ASC, id ASC
              ");
              if ($stmt) {
                $stmt->bind_param('sss', $currentStatusScope, $currentStatusDepartmentOrNull, $currentStatusDepartmentOrNull);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()):
                  $color = trim((string) ($row['color'] ?? ''));
                  ?>
                  <tr data-id="<?= (int) $row['id']; ?>" data-group-key="<?= htmlspecialchars($currentStatusGroup, ENT_QUOTES, 'UTF-8'); ?>">
                    <td><?= (int) $row['id']; ?></td>
                    <td class="status-group-cell"><?= htmlspecialchars($statusDropdownGroups[$currentStatusGroup] ?? $currentStatusGroup, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="status-code-cell"><?= htmlspecialchars($row['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="status-label-cell"><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="status-color-cell">
                      <span class="status-color-preview" style="background-color: <?= htmlspecialchars($color !== '' ? $color : 'transparent', ENT_QUOTES, 'UTF-8'); ?>;"></span>
                      <span class="status-color-text"><?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8'); ?></span>
                    </td>
                    <td class="status-sort-cell"><?= (int) $row['sort_order']; ?></td>
                    <td class="status-active-cell"><?= ((int) $row['active'] === 1 ? 'Yes' : 'No'); ?></td>
                    <td>
                      <button class="btn bg-gradient-primary btn-sm edit-status-definition"><i class="fa fa-edit"></i> Edit</button>
                      <button class="btn bg-gradient-success btn-sm save-status-definition" style="display:none;"><i class="fa fa-save"></i> Save</button>
                      <button class="btn bg-gradient-danger btn-sm delete-status-definition"><i class="fa fa-trash"></i></button>
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

      const specGroupLabels = <?= json_encode($productSpecGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const productSpecSourceKeys = <?= json_encode($productSpecSourceKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const departmentLabels = <?= json_encode($departmentNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const departmentPrefixes = <?= json_encode($departmentPrefixes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

      function escapeHtml(value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function humanizeSpecToken(value) {
        return String(value || '')
          .replace(/[_-]+/g, ' ')
          .replace(/\s+/g, ' ')
          .trim()
          .replace(/\b\w/g, function (match) { return match.toUpperCase(); });
      }

      function normalizeDepartment(value) {
        const dept = String(value || '').trim().toUpperCase();
        return departmentLabels[dept] ? dept : '';
      }

      function buildSpecKeyFromSourceKey(department, sourceKey) {
        const prefix = departmentPrefixes[department] || 'custom';
        const slug = String(sourceKey || '')
          .trim()
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '_')
          .replace(/^_+|_+$/g, '')
          .replace(/_+/g, '_');

        return slug ? (prefix + '_' + slug) : prefix;
      }

      function buildProductSpecGroupKey(specKey, department) {
        return String(specKey || '').trim() + '|' + normalizeDepartment(department);
      }

      function buildProductSpecGroupLabel(specKey, department) {
        const normalizedDept = normalizeDepartment(department);
        const deptLabel = normalizedDept ? (normalizedDept + ' - ') : '';
        let labelSource = String(specKey || '').trim();
        const prefix = departmentPrefixes[normalizedDept] ? (departmentPrefixes[normalizedDept] + '_') : '';

        if (prefix && labelSource.indexOf(prefix) === 0) {
          labelSource = labelSource.substring(prefix.length);
        }

        return deptLabel + (humanizeSpecToken(labelSource) || 'Custom Dropdown');
      }

      function sourceKeyDepartmentHint(sourceKey) {
        const meta = productSpecSourceKeys[String(sourceKey || '').trim()] || null;
        if (!meta || !meta.departments) {
          return '';
        }

        const departments = Object.keys(meta.departments);
        return departments.length === 1 ? departments[0] : '';
      }

      $('.product-spec-key-filter').on('change', function () {
        const key = $(this).val();
        const url = new URL(window.location.href);
        url.searchParams.set('spec_group', key);
        url.searchParams.delete('spec_key');
        window.location.href = url.toString();
      });

      $('.add-product-spec-option').on('click', function () {
        const $table = $('.product-spec-options-table');
        const specKey = $table.data('spec-key');
        const department = normalizeDepartment($table.data('department'));
        const specGroup = $table.data('spec-group');
        const specName = specGroupLabels[specGroup] || buildProductSpecGroupLabel(specKey, department);

        const newRow = `
      <tr class="new-product-spec-row" data-spec-key="${escapeHtml(specKey)}" data-department="${escapeHtml(department)}" data-spec-group="${escapeHtml(specGroup)}">
        <td>&mdash;</td>
        <td>${escapeHtml(department)}</td>
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

      $('.add-product-spec-dropdown').on('click', function () {
        if ($('.new-product-spec-dropdown-row').length) {
          return;
        }

        const sourceOptions = Object.keys(productSpecSourceKeys).map(function (key) {
          const meta = productSpecSourceKeys[key] || {};
          const depts = Object.keys(meta.departments || {});
          const deptHint = depts.length ? ' [' + depts.join(', ') + ']' : '';
          return `<option value="${escapeHtml(key)}" label="${escapeHtml((meta.label || humanizeSpecToken(key)) + deptHint)}"></option>`;
        }).join('');

        const newRow = `
          <tr class="new-product-spec-dropdown-row">
            <td>&mdash;</td>
            <td colspan="7">
              <div class="product-spec-create-panel">
                <div class="product-spec-create-grid">
                  <div class="form-group">
                    <label class="small text-muted mb-1">Department</label>
                    <select class="form-control form-control-sm new-dropdown-department">
                      <option value="G">G - Graphics</option>
                      <option value="S">S - Seat Cover</option>
                      <option value="P">P - Plastics</option>
                      <option value="F">F - Fitting</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">Source key from options_json</label>
                    <input type="text" class="form-control form-control-sm new-dropdown-source-key" list="productSpecSourceKeyList" placeholder="mid-forks">
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">Dropdown key</label>
                    <input type="text" class="form-control form-control-sm new-dropdown-spec-key" placeholder="graphics_mid_forks">
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">First option label</label>
                    <input type="text" class="form-control form-control-sm new-spec-label" placeholder="Yes">
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">First option value</label>
                    <input type="text" class="form-control form-control-sm new-spec-value" placeholder="Yes">
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">Order</label>
                    <input type="number" class="form-control form-control-sm new-spec-sort" value="0" step="1">
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">Active</label>
                    <select class="form-control form-control-sm new-spec-active">
                      <option value="1" selected>Yes</option>
                      <option value="0">No</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">Preview</label>
                    <div class="small text-info new-dropdown-preview">G - Custom Dropdown</div>
                  </div>
                </div>
                <datalist id="productSpecSourceKeyList">${sourceOptions}</datalist>
                <div class="d-flex justify-content-end mt-3" style="gap:8px;">
                  <button class="btn bg-gradient-success btn-sm confirm-product-spec-dropdown-add"><i class="fa fa-check"></i> Create Dropdown</button>
                  <button class="btn bg-gradient-secondary btn-sm cancel-product-spec-dropdown-add"><i class="fa fa-times"></i> Cancel</button>
                </div>
              </div>
            </td>
          </tr>`;

        $('.product-spec-options-table tbody').prepend(newRow);
      });

      $('.product-spec-options-table').on('click', '.cancel-product-spec-add', function () {
        $(this).closest('tr').remove();
      });

      $('.product-spec-options-table').on('click', '.cancel-product-spec-dropdown-add', function () {
        $(this).closest('tr').remove();
      });

      function refreshNewDropdownPreview($row, shouldAutofillKey) {
        const department = normalizeDepartment($row.find('.new-dropdown-department').val());
        const sourceKey = $row.find('.new-dropdown-source-key').val().trim();
        const currentSpecKey = $row.find('.new-dropdown-spec-key').val().trim();
        const specKey = shouldAutofillKey || currentSpecKey === '' ? buildSpecKeyFromSourceKey(department, sourceKey) : currentSpecKey;

        if (shouldAutofillKey || currentSpecKey === '') {
          $row.find('.new-dropdown-spec-key').val(specKey);
        }

        $row.find('.new-dropdown-preview').text(buildProductSpecGroupLabel(specKey, department));
      }

      $('.product-spec-options-table').on('change input', '.new-dropdown-department, .new-dropdown-source-key', function () {
        const $row = $(this).closest('.new-product-spec-dropdown-row');
        const sourceKey = $row.find('.new-dropdown-source-key').val().trim();
        const hintedDept = sourceKeyDepartmentHint(sourceKey);

        if ($(this).hasClass('new-dropdown-source-key') && hintedDept) {
          $row.find('.new-dropdown-department').val(hintedDept);
        }

        refreshNewDropdownPreview($row, true);
      });

      $('.product-spec-options-table').on('input', '.new-dropdown-spec-key', function () {
        const $row = $(this).closest('.new-product-spec-dropdown-row');
        refreshNewDropdownPreview($row, false);
      });

      $('.product-spec-options-table').on('click', '.confirm-product-spec-add', function () {
        const $row = $(this).closest('tr');
        const specKey = $row.data('spec-key');
        const department = normalizeDepartment($row.data('department'));
        const specGroup = $row.data('spec-group');
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
          data: { spec_key: specKey, department: department, label: label, value: value, sort_order: sortOrder, active: active },
          success: function (data) {
            if (!data || !data.ok) {
              alert(data && data.error ? data.error : 'Insert failed.');
              return;
            }
            const specName = specGroupLabels[specGroup] || buildProductSpecGroupLabel(specKey, department);
            $row.replaceWith(`
          <tr data-id="${data.id}" data-spec-key="${escapeHtml(specKey)}" data-department="${escapeHtml(department)}" data-spec-group="${escapeHtml(specGroup)}">
            <td>${data.id}</td>
            <td class="spec-department-cell">${escapeHtml(department)}</td>
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

      $('.product-spec-options-table').on('click', '.confirm-product-spec-dropdown-add', function () {
        const $row = $(this).closest('.new-product-spec-dropdown-row');
        const department = normalizeDepartment($row.find('.new-dropdown-department').val());
        const sourceKey = $row.find('.new-dropdown-source-key').val().trim();
        const specKey = $row.find('.new-dropdown-spec-key').val().trim().toLowerCase();
        const label = $row.find('.new-spec-label').val().trim();
        const value = $row.find('.new-spec-value').val().trim();
        const sortOrder = parseInt($row.find('.new-spec-sort').val(), 10) || 0;
        const active = parseInt($row.find('.new-spec-active').val(), 10) || 0;

        if (department === '' || sourceKey === '' || specKey === '' || label === '' || value === '') {
          alert('Department, source key, dropdown key, label and value are required.');
          return;
        }

        const groupKey = buildProductSpecGroupKey(specKey, department);

        $.ajax({
          url: 'scripts/settings/insert_product_spec_option.php',
          method: 'POST',
          dataType: 'json',
          data: {
            spec_key: specKey,
            department: department,
            label: label,
            value: value,
            sort_order: sortOrder,
            active: active
          },
          success: function (data) {
            if (!data || !data.ok) {
              alert(data && data.error ? data.error : 'Create failed.');
              return;
            }

            const url = new URL(window.location.href);
            url.searchParams.set('spec_group', groupKey);
            url.searchParams.delete('spec_key');
            window.location.href = url.toString();
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

  <script>
    (function () {
      'use strict';

      const statusGroupLabels = <?= json_encode($statusDropdownGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

      function escapeHtml(value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function renderStatusColorCell(color) {
        const safeColor = String(color || '').trim();
        const bgColor = safeColor !== '' ? safeColor : 'transparent';
        return `<span class="status-color-preview" style="background-color:${escapeHtml(bgColor)};"></span><span class="status-color-text">${escapeHtml(safeColor)}</span>`;
      }

      $('.status-definition-group-filter').on('change', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('status_group', $(this).val());
        window.location.href = url.toString();
      });

      $('.add-status-definition').on('click', function () {
        const groupKey = $('.status-definitions-table').data('group-key');
        const groupName = statusGroupLabels[groupKey] || groupKey;

        const newRow = `
          <tr class="new-status-definition-row" data-group-key="${escapeHtml(groupKey)}">
            <td>&mdash;</td>
            <td>${escapeHtml(groupName)}</td>
            <td><input type="text" class="form-control form-control-sm new-status-code" placeholder="READY_TO_SHIP"></td>
            <td><input type="text" class="form-control form-control-sm new-status-label" placeholder="Ready to Ship"></td>
            <td><input type="text" class="form-control form-control-sm new-status-color" placeholder="#28a745"></td>
            <td><input type="number" class="form-control form-control-sm new-status-sort" value="0" step="1"></td>
            <td>
              <select class="form-control form-control-sm new-status-active">
                <option value="1" selected>Yes</option>
                <option value="0">No</option>
              </select>
            </td>
            <td>
              <button class="btn bg-gradient-success btn-sm confirm-status-definition-add"><i class="fa fa-check"></i> Confirm</button>
              <button class="btn bg-gradient-secondary btn-sm cancel-status-definition-add"><i class="fa fa-times"></i> Cancel</button>
            </td>
          </tr>`;

        $('.status-definitions-table tbody').prepend(newRow);
      });

      $('.status-definitions-table').on('click', '.cancel-status-definition-add', function () {
        $(this).closest('tr').remove();
      });

      $('.status-definitions-table').on('click', '.confirm-status-definition-add', function () {
        const $row = $(this).closest('tr');
        const groupKey = $row.data('group-key');
        const code = $row.find('.new-status-code').val().trim().toUpperCase();
        const label = $row.find('.new-status-label').val().trim();
        const color = $row.find('.new-status-color').val().trim();
        const sortOrder = parseInt($row.find('.new-status-sort').val(), 10) || 0;
        const active = parseInt($row.find('.new-status-active').val(), 10) || 0;

        if (code === '' || label === '') {
          alert('Code and label are required.');
          return;
        }

        $.ajax({
          url: 'scripts/settings/insert_status_definition.php',
          method: 'POST',
          dataType: 'json',
          data: { group_key: groupKey, code: code, label: label, color: color, sort_order: sortOrder, active: active },
          success: function (data) {
            if (!data || !data.ok) {
              alert(data && data.error ? data.error : 'Insert failed.');
              return;
            }
            const groupName = statusGroupLabels[groupKey] || groupKey;
            $row.replaceWith(`
              <tr data-id="${data.id}" data-group-key="${escapeHtml(groupKey)}">
                <td>${data.id}</td>
                <td class="status-group-cell">${escapeHtml(groupName)}</td>
                <td class="status-code-cell">${escapeHtml(code)}</td>
                <td class="status-label-cell">${escapeHtml(label)}</td>
                <td class="status-color-cell">${renderStatusColorCell(color)}</td>
                <td class="status-sort-cell">${sortOrder}</td>
                <td class="status-active-cell">${active === 1 ? 'Yes' : 'No'}</td>
                <td>
                  <button class="btn bg-gradient-primary btn-sm edit-status-definition"><i class="fa fa-edit"></i> Edit</button>
                  <button class="btn bg-gradient-success btn-sm save-status-definition" style="display:none;"><i class="fa fa-save"></i> Save</button>
                  <button class="btn bg-gradient-danger btn-sm delete-status-definition"><i class="fa fa-trash"></i></button>
                </td>
              </tr>`);
          }
        });
      });

      $('.status-definitions-table').on('click', '.edit-status-definition', function () {
        const $row = $(this).closest('tr');
        const code = $row.find('.status-code-cell').text().trim();
        const label = $row.find('.status-label-cell').text().trim();
        const color = $row.find('.status-color-text').text().trim();
        const sortOrder = $row.find('.status-sort-cell').text().trim();
        const active = $row.find('.status-active-cell').text().trim() === 'Yes' ? '1' : '0';

        $row.find('.status-code-cell').html(`<input type="text" class="form-control form-control-sm status-code-input" value="${escapeHtml(code)}">`);
        $row.find('.status-label-cell').html(`<input type="text" class="form-control form-control-sm status-label-input" value="${escapeHtml(label)}">`);
        $row.find('.status-color-cell').html(`<input type="text" class="form-control form-control-sm status-color-input" value="${escapeHtml(color)}" placeholder="#28a745">`);
        $row.find('.status-sort-cell').html(`<input type="number" class="form-control form-control-sm status-sort-input" value="${escapeHtml(sortOrder)}" step="1">`);
        $row.find('.status-active-cell').html(`
          <select class="form-control form-control-sm status-active-input">
            <option value="1" ${active === '1' ? 'selected' : ''}>Yes</option>
            <option value="0" ${active === '0' ? 'selected' : ''}>No</option>
          </select>`);

        $(this).hide();
        $row.find('.save-status-definition').show();
      });

      $('.status-definitions-table').on('click', '.save-status-definition', function () {
        const $row = $(this).closest('tr');
        const id = parseInt($row.data('id'), 10) || 0;
        const code = $row.find('.status-code-input').val().trim().toUpperCase();
        const label = $row.find('.status-label-input').val().trim();
        const color = $row.find('.status-color-input').val().trim();
        const sortOrder = parseInt($row.find('.status-sort-input').val(), 10) || 0;
        const active = parseInt($row.find('.status-active-input').val(), 10) || 0;

        if (!id || code === '' || label === '') {
          alert('Code and label are required.');
          return;
        }

        $.ajax({
          url: 'scripts/settings/update_status_definition.php',
          method: 'POST',
          dataType: 'json',
          data: { id: id, code: code, label: label, color: color, sort_order: sortOrder, active: active },
          success: function (data) {
            if (!data || !data.ok) {
              alert(data && data.error ? data.error : 'Save failed.');
              return;
            }
            $row.find('.status-code-cell').text(code);
            $row.find('.status-label-cell').text(label);
            $row.find('.status-color-cell').html(renderStatusColorCell(color));
            $row.find('.status-sort-cell').text(sortOrder);
            $row.find('.status-active-cell').text(active === 1 ? 'Yes' : 'No');
            $row.find('.save-status-definition').hide();
            $row.find('.edit-status-definition').show();
          }
        });
      });

      $('.status-definitions-table').on('click', '.delete-status-definition', function () {
        if (!confirm('Delete this status option?')) return;

        const $row = $(this).closest('tr');
        const id = parseInt($row.data('id'), 10) || 0;
        if (!id) return;

        $.ajax({
          url: 'scripts/settings/delete_status_definition.php',
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
