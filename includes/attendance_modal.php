<!-- Add -->
<div class="modal fade" id="addnew" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form class="form-horizontal" method="POST" action="scripts/attendance_add.php">
        <div class="modal-header">
          <h4 class="modal-title"><b>Pridať Záznam</b></h4>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect ?? '', ENT_QUOTES); ?>">

          <div class="form-group row">
            <label for="employee_add" class="col-sm-3 col-form-label">Zamestnanec</label>
            <div class="col-sm-9">
              <input type="hidden" id="employee_ovo" name="employee" value="<?php echo htmlspecialchars($_GET['eno'] ?? '', ENT_QUOTES); ?>" required>
                <?php
                $sql = "SELECT * FROM employees WHERE id = '".$_GET['eno']."'";
                $query = $conn->query($sql);
                while($row = $query->fetch_array()){
                  $selected = ($row['id'] == (@$_GET['eno'])) ? 'selected' : '';
                  echo '<h5>'.htmlspecialchars($row['firstname'].' '.$row['lastname']).'</h5>';
                }
                ?>              
            </div>
          </div>

          <div class="form-group row">
            <label class="col-sm-3 col-form-label">Dátum</label>
            <div class="col-sm-9">
              <div class="input-group date" id="datepicker_add" data-target-input="nearest">
                <input type="text" name="date" class="form-control datetimepicker-input" data-target="#datepicker_add" required>
                <div class="input-group-append" data-target="#datepicker_add" data-toggle="datetimepicker">
                  <div class="input-group-text"><i class="far fa-calendar-alt"></i></div>
                </div>
              </div>
            </div>
          </div>

          <div class="form-group row">
            <label class="col-sm-3 col-form-label">Príchod</label>
            <div class="col-sm-9">
              <div class="input-group date" id="time_in_add" data-target-input="nearest">
                <input type="text" name="time_in" class="form-control datetimepicker-input" data-target="#time_in_add">
                <div class="input-group-append" data-toggle="timepicker-icon" data-target="#time_in_add">
                  <div class="input-group-text"><i class="far fa-clock"></i></div>
                </div>
              </div>
            </div>
          </div>

          <div class="form-group row">
            <label class="col-sm-3 col-form-label">Odchod</label>
            <div class="col-sm-9">
              <div class="input-group date" id="time_out_add" data-target-input="nearest">
                <input type="text" name="time_out" class="form-control datetimepicker-input" data-target="#time_out_add">
                <div class="input-group-append" data-toggle="timepicker-icon" data-target="#time_out_add">
                  <div class="input-group-text"><i class="far fa-clock"></i></div>
                </div>
              </div>
            </div>
          </div>

          <div class="form-group row">
            <label for="movement_add" class="col-sm-3 col-form-label">Činnosť</label>
            <div class="col-sm-9">
              <select class="form-control" id="movement_add" name="movement" required>
                <option value="1">Práca</option>
                <option value="4">Obed</option>
                <option value="3">Cigareta</option>
                <option value="5">Dovolenka</option>
                <option value="6">Maródka</option>
              </select>
            </div>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">
            <i class="fa fa-close"></i> Zatvoriť
          </button>
          <button type="submit" class="btn btn-primary" name="add">
            <i class="fa fa-save"></i> Uložiť
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- Dovolenka -->
 <?
 include('sviatky.php');
 ?>
 <script>
  window.SVIATKY = <?php echo json_encode(array_values($sviatky)); ?>;
</script>
<style>
.days-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
}

.day-item input {
  display: none;
}

.day-item span {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 36px;
  background: #e9ecef;
  border-radius: 6px;
  cursor: pointer;
  user-select: none;
  color: #000;              /* ← ADD THIS */
  font-weight: 500;
}
/* Weekend default (slightly darker) */
.day-item.weekend span {
  background: #af9797;   /* darker grey */
}
/* holidays highlighted same as weekend */
.day-item.holiday span {
  background: #af9797;
}
/* Checked state */
.day-item input:checked + span {
  background: #6c757d;
  color: #fff;
}
.day-item input:checked + span {
  background: #567c58;
  color: #fff;
}

.day-item.weekend span {
  opacity: .75;
}

