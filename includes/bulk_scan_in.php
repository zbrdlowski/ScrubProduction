<style>
  body {
    padding: 10px;
    background: none;
    font-size: 16px;
    font-family: "Segoe UI", Arial;
  }

  table.form-table {
    width: 100%;
    background: none;
    border-radius: 8px;
    box-shadow: none;
    margin-bottom: 12px;
  }

  table.form-table td {
    padding: 12px;
    vertical-align: middle;
  }

  label {
    font-weight: 600;
  }

  .scan-input {
    font-size: 22px;
    padding: 12px;
    height: 50px;
  }

  .btn-wide {
    padding: 12px 14px;
    font-size: 18px;
    height: 50px;
  }

  .btn-clear {
    background: #d9534f !important;
    color: white !important;
    border: none;
  }

  .btn-send {
    background: #5cb85c !important;
    color: white !important;
    border: none;
  }

  #scannedListBox {
    background: none;
    padding: 0;
    max-height: 300px;
    overflow-y: auto;
  }

  .list-item {
    padding: 8px 6px;
    margin-bottom: 4px;
    background: none;
    border-bottom: 1px solid #ccc;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .barcode {
    font-weight: 600;
    font-size: 16px;
  }

  .desc {
    color: #555;
    font-size: 13px;
    margin-top: 3px;
  }

  .qty-input {
    width: 65px;
    margin-left: 4px;
    display: inline-block;
  }

  .small-btn-x {
    background: none;
    border: none;
    color: #d9534f;
    font-size: 22px;
    line-height: 20px;
    font-weight: bold;
  }

  .clear-x {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 22px;
    color: #999;
    cursor: pointer;
  }

  .input-wrap {
    position: relative;
  }

  .progress {
    height: 20px;
    border-radius: 6px;
    display: none;
    margin-top: 12px;
  }

  #status {
    margin-top: 12px;
    font-size: 16px;
  }

  #scanType {
    border-left: 16px solid green;
    /* default for standard */
    transition: border-color 0.2s;
  }

  #scanType.from-receiving {
    border-left-color: orange;
  }
</style>



