<script src="js/jquery-1.12.4.min.js"></script>
<link rel="stylesheet" href="assets/css/plastics-workflow.css">
<style>
  .order-prep-form-page input[type="checkbox"] {
    transform: scale(1.5);
    margin-right: 8px;
  }

  .order-prep-form-page .order-meta-grid {
    align-items: end;
    display: grid;
    gap: 14px;
    grid-template-columns: minmax(220px, 360px) minmax(260px, 1fr);
    margin-bottom: 16px;
  }

  .order-prep-form-page .order-meta-grid .form-group {
    margin: 0;
  }

  .order-prep-form-page .order-meta-grid .form-control {
    width: 100% !important;
  }

  .order-prep-form-page .order-context {
    align-items: center;
    background: rgba(45, 212, 191, .09);
    border: 1px solid rgba(45, 212, 191, .2);
    border-radius: 10px;
    color: #b8f4e8;
    display: flex;
    gap: 10px;
    min-height: 44px;
    padding: 10px 13px;
  }

  .order-prep-form-page .qty-order-input {
    min-width: 82px;
    text-align: center;
    width: 100%;
  }

  .order-prep-form-page .selection-cell {
    min-width: 58px;
    text-align: center;
  }

  .order-prep-form-page .selection-cell .checkbox {
    margin: 0;
  }

  @media (max-width: 720px) {
    .order-prep-form-page .order-meta-grid {
      grid-template-columns: 1fr;
    }
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

<div class="container-fluid plastics-workflow-page order-prep-form-page">
  <div class="plastics-workflow-hero">
    <h1>Prepare Supplier Order</h1>
    <p>Review selected items, confirm quantities and create the order for <?= htmlspecialchars($supplier) ?>.</p>
  </div>

  <div class="card">
    <div class="card-header">
      <i class="fas fa-file-invoice mr-2"></i>
      Order details
    </div>
    <div class="card-body">
<form method="post" action="scripts/submit_order.php" id="orderForm">
  <input type="hidden" name="supplier" value="<?= htmlspecialchars($supplier) ?>">
    <input type="hidden" name="order_number" id="order_number_hidden">
  <div class="order-meta-grid">
    <div class="form-group">
      <label for="order_number">Order Number</label>
      <input type="text" name="order_number" id="order_number" class="form-control" required placeholder="Enter supplier order number">
    </div>
    <div class="order-context">
      <i class="fas fa-building"></i>
      <span><strong><?= htmlspecialchars($supplier) ?></strong> · <?= count($items) ?> selected item<?= count($items) === 1 ? '' : 's' ?></span>
    </div>
  </div>
  <div class="plastics-workflow-table-wrap">
  <table id="orderPrepareTable" class="table table-bordered table-striped table-hover" style="width: 100%;">
    <thead>
  <tr>
    <th class="selection-cell">
      <label>
        <input type="checkbox" id="toggleAll">     
      </label>
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
          
            <td class="selection-cell">
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
<input type="number" name="qty[<?= $item['id'] ?>]" value="<?= $toOrder ?>" class="form-control input-sm qty-order-input" min="1">

          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <!-- Trigger modal -->
<div class="plastics-workflow-actions">
  <button type="button" class="btn btn-success" data-toggle="modal" data-target="#confirmOrderModal">
    <i class="fas fa-check mr-1"></i>Submit Order
  </button>
  <a href="index.php?page=order_prepare&amp;supplier=<?= urlencode($supplier) ?>" class="btn btn-default">
    <i class="fas fa-arrow-left mr-1"></i>Back to order queue
  </a>
</div>
<!-- Confirmation Modal -->
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

  // DataTables keeps only the current page in the DOM. Render every row before
  // the native form submit so selected items and their quantities from all
  // pages are included in the request.
  if ($.fn.dataTable && $.fn.dataTable.isDataTable('#orderPrepareTable')) {
    $('#orderPrepareTable').DataTable().page.len(-1).draw(false);
  }

  document.getElementById('orderForm').submit();
});
</script>
</form>
<script>
$(function () {
  var $toggleAll = $('#toggleAll');

  function getAllItemCheckboxes() {
    if ($.fn.dataTable && $.fn.dataTable.isDataTable('#orderPrepareTable')) {
      return $($('#orderPrepareTable').DataTable().rows().nodes())
        .find("input[name='selected[]']");
    }

    return $("input[name='selected[]']");
  }

  // Initialize toggleAll state on load
  var $itemCheckboxes = getAllItemCheckboxes();
  $toggleAll.prop('checked', $itemCheckboxes.length === $itemCheckboxes.filter(':checked').length);

  // Toggle All handler
  $toggleAll.on('change', function () {
    var checked = $(this).prop('checked');
    getAllItemCheckboxes().prop('checked', checked);
  });

  // Individual checkbox handler (delegated - safe if rows change)
  $(document).on('change', "input[name='selected[]']", function () {
    var $all = getAllItemCheckboxes();
    var allCount = $all.length;
    var checkedCount = $all.filter(':checked').length;
    $toggleAll.prop('checked', allCount > 0 && allCount === checkedCount);
  });
});
</script>
</div>
</div>
</div>