.day-item.disabled span {
  opacity: .35;
  pointer-events: none;
}
</style>
<div class="modal fade" id="addovolenka" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">

      <form class="form-horizontal" method="POST" action="scripts/dovolenka_add.php">
        <div class="modal-header">
          <h4 class="modal-title"><b>Pridať Dovolenku / Maródku</b></h4>
          <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect ?? '', ENT_QUOTES); ?>">

          <!-- employee -->
          <div class="form-group row">
            <label for="employee_ovo" class="col-sm-3 col-form-label">Zamestnanec</label>
            <div class="col-sm-9">
              <input type="hidden" id="employee_add" name="employee" value="<?php echo htmlspecialchars($_GET['eno'] ?? '', ENT_QUOTES); ?>" required>
                <?php
                $sql = "SELECT * FROM employees WHERE id = '".$_GET['eno']."'";
                $query = $conn->query($sql);
                while($row = $query->fetch_array()){
                  $selected = ($row['id'] == (@$_GET['eno'])) ? 'selected' : '';
                  echo '<h5>'.htmlspecialchars($row['firstname'].' '.$row['lastname']).'</h5>';
                }
                ?> 
            </div>
          </div>

          <!-- year -->
          <div class="form-group row">
            <label for="year_ovo" class="col-sm-3 col-form-label">Rok</label>
            <div class="col-sm-9">
              <select class="form-control" id="year_ovo" name="year" required>
                <?php
                $yearsql   = "SHOW TABLES FROM scrubproduction";
                $yearquery = $conn->query($yearsql);
                $years = [];
                while ($r = $yearquery->fetch_row()) {
                  $tableName = $r[0];
                  if (strpos($tableName, 'attdn_') === 0) {
                    $y = substr($tableName, 6);
                    if (ctype_digit($y)) $years[] = $y;
                  }
                }
                $years = array_values(array_unique($years));
                sort($years, SORT_NUMERIC);
                $currentYear = $_GET['year'] ?? '';
                foreach ($years as $y) {
                  $sel = ((string)$y === (string)$currentYear) ? ' selected' : '';
                  echo '<option value="'.htmlspecialchars($y, ENT_QUOTES).'"'.$sel.'>'.$y.'</option>';
                }
                ?>
              </select>
            </div>
          </div>

          <!-- month -->
          <div class="form-group row">
            <label for="month_ovo" class="col-sm-3 col-form-label">Mesiac</label>
            <div class="col-sm-9">
              <select class="form-control" id="month_ovo" name="month" required>
                <?php
                foreach ($mesiace as $key => $value) {
                  $sel = ((string)$key === (string)$Month) ? ' selected' : '';
                  echo '<option value="'.htmlspecialchars($key, ENT_QUOTES).'"'.$sel.'>'
                    .htmlspecialchars($value).'</option>';
                }
                ?>
              </select>
            </div>
          </div>

          <!-- time from -->
          <div class="form-group row">
            <label class="col-sm-3 col-form-label">Od</label>
            <div class="col-sm-9">
              <div class="input-group date" id="time_in_ovo_picker" data-target-input="nearest">
                <input type="text" name="time_in" class="form-control datetimepicker-input"
                       data-target="#time_in_ovo_picker"
                       value="<?php echo htmlspecialchars($StartPraca ?? '', ENT_QUOTES); ?>">
                <div class="input-group-append" data-toggle="timepicker-icon" data-target="#time_in_ovo_picker">
                  <div class="input-group-text"><i class="far fa-clock"></i></div>
                </div>
              </div>
            </div>
          </div>

          <!-- time to -->
          <div class="form-group row">
            <label class="col-sm-3 col-form-label">Do</label>
            <div class="col-sm-9">
              <div class="input-group date" id="time_out_ovo_picker" data-target-input="nearest">
                <input type="text" name="time_out" class="form-control datetimepicker-input"
                       data-target="#time_out_ovo_picker"
                       value="<?php echo htmlspecialchars($EndPraca ?? '', ENT_QUOTES); ?>">
                <div class="input-group-append" data-toggle="timepicker-icon" data-target="#time_out_ovo_picker">
                  <div class="input-group-text"><i class="far fa-clock"></i></div>
                </div>
              </div>
            </div>
          </div>

          <!-- movement -->
          <div class="form-group row">
            <label for="movement_ovo" class="col-sm-3 col-form-label">Činnosť</label>
            <div class="col-sm-9">
              <select class="form-control" id="movement_ovo" name="movement" required>
                <option value="1">Práca</option>
			  <option value="5">Dovolenka</option>
                <option value="6">PN</option>
              </select>
            </div>
          </div>

          <!-- days -->
          <div class="form-group row">
            <label class="col-sm-3 col-form-label">Dni</label>
            <div class="col-sm-9">
              <div id="days_grid" class="days-grid"></div>
            </div>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">
            <i class="fa fa-close"></i> Zatvoriť
          </button>
          <button type="submit" class="btn btn-primary" name="add">
            <i class="fa fa-save"></i> Uložiť
          </button>
        </div>
      </form>

    </div>
  </div>
