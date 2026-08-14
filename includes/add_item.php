<link rel="stylesheet" href="js/jquery-ui.css">
<script src="js/jquery-ui.min.js"></script>

<style>
/* jQuery UI Autocomplete – AdminLTE Dark Skin */
.ui-autocomplete {
  background: #222d32;
  border: 1px solid #374850;
  color: #ecf0f5;
  max-height: 250px;
  overflow-y: auto;
  overflow-x: hidden;
  z-index: 1051;
  box-shadow: 0 4px 10px rgba(0,0,0,.6);
  font-size: 14px;
}

.ui-autocomplete .ui-menu-item-wrapper {
  padding: 8px 12px;
  cursor: pointer;
}

.ui-autocomplete .ui-menu-item-wrapper.ui-state-active {
  background: #3c8dbc;
  color: #fff;
  border: none;
}

.ui-corner-all {
  border-radius: 0;
}

/* Compact Add Item form for AdminLTE 3.2 dark mode */
.add-item-page {
  padding: 8px 12px 12px;
}

.add-item-page .page-title {
  margin: 0 0 10px;
  font-size: 22px;
  font-weight: 600;
}

.add-item-card {
  margin-bottom: 0;
}

.add-item-card .card-body {
  padding: 12px 14px;
}

.add-item-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px 14px;
}

.add-item-column {
  min-width: 0;
}

.add-item-section-title {
  margin: 0 0 8px;
  padding-bottom: 5px;
  border-bottom: 1px solid #4b545c;
  color: #adb5bd;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
}

.add-item-page .form-group {
  margin-bottom: 7px;
}

.add-item-page label {
  margin-bottom: 2px;
  font-size: 12px;
  line-height: 1.15;
  color: #ced4da;
}

.add-item-page .form-control {
  height: 31px;
  padding: 4px 8px;
  font-size: 13px;
}

.add-item-actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid #4b545c;
}

.add-item-actions .btn {
  min-width: 120px;
}

.add-item-page footer {
  padding: 8px 0 0 !important;
  font-size: 12px;
}

@media (max-width: 1199.98px) {
  .add-item-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 767.98px) {
  .add-item-page {
    padding-left: 6px;
    padding-right: 6px;
  }

  .add-item-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<?php
session_start();

if (isset($_SESSION['error'])) {
  echo "
    <div class='alert alert-danger alert-dismissible'>
      <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
      <h4><i class='icon fa fa-warning'></i> Nie je to dobré!</h4>
      ".$_SESSION['error']."
    </div>
  ";
  unset($_SESSION['error']);
}

if (isset($_SESSION['success'])) {
  echo "
    <div class='alert alert-success alert-dismissible'>
      <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
      <h4><i class='icon fa fa-check'></i> Podarilo sa!</h4>
      ".$_SESSION['success']."
    </div>
  ";
  unset($_SESSION['success']);
}
?>

<div class="dark-section add-item-page">
  <h2 class="text-center text-light page-title">Add New Item to Warehouse</h2>

  <div class="container-fluid px-0">
    <div class="card card-dark add-item-card">
      <div class="card-body">
        <form action="scripts/add_item.php" method="POST">
          <input type="hidden" id="page" name="page" value="add_item">
          <input type="hidden" id="baseline" name="baseline" value="0">

          <div class="add-item-grid">
            <!-- Column 1: Basic item data -->
            <div class="add-item-column">
              <div class="add-item-section-title">Item</div>

              <div class="form-group">
                <label for="brand">Brand:</label>
                <input type="text" class="form-control form-control-sm" id="brand" name="brand">
              </div>

              <div class="form-group">
                <label for="barcode">Barcode:</label>
                <input type="text" class="form-control form-control-sm" id="barcode" name="barcode" required>
              </div>

              <div class="form-group">
                <label for="scrubcode">Scrubcode:</label>
                <input type="text" class="form-control form-control-sm" id="scrubcode" name="scrubcode">
              </div>

              <div class="form-group">
                <label for="name">Model:</label>
                <input type="text" class="form-control form-control-sm" id="name" name="name" required>
              </div>

              <div class="form-group">
                <label for="description">Part:</label>
                <input type="text" class="form-control form-control-sm" id="description" name="description">
              </div>

              <div class="form-group">
                <label for="color">Color:</label>
                <input type="text" class="form-control form-control-sm" id="color" name="color">
              </div>
            </div>

            <!-- Column 2: Stock + primary supplier refs -->
            <div class="add-item-column">
              <div class="add-item-section-title">Stock & Supplier</div>

              <div class="form-group">
                <label for="optimum">Optimum:</label>
                <input type="number" class="form-control form-control-sm" id="optimum" name="optimum">
              </div>

              <div class="form-group">
                <label for="moq">Max:</label>
                <input type="number" class="form-control form-control-sm" id="moq" name="moq">
              </div>

              <div class="form-group">
                <label for="main_supplier">Main Supplier:</label>
                <input type="text" class="form-control form-control-sm" id="main_supplier" name="main_supplier">
              </div>

              <div class="form-group">
                <label for="ufo_pn">UFO PN:</label>
                <input type="text" class="form-control form-control-sm" id="ufo_pn" name="ufo_pn">
              </div>

              <div class="form-group">
                <label for="ufo_barcode">UFO Barcode:</label>
                <input type="text" class="form-control form-control-sm" id="ufo_barcode" name="ufo_barcode">
              </div>

              <div class="form-group">
                <label for="rt_pn">R-Tech PN:</label>
                <input type="text" class="form-control form-control-sm" id="rt_pn" name="rt_pn">
              </div>

              <div class="form-group">
                <label for="rt_barcode">R-Tech Barcode:</label>
                <input type="text" class="form-control form-control-sm" id="rt_barcode" name="rt_barcode">
              </div>
            </div>

            <!-- Column 3: Remaining supplier refs -->
            <div class="add-item-column">
              <div class="add-item-section-title">Supplier References</div>

              <div class="form-group">
                <label for="ps_pn">Polisport PN:</label>
                <input type="text" class="form-control form-control-sm" id="ps_pn" name="ps_pn">
              </div>

              <div class="form-group">
                <label for="ps_barcode">Polisport Barcode:</label>
                <input type="text" class="form-control form-control-sm" id="ps_barcode" name="ps_barcode">
              </div>

              <div class="form-group">
                <label for="ac_pn">Acerbis PN:</label>
                <input type="text" class="form-control form-control-sm" id="ac_pn" name="ac_pn">
              </div>

              <div class="form-group">
                <label for="ac_barcode">Acerbis Barcode:</label>
                <input type="text" class="form-control form-control-sm" id="ac_barcode" name="ac_barcode">
              </div>

              <div class="form-group">
                <label for="other_pn">Other PN:</label>
                <input type="text" class="form-control form-control-sm" id="other_pn" name="other_pn">
              </div>

              <div class="form-group">
                <label for="other_barcode">Other Barcode:</label>
                <input type="text" class="form-control form-control-sm" id="other_barcode" name="other_barcode">
              </div>
            </div>
          </div>

          <div class="add-item-actions">
            <button type="submit" class="btn btn-success btn-sm">
              <i class="fas fa-plus mr-1"></i> Add Item
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <footer class="text-center text-light">
    &copy; <?= date('Y') ?> SCRUBDESIGNZ. All rights reserved.
  </footer>
</div>

<script>
$(function () {
  $('#main_supplier').autocomplete({
    source: 'scripts/get_suppliers.php',
    minLength: 1,
    delay: 150,
    autoFocus: true
  });
});
</script>
