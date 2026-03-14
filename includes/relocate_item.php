<div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h2>🔄 Relocate Item (Change Location)</h2>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
<?php
session_start();
require_once('includes/conn.php');
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
$barcode = $_POST['barcode'] ?? '';
$item_id = null;
$locations = [];

if ($barcode) {
    // Get item ID
    $stmt = $pdo->prepare("SELECT id FROM items WHERE barcode = :barcode");
    $stmt->execute(['barcode' => $barcode]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    $item_id = $item['id'] ?? null;

    if ($item_id) {
        // Get all shelf locations for this item
        $stmt = $pdo->prepare("
            SELECT stock_levels.id AS stock_id, stock_levels.shelf_id, stock_levels.shelf_name, stock_levels.quantity
            FROM stock_levels
            WHERE stock_levels.item_id = :item_id AND stock_levels.quantity > 0
        ");
        $stmt->execute(['item_id' => $item_id]);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<div class="container">
  <form method="POST" class="form-inline text-center" style="margin-bottom: 20px;">
    <input type="text" name="barcode" class="form-control input-sm" placeholder="Enter Barcode" value="<?= htmlspecialchars($barcode) ?>" required>
    <button type="submit" class="btn btn-primary">🔍 Find Locations</button>
  </form>

  <?php if ($barcode && $item_id && $locations): ?>
    <form method="POST" action="scripts/relocate_item.php">
      <input type="hidden" name="item_id" value="<?= $item_id ?>">
      <input type="hidden" name="barcode" value="<?= htmlspecialchars($barcode) ?>">

      <table class="table table-bordered table-striped" id="relocateTable">
        <thead>
          <tr>
            <th>Select</th>
            <th>Shelf</th>
            <th>Available Qty</th>
            <th>Qty to Move</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($locations as $loc): ?>
            <tr>
              <td style="text-align: center;"><input type="checkbox" name="selected[]" value="<?= $loc['stock_id'] ?>" style="transform: scale(1.5);"></td>
              <td><?= htmlspecialchars($loc['shelf_name']) ?></td>
              <td><?= $loc['quantity'] ?></td>
              <td>
                <input type="number" name="qty[<?= $loc['stock_id'] ?>]" value="<?= $loc['quantity'] ?>" class="form-control input-sm" min="1" max="<?= $loc['quantity'] ?>">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="form-group text-center">
        <label for="new_location"></label>
        <input type="text" name="new_location" class="form-control input-sm" required style="width: 200px; display: inline-block;" placeholder="Scan New Location">
      </div>

      <button type="submit" class="btn btn-success btn-block">🚚 Relocate Selected Stock</button>
    </form>
  <?php elseif ($barcode): ?>
    <div class="alert alert-warning text-center">No stock found for barcode <strong><?= htmlspecialchars($barcode) ?></strong>.</div>
  <?php endif; ?>
</div>

<script>
  $(document).ready(function() {
    $('#relocateTable').DataTable();
  });
</script>
               </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
        </div>
        <!-- /.row -->
      </div>
      <!-- /.container-fluid -->  