</div>
<!-- Edit -->

<div class="modal fade" id="edit" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title"><b><span id="employee_name"></span></b></h4>
        
      </div>
      <form class="form-horizontal" method="POST" action="scripts/attendance_edit.php">
        <div class="modal-body">
          <input type="hidden" name="year" value="<?php echo htmlspecialchars($year ?? '', ENT_QUOTES); ?>">
          <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect ?? '', ENT_QUOTES); ?>">
          <input type="hidden" id="attid" name="id">

          <div class="form-group row">
            <label class="col-sm-3 col-form-label">Dátum</label>
            <div class="col-sm-9">
              <div class="input-group date" id="datepicker_edit" data-target-input="nearest">
                <input type="text" name="edit_date" class="form-control datetimepicker-input" data-target="#datepicker_edit">
                <div class="input-group-append" data-target="#datepicker_edit" data-toggle="datetimepicker">
                  <div class="input-group-text"><i class="far fa-calendar-alt"></i></div>
                </div>
              </div>
            </div>
          </div>

          <div class="form-group row">
            <label class="col-sm-3 col-form-label">Príchod</label>
            <div class="col-sm-9">
              <div class="input-group date" id="time_in_edit" data-target-input="nearest">
                <input type="text" name="edit_time_in" class="form-control datetimepicker-input" data-target="#time_in_edit">
                <div class="input-group-append" data-toggle="timepicker-icon" data-target="#time_in_edit">
                  <div class="input-group-text"><i class="far fa-clock"></i></div>
                </div>
              </div>
            </div>
          </div>

          <div class="form-group row">
            <label class="col-sm-3 col-form-label">Odchod</label>
            <div class="col-sm-9">
              <div class="input-group date" id="time_out_edit" data-target-input="nearest">
                <input type="text" name="edit_time_out" class="form-control datetimepicker-input" data-target="#time_out_edit">
                <div class="input-group-append" data-toggle="timepicker-icon" data-target="#time_out_edit">
                  <div class="input-group-text"><i class="far fa-clock"></i></div>
                </div>
              </div>
            </div>
          </div>

          <div class="form-group row">
            <label for="edit_movement" class="col-sm-3 col-form-label">Činnosť</label>
            <div class="col-sm-9">
              <select class="form-control" id="edit_movement" name="edit_movement" required>
                <option value="1">Práca</option>
                <option value="4">Obed</option>
                <option value="3">Cigareta</option>
                <option value="5">Dovolenka</option>
                <option value="6">Maródka</option>
              </select>
            </div>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fa fa-close"></i> Zatvoriť</button>
          <button type="submit" class="btn btn-success" name="edit"><i class="fa fa-check-square-o"></i> Upraviť</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete -->
<div class="modal fade" id="delete">
    <div class="modal-dialog">
        <div class="modal-content">
          	<div class="modal-header">
            	
            	<h4 class="modal-title"><b><span id="attendance_date"></span></b></h4>
          	</div>
          	<div class="modal-body">
            	<form class="form-horizontal" method="POST" action="scripts/attendance_delete.php">
				<input type="hidden" name="year" value="<?echo $year;?>">
				<input type="hidden" name="redirect" value="<?echo $redirect;?>">
            		<input type="hidden" id="del_attid" name="id">
            		<div class="text-center">
	                	<p>VYMAZAŤ ZÁZNAM ?</p>
	                	<h2 id="del_employee_name" class="bold"></h2>
	            	</div>
          	</div>
          	<div class="modal-footer">
            	<button type="button" class="btn btn-default btn-flat pull-left" data-dismiss="modal"><i class="fa fa-close"></i> Zatvoriť</button>
            	<button type="submit" class="btn btn-danger btn-flat" name="delete"><i class="fa fa-trash"></i> Odstrániť</button>
            	</form>
          	</div>
        </div>
    </div>
