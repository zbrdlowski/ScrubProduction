<script src="js/jquery-1.12.4.min.js"></script>
<style>
  input[type="checkbox"] {
    transform: scale(1.5);
    margin-right: 8px;
  }
</style>
<?php

$supplier = $_POST['supplier'] ?? '';
$itemIds = $_POST['items'] ?? [];

if (!$supplier || empty($itemIds)) {
    die("No supplier or items selected.");
}

// Build placeholders for the IN clause
$placeholders = implode(',', array_fill(0, count($itemIds), '?'));

// Prepare and execute the filtered query
$sql = "SELECT * FROM items WHERE id IN ($placeholders) AND main_supplier = ? ORDER BY name";
$stmt = $pdo->prepare($sql);
$stmt->execute([...$itemIds, $supplier]);

$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h3>Prepare Order for <?= htmlspecialchars($supplier) ?></h3>
<form method="post" action="scripts/submit_order.php" id="orderForm">
  <input type="hidden" name="supplier" value="<?= htmlspecialchars($supplier) ?>">
    <input type="hidden" name="order_number" id="order_number_hidden">
  <div class="form-group">
  <label for="order_number">Order Number:</label>
  <input type="text" name="order_number" id="order_number" class="form-control input-sm" required style="width: 200px;">
  
</div>
  <table class="table table-bordered table-striped">
    <thead>
  <tr>
    <th><center>
      <label>
        <input type="checkbox" id="toggleAll">     
      </label></center>
    </th>  
    <th>Brand</th>
    <th>Barcode</th>
    <th>Name</th>
    <th>Description</th>
    <th>Color</th>
    <th>Qty to Order</th>
  </tr>
</thead>
    <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          
            <td align="center">
            <div class="checkbox">
                <label>
                <input type="checkbox" name="selected[]" value="<?= $item['id'] ?>" checked>
                </label>
            </div>
            </td>
          <td><?= htmlspecialchars($item['brand']) ?></td>
          <td><?= htmlspecialchars($item['barcode']) ?></td>
          <td><?= htmlspecialchars($item['name']) ?></td>
          <td><?= htmlspecialchars($item['description']) ?></td>
          <td><?= htmlspecialchars($item['color']) ?></td>
          <td>
            <input type="number" name="qty[<?= $item['id'] ?>]" value="<?= $item['moq'] - $item['quantity']?>" class="form-control input-sm" min="1">
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <!-- Trigger modal -->
<button type="button" class="btn btn-success" data-toggle="modal" data-target="#confirmOrderModal">
  Submit Order
</button><!-- Confirmation Modal -->
<div class="modal fade" id="confirmOrderModal" tabindex="-1" role="dialog" aria-labelledby="confirmOrderModalLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title" id="confirmOrderModalLabel">Confirm Order Submission</h4>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to Create this order to <strong><?= htmlspecialchars($supplier) ?></strong>?</p>
        <p>You’ll be able to review and export it later.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmSubmit">Yes, Create it</button>
      </div>
    </div>
  </div>
</div>
<script>
$('#confirmSubmit').click(function() {
  var orderNum = $('#order_number').val().trim();
  if (!orderNum) {
    alert('Please enter an order number.');
    return;
  }
  $('#order_number_hidden').val(orderNum);
  $('#orderForm').submit();
});
</script>
</form>
<script>
$(function () {
  // Cache selectors
  var $toggleAll = $('#toggleAll');
  var $itemCheckboxes = $("input[name='selected[]']");

  // Initialize toggleAll state on load
  $toggleAll.prop('checked', $itemCheckboxes.length === $itemCheckboxes.filter(':checked').length);

  // Toggle All handler
  $toggleAll.on('change', function () {
    var checked = $(this).prop('checked');
    $("input[name='selected[]']").prop('checked', checked);
  });

  // Individual checkbox handler (delegated - safe if rows change)
  $(document).on('change', "input[name='selected[]']", function () {
    var $all = $("input[name='selected[]']");
    var allCount = $all.length;
    var checkedCount = $all.filter(':checked').length;
    $toggleAll.prop('checked', allCount > 0 && allCount === checkedCount);
  });
});
</script>