<div class="container-fluid">

  <?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']);
    unset($_SESSION['success']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']);
    unset($_SESSION['error']); ?></div>
  <?php endif; ?>
  <label for="scanType">Scan Type: STANDARD STOCK OPERATION</label>
  <input type="hidden" id="scanType" value="standard">

  <div class="row">
    <div class="col-xs-12">
      <div class="form-group">
        <label for="orderId">Order #</label>
        <div class="input-wrap" style="position:relative;">
          <input id="orderId" class="form-control" placeholder="Enter order number (or scan order QR)"
            autocomplete="off" />
          <span class="clear-x" onclick="$('#orderId').val(''); saveState();"
            style="position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; font-size:20px; color:#999;">
            ×
          </span>
        </div>
      </div>
    </div>
  </div>
  <div class="row">
    <div class="col-xs-12">
      <div class="form-group">
        <label for="shelfLocation">Bay / Shelf (scan QR)</label>
        <div class="input-wrap" style="position:relative;">
          <input id="shelfLocation" class="form-control" placeholder="Scan shelf QR (e.g. A010)" autocomplete="off" />

          <span class="clear-x" onclick="$('#shelfLocation').val(''); saveState();"
            style="position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; font-size:20px; color:#999;">
            ×
          </span>

        </div>
      </div>
    </div>
  </div>
  <div class="row">
    <div class="col-xs-12">
      <div class="form-group">
        <label for="scanInput">Scan box barcode</label>
        <input id="scanInput" class="form-control scan-input" placeholder="Scan barcode here — list adds on Enter"
          autocomplete="off" autofocus />
        <div class="hint">Tip: your scanner usually sends an Enter after barcode — that will add the item to the list.
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-xs-12">
      <div id="scannedListBox">
        <div id="scannedList"></div>
      </div>
    </div>
  </div>

  <div class="row" style="margin-top:12px;">
    <div class="col-xs-6">
      <button id="btnClearList" class="btn btn-clear btn-wide btn-block">
        <i class="glyphicon glyphicon-trash"></i> Clear
      </button>
    </div>

    <div class="col-xs-6">
      <button id="btnSend" class="btn btn-send btn-wide btn-block">
        <i class="glyphicon glyphicon-ok"></i> Send <span id="sendCount"></span>
      </button>
    </div>
  </div>

  <div class="progress">
    <div id="progressBar" class="progress-bar progress-bar-striped active" role="progressbar" style="width:0%"></div>
  </div>

  <div id="status" style="margin-top:10px;"></div>
</div>

<!-- jQuery (use same jQuery as your app) -->
<script src="js/jquery-1.12.4.min.js"></script>
<script>
  $(function () {

    // Keep values persistent across reloads
    const orderInput = $('#orderId');
    const shelfInput = $('#shelfLocation');
    const scanInput = $('#scanInput');
    const listDiv = $('#scannedList');
    const sendBtn = $('#btnSend');
    const clearBtn = $('#btnClearList');
    const progress = $('.progress');
    const progressBar = $('#progressBar');
    const status = $('#status');
    const sendCount = $('#sendCount');

    // Load from localStorage
    orderInput.val(localStorage.getItem('scan_order') || '');
    //shelfInput.val(localStorage.getItem('scan_shelf') || '');

    // Internal scanned items array: { barcode, qty }
    let items = JSON.parse(localStorage.getItem('scan_items') || '[]');

    function renderList() {
      listDiv.empty();
      if (items.length === 0) {
        listDiv.append('<div class="text-muted">No items scanned yet.</div>');
      } else {
        items.forEach((it, idx) => {
          const $row = $(`
          <div class="list-item" data-idx="${idx}">
            <div class="list-left">
              <span class="barcode">${$('<div>').text(it.barcode).html()}</span>
              <span class="desc">Qty: <input type="number" min="1" class="qty-input form-control" value="${it.qty}" /></span>
            </div>
            <div class="list-right">
              &nbsp;&nbsp;&nbsp;<button class="small-btn-x remove-item">×</button></span></button>
            </div>
          </div>
        `);
          // wire qty change
          $row.find('.qty-input').on('change', function () {
            const v = parseInt($(this).val()) || 1;
            items[idx].qty = v;
            saveState();
          });
          // remove handler
          $row.find('.remove-item').on('click', function () {
            items.splice(idx, 1);
            saveState();
            renderList();
          });
          listDiv.append($row);
        });
      }
      sendCount.text(items.length ? `(${items.length})` : '');
    }

    function saveState() {
      localStorage.setItem('scan_order', orderInput.val());
      //localStorage.setItem('scan_shelf', shelfInput.val());
      localStorage.setItem('scan_items', JSON.stringify(items));
    }
    window.saveState = saveState;

    // Add scanned barcode to array
    function addScanned(barcode) {
      barcode = (barcode || '').trim();
      if (!barcode) return;
      // If same barcode exists, increment qty (optional) — here we append new entry
      // Try to find existing and increment:
      const existing = items.find(it => it.barcode === barcode);
      if (existing) {
        existing.qty = (existing.qty || 1) + 1;
      } else {
        items.push({ barcode: barcode, qty: 1 });
      }
      saveState();
      renderList();
    }

    // Load initial list
    renderList();

    // Persist order/shelf on change
    orderInput.on('input', function () { saveState(); });
    //shelfInput.on('input', function(){ saveState(); });

    // When scanning in the scanInput, add on Enter (or when scanner sends newline)
    scanInput.on('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        const code = $(this).val().trim();
        if (code) {
          addScanned(code);
          $(this).val('');
          // optional: vibrate mobile
          if (navigator.vibrate) navigator.vibrate(50);
        }
      }
    });

    // Also allow user to paste multiple lines — split by newlines
    scanInput.on('paste', function (e) {
      setTimeout(() => {
        const val = $(this).val();
        if (val.indexOf('\n') !== -1) {
          const parts = val.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
          parts.forEach(p => addScanned(p));
          $(this).val('');
        }
      }, 50);
    });

    // Clear list
    clearBtn.on('click', function () {
      if (!confirm('Clear scanned items?')) return;
      items = [];
      saveState();
      renderList();
    });

    // Main send routine: POST each item one by one to scripts/scan_in.php
    function refreshMovements() {
      $.getJSON('scripts/movements.php', function (data) {
        const tbody = $('#movementsBody').empty();
        data.forEach(row => {
          const tr = $(`
                <tr class="${row.movement_type === 'IN' ? 'success' : 'danger'}">
                    <td>${new Date(row.timestamp).toLocaleString()}</td>
                    <td>${row.operator}</td>
                    <td>${row.order_id}</td>
                    <td>${row.item_name}</td>
                    <td>${row.shelf_name}</td>
                    <td class="text-center">${row.quantity}</td>
                    <td class="text-center">
                        <span class="label label-${row.movement_type === 'IN' ? 'success' : 'danger'}">
                            ${row.movement_type}
                        </span>
                    </td>
                </tr>
            `);
          tbody.append(tr);
        });
      });
    }

    function unlockForm() {
      sendBtn.prop('disabled', false);
      clearBtn.prop('disabled', false);
      scanInput.prop('disabled', false);
      progress.hide();
      progressBar.css('width', '0%').text('');
    }

    sendBtn.on('click', function (e) {
      e.preventDefault();
      status.empty();
      const order_id = orderInput.val().trim();
      const shelf = shelfInput.val().trim();
      const scan_type = $('#scanType').val();

      if (!order_id) {
        status.html('<div class="alert alert-warning">Missing order number.</div>');
        orderInput.focus();
        return;
      }
      if (!shelf) {
        status.html('<div class="alert alert-warning">Missing shelf location.</div>');
        shelfInput.focus();
        return;
      }
      if (!items.length) {
        status.html('<div class="alert alert-warning">No scanned items to send.</div>');
        scanInput.focus();
        return;
      }

      progress.show();
      sendBtn.prop('disabled', true);
      clearBtn.prop('disabled', true);
      scanInput.prop('disabled', true);


      (function postNext(i) {
        if (i >= items.length) {
          progressBar.css('width', '100%').text('100%');
          status.html(`<div class="alert alert-success">Done. Success: ${items.length}</div>`);

          items = [];
          saveState();
          renderList();
          // CLEAR shelf AFTER sending
          shelfInput.val('');

          // remove any accidentally stored shelf value
          localStorage.removeItem('scan_shelf');

          // keep order, clear items only
          localStorage.setItem('scan_items', JSON.stringify([]));
          refreshMovements(); // refresh last 10 movements dynamically

          sendBtn.prop('disabled', false);
          clearBtn.prop('disabled', false);
          scanInput.prop('disabled', false);
          progress.hide();
          progressBar.css('width', '0%').text('');
          return;
        }

        const it = items[i];

        $.ajax({
          url: 'scripts/scan_in.php',
          method: 'POST',
          dataType: 'json',
          data: {
            barcode: it.barcode,
            order_id: order_id,
            shelf_location: shelf,
            quantity: it.qty || 1,
            scan_type: scan_type
          }
        }).done(function (resp) {
          if (!resp || resp.status !== 'ok') {
            status.html('<div class="alert alert-danger">' + (resp.message || 'Unknown error') + '</div>');
            unlockForm();
            return;
          }

          const pct = Math.round(((i + 1) / items.length) * 100);
          progressBar.css('width', pct + '%').text(pct + '%');
          postNext(i + 1);

        }).fail(function (xhr) {
          if (xhr.status === 401) {
            alert('Session expired. Please log in again.');
            window.top.location.href = 'login.php';
            return;
          }

          let msg = 'Server error.';
          if (xhr.responseJSON && xhr.responseJSON.message) {
            msg = xhr.responseJSON.message;
          }

          status.html('<div class="alert alert-danger">' + msg + '</div>');
          unlockForm();
        });
      })(0);
    });


    // convenience: tap list item to copy barcode back to scanInput for correction
    listDiv.on('click', '.list-item', function () {
      const idx = $(this).data('idx');
      if (typeof idx === 'undefined') return;
      const code = items[idx].barcode;
      scanInput.val(code).focus();
    });

  });