</div>

<script>
function initAttendancePickers() {
  console.log('initAttendancePickers - using Tempusdominus jQuery API');
  
  // Date pickers - using jQuery .datetimepicker() method
  $('#datepicker_add').datetimepicker({
    format: 'YYYY-MM-DD'
  });
  
  $('#datepicker_edit').datetimepicker({
    format: 'YYYY-MM-DD'
  });

  // Time pickers - format HH:mm:ss, step by 1 minute
  $('#time_in_add, #time_out_add, #time_in_edit, #time_out_edit').datetimepicker({
    format: 'HH:mm:ss'
  });
  
  console.log('All pickers initialized');
}

function initOvoPickers() {
  console.log('initOvoPickers - using Tempusdominus jQuery API');
  
  $('#time_in_ovo_picker, #time_out_ovo_picker').datetimepicker({
    format: 'HH:mm:ss'
  });
}

// Global click handler for timepicker icons
$(document).on('click', '[data-toggle="timepicker-icon"]', function(e) {
  e.preventDefault();
  e.stopPropagation();
  
  var targetId = $(this).data('target');
  var $target = $(targetId);
  
  if ($target.length) {
    $target.datetimepicker('toggle');
  }
});

function daysInMonth(year, month1to12) {
  return new Date(year, month1to12, 0).getDate();
}

function pad2(n) { return String(n).padStart(2, '0'); }

function renderDaysGrid(year, month1to12) {
  const grid = document.getElementById('days_grid');
  if (!grid) return;

  grid.innerHTML = '';
  const holidays = Array.isArray(window.SVIATKY) ? window.SVIATKY : [];
  const holidaySet = new Set(holidays);

  const firstDay = new Date(year, month1to12 - 1, 1);
  let startDay = firstDay.getDay();
  startDay = (startDay === 0) ? 6 : startDay - 1;

  const totalDays = new Date(year, month1to12, 0).getDate();

  for (let i = 0; i < startDay; i++) {
    grid.appendChild(document.createElement('div'));
  }

  for (let d = 1; d <= totalDays; d++) {
    const dateObj = new Date(year, month1to12 - 1, d);
    let dow = dateObj.getDay();
    dow = (dow === 0) ? 6 : dow - 1;
    const isWeekend = (dow === 5 || dow === 6);

    const ddmm = `${pad2(d)}-${pad2(month1to12)}`;
    const isHoliday = holidaySet.has(ddmm);

    const label = document.createElement('label');
    label.className = 'day-item' + (isWeekend ? ' weekend' : '') + (isHoliday ? ' holiday' : '');
    label.innerHTML = `
      <input type="checkbox" name="copy[]" value="${d}">
      <span>${d}</span>
    `;
    grid.appendChild(label);
  }
}

$(function () {
  console.log('Tempusdominus jQuery method available:', typeof $.fn.datetimepicker !== 'undefined');
  
  // Initialize pickers at page load
  initAttendancePickers();
  initOvoPickers();

  // Re-initialize when modals open
  $('#addnew').on('shown.bs.modal', function () {
    initAttendancePickers();
  });

  $('#edit').on('shown.bs.modal', function () {
    initAttendancePickers();
  });

  $('#addovolenka').on('shown.bs.modal', function () {
    initOvoPickers();
    const year = parseInt($('#year_ovo').val(), 10);
    const month = parseInt($('#month_ovo').val(), 10);
    renderDaysGrid(year, month);
  });

  $(document).on('change', '#year_ovo, #month_ovo', function () {
    const year = parseInt($('#year_ovo').val(), 10);
    const month = parseInt($('#month_ovo').val(), 10);
    renderDaysGrid(year, month);
  });
});
</script>