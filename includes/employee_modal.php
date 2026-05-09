<!-- Add -->
<style>
  .employee-add-modal .modal-content {
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, .12);
  }

  .employee-add-modal .modal-header {
    background: linear-gradient(135deg, #17a2b8, #6610f2);
    color: #fff;
    border-bottom: 0;
  }

  .employee-add-modal .modal-body {
    background: #2f343a;
    color: #e9ecef;
  }

  .employee-add-modal .section-card {
    background: rgba(255, 255, 255, .06);
    border: 1px solid rgba(255, 255, 255, .10);
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 14px;
  }

  .employee-add-modal .section-title {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 12px;
  }

  .employee-add-modal label {
    font-size: 12px;
    color: #adb5bd;
    margin-bottom: 4px;
  }

  .employee-add-modal .form-control,
  .employee-add-modal .custom-file-label {
    background: rgba(0, 0, 0, .20);
    border-color: rgba(255, 255, 255, .18);
    color: #fff;
  }

  .employee-add-modal .form-control:focus {
    background: rgba(0, 0, 0, .30);
    color: #fff;
    border-color: #17a2b8;
    box-shadow: 0 0 0 .1rem rgba(23, 162, 184, .25);
  }

  .employee-add-modal .modal-footer {
    background: #252a30;
    border-top: 1px solid rgba(255, 255, 255, .10);
  }

  .employee-switch-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 10px;
  }

  .employee-switch-card {
    background: rgba(0, 0, 0, .18);
    border: 1px solid rgba(255, 255, 255, .10);
    border-radius: 12px;
    padding: 10px 12px;
  }

  .employee-switch-card small {
    display: block;
    color: #adb5bd;
    line-height: 1.2;
    margin-top: 3px;
  }
  .btn-outline-pink {
  color: #ff7eb6;
  border-color: #ff7eb6;
}

.btn-outline-pink:hover,
.btn-outline-pink.active {
  background: #ff7eb6;
  color: #fff;
  border-color: #ff7eb6;
}
</style>

