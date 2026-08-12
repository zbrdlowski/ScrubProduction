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
      <td style='width:0.1em;'>&mdash;</td>
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
      <td style='width:0.1em;'>&mdash;</td>
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
  require_once __DIR__ . '/status_definition_extensions.php';
  statusDefinitionEnsureExtensions($conn);

  $departmentNames = [
    'G' => 'Graphics',
    'S' => 'Seat Cover',
    'P' => 'Plastics',
    'F' => 'Fitting',
  ];
  $productSpecDefaults = [
    'graphics_material' => ['department' => 'G', 'label' => 'Material', 'field_type' => 'dropdown'],
    'graphics_finish' => ['department' => 'G', 'label' => 'Finish', 'field_type' => 'dropdown'],
    'graphics_grip' => ['department' => 'G', 'label' => 'Grip', 'field_type' => 'dropdown'],
    'graphics_tr_swingarms' => ['department' => 'G', 'label' => 'Tr. Swingarms', 'field_type' => 'dropdown'],
    'graphics_printer' => ['department' => 'G', 'label' => 'Printer', 'field_type' => 'dropdown'],
    'seat_waterproof_seams' => ['department' => 'S', 'label' => 'Waterproof Seams', 'field_type' => 'dropdown'],
    'seat_enduro_pocket' => ['department' => 'S', 'label' => 'Enduro Pocket', 'field_type' => 'dropdown'],
    'seat_side_brand_patches' => ['department' => 'S', 'label' => 'Side Brand Patches', 'field_type' => 'dropdown'],
  ];
  $fieldTypeLabels = [
    'dropdown' => 'Dropdown',
    'text' => 'Text',
    'checkbox' => 'Checkbox',
    'radio' => 'Radio',
  ];
  $departmentPrefixes = [
    'G' => 'graphics',
    'S' => 'seat',
    'P' => 'plastics',
    'F' => 'fitting',
  ];
  $graphicsSubcategoryLabels = defined('GRAPHICS_SUBCAT_LABELS') ? GRAPHICS_SUBCAT_LABELS : [];
  $graphicsSubcategorySlugs = [];
  foreach ($graphicsSubcategoryLabels as $subCategoryCode => $_subCategoryLabel) {
    $graphicsSubcategorySlugs[$subCategoryCode] = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', (string) $subCategoryCode));
  }
  $normalizeProductSpecSourceKey = static function (string $value): string {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? $value;
    $value = trim($value, '-');
    return preg_replace('/-+/', '-', $value) ?? $value;
  };
  $decodeProductSpecStoredMeta = static function (?string $rawValue): array {
    $rawValue = trim((string) $rawValue);
    if ($rawValue === '') {
      return ['source_key' => '', 'field_label' => ''];
    }

    $decoded = json_decode($rawValue, true);
    if (is_array($decoded)) {
      return [
        'source_key' => trim((string) ($decoded['source_key'] ?? '')),
        'field_label' => trim((string) ($decoded['field_label'] ?? '')),
      ];
    }

    return ['source_key' => $rawValue, 'field_label' => ''];
  };
  $normalizeProductSpecDepartment = static function (?string $code): string {
    $code = strtoupper(trim((string) $code));
    if (isset(['G' => true, 'S' => true, 'P' => true, 'F' => true][$code])) {
      return $code;
    }

    return '';
  };
  $normalizeProductSpecSubcategory = static function (?string $code) use ($graphicsSubcategoryLabels): string {
    $code = strtoupper(trim((string) $code));
    return isset($graphicsSubcategoryLabels[$code]) ? $code : '';
  };
  $humanizeProductSpecKey = static function (string $value): string {
    $value = str_replace(['_', '-'], ' ', trim($value));
    $value = preg_replace('/\s+/', ' ', $value);
    return $value !== '' ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : 'Custom Dropdown';
  };
  $detectProductSpecSubcategory = static function (string $specKey, string $department) use ($graphicsSubcategorySlugs, $normalizeProductSpecSubcategory): string {
    if ($department !== 'G') {
      return '';
    }

    $normalizedSpecKey = strtolower(trim($specKey));
    foreach ($graphicsSubcategorySlugs as $subCategoryCode => $subCategorySlug) {
      $prefix = 'graphics_' . $subCategorySlug . '_';
      if (strpos($normalizedSpecKey, $prefix) === 0) {
        return $normalizeProductSpecSubcategory($subCategoryCode);
      }
    }

    return '';
  };
  $buildProductSpecGroupKey = static function (string $specKey, string $department, string $subCategory = '') use ($normalizeProductSpecDepartment, $normalizeProductSpecSubcategory): string {
    return trim($specKey) . '|' . $normalizeProductSpecDepartment($department) . '|' . $normalizeProductSpecSubcategory($subCategory);
  };
  $buildProductSpecGroupLabel = static function (string $specKey, string $department, string $subCategory = '') use ($departmentNames, $departmentPrefixes, $productSpecDefaults, $humanizeProductSpecKey, $graphicsSubcategoryLabels, $graphicsSubcategorySlugs, $normalizeProductSpecSubcategory): string {
    $subCategory = $normalizeProductSpecSubcategory($subCategory);
    if (isset($productSpecDefaults[$specKey])) {
      $label = (string) ($productSpecDefaults[$specKey]['label'] ?? $specKey);
    } else {
      $label = $specKey;
      if ($department === 'G' && $subCategory !== '' && isset($graphicsSubcategorySlugs[$subCategory])) {
        $prefix = 'graphics_' . $graphicsSubcategorySlugs[$subCategory] . '_';
        if (strpos($label, $prefix) === 0) {
          $label = substr($label, strlen($prefix));
        }
      } elseif ($department !== '' && isset($departmentPrefixes[$department])) {
        $prefix = $departmentPrefixes[$department] . '_';
        if (strpos($label, $prefix) === 0) {
          $label = substr($label, strlen($prefix));
        }
      }
      $label = $humanizeProductSpecKey($label);
    }

    $deptLabel = $departmentNames[$department] ?? $department;
    $groupLabel = $deptLabel !== '' ? $department . ' - ' . $deptLabel : $label;
    if ($subCategory !== '' && isset($graphicsSubcategoryLabels[$subCategory])) {
      $groupLabel .= ' - ' . $graphicsSubcategoryLabels[$subCategory];
    }

    return $groupLabel . ' - ' . $label;
  };
  $deriveProductSpecSourceKey = static function (string $specKey, string $department) use ($departmentPrefixes): string {
    $specKey = trim($specKey);
    if ($specKey === '') {
      return '';
    }

    if ($department !== '' && isset($departmentPrefixes[$department])) {
      $prefix = $departmentPrefixes[$department] . '_';
      if (strpos($specKey, $prefix) === 0) {
        $specKey = substr($specKey, strlen($prefix));
      }
    }

    return str_replace('_', '-', strtolower($specKey));
  };
  $productSpecCategoryOptions = [];
  /** @var \Closure(string, string=): void $registerProductSpecCategory */
  $registerProductSpecCategory = static function (string $department, string $subCategory = '') use (&$productSpecCategoryOptions, $departmentNames, $graphicsSubcategoryLabels, $normalizeProductSpecDepartment, $normalizeProductSpecSubcategory): void {
    $department = $normalizeProductSpecDepartment($department);
    $subCategory = $department === 'G' ? $normalizeProductSpecSubcategory($subCategory) : '';
    if ($department === '') {
      return;
    }

    $categoryKey = $department . '|' . $subCategory;
    $label = $department . ' - ' . ($departmentNames[$department] ?? $department);
    if ($subCategory !== '' && isset($graphicsSubcategoryLabels[$subCategory])) {
      $label .= ' - ' . $graphicsSubcategoryLabels[$subCategory];
    }

    $productSpecCategoryOptions[$categoryKey] = [
      'department' => $department,
      'subcategory' => $subCategory,
      'label' => $label,
    ];
  };
  $registerProductSpecCategory('G');
  foreach ($graphicsSubcategoryLabels as $subCategoryCode => $_subCategoryLabel) {
    $registerProductSpecCategory('G', (string) $subCategoryCode);
  }
  $registerProductSpecCategory('P');
  $registerProductSpecCategory('S');
  $registerProductSpecCategory('F');

  $productSpecGroups = [];
  foreach ($productSpecDefaults as $specKey => $meta) {
    $department = $normalizeProductSpecDepartment($meta['department'] ?? '');
    $subCategory = $detectProductSpecSubcategory($specKey, $department);
    $groupKey = $buildProductSpecGroupKey($specKey, $department, $subCategory);
    $productSpecGroups[$groupKey] = $buildProductSpecGroupLabel($specKey, $department, $subCategory);
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
      $subCategory = $detectProductSpecSubcategory($specKey, $department);
      $groupKey = $buildProductSpecGroupKey($specKey, $department, $subCategory);
      $productSpecGroups[$groupKey] = $buildProductSpecGroupLabel($specKey, $department, $subCategory);
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
  [$currentSpecKey, $currentSpecDepartment, $currentSpecSubcategory] = array_pad(explode('|', $requestedSpecGroup, 3), 3, '');
  $currentSpecDepartment = $normalizeProductSpecDepartment($currentSpecDepartment);
  $currentSpecSubcategory = $currentSpecDepartment === 'G' ? $normalizeProductSpecSubcategory($currentSpecSubcategory) : '';
  $currentProductSpecCategoryKey = $currentSpecDepartment . '|' . $currentSpecSubcategory;
  $productSpecHasFieldSortOrder = false;
  $productSpecHasFieldLabel = false;
  $productSpecFieldLabelStmt = $conn->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'product_spec_options'
      AND COLUMN_NAME = 'field_label'
    LIMIT 1
  ");
  if ($productSpecFieldLabelStmt && $productSpecFieldLabelStmt->execute()) {
    $productSpecFieldLabelResult = $productSpecFieldLabelStmt->get_result();
    $productSpecHasFieldLabel = $productSpecFieldLabelResult && $productSpecFieldLabelResult->fetch_assoc() !== null;
    $productSpecFieldLabelStmt->close();
  }
  if (!$productSpecHasFieldLabel) {
    $conn->query("
      ALTER TABLE product_spec_options
      ADD COLUMN field_label VARCHAR(190) NULL DEFAULT NULL AFTER field_type
    ");
    $productSpecHasFieldLabel = true;
  }
  $productSpecFieldSortStmt = $conn->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'product_spec_options'
      AND COLUMN_NAME = 'field_sort_order'
    LIMIT 1
  ");
  if ($productSpecFieldSortStmt && $productSpecFieldSortStmt->execute()) {
    $productSpecFieldSortOrderResult = $productSpecFieldSortStmt->get_result();
    $productSpecHasFieldSortOrder = $productSpecFieldSortOrderResult && $productSpecFieldSortOrderResult->fetch_assoc() !== null;
    $productSpecFieldSortStmt->close();
  }

  $productSpecSourceKeys = [];
  $registerProductSpecSourceKey = static function (string $sourceKey, string $department) use (&$productSpecSourceKeys, $humanizeProductSpecKey, $normalizeProductSpecSourceKey): void {
    $canonicalKey = $normalizeProductSpecSourceKey($sourceKey);
    if ($canonicalKey === '') {
      return;
    }

    if (!isset($productSpecSourceKeys[$canonicalKey])) {
      $productSpecSourceKeys[$canonicalKey] = [
        'label' => $humanizeProductSpecKey($canonicalKey),
        'display_key' => $canonicalKey,
        'aliases' => [],
        'departments' => [],
      ];
    }

    $trimmedSourceKey = trim($sourceKey);
    if ($trimmedSourceKey !== '') {
      $productSpecSourceKeys[$canonicalKey]['aliases'][$trimmedSourceKey] = true;
      if ($productSpecSourceKeys[$canonicalKey]['display_key'] === $canonicalKey && strpos($trimmedSourceKey, ' ') === false) {
        $productSpecSourceKeys[$canonicalKey]['display_key'] = $trimmedSourceKey;
      }
    }

    if ($department !== '') {
      $productSpecSourceKeys[$canonicalKey]['departments'][$department] = true;
    }
  };
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

        $registerProductSpecSourceKey($sourceKey, $itemType);
      }
    }
    $productSpecSourceStmt->close();
  }

  $defaultProductSpecSourceKeys = [
    'G' => ['base-material', 'graphics-finish', 'grip', 'tr-swingarms', 'name', 'number', 'name-font', 'number-font', 'number-color', 'number-plate-color', 'note'],
    'S' => ['waterproof-seams', 'enduro-pocket', 'side-brand-patches', 'patch-style', 'note'],
    'P' => ['my-item-note', 'note'],
    'F' => ['note'],
  ];
  foreach ($defaultProductSpecSourceKeys as $department => $sourceKeys) {
    foreach ($sourceKeys as $sourceKey) {
      $registerProductSpecSourceKey($sourceKey, $department);
    }
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
  $statusTargetOptions = statusDefinitionAllowedTargetKeys($currentStatusDepartmentOrNull);
  $statusTargetsByDefinition = [];
  $statusTargetResult = $conn->query("SELECT status_definition_id, target_type, subcategory_code FROM status_definition_targets ORDER BY target_type, subcategory_code");
  if ($statusTargetResult instanceof mysqli_result) {
    while ($targetRow = $statusTargetResult->fetch_assoc()) {
      $targetKey = strtoupper((string)($targetRow['target_type'] ?? ''));
      $subcategoryCode = strtoupper((string)($targetRow['subcategory_code'] ?? ''));
      if ($targetKey === 'SUBCATEGORY' && $subcategoryCode !== '') {
        $targetKey .= ':' . $subcategoryCode;
      }
      $statusTargetsByDefinition[(int)$targetRow['status_definition_id']][] = $targetKey;
    }
    $statusTargetResult->free();
  }
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
      overflow-x: auto;
      overflow-y: visible;
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


    .settings-dropdown-stack .settings-compact-card {
      width: 100%;
    }

    .settings-compact-card .card-header {
      gap: .5rem;
    }

    .settings-compact-card .card-header>.d-flex {
      flex-wrap: nowrap !important;
      justify-content: flex-end;
      max-width: none;
    }

    .settings-compact-card .card-body {
      overflow-x: auto;
    }

    /* Dynamic table layout: columns size by content, not by forced widths. */
    .settings-compact-card table.status-definitions-table,
    .settings-compact-card table.product-spec-options-table {
      width: max-content;
      min-width: 100%;
      table-layout: auto;
    }

    .settings-compact-card table.status-definitions-table th,
    .settings-compact-card table.status-definitions-table td,
    .settings-compact-card table.product-spec-options-table th,
    .settings-compact-card table.product-spec-options-table td {
      width: auto !important;
      max-width: none !important;
      white-space: nowrap;
    }

    .settings-compact-card .status-definitions-table .status-label-cell,
    .settings-compact-card .product-spec-options-table .spec-label-cell,
    .settings-compact-card .product-spec-options-table .spec-value-cell {
      min-width: 140px;
    }

    .settings-compact-card .product-spec-options-table .spec-name-cell {
      min-width: 260px;
    }

    .settings-compact-card .status-definitions-table .status-color-cell,
    .settings-compact-card .product-spec-options-table .spec-sourcekey-cell {
      min-width: 110px;
    }

    .settings-compact-card .status-definitions-table td:last-child,
    .settings-compact-card .product-spec-options-table td:last-child {
      white-space: nowrap;
    }

    .settings-compact-card .status-definitions-table td:last-child .btn,
    .settings-compact-card .product-spec-options-table td:last-child .btn {
      margin-right: .25rem;
      white-space: nowrap;
    }

    .settings-compact-card .status-definitions-table td:last-child .btn:last-child,
    .settings-compact-card .product-spec-options-table td:last-child .btn:last-child {
      margin-right: 0;
    }


    /* Header toolbar: title left, select + buttons right, same row. */
    .settings-compact-card .settings-card-toolbar {
      width: 100%;
      min-width: 0;
    }

    .settings-compact-card .settings-card-actions {
      gap: 8px;
      min-width: 0;
      margin-left: auto;
    }

    .settings-compact-card .settings-card-actions select {
      width: 420px;
      max-width: 42vw;
      min-width: 260px;
      flex: 0 1 420px;
    }

    .settings-compact-card .settings-card-actions .btn {
      flex: 0 0 auto;
      white-space: nowrap;
    }

    @media (max-width: 991.98px) {
      .settings-compact-card .settings-card-toolbar {
        flex-wrap: wrap !important;
        gap: 8px;
      }

      .settings-compact-card .settings-card-actions {
        width: 100%;
        margin-left: 0;
        justify-content: flex-start;
      }

      .settings-compact-card .settings-card-actions select {
        width: auto;
        max-width: none;
        flex: 1 1 260px;
      }
    }
  </style>

  <div class="row settings-dropdown-stack">
    <div class="col-12 mb-3">
      <div class="card card-dark border-warning settings-compact-card">
        <div class="card-header">
          <div class="d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0">Status Dropdowns</h3>

            <div class="d-flex align-items-center">
              <select class="form-control form-control-sm status-definition-group-filter mr-2" style="width:320px;">
                <?php foreach ($statusDropdownGroups as $key => $label): ?>
                  <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" <?= $currentStatusGroup === $key ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <button class="btn bg-gradient-success btn-xs add-status-definition" style="white-space: nowrap;">
                <i class="fa fa-plus"></i> Add Status
              </button>
            </div>
          </div>
        </div>
        <div class="card-body p-0 card-body-scroll">
          <table class="table table-bordered table-striped mb-0 status-definitions-table"
            data-group-key="<?= htmlspecialchars($currentStatusGroup, ENT_QUOTES, 'UTF-8'); ?>">
            <thead>
              <tr>
                <th style="background-color:gray; width:60px;">ID</th>
                <th style="background-color:gray; width:150px;">Dropdown</th>
                <th style="background-color:gray; width:120px;">Code</th>
                <th style="background-color:gray;">Label</th>
                <th style="background-color:gray; width:110px;">Color</th>
                <th style="background-color:gray; width:85px;">Order</th>
                <th style="background-color:gray; min-width:180px;">Applies To</th>
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
                  $rowTargets = $row['scope'] === 'item'
                    ? statusDefinitionNormalizeTargetKeys($statusTargetsByDefinition[(int)$row['id']] ?? ['ALL'], $row['department'])
                    : [];
                  $rowTargetLabels = [];
                  foreach ($rowTargets as $targetKey) {
                    $rowTargetLabels[] = $statusTargetOptions[$targetKey] ?? $targetKey;
                  }
                  ?>
                  <tr data-id="<?= (int) $row['id']; ?>"
                    data-group-key="<?= htmlspecialchars($currentStatusGroup, ENT_QUOTES, 'UTF-8'); ?>">
                    <td><?= (int) $row['id']; ?></td>
                    <td class="status-group-cell">
                      <?= htmlspecialchars($statusDropdownGroups[$currentStatusGroup] ?? $currentStatusGroup, ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td class="status-code-cell"><?= htmlspecialchars($row['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="status-label-cell"><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="status-color-cell">
                      <span class="status-color-preview"
                        style="background-color: <?= htmlspecialchars($color !== '' ? $color : 'transparent', ENT_QUOTES, 'UTF-8'); ?>;"></span>
                      <span class="status-color-text"><?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8'); ?></span>
                    </td>
                    <td class="status-sort-cell"><?= (int) $row['sort_order']; ?></td>
                    <td class="status-targets-cell" data-targets="<?= htmlspecialchars(json_encode($rowTargets, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                      <?= $row['scope'] === 'item' ? htmlspecialchars(implode(', ', $rowTargetLabels), ENT_QUOTES, 'UTF-8') : '&mdash;'; ?>
                    </td>
                    <td class="status-active-cell"><?= ((int) $row['active'] === 1 ? 'Yes' : 'No'); ?></td>
                    <td>
                      <button class="btn bg-gradient-primary btn-sm edit-status-definition"><i class="fa fa-edit"></i>
                        Edit</button>
                      <button class="btn bg-gradient-success btn-sm save-status-definition" style="display:none;"><i
                          class="fa fa-save"></i> Save</button>
                      <button class="btn bg-gradient-danger btn-sm delete-status-definition"><i
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
    <div class="col-12">
      <div class="card card-dark border-info settings-compact-card">
        <div class="card-header">
          <div class="d-flex align-items-center justify-content-between flex-nowrap settings-card-toolbar">
            <div class="mr-3 flex-shrink-0">
              <h3 class="card-title mb-0">Product Specification Dropdowns</h3>
              <div class="small text-muted mt-1">`Field Label` je friendly name pola. `Option Label` je nazov konkretnej hodnoty. `Source Key` je pri editacii zamknuty, aby sa nerozbili importovane mapovania.</div>
            </div>

            <div class="d-flex align-items-center flex-nowrap settings-card-actions product-spec-card-actions">
              <select class="form-control form-control-sm product-spec-key-filter">
                <?php foreach ($productSpecGroups as $key => $label): ?>
                  <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" <?= $requestedSpecGroup === $key ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button class="btn bg-gradient-info btn-xs add-product-spec-dropdown">
                <i class="fa fa-plus"></i> New Field
              </button>
              <button class="btn bg-gradient-success btn-xs add-product-spec-option">
                <i class="fa fa-plus"></i> Add Option
              </button>
            </div>
          </div>
        </div>
        <div class="card-body p-0 card-body-scroll">
          <table class="table table-bordered table-striped mb-0 product-spec-options-table"
            data-spec-key="<?= htmlspecialchars($currentSpecKey, ENT_QUOTES, 'UTF-8'); ?>"
            data-spec-group="<?= htmlspecialchars($requestedSpecGroup, ENT_QUOTES, 'UTF-8'); ?>"
            data-department="<?= htmlspecialchars($currentSpecDepartment, ENT_QUOTES, 'UTF-8'); ?>"
            data-subcategory="<?= htmlspecialchars($currentSpecSubcategory, ENT_QUOTES, 'UTF-8'); ?>"
            data-category-key="<?= htmlspecialchars($currentProductSpecCategoryKey, ENT_QUOTES, 'UTF-8'); ?>"
            data-source-key="<?= htmlspecialchars($deriveProductSpecSourceKey($currentSpecKey, $currentSpecDepartment), ENT_QUOTES, 'UTF-8'); ?>">
            <thead>
              <tr>
                <th style="background-color:gray; width:70px;">ID</th>
                <th style="background-color:gray; width:90px;">Dept</th>
                <th style="background-color:gray; width:100px;">Type</th>
                <th style="background-color:gray; width:200px;">Field Label</th>
                <th style="background-color:gray; width:180px;">Source Key</th>
                <th style="background-color:gray; width:110px;">Subcats</th>
                <th style="background-color:gray;">Option Label</th>
                <th style="background-color:gray;">Value</th>
                <th style="background-color:gray; width:110px;">Field Order</th>
                <th style="background-color:gray; width:110px;">Option Order</th>
                <th style="background-color:gray; width:90px;">Active</th>
                <th style="background-color:gray; width:210px;">Tools</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $fieldSortSelectSql = $productSpecHasFieldSortOrder ? 'field_sort_order' : 'sort_order AS field_sort_order';
              $fieldLabelSelectSql = $productSpecHasFieldLabel ? 'field_label' : "'' AS field_label";
              $stmt = $conn->prepare("
                SELECT id, spec_key, department, field_type, $fieldLabelSelectSql, label, value, sort_order, $fieldSortSelectSql, active, color, apply_to_subcategories
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
                  $rowSubcategory = $detectProductSpecSubcategory((string) ($row['spec_key'] ?? ''), $rowDepartment);
                  $rowGroupKey = $buildProductSpecGroupKey((string) ($row['spec_key'] ?? ''), $rowDepartment, $rowSubcategory);
                  $rowStoredMeta = $decodeProductSpecStoredMeta((string) ($row['color'] ?? ''));
                  $rowSourceKey = trim((string) ($rowStoredMeta['source_key'] ?? ''));
                  if ($rowSourceKey === '') {
                    $rowSourceKey = $deriveProductSpecSourceKey((string) ($row['spec_key'] ?? ''), $rowDepartment);
                  }
                  $rowFieldLabel = trim((string) ($row['field_label'] ?? ''));
                  if ($rowFieldLabel === '') {
                    $rowFieldLabel = trim((string) ($rowStoredMeta['field_label'] ?? ''));
                  }
                  if ($rowFieldLabel === '') {
                    $rowFieldLabel = $productSpecGroups[$rowGroupKey] ?? $buildProductSpecGroupLabel((string) $row['spec_key'], $rowDepartment, $rowSubcategory);
                  }
                  $rowApplyToSubcategories = (int) ($row['apply_to_subcategories'] ?? 0) === 1 ? 1 : 0;
                  $rowCanApplyToSubcategories = ($rowDepartment === 'G' && $rowSubcategory === '');
                  ?>
                  <tr data-id="<?= (int) $row['id']; ?>"
                    data-spec-key="<?= htmlspecialchars($row['spec_key'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-department="<?= htmlspecialchars($rowDepartment, ENT_QUOTES, 'UTF-8'); ?>"
                    data-subcategory="<?= htmlspecialchars($rowSubcategory, ENT_QUOTES, 'UTF-8'); ?>"
                    data-spec-group="<?= htmlspecialchars($rowGroupKey, ENT_QUOTES, 'UTF-8'); ?>"
                    data-source-key="<?= htmlspecialchars($rowSourceKey, ENT_QUOTES, 'UTF-8'); ?>"
                    data-field-label="<?= htmlspecialchars($rowFieldLabel, ENT_QUOTES, 'UTF-8'); ?>"
                    data-field-sort-order="<?= (int) ($row['field_sort_order'] ?? $row['sort_order'] ?? 0); ?>"
                    data-apply-to-subcategories="<?= $rowApplyToSubcategories; ?>">
                    <td><?= (int) $row['id']; ?></td>
                    <td class="spec-department-cell"><?= htmlspecialchars($rowDepartment, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="spec-fieldtype-cell">
                      <?= htmlspecialchars($fieldTypeLabels[$row['field_type'] ?? 'dropdown'] ?? 'Dropdown', ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td class="spec-name-cell">
                      <?= htmlspecialchars($rowFieldLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td class="spec-sourcekey-cell"><?= htmlspecialchars($rowSourceKey, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="spec-subcats-cell">
                      <?= $rowCanApplyToSubcategories ? ($rowApplyToSubcategories === 1 ? 'Yes' : 'No') : '&mdash;'; ?></td>
                    <td class="spec-label-cell"><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="spec-value-cell"><?= htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="spec-field-sort-cell"><?= (int) ($row['field_sort_order'] ?? $row['sort_order'] ?? 0); ?></td>
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

      const specGroupLabels = <?= json_encode($productSpecGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const productSpecSourceKeys = <?= json_encode($productSpecSourceKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const departmentLabels = <?= json_encode($departmentNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const departmentPrefixes = <?= json_encode($departmentPrefixes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const graphicsSubcategoryLabels = <?= json_encode($graphicsSubcategoryLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const graphicsSubcategorySlugs = <?= json_encode($graphicsSubcategorySlugs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const productSpecCategories = <?= json_encode($productSpecCategoryOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const controlsScrollStorageKey = 'controls-scroll:' + window.location.pathname;

      window.__controllsRememberScroll = function () {
        try {
          sessionStorage.setItem(controlsScrollStorageKey, String(window.scrollY || window.pageYOffset || 0));
        } catch (err) {
        }
      };

      window.__controllsRestoreScroll = function () {
        try {
          const stored = sessionStorage.getItem(controlsScrollStorageKey);
          if (stored === null) {
            return;
          }
          sessionStorage.removeItem(controlsScrollStorageKey);
          const top = parseInt(stored, 10);
          if (!isNaN(top)) {
            window.requestAnimationFrame(function () {
              window.scrollTo(0, top);
            });
          }
        } catch (err) {
        }
      };

      window.__controllsRestoreScroll();
      window.addEventListener('DOMContentLoaded', window.__controllsRestoreScroll);
      window.addEventListener('load', window.__controllsRestoreScroll);

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

      function normalizeSubcategory(value) {
        const subcategory = String(value || '').trim().toUpperCase();
        return graphicsSubcategoryLabels[subcategory] ? subcategory : '';
      }

      function buildCategoryKey(department, subcategory) {
        const normalizedDepartment = normalizeDepartment(department);
        const normalizedSubcategory = normalizedDepartment === 'G' ? normalizeSubcategory(subcategory) : '';
        return normalizedDepartment + '|' + normalizedSubcategory;
      }

      function parseCategoryKey(categoryKey) {
        const parts = String(categoryKey || '').split('|');
        const department = normalizeDepartment(parts[0] || '');
        const subcategory = department === 'G' ? normalizeSubcategory(parts[1] || '') : '';
        return { department, subcategory, categoryKey: buildCategoryKey(department, subcategory) };
      }

      function isRootGraphicsCategory(categoryKey) {
        const parsed = parseCategoryKey(categoryKey);
        return parsed.department === 'G' && parsed.subcategory === '';
      }

      function syncApplyToSubcategoriesVisibility($row) {
        const categoryKey = $row.find('.new-dropdown-category').val() || '';
        const isRootGraphics = isRootGraphicsCategory(categoryKey);
        const $group = $row.find('.new-dropdown-subcats-group');
        const $input = $row.find('.new-dropdown-apply-subcategories');

        if (!$group.length || !$input.length) {
          return;
        }

        if (isRootGraphics) {
          $group.show();
        } else {
          $group.hide();
          $input.val('0');
        }
      }

      function normalizeSourceKey(value) {
        return String(value || '')
          .trim()
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '')
          .replace(/-+/g, '-');
      }

      function inferGraphicsSubcategoryFromSpecKey(specKey, department) {
        if (normalizeDepartment(department) !== 'G') {
          return '';
        }

        const normalizedSpecKey = String(specKey || '').trim().toLowerCase();
        for (const [subcategory, slug] of Object.entries(graphicsSubcategorySlugs)) {
          if (normalizedSpecKey.indexOf('graphics_' + slug + '_') === 0) {
            return normalizeSubcategory(subcategory);
          }
        }

        return '';
      }

      function buildSpecKeyPrefix(categoryKey) {
        const parsed = parseCategoryKey(categoryKey);
        if (!parsed.department) {
          return 'custom_';
        }
        if (parsed.department === 'G' && parsed.subcategory) {
          return 'graphics_' + graphicsSubcategorySlugs[parsed.subcategory];
        }

        return departmentPrefixes[parsed.department] || 'custom';
      }

      function buildSpecKeyFromSourceKey(categoryKey, sourceKey) {
        const prefix = buildSpecKeyPrefix(categoryKey);
        const slug = String(sourceKey || '')
          .trim()
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '_')
          .replace(/^_+|_+$/g, '')
          .replace(/_+/g, '_');

        return slug ? (prefix + '_' + slug) : prefix;
      }

      function ensureSpecKeyMatchesCategory(categoryKey, specKey, sourceKey) {
        const prefix = buildSpecKeyPrefix(categoryKey);
        const cleanSpecKey = String(specKey || '')
          .trim()
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '_')
          .replace(/^_+|_+$/g, '')
          .replace(/_+/g, '_');

        if (!cleanSpecKey) {
          return buildSpecKeyFromSourceKey(categoryKey, sourceKey);
        }

        return cleanSpecKey.indexOf(prefix + '_') === 0 || cleanSpecKey === prefix
          ? cleanSpecKey
          : (prefix + '_' + cleanSpecKey);
      }

      function buildProductSpecGroupKey(specKey, department, subcategory) {
        return String(specKey || '').trim() + '|' + normalizeDepartment(department) + '|' + normalizeSubcategory(subcategory);
      }

      function buildProductSpecGroupLabel(specKey, department, subcategory) {
        const parsedCategory = parseCategoryKey(buildCategoryKey(department, subcategory));
        const normalizedDept = parsedCategory.department;
        const normalizedSubcategory = parsedCategory.subcategory;
        const labelParts = [];
        if (normalizedDept) {
          labelParts.push(normalizedDept, departmentLabels[normalizedDept] || normalizedDept);
        }
        if (normalizedSubcategory) {
          labelParts.push(graphicsSubcategoryLabels[normalizedSubcategory] || normalizedSubcategory);
        }
        let labelSource = String(specKey || '').trim();
        let prefix = departmentPrefixes[normalizedDept] ? (departmentPrefixes[normalizedDept] + '_') : '';

        if (normalizedDept === 'G' && normalizedSubcategory) {
          prefix = 'graphics_' + graphicsSubcategorySlugs[normalizedSubcategory] + '_';
        }

        if (prefix && labelSource.indexOf(prefix) === 0) {
          labelSource = labelSource.substring(prefix.length);
        }

        const fieldLabel = humanizeSpecToken(labelSource) || 'Custom Dropdown';
        return labelParts.length ? (labelParts.join(' - ') + ' - ' + fieldLabel) : fieldLabel;
      }

      function sourceKeyDepartmentHint(sourceKey) {
        const meta = productSpecSourceKeys[normalizeSourceKey(sourceKey)] || null;
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
        window.__controllsRememberScroll();
        window.location.href = url.toString();
      });

      $('.add-product-spec-option').on('click', function () {
        const $table = $('.product-spec-options-table');
        const specKey = $table.data('spec-key');
        const department = normalizeDepartment($table.data('department'));
        const subcategory = normalizeSubcategory($table.data('subcategory'));
        const specGroup = $table.data('spec-group');
        const specName = specGroupLabels[specGroup] || buildProductSpecGroupLabel(specKey, department, subcategory);
        const sourceKey = String($table.find('tbody tr[data-source-key]').first().data('source-key') || $table.data('source-key') || '');
        const fieldLabel = String($table.find('tbody tr[data-field-label]').first().data('field-label') || specName);
        const applyToSubcategories = parseInt($table.find('tbody tr[data-apply-to-subcategories]').first().data('apply-to-subcategories'), 10) === 1 ? 1 : 0;
        const canApplyToSubcategories = department === 'G' && !subcategory;
        const fieldSortOrder = parseInt($table.find('tbody tr[data-field-sort-order]').first().data('field-sort-order'), 10) || 0;

        const newRow = `
      <tr class="new-product-spec-row" data-spec-key="${escapeHtml(specKey)}" data-department="${escapeHtml(department)}" data-subcategory="${escapeHtml(subcategory)}" data-spec-group="${escapeHtml(specGroup)}" data-field-label="${escapeHtml(fieldLabel)}" data-apply-to-subcategories="${applyToSubcategories}" data-field-sort-order="${fieldSortOrder}">
        <td>&mdash;</td>
        <td>${escapeHtml(department)}</td>
        <td class="spec-fieldtype-cell">Option</td>
        <td class="spec-name-cell">${escapeHtml(fieldLabel)}</td>
        <td class="spec-sourcekey-cell">${escapeHtml(sourceKey)}</td>
        <td class="spec-subcats-cell">${canApplyToSubcategories ? (applyToSubcategories === 1 ? 'Yes' : 'No') : '&mdash;'}</td>
        <td><input type="text" class="form-control form-control-sm new-spec-label" placeholder="Option label"></td>
        <td><input type="text" class="form-control form-control-sm new-spec-value" placeholder="Saved value"></td>
        <td><input type="number" class="form-control form-control-sm new-spec-field-sort" value="${fieldSortOrder}" step="1"></td>
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

        const currentCategoryKey = String($('.product-spec-options-table').data('category-key') || 'G|');
        const sourceOptions = Object.keys(productSpecSourceKeys).map(function (key) {
          const meta = productSpecSourceKeys[key] || {};
          const depts = Object.keys(meta.departments || {});
          const deptHint = depts.length ? ' [' + depts.join(', ') + ']' : '';
          const displayKey = meta.display_key || key;
          return `<option value="${escapeHtml(displayKey)}" label="${escapeHtml((meta.label || humanizeSpecToken(displayKey)) + deptHint)}"></option>`;
        }).join('');
        const categoryOptions = Object.entries(productSpecCategories).map(function ([key, meta]) {
          const selected = key === currentCategoryKey ? ' selected' : '';
          return `<option value="${escapeHtml(key)}"${selected}>${escapeHtml(meta.label || key)}</option>`;
        }).join('');

        const newRow = `
          <tr class="new-product-spec-dropdown-row">
            <td>&mdash;</td>
            <td colspan="11">
              <div class="product-spec-create-panel">
                <div class="product-spec-create-grid">
                  <div class="form-group">
                    <label class="small text-muted mb-1">Category</label>
                    <select class="form-control form-control-sm new-dropdown-category">
                      ${categoryOptions}
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">Field type</label>
                    <select class="form-control form-control-sm new-dropdown-field-type">
                      <option value="dropdown">Dropdown</option>
                      <option value="text">Text</option>
                      <option value="checkbox">Checkbox</option>
                      <option value="radio">Radio</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">Field label</label>
                    <input type="text" class="form-control form-control-sm new-field-label" placeholder="Accent 1">
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">Source key from options_json</label>
                    <input type="text" class="form-control form-control-sm new-dropdown-source-key" list="productSpecSourceKeyList" placeholder="mid-forks">
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">Field key</label>
                    <input type="text" class="form-control form-control-sm new-dropdown-spec-key" placeholder="graphics_mid_forks">
                  </div>
                  <div class="form-group new-dropdown-subcats-group" style="display:none;">
                    <label class="small text-muted mb-1">Show in subcategories</label>
                    <select class="form-control form-control-sm new-dropdown-apply-subcategories">
                      <option value="0" selected>No</option>
                      <option value="1">Yes</option>
                    </select>
                  </div>
                  <div class="form-group new-field-first-option-group">
                    <label class="small text-muted mb-1">First option label</label>
                    <input type="text" class="form-control form-control-sm new-spec-label" placeholder="Yes">
                  </div>
                  <div class="form-group new-field-first-option-group">
                    <label class="small text-muted mb-1">First option value</label>
                    <input type="text" class="form-control form-control-sm new-spec-value" placeholder="Yes">
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">Field order</label>
                    <input type="number" class="form-control form-control-sm new-spec-field-sort" value="0" step="1">
                  </div>
                  <div class="form-group">
                    <label class="small text-muted mb-1">Option order</label>
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
                    <div class="small text-info new-dropdown-preview">G - Graphics - Custom Dropdown</div>
                  </div>
                </div>
                <div class="new-field-type-hint small text-muted mt-2" style="display:none;"></div>
                <datalist id="productSpecSourceKeyList">${sourceOptions}</datalist>
                <div class="d-flex justify-content-end mt-3" style="gap:8px;">
                  <button class="btn bg-gradient-success btn-sm confirm-product-spec-dropdown-add"><i class="fa fa-check"></i> Create Field</button>
                  <button class="btn bg-gradient-secondary btn-sm cancel-product-spec-dropdown-add"><i class="fa fa-times"></i> Cancel</button>
                </div>
              </div>
            </td>
          </tr>`;

        $('.product-spec-options-table tbody').prepend(newRow);
        refreshNewDropdownPreview($('.product-spec-options-table tbody .new-product-spec-dropdown-row').first(), true);
      });

      $('.product-spec-options-table').on('click', '.cancel-product-spec-add', function () {
        $(this).closest('tr').remove();
      });

      $('.product-spec-options-table').on('click', '.cancel-product-spec-dropdown-add', function () {
        $(this).closest('tr').remove();
      });

      function refreshNewDropdownPreview($row, shouldAutofillKey) {
        const parsedCategory = parseCategoryKey($row.find('.new-dropdown-category').val());
        const categoryKey = parsedCategory.categoryKey;
        const sourceKey = $row.find('.new-dropdown-source-key').val().trim();
        const $fieldLabelInput = $row.find('.new-field-label');
        const currentSpecKey = $row.find('.new-dropdown-spec-key').val().trim();
        const specKey = shouldAutofillKey || currentSpecKey === ''
          ? buildSpecKeyFromSourceKey(categoryKey, sourceKey)
          : ensureSpecKeyMatchesCategory(categoryKey, currentSpecKey, sourceKey);

        if (shouldAutofillKey || currentSpecKey === '') {
          $row.find('.new-dropdown-spec-key').val(specKey);
        }
        if ($fieldLabelInput.length && (shouldAutofillKey || $fieldLabelInput.val().trim() === '')) {
          $fieldLabelInput.val(humanizeSpecToken(sourceKey || specKey));
        }

        syncApplyToSubcategoriesVisibility($row);
        const previewLabel = $fieldLabelInput.length ? $fieldLabelInput.val().trim() : '';
        $row.find('.new-dropdown-preview').text(previewLabel !== '' ? previewLabel : buildProductSpecGroupLabel(specKey, parsedCategory.department, parsedCategory.subcategory));
      }

      $('.product-spec-options-table').on('change input', '.new-dropdown-category, .new-dropdown-source-key', function () {
        const $row = $(this).closest('.new-product-spec-dropdown-row');
        const sourceKey = $row.find('.new-dropdown-source-key').val().trim();
        const hintedDept = sourceKeyDepartmentHint(sourceKey);

        if ($(this).hasClass('new-dropdown-source-key') && hintedDept) {
          $row.find('.new-dropdown-category').val(buildCategoryKey(hintedDept, ''));
        }

        refreshNewDropdownPreview($row, true);
      });

      $('.product-spec-options-table').on('input', '.new-dropdown-spec-key', function () {
        const $row = $(this).closest('.new-product-spec-dropdown-row');
        refreshNewDropdownPreview($row, false);
      });

      $('.product-spec-options-table').on('input', '.new-field-label', function () {
        const $row = $(this).closest('.new-product-spec-dropdown-row');
        refreshNewDropdownPreview($row, false);
      });

      $('.product-spec-options-table').on('click', '.confirm-product-spec-add', function () {
        const $row = $(this).closest('tr');
        const specKey = $row.data('spec-key');
        const department = normalizeDepartment($row.data('department'));
        const subcategory = normalizeSubcategory($row.data('subcategory'));
        const specGroup = $row.data('spec-group');
        const fieldLabel = String($row.data('field-label') || '').trim();
        const label = $row.find('.new-spec-label').val().trim();
        const value = $row.find('.new-spec-value').val().trim();
        const fieldSortOrder = parseInt($row.find('.new-spec-field-sort').val(), 10) || 0;
        const sortOrder = parseInt($row.find('.new-spec-sort').val(), 10) || 0;
        const active = parseInt($row.find('.new-spec-active').val(), 10) || 0;
        const applyToSubcategories = parseInt($row.closest('tr').data('apply-to-subcategories'), 10) === 1 ? 1 : 0;

        if (label === '' || value === '') {
          alert('Label and value are required.');
          return;
        }

        $.ajax({
          url: 'scripts/settings/insert_product_spec_option.php',
          method: 'POST',
          dataType: 'json',
          data: { spec_key: specKey, department: department, field_label: fieldLabel, source_key: String($row.closest('tr').data('source-key') || $('.product-spec-options-table tbody tr[data-source-key]').first().data('source-key') || $('.product-spec-options-table').data('source-key') || ''), label: label, value: value, field_sort_order: fieldSortOrder, sort_order: sortOrder, active: active, apply_to_subcategories: applyToSubcategories },
          success: function (data) {
            if (!data || !data.ok) {
              alert(data && data.error ? data.error : 'Insert failed.');
              return;
            }
            const specName = fieldLabel || specGroupLabels[specGroup] || buildProductSpecGroupLabel(specKey, department, subcategory);
            const sourceKey = String($row.closest('tr').data('source-key') || $('.product-spec-options-table tbody tr[data-source-key]').first().data('source-key') || $('.product-spec-options-table').data('source-key') || '');
            const canApplyToSubcategories = department === 'G' && !subcategory;
            $row.replaceWith(`
          <tr data-id="${data.id}" data-spec-key="${escapeHtml(specKey)}" data-department="${escapeHtml(department)}" data-subcategory="${escapeHtml(subcategory)}" data-spec-group="${escapeHtml(specGroup)}" data-source-key="${escapeHtml(sourceKey)}" data-field-label="${escapeHtml(specName)}" data-apply-to-subcategories="${applyToSubcategories}" data-field-sort-order="${fieldSortOrder}">
            <td>${data.id}</td>
            <td class="spec-department-cell">${escapeHtml(department)}</td>
            <td class="spec-fieldtype-cell">Option</td>
            <td class="spec-name-cell">${escapeHtml(specName)}</td>
            <td class="spec-sourcekey-cell">${escapeHtml(sourceKey)}</td>
            <td class="spec-subcats-cell">${canApplyToSubcategories ? (applyToSubcategories === 1 ? 'Yes' : 'No') : '&mdash;'}</td>
            <td class="spec-label-cell">${escapeHtml(label)}</td>
            <td class="spec-value-cell">${escapeHtml(value)}</td>
            <td class="spec-field-sort-cell">${fieldSortOrder}</td>
            <td class="spec-sort-cell">${sortOrder}</td>
            <td class="spec-active-cell">${active === 1 ? 'Yes' : 'No'}</td>
            <td>
              <button class="btn bg-gradient-primary btn-sm edit-product-spec-option"><i class="fa fa-edit"></i> Edit</button>
              <button class="btn bg-gradient-success btn-sm save-product-spec-option" style="display:none;"><i class="fa fa-save"></i> Save</button>
              <button class="btn bg-gradient-danger btn-sm delete-product-spec-option"><i class="fa fa-trash"></i></button>
            </td>
          </tr>`);
          },
          error: function (xhr) {
            alert('Insert request failed: ' + xhr.status + '\n' + (xhr.responseText || ''));
          }
        });
      });

      // --- Field type change: skryj/zobraz option polia, nastav hint ---
      const fieldTypeHints = {
        dropdown: '',
        text: 'Text field — žiadne options. Zákazník píše voľný text. Label/value polia nie sú potrebné.',
        checkbox: 'Checkbox — automaticky sa vytvoria dve options: Yes (1) a No (0).',
        radio: 'Radio — pridaj options rovnako ako pri Dropdown. Render bude ako tlačidlá.',
      };

      $('.product-spec-options-table').on('change', '.new-dropdown-field-type', function () {
        const $row = $(this).closest('.new-product-spec-dropdown-row');
        const ft = $(this).val();
        const $optionGroups = $row.find('.new-field-first-option-group');
        const $hint = $row.find('.new-field-type-hint');

        if (ft === 'text') {
          $optionGroups.hide();
          $row.find('.new-spec-label').val('');
          $row.find('.new-spec-value').val('');
        } else if (ft === 'checkbox') {
          $optionGroups.hide();
          // Checkbox — options sa generujú automaticky na serveri
        } else {
          $optionGroups.show();
        }

        if (fieldTypeHints[ft]) {
          $hint.text(fieldTypeHints[ft]).show();
        } else {
          $hint.hide();
        }

        refreshNewDropdownPreview($row, false);
      });

      $('.product-spec-options-table').on('click', '.confirm-product-spec-dropdown-add', function () {
        const $row = $(this).closest('.new-product-spec-dropdown-row');
        const parsedCategory = parseCategoryKey($row.find('.new-dropdown-category').val());
        const department = parsedCategory.department;
        const subcategory = parsedCategory.subcategory;
        const categoryKey = parsedCategory.categoryKey;
        const fieldType = $row.find('.new-dropdown-field-type').val() || 'dropdown';
        const sourceKey = $row.find('.new-dropdown-source-key').val().trim();
        const specKey = ensureSpecKeyMatchesCategory(categoryKey, $row.find('.new-dropdown-spec-key').val().trim(), sourceKey);
        const fieldLabel = $row.find('.new-field-label').val().trim() || humanizeSpecToken(sourceKey || specKey);
        const fieldSortOrder = parseInt($row.find('.new-spec-field-sort').val(), 10) || 0;
        const sortOrder = parseInt($row.find('.new-spec-sort').val(), 10) || 0;
        const active = parseInt($row.find('.new-spec-active').val(), 10) || 0;
        const applyToSubcategories = parseInt($row.find('.new-dropdown-apply-subcategories').val(), 10) === 1 ? 1 : 0;

        // Label/value závisí od field_type
        let label = $row.find('.new-spec-label').val().trim();
        let value = $row.find('.new-spec-value').val().trim();

        if (fieldType === 'text') {
          // Text field — uložíme jeden placeholder záznam
          label = label || 'Text';
          value = value || '_text_';
        } else if (fieldType === 'checkbox') {
          // Checkbox — server vytvorí Yes/No automaticky; pošleme prázdne
          label = 'Yes';
          value = '1';
        } else {
          // dropdown / radio — vyžadujú vyplnenie
          if (label === '' || value === '') {
            alert('First option label and value are required.');
            return;
          }
        }

        if (department === '' || specKey === '') {
          alert('Category and field key are required.');
          return;
        }

        const groupKey = buildProductSpecGroupKey(specKey, department, subcategory);

        $.ajax({
          url: 'scripts/settings/insert_product_spec_option.php',
          method: 'POST',
          dataType: 'json',
          data: {
            spec_key: specKey,
            department: department,
            field_label: fieldLabel,
            source_key: sourceKey,
            field_type: fieldType,
            label: label,
            value: value,
            field_sort_order: fieldSortOrder,
            sort_order: sortOrder,
            active: active,
            apply_to_subcategories: applyToSubcategories,
            // Pre checkbox — server vie že má pridať aj No (0)
            auto_checkbox: fieldType === 'checkbox' ? '1' : '0',
          },
          success: function (data) {
            if (!data || !data.ok) {
              alert(data && data.error ? data.error : 'Create failed.');
              return;
            }

            const url = new URL(window.location.href);
            url.searchParams.set('spec_group', groupKey);
            url.searchParams.delete('spec_key');
            window.__controllsRememberScroll();
            window.location.href = url.toString();
          },
          error: function (xhr) {
            alert('Create request failed: ' + xhr.status + '\n' + (xhr.responseText || ''));
          }
        });
      });

      $('.product-spec-options-table').on('click', '.edit-product-spec-option', function (e) {
        e.preventDefault();
        const $row = $(this).closest('tr');
        const fieldLabel = String($row.data('field-label') || $row.find('.spec-name-cell').text().trim());
        const sourceKey = String($row.data('source-key') || $row.find('.spec-sourcekey-cell').text().trim());
        const label = $row.find('.spec-label-cell').text().trim();
        const value = $row.find('.spec-value-cell').text().trim();
        const fieldSortOrder = $row.find('.spec-field-sort-cell').text().trim();
        const sortOrder = $row.find('.spec-sort-cell').text().trim();
        const active = $row.find('.spec-active-cell').text().trim() === 'Yes' ? '1' : '0';
        const applyToSubcategories = String($row.data('apply-to-subcategories') || '0') === '1' ? '1' : '0';
        const canApplyToSubcategories = String($row.data('department') || '') === 'G' && String($row.data('subcategory') || '') === '';

        $row.find('.spec-name-cell').html(`<input type="text" class="form-control form-control-sm spec-name-input" value="${escapeHtml(fieldLabel)}">`);
        $row.find('.spec-sourcekey-cell').html(`<div class="text-muted small" title="Source Key is locked during edit">${escapeHtml(sourceKey)}</div>`);
        $row.find('.spec-subcats-cell').html(canApplyToSubcategories
          ? `<select class="form-control form-control-sm spec-subcats-input">
              <option value="0" ${applyToSubcategories === '0' ? 'selected' : ''}>No</option>
              <option value="1" ${applyToSubcategories === '1' ? 'selected' : ''}>Yes</option>
            </select>`
          : '&mdash;');
        $row.find('.spec-label-cell').html(`<input type="text" class="form-control form-control-sm spec-label-input" value="${escapeHtml(label)}">`);
        $row.find('.spec-value-cell').html(`<input type="text" class="form-control form-control-sm spec-value-input" value="${escapeHtml(value)}">`);
        $row.find('.spec-field-sort-cell').html(`<input type="number" class="form-control form-control-sm spec-field-sort-input" value="${escapeHtml(fieldSortOrder)}" step="1">`);
        $row.find('.spec-sort-cell').html(`<input type="number" class="form-control form-control-sm spec-sort-input" value="${escapeHtml(sortOrder)}" step="1">`);
        $row.find('.spec-active-cell').html(`
      <select class="form-control form-control-sm spec-active-input">
        <option value="1" ${active === '1' ? 'selected' : ''}>Yes</option>
        <option value="0" ${active === '0' ? 'selected' : ''}>No</option>
      </select>`);

        $(this).hide();
        $row.find('.save-product-spec-option').show();
      });

      $('.product-spec-options-table').on('click', '.save-product-spec-option', function (e) {
        e.preventDefault();
        const $row = $(this).closest('tr');
        const id = parseInt($row.data('id'), 10) || 0;
        const fieldLabel = $row.find('.spec-name-input').val().trim();
        const sourceKey = String($row.data('source-key') || '').trim();
        const label = $row.find('.spec-label-input').val().trim();
        const value = $row.find('.spec-value-input').val().trim();
        const fieldSortOrder = parseInt($row.find('.spec-field-sort-input').val(), 10) || 0;
        const sortOrder = parseInt($row.find('.spec-sort-input').val(), 10) || 0;
        const active = parseInt($row.find('.spec-active-input').val(), 10) || 0;
        const canApplyToSubcategories = String($row.data('department') || '') === 'G' && String($row.data('subcategory') || '') === '';
        const applyToSubcategories = canApplyToSubcategories
          ? (parseInt($row.find('.spec-subcats-input').val(), 10) === 1 ? 1 : 0)
          : 0;

        if (!id || fieldLabel === '' || label === '' || value === '') {
          alert('Field, label and value are required.');
          return;
        }

        $.ajax({
          url: 'scripts/settings/update_product_spec_option.php',
          method: 'POST',
          dataType: 'json',
          data: { id: id, field_label: fieldLabel, source_key: sourceKey, label: label, value: value, field_sort_order: fieldSortOrder, sort_order: sortOrder, active: active, apply_to_subcategories: applyToSubcategories },
          success: function (data) {
            if (!data || !data.ok) {
              alert(data && data.error ? data.error : 'Save failed.');
              return;
            }
            const specKey = String($row.data('spec-key') || '');
            const department = String($row.data('department') || '');
            const originalSourceKey = String($row.data('source-key') || '');
            $('.product-spec-options-table tbody tr').each(function () {
              const $peer = $(this);
              if (
                String($peer.data('spec-key') || '') === specKey &&
                String($peer.data('department') || '') === department &&
                String($peer.data('source-key') || '') === originalSourceKey
              ) {
                $peer.attr('data-field-label', fieldLabel);
                $peer.find('.spec-name-cell').text(fieldLabel);
              }
            });
            $row.attr('data-field-label', fieldLabel);
            $row.attr('data-source-key', sourceKey);
            $row.attr('data-apply-to-subcategories', applyToSubcategories);
            $row.attr('data-field-sort-order', fieldSortOrder);
            $row.find('.spec-name-cell').text(fieldLabel);
            $row.find('.spec-sourcekey-cell').text(sourceKey);
            $row.find('.spec-subcats-cell').html(canApplyToSubcategories ? (applyToSubcategories === 1 ? 'Yes' : 'No') : '&mdash;');
            $row.find('.spec-label-cell').text(label);
            $row.find('.spec-value-cell').text(value);
            $row.find('.spec-field-sort-cell').text(fieldSortOrder);
            $row.find('.spec-sort-cell').text(sortOrder);
            $row.find('.spec-active-cell').text(active === 1 ? 'Yes' : 'No');
            $row.find('.save-product-spec-option').hide();
            $row.find('.edit-product-spec-option').show();
          },
          error: function (xhr) {
            alert('Save request failed: ' + xhr.status + '\n' + (xhr.responseText || ''));
          }
        });
      });

      $('.product-spec-options-table').on('click', '.delete-product-spec-option', function (e) {
        e.preventDefault();
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
      const statusTargetLabels = <?= json_encode($statusTargetOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

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

      function normalizeStatusColorValue(color) {
        const safeColor = String(color || '').trim();
        return /^#[0-9a-f]{6}$/i.test(safeColor) ? safeColor : '#28a745';
      }

      function isItemStatusGroup(groupKey) {
        return String(groupKey || '').indexOf('item|') === 0;
      }

      function buildStatusTargetOptions(selected) {
        const selectedSet = new Set(Array.isArray(selected) && selected.length ? selected : ['ALL']);
        return Object.keys(statusTargetLabels).map(function (key) {
          return `<option value="${escapeHtml(key)}" ${selectedSet.has(key) ? 'selected' : ''}>${escapeHtml(statusTargetLabels[key])}</option>`;
        }).join('');
      }

      function normalizeSelectedTargets($select) {
        const selected = ($select.val() || []).map(String);
        return selected.includes('ALL') || selected.length === 0 ? ['ALL'] : selected;
      }

      function renderStatusTargetsCell(targets, isItem) {
        if (!isItem) return '&mdash;';
        const normalized = Array.isArray(targets) && targets.length ? targets : ['ALL'];
        return normalized.map(function (key) { return statusTargetLabels[key] || key; }).join(', ');
      }

      $('.status-definition-group-filter').on('change', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('status_group', $(this).val());
        if (typeof window.__controllsRememberScroll === 'function') {
          window.__controllsRememberScroll();
        }
        window.location.href = url.toString();
      });

      $('.add-status-definition').on('click', function () {
        const groupKey = $('.status-definitions-table').data('group-key');
        const groupName = statusGroupLabels[groupKey] || groupKey;
        const isItem = isItemStatusGroup(groupKey);

        const newRow = `
          <tr class="new-status-definition-row" data-group-key="${escapeHtml(groupKey)}">
            <td>&mdash;</td>
            <td>${escapeHtml(groupName)}</td>
            <td><input type="text" class="form-control form-control-sm new-status-code" placeholder="READY_TO_SHIP"></td>
            <td><input type="text" class="form-control form-control-sm new-status-label" placeholder="Ready to Ship"></td>
            <td><input type="color" class="form-control form-control-sm new-status-color" value="#28a745" title="Choose color"></td>
            <td><input type="number" class="form-control form-control-sm new-status-sort" value="0" step="1"></td>
            <td>${isItem ? `<select multiple size="4" class="form-control form-control-sm new-status-targets">${buildStatusTargetOptions(['ALL'])}</select>` : '&mdash;'}</td>
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
        const isItem = isItemStatusGroup(groupKey);
        const targets = isItem ? normalizeSelectedTargets($row.find('.new-status-targets')) : [];

        if (code === '' || label === '') {
          alert('Code and label are required.');
          return;
        }

        $.ajax({
          url: 'scripts/settings/insert_status_definition.php',
          method: 'POST',
          dataType: 'json',
          data: { group_key: groupKey, code: code, label: label, color: color, sort_order: sortOrder, targets: targets, active: active },
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
                <td class="status-targets-cell" data-targets="${escapeHtml(JSON.stringify(targets))}">${escapeHtml(renderStatusTargetsCell(targets, isItem))}</td>
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
        const pickerColor = normalizeStatusColorValue(color);
        const sortOrder = $row.find('.status-sort-cell').text().trim();
        const active = $row.find('.status-active-cell').text().trim() === 'Yes' ? '1' : '0';
        const groupKey = String($row.data('group-key') || '');
        const isItem = isItemStatusGroup(groupKey);
        let targets = $row.find('.status-targets-cell').data('targets');
        if (typeof targets === 'string') {
          try { targets = JSON.parse(targets); } catch (e) { targets = ['ALL']; }
        }
        if (!Array.isArray(targets) || !targets.length) targets = ['ALL'];

        $row.find('.status-code-cell').html(`<input type="text" class="form-control form-control-sm status-code-input" value="${escapeHtml(code)}">`);
        $row.find('.status-label-cell').html(`<input type="text" class="form-control form-control-sm status-label-input" value="${escapeHtml(label)}">`);
        $row.find('.status-color-cell').html(`<input type="color" class="form-control form-control-sm status-color-input" value="${escapeHtml(pickerColor)}" title="Choose color">`);
        $row.find('.status-sort-cell').html(`<input type="number" class="form-control form-control-sm status-sort-input" value="${escapeHtml(sortOrder)}" step="1">`);
        if (isItem) {
          $row.find('.status-targets-cell').html(`<select multiple size="4" class="form-control form-control-sm status-targets-input">${buildStatusTargetOptions(targets)}</select>`);
        }
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
        const groupKey = String($row.data('group-key') || '');
        const isItem = isItemStatusGroup(groupKey);
        const targets = isItem ? normalizeSelectedTargets($row.find('.status-targets-input')) : [];

        if (!id || code === '' || label === '') {
          alert('Code and label are required.');
          return;
        }

        $.ajax({
          url: 'scripts/settings/update_status_definition.php',
          method: 'POST',
          dataType: 'json',
          data: { id: id, code: code, label: label, color: color, sort_order: sortOrder, targets: targets, active: active },
          success: function (data) {
            if (!data || !data.ok) {
              alert(data && data.error ? data.error : 'Save failed.');
              return;
            }
            $row.find('.status-code-cell').text(code);
            $row.find('.status-label-cell').text(label);
            $row.find('.status-color-cell').html(renderStatusColorCell(color));
            $row.find('.status-sort-cell').text(sortOrder);
            $row.find('.status-targets-cell').attr('data-targets', JSON.stringify(targets)).data('targets', targets).text(renderStatusTargetsCell(targets, isItem));
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
