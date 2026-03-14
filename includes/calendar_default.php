<?php
// calendar_default.php (AdminLTE / Bootstrap 3 friendly)
// NOTE: No <wrapper> or <content-wrapper> here (handled by index.php)

?>


  <div class="box-body">
    <div class="row">
      <div class="col-sm-10">
        <form class="form-horizontal">

          <div class="form-group">
            <label for="eno" class="col-sm-3 control-label">Vyber zamestnanca</label>
            <div class="col-sm-9">
              <select
                class="form-control"
                id="eno"
                name="eno"
                onchange="this.value && (window.location = this.value);"
              >
                <?php
                $query2 = $conn->query($sql2);
                echo '<option value="index.php?page=calendar" selected>Vyber zamestnanca</option>';
                while ($row2 = $query2->fetch_array()) {
                  $selected = ((string)$row2['id'] === (string)$eno) ? ' selected' : '';
                  echo '<option value="index.php?page=calendar&eno='.$row2['id'].'&year='.$Year.'&month='.$Month.'&activedisp='.$ActiveDisp.'"'.$selected.'>'
                      .$row2['lastname'].' '.$row2['firstname'].
                      '</option>';
                }
                ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label for="month" class="col-sm-3 control-label">Vyber mesiac</label>
            <div class="col-sm-9">
              <select
                class="form-control"
                id="month"
                name="month"
                onchange="this.value && (window.location = this.value);"
              >
                <?php
                foreach ($mesiace as $key => $value) {
                  $sel = ((string)$key === (string)$Month) ? ' selected' : '';
                  echo '<option value="index.php?page=calendar&eno='.$eno.'&year='.$Year.'&month='.$key.'&activedisp='.$ActiveDisp.'"'.$sel.'>'.$value.'</option>';
                }
                ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label for="year" class="col-sm-3 control-label">Vyber rok</label>
            <div class="col-sm-9">
              <select
                class="form-control"
                id="year"
                name="year"
                onchange="this.value && (window.location = this.value);"
              >
                <?php
                $yearsql = "SHOW TABLES FROM dochadzka";
                $yearquery = $conn->query($yearsql);
                while ($yearow = $yearquery->fetch_array()) {
                  if (substr($yearow[0], 0, 6) === 'attdn_') {
                    $yearloop = substr($yearow[0], -4);
                    $sel = ((string)$Year === (string)$yearloop) ? ' selected' : '';
                    echo '<option value="index.php?page=calendar&eno='.$eno.'&year='.$yearloop.'&month='.$Month.'&activedisp='.$ActiveDisp.'"'.$sel.'>'.$yearloop.'</option>';
                  }
                }
                ?>
              </select>
            </div>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>

<script>


 $(function() {
   // Tooltip initialization for attendance entries
   $('[data-toggle="tooltip"]').tooltip();

   // Handle attendance-entry clicks
   $(document).on('click', '.attendance-entry', function() {
     var date = $(this).data('date');
     var type = $(this).data('type');
     $('#attendanceModal').modal('show');
     $('#attendanceModal .modal-title').text('Dochádzka - ' + date);
     $('#attendanceDetails').html('<p>Načítavam údaje...</p>');

     $.ajax({
       url: 'attendance_details.php',
       method: 'GET',
       data: { eno: '<?php echo $eno; ?>', date: date, type: type },
       success: function(response) {
         $('#attendanceDetails').html(response);
       },
       error: function() {
         $('#attendanceDetails').html('<p class="text-danger">Chyba při načítání údajů.</p>');
       }
     });
    });
});