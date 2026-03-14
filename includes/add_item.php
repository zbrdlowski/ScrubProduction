
<link rel="stylesheet"
      href="js/jquery-ui.css">
<script src="js/jquery-ui.min.js"></script>
<style>
/* jQuery UI Autocomplete – AdminLTE Dark Skin */
.ui-autocomplete {
  background: #222d32;           /* AdminLTE dark */
  border: 1px solid #374850;
  color: #ecf0f5;
  max-height: 250px;
  overflow-y: auto;
  overflow-x: hidden;
  z-index: 1051; /* above modals */
  box-shadow: 0 4px 10px rgba(0,0,0,.6);
  font-size: 14px;
}

/* Individual items */
.ui-autocomplete .ui-menu-item-wrapper {
  padding: 8px 12px;
  cursor: pointer;
}

/* Hover / active item */
.ui-autocomplete .ui-menu-item-wrapper.ui-state-active {
  background: #3c8dbc; /* AdminLTE primary blue */
  color: #fff;
  border: none;
}

/* Remove jQuery UI rounded corners */
.ui-corner-all {
  border-radius: 0;
}
</style>
<?php
 session_start();
       if(isset($_SESSION['error'])){
          echo "
            <div class='alert alert-danger alert-dismissible'>
              <button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;</button>
              <h4><i class='icon fa fa-warning'></i> Nie je to dobré!</h4>
              ".$_SESSION['error']."
            </div>
          ";
          unset($_SESSION['error']);
        }
        if(isset($_SESSION['success'])){
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
<div class="dark-section">
  <h2 class="text-center text-light">Add New Item to Warehouse</h2>

  <div class="container">
    <div class="row">
      <div class="col-md-12 col-md-offset-3">
        <form action="scripts/add_item.php" method="POST" class="well well-lg">
          <input type="hidden" class="form-control" id="page" name="page" value="add_item">
  <div class="form-group">
    <label for="brand">Brand:</label>
    <input type="text" class="form-control" id="brand" name="brand">
  </div>

  <div class="form-group">
    <label for="barcode">Barcode:</label>
    <input type="text" class="form-control" id="barcode" name="barcode" required>
  </div>

  <div class="form-group">
    <label for="scrubcode">Scrubcode:</label>
    <input type="text" class="form-control" id="scrubcode" name="scrubcode">
  </div>

  <div class="form-group">
    <label for="name">Model:</label>
    <input type="text" class="form-control" id="name" name="name" required>
  </div>

  <div class="form-group">
    <label for="description">Part:</label>
    <input type="text" class="form-control" id="description" name="description">
  </div>

  <div class="form-group">
    <label for="color">Color:</label>
    <input type="text" class="form-control" id="color" name="color">
  </div>

  <div class="form-group">
    <label for="optimum">Optimum:</label>
    <input type="number" class="form-control" id="optimum" name="optimum">
  </div>

  <div class="form-group">
    <label for="moq">Max:</label>
    <input type="number" class="form-control" id="moq" name="moq">
  </div>

  <div class="form-group">
    <label for="main_supplier">Main Supplier:</label>
    <input type="text"
       class="form-control"
       id="main_supplier"
       name="main_supplier">
  </div>

  
    
    <input type="hidden" class="form-control" id="baseline" name="baseline" value="0">
  

  <div class="form-group">
    <label for="ufo_pn">UFO PN:</label>
    <input type="text" class="form-control" id="ufo_pn" name="ufo_pn">
  </div>

  <div class="form-group">
    <label for="ufo_barcode">UFO Barcode:</label>
    <input type="text" class="form-control" id="ufo_barcode" name="ufo_barcode">
  </div>

  <div class="form-group">
    <label for="rt_pn">R-Tech PN:</label>
    <input type="text" class="form-control" id="rt_pn" name="rt_pn">
  </div>

  <div class="form-group">
    <label for="rt_barcode">R-Tech Barcode:</label>
    <input type="text" class="form-control" id="rt_barcode" name="rt_barcode">
  </div>

  <div class="form-group">
    <label for="ps_pn">Polisport PN:</label>
    <input type="text" class="form-control" id="ps_pn" name="ps_pn">
  </div>

  <div class="form-group">
    <label for="ps_barcode">Polisport Barcode:</label>
    <input type="text" class="form-control" id="ps_barcode" name="ps_barcode">
  </div>

  <div class="form-group">
    <label for="ac_pn">Acerbis PN:</label>
    <input type="text" class="form-control" id="ac_pn" name="ac_pn">
  </div>

  <div class="form-group">
    <label for="ac_barcode">Acerbis Barcode:</label>
    <input type="text" class="form-control" id="ac_barcode" name="ac_barcode">
  </div>

  <div class="form-group">
    <label for="other_pn">Other PN:</label>
    <input type="text" class="form-control" id="other_pn" name="other_pn">
  </div>

  <div class="form-group">
    <label for="other_barcode">Other Barcode:</label>
    <input type="text" class="form-control" id="other_barcode" name="other_barcode">
  </div>

  <button type="submit" class="btn btn-success">Add Item</button>
</form>

      </div>
    </div>
  </div>
 <footer class="text-center text-light" style="padding: 20px 0;">
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