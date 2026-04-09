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
$sql = "SELECT 
    items.id,
    items.brand,
    items.barcode,
    items.name,
    items.description,
    items.color,
    items.quantity,
    items.optimum,
    items.moq,

    /* created + sent */
    (
        SELECT COALESCE(SUM(po.quantity_to_order), 0)
        FROM plastics_orders po
        WHERE po.barcode = items.barcode
          AND po.status IN ('created','sent')
    ) AS quantity_to_order,

    /* sent only */
    (
        SELECT COALESCE(SUM(po.quantity_to_order), 0)
        FROM plastics_orders po
        WHERE po.barcode = items.barcode
          AND po.status = 'sent'
    ) AS quantity_sent

FROM items
WHERE items.id IN ($placeholders)
  AND items.main_supplier = ?
ORDER BY items.name";
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
  <table id="orderPrepareTable" class="table table-bordered table-striped">
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
    <th align="center">Current Qty</th>
    <th align="center">Min</th>
    <th align="center">Max</th>
    <th>Ordered</th>
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
          <td align="center"><?= (int)$item['quantity'] ?></td>   <!-- Current qty -->
          <td align="center"><?= (int)$item['optimum'] ?></td>    <!-- Min -->
          <td align="center"><?= (int)$item['moq'] ?></td>         <!-- Max -->
          <td align="center"><?= (int)$item['quantity_sent'] ?></td>          <!-- Ordered qty -->
          <td>
            <?php $toOrder = $item['moq'] - ($item['quantity'] + $item['quantity_to_order']); ?>
<input type="number" name="qty[<?= $item['id'] ?>]" value="<?= $toOrder ?>" class="form-control input-sm" min="1">

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
