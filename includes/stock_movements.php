<?php
    
// Build filter clauses + parameters
$filters = [];
$params  = [];

// --- DATE: FROM ---
if (!empty($_GET['from'])) {
    // Normalize to start of day
    $from = trim($_GET['from']);
    $filters[] = "im.timestamp >= :from";
    $params['from'] = $from . " 00:00:00";
}

// --- DATE: TO ---
if (!empty($_GET['to'])) {
    // Normalize to end of day
    $to = trim($_GET['to']);
    $filters[] = "im.timestamp <= :to";
    $params['to'] = $to . " 23:59:59";
}

// --- ORDER NUMBER ---
if (!empty($_GET['order_id'])) {
    $filters[] = "im.order_id LIKE :order_id";
    $params['order_id'] = "%" . trim($_GET['order_id']) . "%";
}

// --- ITEM NAME ---
if (!empty($_GET['item_name'])) {
    $filters[] = "(it.name LIKE :item_name OR im.item_name LIKE :item_name)";
    $params['item_name'] = "%" . trim($_GET['item_name']) . "%";
}

// --- SHELF NAME ---
if (!empty($_GET['shelf_name'])) {
    $filters[] = "im.shelf_name LIKE :shelf_name";
    $params['shelf_name'] = "%" . trim($_GET['shelf_name']) . "%";
}

// --- OPERATOR ---
if (!empty($_GET['operator'])) {
    $filters[] = "im.operator LIKE :operator";
    $params['operator'] = "%" . trim($_GET['operator']) . "%";
}

// MOVEMENTS
if (!empty($_GET['movement_type'])) {
    $filters[] = "im.movement_type LIKE :movement_type";
    $params['movement_type'] = "%" . trim($_GET['movement_type']) . "%";
}

// Build final SQL
$whereSQL = "";
if (!empty($filters)) {
    $whereSQL = " AND " . implode(" AND ", $filters);
}

$sql = "SELECT im.*, it.name, it.description, it.color
    FROM inventory_movements im
    LEFT JOIN items it ON im.item_id = it.id
    WHERE 1=1
    $whereSQL
    ORDER BY im.timestamp DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
?>

<!-- Filter Form -->
<div class="panel panel-info">
  <div class="panel-heading">
    <h3 class="panel-title"><i class="fa fa-filter"></i> Filter Inventory Movements</h3>
  </div>
  <div class="panel-body">
    <form method="GET" action="index.php" class="form-inline">
        <input type="hidden" name="page" value="stock_movements">
      <div class="form-group">
        <label for="from" >From: &nbsp;&nbsp;&nbsp;&nbsp;</label>
        <input type="date" name="from" id="from" class="form-control" value="<?= htmlspecialchars($_GET['from'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="to">&nbsp;&nbsp;&nbsp;&nbsp;To:&nbsp;&nbsp;&nbsp;&nbsp;</label>
        <input type="date" name="to" id="to" class="form-control" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="order_id">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</label>
        <input type="text" name="order_id" id="order_id" class="form-control" placeholder="Scrub Order Number" value="<?= htmlspecialchars($_GET['order_id'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="item_name">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</label>
        <input type="text" name="item_name" id="item_name" class="form-control" placeholder="Scrub Barcode" value="<?= htmlspecialchars($_GET['item_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="operator">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</label>
        <input type="text" name="operator" id="operator" class="form-control" placeholder="Operator" value="<?= htmlspecialchars($_GET['operator'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="shelf_name">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</label>
        <input type="text" name="shelf_name" id="shelf_name" class="form-control" placeholder="Shelf name" value="<?= htmlspecialchars($_GET['shelf_name'] ?? '') ?>">
      </div>&nbsp;&nbsp;&nbsp;&nbsp;
      <div class="form-group">
            <input type="text" name="movement_type" class="form-control" placeholder="Movement" value="<?= htmlspecialchars($_GET['movement_type'] ?? '') ?>">
        </div>
         &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
      <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Search</button>&nbsp;&nbsp;&nbsp;&nbsp;
      <a href="index.php?page=stock_movements" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
    </form>
  </div>
</div>

<!-- Data Table -->

  <div class="panel-heading">
    <hr />
  </div>
      <table width="100%" id="example1" class="table table-bordered table-striped">
  <thead>
    <tr> 
      <th class="text-center">Date</th>
      <th class="text-center">Operator</th>            
      <th>Order No.</th>            
      <th>Scrub P/N</th>
      <th>Location</th>
      <th class="text-center">Quantity</th>
      <th class="text-center">Movement Type</th>
      <th>Model</th>
      <th>Part Desc.</th> 
      <th>Color</th>
      <th class="text-center">Tools</th> <!-- NEW -->
    </tr>
  </thead>
  <tbody>
    <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
      <tr data-id="<?= $row['id']; ?>">
        <td class="text-center" data-order="<?= date('Y-m-d', strtotime($row['timestamp'])) ?>">
          <?= date("d.m.Y H:i", strtotime($row['timestamp'])); ?>
        </td>
        <td class="operator-cell"><?= htmlspecialchars($row['operator']) ?></td>                                   
        <td class="order-cell"><?= htmlspecialchars($row['order_id']) ?></td>
        <td><?= htmlspecialchars($row['item_name']) ?></td>
        <td><?= htmlspecialchars($row['shelf_name']) ?></td>
        <td class="text-center"><?= $row['quantity'] ?></td>
        <td class="text-center">
          <span class="label label-<?= $row['movement_type'] === 'IN' ? 'success' : 'danger' ?>">
            <?= $row['movement_type'] ?>
          </span>
        </td>
        <td><?= htmlspecialchars($row['name']) ?></td>
        <td><?= htmlspecialchars($row['description']) ?></td>
        <td><?= htmlspecialchars($row['color']) ?></td>

        <!-- NEW ACTION COLUMN -->
        <td class="text-center" style="width:120px;">
          <button class="btn btn-primary btn-small edit-order"><i class="fa fa-edit"></i> Edit</button>
          <button class="btn btn-success btn-small save-order" style="display:none;"><i class="fa fa-save"></i> Save</button>
        </td>
      </tr>
    <?php endwhile; ?>
  </tbody>
</table>
<script>
$(document).ready(function() {

  // --- CLICK EDIT ---
  $(document).on('click', '.edit-order', function() {
    var $row = $(this).closest('tr');
    var cell = $row.find('.order-cell');
    var current = cell.text().trim();

    // replace text with input
    cell.html('<input type="text" class="form-control order-input" value="' + current + '">');

    // toggle buttons
    $row.find('.edit-order').hide();
    $row.find('.save-order').show();
  });

  // --- CLICK SAVE ---
  $(document).on('click', '.save-order', function() {
    var $row = $(this).closest('tr');
    var id = $row.data('id');
    var newVal = $row.find('.order-input').val();

    $.ajax({
      url: 'scripts/update_order.php',
      method: 'POST',
      data: {
        id: id,
        order: newVal
      },
      success: function(res) {
        if (res === "OK") {
          // replace input with text
          $row.find('.order-cell').text(newVal);

          // toggle buttons back
          $row.find('.save-order').hide();
          $row.find('.edit-order').show();
        } else {
          alert("Error saving!");
        }
      }
    });
  });

});
</script>


