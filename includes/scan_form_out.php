<?php
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

<h2 class="text-center">📦 Scan Item OUT</h2>

<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-8">
      <div class="panel panel-default">
        <div class="panel-body">
          <form id="scanForm" method="POST" action="scripts/scan_out.php" autocomplete="off">
            <div class="form-group">
              <input type="hidden" id="movement" name="movement" value="OUT" required>
            </div>

            <div class="form-group position-relative">
              <label for="order_id">Order Nr:</label>
              <div style="position:relative;">
                <input type="text" class="form-control" id="order_id" name="order_id" required>
                <span id="clearOrder"
                      style="position:absolute; right:8px; top:7px; cursor:pointer; font-weight:bold; color:black;">✖</span>
              </div>
            </div>

            <div class="form-group">
              <label for="barcode">Item Barcode:</label>
              <input type="text" class="form-control" id="barcode" name="barcode" required autofocus>
            </div>

            <div class="form-group">
              <label for="shelf_location">Shelf Location (e.g., A2-01-01):</label>
              <input type="text" class="form-control" id="shelf_location" name="shelf_location" required>
            </div>

            <div class="form-group">
              <label for="quantity">Quantity: 1</label>
              <input type="hidden" class="form-control" id="quantity" name="quantity" value="1" required>
            </div>

            <button type="submit" id="btnSendOut" class="btn btn-danger btn-block">🚀 VYSKLADNIŤ</button>
          </form>

          <div id="scanStatus" style="margin-top:12px;"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
// LAST 10 INVENTORY MOVEMENTS
$operator = $_SESSION['name'] ?? 'emergency input';

$sql = "SELECT im.*, it.name, it.description, it.color
    FROM inventory_movements im
    LEFT JOIN items it ON im.item_id = it.id
    WHERE im.operator IN (:operator, 'emergency input', 'unknown')
    ORDER BY im.timestamp DESC
    LIMIT 10
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'operator' => $operator
]);

?>
<hr>

<h4><i class="fa fa-history"></i> Last 10 Inventory Movements</h4>

<table id="example0" class="table table-bordered table-striped">
  <thead>
    <tr>
      <th>Date</th>
      <th>Operator</th>
      <th>Order</th>
      <th>Barcode</th>
      <th>Shelf</th>
      <th class="text-center">Qty</th>
      <th class="text-center">Type</th>
    </tr>
  </thead>

  <tbody id="movementsBody">

  <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
    <tr class="<?= $row['movement_type'] === 'IN' ? 'success' : 'danger' ?>">
      <td><?= date("d.m.Y H:i", strtotime($row['timestamp'])) ?></td>
      <td><?= htmlspecialchars($row['operator']) ?></td>
      <td><?= htmlspecialchars($row['order_id']) ?></td>
      <td><?= htmlspecialchars($row['item_name']) ?></td>
      <td><?= htmlspecialchars($row['shelf_name']) ?></td>
      <td class="text-center"><?= $row['quantity'] ?></td>
      <td class="text-center">
        <span class="label label-<?= $row['movement_type'] === 'IN' ? 'success' : 'danger' ?>">
          <?= $row['movement_type'] ?>
        </span>
      </td>
    </tr>
  <?php endwhile; ?>
  </tbody>
</table>

<footer class="text-center text-light" style="padding: 20px 0;">
  &copy; <?= date('Y') ?> SCRUBDESIGNZ. All rights reserved.
</footer>

<script>
(function() {
  const form = document.getElementById('scanForm');
  const statusBox = document.getElementById('scanStatus');
  const sendBtn = document.getElementById('btnSendOut');
  const orderInput = document.getElementById('order_id');
  const barcodeInput = document.getElementById('barcode');
  const shelfInput = document.getElementById('shelf_location');
  const clearOrder = document.getElementById('clearOrder');

  function showStatus(type, message) {
    const cls = type === 'success' ? 'alert-success' : 'alert-danger';
    statusBox.innerHTML = '<div class="alert ' + cls + '">' + escapeHtml(message) + '</div>';
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function lockForm() {
    sendBtn.disabled = true;
    barcodeInput.disabled = true;
    shelfInput.disabled = true;
  }

  function unlockForm() {
    sendBtn.disabled = false;
    barcodeInput.disabled = false;
    shelfInput.disabled = false;
  }

  function handleSessionExpired() {
    lockForm();
    alert('Session expired. Please log in again.');
    window.top.location.href = 'login.php';
  }

  function checkSession() {
    fetch('scripts/session_check.php', {
      method: 'GET',
      cache: 'no-store',
      credentials: 'same-origin'
    }).then(function(response) {
      if (response.status === 401) {
        handleSessionExpired();
      }
    }).catch(function() {
      // Network failure should not submit blindly; show warning but do not redirect.
      showStatus('error', 'Cannot verify session. Check connection and try again.');
    });
  }

  form.addEventListener('submit', function(e) {
    const order = orderInput.value.trim();
    const barcode = barcodeInput.value.trim();
    const shelf = shelfInput.value.trim();

    if (!order || !barcode || !shelf) {
      e.preventDefault();
      showStatus('error', 'Order, barcode and shelf are required.');
      return;
    }

    sendBtn.disabled = true;
  });
  clearOrder.addEventListener('click', function() {
    orderInput.value = '';
    fetch('scripts/clear_saved_order.php', {
      method: 'GET',
      cache: 'no-store',
      credentials: 'same-origin'
    });
  });

  checkSession();
  setInterval(checkSession, 30000);
})();
</script>
