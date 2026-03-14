<style>
  body { 
    padding:10px; 
    background:#eef1f5; 
    font-size:16px; 
    font-family: "Segoe UI", Arial;
  }

  .section-box { 
    background:white; 
    padding:12px; 
    border-radius:8px; 
    margin-bottom:12px; 
    box-shadow: 0 1px 4px rgba(0,0,0,0.1);
  }

  label { font-weight:600; }

  .scan-input { 
    font-size:22px; 
    padding:12px; 
    height:50px; 
  }

  .btn-wide {
    padding:12px 14px;
    font-size:18px;
    height:50px;
  }

  .btn-clear {
    background:#d9534f !important;
    color:white !important;
    border:none;
  }

  .btn-send {
    background:#5cb85c !important;
    color:white !important;
    border:none;
  }

  #scannedListBox {
    background:white; 
    padding:10px; 
    border-radius:8px;
    max-height: 300px;
    overflow-y: auto;
    box-shadow: 0 1px 4px rgba(0,0,0,0.1);
  }

  .list-item {
    padding:10px 12px; 
    margin-bottom:8px; 
    background:#fafafa; 
    border-radius:6px; 
    border:1px solid #ddd; 
    display:flex; 
    align-items:center; 
    justify-content:space-between;
  }

  .barcode { 
    font-weight:600; 
    font-size:16px;
  }

  .desc { 
    color:#777; 
    font-size:13px; 
    margin-top:3px;
  }

  .qty-input {
    width:65px;
    margin-left:4px;
    display:inline-block;
  }

  .small-btn { 
    padding:6px 8px; 
    font-size:14px;
  }

  .progress {
    height:20px;
    border-radius:6px;
    display:none;
    margin-top:12px;
  }

  #status { 
    margin-top:12px; 
    font-size:16px; 
  }
</style>



<div class="container-fluid">

  <?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
  <?php endif; ?>

  <div class="row top-row">
    <div class="col-xs-12">
      <div class="form-group">
        <label for="orderId">Order #</label>
        <input id="orderId" class="form-control" placeholder="Enter order number (or scan order QR)" autocomplete="off" />
      </div>
    </div>

    <div class="col-xs-12">
      <div class="form-group">
        <label for="shelfLocation">Bay / Shelf (scan QR)</label>
        <input id="shelfLocation" class="form-control" placeholder="Scan shelf QR (e.g. A010)" autocomplete="off" />
      </div>
    </div>

    <div class="col-xs-12">
      <div class="form-group">
        <label for="scanInput">Scan box barcode</label>
        <input id="scanInput" class="form-control scan-input" placeholder="Scan barcode here — list adds on Enter" autocomplete="off" autofocus />
        <div class="hint">Tip: your scanner usually sends an Enter after barcode — that will add the item to the list.</div>
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
$(function() {

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
  shelfInput.val(localStorage.getItem('scan_shelf') || '');

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
              &nbsp;&nbsp;&nbsp;<button class="btn btn-xs btn-danger small-btn remove-item"><span class="glyphicon glyphicon-trash"></span></button>
            </div>
          </div>
        `);
        // wire qty change
        $row.find('.qty-input').on('change', function() {
          const v = parseInt($(this).val()) || 1;
          items[idx].qty = v;
          saveState();
        });
        // remove handler
        $row.find('.remove-item').on('click', function() {
          items.splice(idx,1);
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
    localStorage.setItem('scan_shelf', shelfInput.val());
    localStorage.setItem('scan_items', JSON.stringify(items));
  }

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
  orderInput.on('input', function(){ saveState(); });
  shelfInput.on('input', function(){ saveState(); });

  // When scanning in the scanInput, add on Enter (or when scanner sends newline)
  scanInput.on('keydown', function(e) {
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
  scanInput.on('paste', function(e) {
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
  clearBtn.on('click', function() {
    if (!confirm('Clear scanned items?')) return;
    items = [];
    saveState();
    renderList();
  });

  // Main send routine: POST each item one by one to scripts/scan_in.php
  sendBtn.on('click', function() {
    const order_id = orderInput.val().trim();
    const shelf = shelfInput.val().trim();

    if (!order_id) { alert('Please enter Order #'); orderInput.focus(); return; }
    if (!shelf) { alert('Please scan shelf location'); shelfInput.focus(); return; }
    if (!items.length) { alert('No items scanned'); scanInput.focus(); return; }

    if (!confirm(`Send ${items.length} scanned item(s) to server?`)) return;

    // UI lock
    sendBtn.prop('disabled', true);
    clearBtn.prop('disabled', true);
    scanInput.prop('disabled', true);
    orderInput.prop('disabled', true);
    shelfInput.prop('disabled', true);
    progress.show();
    status.html('');
    let succeeded = 0;
    let failed = 0;

    // sequential posting (to avoid hammering server)
    (function postNext(i) {
      if (i >= items.length) {
        // done
        progressBar.css('width','100%').text('100%');
        status.html(`<div class="alert alert-success">Done. Success: ${succeeded}, Failed: ${failed}</div>`);
        // Remove succeeded items from list (we will clear all on success)
        if (failed === 0) {
          items = [];
          saveState();
          renderList();
        }
        // unlock
        sendBtn.prop('disabled', false);
        clearBtn.prop('disabled', false);
        scanInput.prop('disabled', false);
        orderInput.prop('disabled', false);
        shelfInput.prop('disabled', false);
        setTimeout(()=>{ progress.hide(); progressBar.css('width','0%').text(''); }, 1200);
        return;
      }

      const it = items[i];
      // POST payload — matches scan_in.php expected fields
      $.post('scripts/scan_in.php', {
        barcode: it.barcode,
        order_id: order_id,
        shelf_location: shelf,
        quantity: it.qty || 1
      }).done(function(resp) {
        // server may redirect or return HTML — assume success if no error text; you can customize to JSON
        succeeded++;
      }).fail(function(xhr) {
        failed++;
        console.error('scan_in fail', xhr.responseText);
      }).always(function() {
        const pct = Math.round(((i+1) / items.length) * 100);
        progressBar.css('width', pct + '%').text(pct + '%');
        postNext(i+1);
      });
    })(0);
  });

  // convenience: tap list item to copy barcode back to scanInput for correction
  listDiv.on('click', '.list-item', function() {
    const idx = $(this).data('idx');
    if (typeof idx === 'undefined') return;
    const code = items[idx].barcode;
    scanInput.val(code).focus();
  });

});
</script>
