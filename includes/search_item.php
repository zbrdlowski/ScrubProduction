
<?php
$query = $_GET['query'] ?? '';
$results = [];
$onShelf = [];
$otherLocations = [];
?>
<div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h2>🔍 Search Results for "<?= htmlspecialchars($query) ?>"</h2>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
<?php
if ($query) {

    // Run your original query
    $stmt = $pdo->prepare("SELECT 
        items.barcode,
        items.name,
        items.description,
        items.color,
        stock_levels.shelf_name,
        stock_levels.quantity,
        stock_levels.item_id
      FROM items
      LEFT JOIN stock_levels ON items.id = stock_levels.item_id
      WHERE 
        items.barcode LIKE :q OR
        items.name LIKE :q OR
        items.description LIKE :q OR
        stock_levels.shelf_name LIKE :q
        OR items.id IN (
            SELECT item_id FROM stock_levels WHERE shelf_name LIKE :q
        )
      ORDER BY stock_levels.shelf_name
    ");

    $stmt->execute(['q' => "%$query%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Split into "on shelf" and "other locations"
    foreach ($results as $row) {
        if (strcasecmp($row['shelf_name'], $query) === 0) {
            $onShelf[] = $row;
        } else {
            $otherLocations[] = $row;
        }
    }
}
?>

<div class="container">
    <!-- 🔍 Quick Search -->
  <div class="col-md-12">
    <div class="card">
      <div class="card-body">
        <div class="dashboard-section">
          <div class="panel panel-default">
            <div class="panel-heading"><i class="fa fa-search"></i> Quick Search</div><br />
            <div class="panel-body">
              <form method="GET" action="index.php" class="d-flex justify-content-center" style="gap:10px;">
                <input type="hidden" name="page" value="search_item">

                <input type="text" 
                      name="query" 
                      class="form-control" 
                      style="max-width:300px;" 
                      placeholder="Barcode, Shelf, or Name" 
                      required>

                <button type="submit" class="btn btn-primary">🔍 Search</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php if ($query && ($onShelf || $otherLocations)): ?>

    <?php if ($onShelf): ?>
    <h3 class="text-success">📍 Items located at shelf: <strong><?= htmlspecialchars($query) ?></strong></h3>
    <table class="table table-bordered table-striped">
      <thead>
        <tr>
          <th>P/N</th>
          <th>Shelf</th>
          <th>Quantity</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($onShelf as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['barcode']) ?></td>
            <td><?= htmlspecialchars($row['shelf_name']) ?></td>
            <td><?= $row['quantity'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>


    <?php if ($otherLocations): ?>
      <hr>
      <h3 class="text-info">📦 Same items found in other shelves</h3>

      <table class="table table-bordered table-striped">
        <thead>
          <tr>
            <th>P/N</th>
            <th>Shelf</th>
            <th>Quantity</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($otherLocations as $row): ?>
            <tr>
              <td><?= htmlspecialchars($row['barcode']) ?></td>
              <td><?= htmlspecialchars($row['shelf_name']) ?></td>
              <td><?= $row['quantity'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

<?php elseif ($query): ?>
    <div class="alert alert-warning text-center">
      No results found for <strong><?= htmlspecialchars($query) ?></strong>.
    </div>
<?php endif; ?>
</div>
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