<div class="modal fade employee-add-modal" id="addnew">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">

      <form method="POST" action="scripts/employee_add.php" enctype="multipart/form-data">

        <div class="modal-header">
          <div>
            <h4 class="modal-title mb-0">
              <i class="fas fa-user-plus mr-2"></i><b>Pridať zamestnanca</b>
            </h4>
            <small>Nový používateľ, dochádzka, grid a profile orders nastavenia</small>
          </div>

          <button type="button" class="close text-light" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body">

          <div class="row">

            <div class="col-lg-6">
              <div class="section-card">
                <div class="section-title text-info">
                  <i class="fas fa-id-card mr-1"></i> Základné údaje
                </div>

                <div class="form-row">
                  <div class="form-group col-md-6">
                    <label>Meno</label>
                    <input type="text" class="form-control" name="firstname" required>
                  </div>

                  <div class="form-group col-md-6">
                    <label>Priezvisko</label>
                    <input type="text" class="form-control" name="lastname" required>
                  </div>
                </div>

                <div class="form-group">
                  <label>Adresa</label>
                  <textarea class="form-control" name="address" rows="3"></textarea>
                </div>

                <div class="form-row">
                  <div class="form-group col-md-6">
                    <label>Dátum narodenia</label>
                    <div class="input-group date" id="datepicker_add" data-target-input="nearest">
                      <input type="text" class="form-control datetimepicker-input" data-target="#datepicker_add"
                        id="datepicker_add_input" name="birthdate">
                      <div class="input-group-append" data-target="#datepicker_add" data-toggle="datetimepicker">
                        <div class="input-group-text"><i class="far fa-calendar-alt"></i></div>
                      </div>
                    </div>
                  </div>

                  <div class="form-group col-md-6">
                    <label>Telefón / Kontakt</label>
                    <input type="text" class="form-control" name="contact">
                  </div>
                </div>

                <div class="form-group mb-0">
                  <label class="d-block">Pohlavie</label>

                  <div class="btn-group btn-group-toggle d-flex" data-toggle="buttons">

                    <label class="btn btn-outline-info active w-50">
                      <input type="radio" name="gender" value="Male" autocomplete="off" checked>
                      <i class="fas fa-mars mr-1"></i> Male
                    </label>

                    <label class="btn btn-outline-pink w-50">
                      <input type="radio" name="gender" value="Female" autocomplete="off">
                      <i class="fas fa-venus mr-1"></i> Female
                    </label>

                  </div>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="section-card">
                <div class="section-title text-warning">
                  <i class="fas fa-briefcase mr-1"></i> Práca a dochádzka
                </div>

                <div class="form-row">
                  <div class="form-group col-md-6">
                    <label>Pracovná pozícia</label>
                    <select class="form-control" name="position" required>
                      <option value="">- Select -</option>
                      <?php
                      $sql = "SELECT * FROM position";
                      $query = $conn->query($sql);
                      while ($prow = $query->fetch_assoc()) {
                        echo "<option value='" . $prow['id'] . "'>" . $prow['description'] . "</option>";
                      }
                      ?>
                    </select>
                  </div>

                  <div class="form-group col-md-6">
                    <label>Pracovná doba</label>
                    <select class="form-control" name="schedule" required>
                      <option value="">- Select -</option>
                      <?php
                      $sql = "SELECT * FROM schedules";
                      $query = $conn->query($sql);
                      while ($srow = $query->fetch_assoc()) {
                        echo "<option value='" . $srow['id'] . "'>" . $srow['time_in'] . " - " . $srow['time_out'] . "</option>";
                      }
                      ?>
                    </select>
                  </div>
                </div>

                <div class="form-group">
                  <label>Osobný prehľad</label>
                  <select class="form-control" name="personal" required>
                    <option value="X" selected>Nezobraziť nič</option>
                    <option value="A">Zobraziť iba denný prehľad</option>
                    <option value="B">Zobraziť iba mesačný prehľad</option>
                    <option value="C">Zobraziť oba prehľady</option>
                  </select>
                </div>

                <div class="form-group mb-0">
                  <label>Foto</label>
                  <div class="custom-file">
                    <input type="file" class="custom-file-input" name="photo" id="employee_add_photo">
                    <label class="custom-file-label" for="employee_add_photo">Vybrať súbor...</label>
                  </div>
                </div>
              </div>

              <div class="section-card">
                <div class="section-title text-success">
                  <i class="fas fa-toggle-on mr-1"></i> Prepínače
                </div>

                <div class="employee-switch-grid">

                  <div class="employee-switch-card">
                    <div class="custom-control custom-switch">
                      <input type="checkbox" class="custom-control-input" id="add_active" name="active" value="Active"
                        checked>
                      <label class="custom-control-label" for="add_active">Aktívny</label>
                    </div>
                    <small>Zamestnanec je aktívny v systéme.</small>
                  </div>

                  <div class="employee-switch-card">
                    <div class="custom-control custom-switch">
                      <input type="checkbox" class="custom-control-input" id="add_grid" name="grid" value="1" checked>
                      <label class="custom-control-label" for="add_grid">Zobraziť v gride</label>
                    </div>
                    <small>Dochádzka, online status, obed, prestávky.</small>
                  </div>

                  <div class="employee-switch-card">
                    <div class="custom-control custom-switch">
                      <input type="checkbox" class="custom-control-input" id="add_personal_orders"
                        name="personal_orders" value="1">
                      <label class="custom-control-label" for="add_personal_orders">Profile Orders</label>
                    </div>
                    <small>Zobrazí tab Orders v profile zamestnanca.</small>
                  </div>

                  <div class="employee-switch-card">
                    <div class="custom-control custom-switch">
                      <input type="checkbox" class="custom-control-input" id="add_chat" name="chat" value="yes">
                      <label class="custom-control-label" for="add_chat">Chat</label>
                    </div>
                    <small>Zamestnanec sa môže zobrazovať v chate.</small>
                  </div>

                </div>
              </div>
            </div>

          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-flat" data-dismiss="modal">
            <i class="fa fa-close"></i> Zavrieť
          </button>

          <button type="submit" class="btn btn-info btn-flat" name="add">
            <i class="fa fa-save"></i> Uložiť zamestnanca
          </button>
        </div>

      </form>

    </div>
  </div>
</div>

<script>
  $(function () {
    if (typeof $.fn.datetimepicker !== 'undefined') {
      $('#addnew').on('shown.bs.modal', function () {
        $('#datepicker_add').datetimepicker({ format: 'YYYY-MM-DD' });
      });
    }

    $(document).on('change', '#employee_add_photo', function () {
      var fileName = $(this).val().split('\\').pop();
      $(this).next('.custom-file-label').html(fileName || 'Vybrať súbor...');
    });
  });
</script>