</script>
<script>
  // Change border color of scanType based on selection
  $('#scanType').on('change', function () {
    const val = $(this).val();
    if (val === 'from_receiving') {
      $(this).addClass('from-receiving');
    } else {
      $(this).removeClass('from-receiving');
    }
  }).trigger('change');
  function checkSession() {
    $.ajax({
        url: 'scripts/session_check.php',
        method: 'GET',
        dataType: 'json',
        cache: false
    }).done(function(resp) {
        if (!resp || resp.status !== 'ok') {
            $('#btnSend').prop('disabled', true);
            $('#scanInput').prop('disabled', true);
            alert('Session expired. Please log in again.');
            window.top.location.href = 'login.php';
        }
    }).fail(function(xhr) {
        $('#btnSend').prop('disabled', true);
        $('#scanInput').prop('disabled', true);
        alert('Session expired. Please log in again.');
        window.top.location.href = 'login.php';
    });
}

checkSession();
setInterval(checkSession, 30000); // check session every 30 seconds
</script>
<?php
// LAST 10 INVENTORY MOVEMENTS
$operator = $_SESSION['name'] ?? 'emergency input';

$sql = "SELECT im.*, it.name, it.description, it.color
    FROM inventory_movements im
    LEFT JOIN items it ON im.item_id = it.id
    WHERE im.operator IN (:operator, 'emergency input')
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