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

$id = $_GET['id'] ?? null;
if (!$id) {
  echo "<div class='alert alert-danger'>Missing item ID.</div>";
  exit;
}

$stmt = $conn->prepare("SELECT * FROM items WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$item = $result->fetch_assoc();

if (!$item) {
  echo "<div class='alert alert-warning'>Item not found.</div>";
  
} 
?>
  
<div class="dark-section">
  <h2 class="text-center text-light">Update Item <?= htmlspecialchars($item['barcode']) ?></h2>

  <div class="container">
    <div class="row">
      <div class="col-md-12 col-md-offset-3">
        <form action="scripts/update_item.php" method="POST" class="well well-lg">
            <input type="hidden" class="form-control" id="id" name="id" value="<?= htmlspecialchars($item['id']) ?>">
          <input type="hidden" class="form-control" id="page" name="page" value="add_item">
  <div class="form-group">
    <label for="brand">Brand:</label>
    <input type="text" class="form-control" id="brand" name="brand" value="<?= htmlspecialchars($item['brand']) ?>">
  </div>

  <div class="form-group">
    <label for="barcode">Barcode:</label>
    <input type="text" class="form-control" id="barcode" name="barcode" required value="<?= htmlspecialchars($item['barcode']) ?>">
  </div>

  <div class="form-group">
    <label for="scrubcode">Scrubcode:</label>
    <input type="text" class="form-control" id="scrubcode" name="scrubcode" value="<?= htmlspecialchars($item['scrubcode']) ?>">
  </div>

  <div class="form-group">
    <label for="name">Model:</label>
    <input type="text" class="form-control" id="name" name="name" required value="<?= htmlspecialchars($item['name']) ?>">
  </div>

  <div class="form-group">
    <label for="description">Part:</label>
    <input type="text" class="form-control" id="description" name="description" value="<?= htmlspecialchars($item['description']) ?>">
  </div>

  <div class="form-group">
    <label for="color">Color:</label>
    <input type="text" class="form-control" id="color" name="color" value="<?= htmlspecialchars($item['color']) ?>">
  </div>

  <div class="form-group">
    <label for="optimum">Optimum:</label>
    <input type="number" class="form-control" id="optimum" name="optimum" value="<?= htmlspecialchars($item['optimum']) ?>">
  </div>

  <div class="form-group">
    <label for="moq">Max:</label>
    <input type="number" class="form-control" id="moq" name="moq" value="<?= htmlspecialchars($item['moq']) ?>">
  </div>

  <div class="form-group">
    <label for="main_supplier">Main Supplier:</label>
    <input type="text"
       class="form-control"
       id="main_supplier"
       name="main_supplier"
       value="<?= htmlspecialchars($item['main_supplier']) ?>">
  </div>  
    
    <input type="hidden" class="form-control" id="baseline" name="baseline" value="0">
  

  <div class="form-group">
    <label for="ufo_pn">UFO PN:</label>
    <input type="text" class="form-control" id="ufo_pn" name="ufo_pn" value="<?= htmlspecialchars($item['ufo_pn']) ?>">
  </div>

  <div class="form-group">
    <label for="ufo_barcode">UFO Barcode:</label>
    <input type="text" class="form-control" id="ufo_barcode" name="ufo_barcode" value="<?= htmlspecialchars($item['ufo_barcode']) ?>">
  </div>

  <div class="form-group">
    <label for="rt_pn">R-Tech PN:</label>
    <input type="text" class="form-control" id="rt_pn" name="rt_pn" value="<?= htmlspecialchars($item['rt_pn']) ?>">
  </div>

  <div class="form-group">
    <label for="rt_barcode">R-Tech Barcode:</label>
    <input type="text" class="form-control" id="rt_barcode" name="rt_barcode" value="<?= htmlspecialchars($item['rt_barcode']) ?>">
  </div>

  <div class="form-group">
    <label for="ps_pn">Polisport PN:</label>
    <input type="text" class="form-control" id="ps_pn" name="ps_pn" value="<?= htmlspecialchars($item['ps_pn']) ?>">
  </div>

  <div class="form-group">
    <label for="ps_barcode">Polisport Barcode:</label>
    <input type="text" class="form-control" id="ps_barcode" name="ps_barcode" value="<?= htmlspecialchars($item['ps_barcode']) ?>">
  </div>

  <div class="form-group">
    <label for="ac_pn">Acerbis PN:</label>
    <input type="text" class="form-control" id="ac_pn" name="ac_pn" value="<?= htmlspecialchars($item['ac_pn']) ?>">
  </div>

  <div class="form-group">
    <label for="ac_barcode">Acerbis Barcode:</label>
    <input type="text" class="form-control" id="ac_barcode" name="ac_barcode" value="<?= htmlspecialchars($item['ac_barcode']) ?>">
  </div>

  <div class="form-group">
    <label for="other_pn">Other PN:</label>
    <input type="text" class="form-control" id="other_pn" name="other_pn" value="<?= htmlspecialchars($item['other_pn']) ?>">
  </div>

  <div class="form-group">
    <label for="other_barcode">Other Barcode:</label>
    <input type="text" class="form-control" id="other_barcode" name="other_barcode" value="<?= htmlspecialchars($item['other_barcode']) ?>">
  </div>

  <button type="submit" class="btn btn-success">Update Item</button>
</form>
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