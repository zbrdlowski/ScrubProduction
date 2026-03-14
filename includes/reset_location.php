<div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h2>🧹 Reset Shelf Position</h2>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
<?php
session_start();
// echo pri upesnom / neuspesnom odoslani formulara
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

require_once('includes/conn.php');

$shelf = $_POST['shelf'] ?? '';
$items = [];

if ($shelf) {
    $stmt = $pdo->prepare("SELECT stock_levels.item_id, stock_levels.item_code, stock_levels.shelf_id, stock_levels.shelf_name, stock_levels.quantity, items.barcode, items.name, items.description, items.color, items.brand FROM stock_levels
        LEFT JOIN items ON stock_levels.item_id = items.id
        WHERE stock_levels.shelf_name = :shelf AND stock_levels.quantity > 0");
    $stmt->execute(['shelf' => $shelf]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>



<div class="container">
  <form method="POST" class="form-inline text-center" style="margin-bottom: 20px;">
    <input type="text" name="shelf" class="form-control input-sm" placeholder="Enter Shelf (e.g. A2-01-01)" value="<?= htmlspecialchars($shelf) ?>" required>
    <button type="submit" class="btn btn-primary">🔍 Load Shelf</button>
  </form>

  <?php if ($shelf && $items): ?>
    <form method="POST" action="scripts/reset_position.php">
      <input type="hidden" name="shelf" value="<?= htmlspecialchars($shelf) ?>">
      <table class="table table-bordered table-striped" id="resetTable">
        <thead>
          <tr>
            <th>Barcode</th>

            <th>Quantity</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><?= htmlspecialchars($item['barcode']) ?></td>
              
              <td>
                  <?= $item['quantity'] ?>
                  <input type="hidden" name="items[<?= $item['item_id'] ?>]" value="<?= $item['quantity'] ?>">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="btn btn-danger btn-block">🧹 Reset This Shelf</button>
    </form>
  <?php elseif ($shelf): ?>
    <div class="alert alert-warning text-center">No items found on shelf <strong><?= htmlspecialchars($shelf) ?></strong>.</div>
  <?php endif; ?>
</div>

<script>
  $(document).ready(function() {
    $('#resetTable').DataTable();
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