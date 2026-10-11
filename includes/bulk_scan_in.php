<style>
  .mobile-scan-page {
    --scan-accent: #2dd4bf;
    --scan-accent-dark: #0f766e;
    --scan-card: #202b38;
    --scan-card-soft: #273545;
    --scan-border: rgba(148, 163, 184, .25);
    --scan-muted: #9fb0c3;
    font-family: "Segoe UI", Arial, sans-serif;
    margin: 0 auto;
    max-width: 720px;
    padding: 10px 10px 24px;
  }

  .mobile-scan-page .scan-hero {
    background: linear-gradient(135deg, rgba(15, 118, 110, .42), rgba(30, 64, 175, .28));
    border: 1px solid rgba(45, 212, 191, .22);
    border-radius: 16px;
    margin-bottom: 12px;
    overflow: hidden;
    padding: 16px;
    position: relative;
  }

  .mobile-scan-page .scan-hero::after {
    background: rgba(45, 212, 191, .08);
    border-radius: 999px;
    content: "";
    height: 120px;
    position: absolute;
    right: -42px;
    top: -52px;
    width: 120px;
  }

  .mobile-scan-page .scan-hero h2 {
    font-size: 22px;
    font-weight: 800;
    margin: 0 0 4px;
  }

  .mobile-scan-page .scan-hero p {
    color: #b9c8d8;
    font-size: 13px;
    margin: 0;
  }

  .mobile-scan-page .scan-mode {
    align-items: center;
    background: rgba(4, 18, 28, .35);
    border: 1px solid rgba(45, 212, 191, .2);
    border-radius: 999px;
    color: #b8fff3;
    display: inline-flex;
    font-size: 12px;
    font-weight: 800;
    gap: 7px;
    letter-spacing: .04em;
    margin-top: 12px;
    padding: 7px 11px;
    text-transform: uppercase;
  }

  .mobile-scan-page .scan-mode-dot {
    background: #34d399;
    border-radius: 50%;
    box-shadow: 0 0 0 4px rgba(52, 211, 153, .13);
    height: 8px;
    width: 8px;
  }

  .mobile-scan-page .scan-card {
    background: var(--scan-card);
    border: 1px solid var(--scan-border);
    border-radius: 16px;
    box-shadow: 0 10px 26px rgba(2, 8, 23, .18);
    margin-bottom: 12px;
    padding: 14px;
  }

  .mobile-scan-page .scan-step {
    border-bottom: 1px solid rgba(148, 163, 184, .14);
    margin-bottom: 14px;
    padding-bottom: 14px;
  }

  .mobile-scan-page .scan-step:last-of-type {
    border-bottom: 0;
    margin-bottom: 0;
    padding-bottom: 0;
  }

  .mobile-scan-page .scan-step-label {
    align-items: center;
    display: flex;
    font-size: 16px;
    font-weight: 800;
    gap: 9px;
    margin-bottom: 8px;
  }

  .mobile-scan-page .scan-step-number {
    align-items: center;
    background: rgba(45, 212, 191, .14);
    border: 1px solid rgba(45, 212, 191, .28);
    border-radius: 50%;
    color: #75f4df;
    display: inline-flex;
    flex: 0 0 30px;
    font-size: 14px;
    height: 30px;
    justify-content: center;
  }

  .mobile-scan-page .input-wrap {
    position: relative;
  }

  .mobile-scan-page .form-control {
    background: #182330;
    border: 2px solid rgba(148, 163, 184, .34);
    border-radius: 11px;
    color: #f4f8fb;
    font-size: 18px;
    height: 54px;
    padding: 10px 48px 10px 13px;
  }

  .mobile-scan-page .form-control:focus {
    border-color: var(--scan-accent);
    box-shadow: 0 0 0 4px rgba(45, 212, 191, .13);
  }

  .mobile-scan-page .form-control::placeholder {
    color: #7f91a5;
  }

  .mobile-scan-page .scan-input {
    border-color: rgba(45, 212, 191, .55);
    font-size: 22px;
    font-weight: 700;
    height: 68px;
    padding-right: 14px;
  }

  .mobile-scan-page .clear-x {
    align-items: center;
    color: #8fa1b4;
    cursor: pointer;
    display: flex;
    font-size: 25px;
    height: 48px;
    justify-content: center;
    position: absolute;
    right: 2px;
    top: 50%;
    transform: translateY(-50%);
    width: 48px;
  }

  .mobile-scan-page .scan-hint {
    align-items: flex-start;
    color: var(--scan-muted);
    display: flex;
    font-size: 13px;
    gap: 7px;
    line-height: 1.4;
    margin-top: 8px;
  }

  .mobile-scan-page .scan-feedback {
    align-items: center;
    background: rgba(45, 212, 191, .09);
    border: 1px solid rgba(45, 212, 191, .18);
    border-radius: 10px;
    color: #b8fff3;
    display: flex;
    font-size: 13px;
    font-weight: 700;
    gap: 8px;
    margin-top: 10px;
    padding: 9px 11px;
  }

  .mobile-scan-page .scan-feedback.is-success {
    background: rgba(34, 197, 94, .12);
    border-color: rgba(34, 197, 94, .3);
    color: #baf7cb;
  }

  .mobile-scan-page .scan-summary {
    align-items: center;
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
  }

  .mobile-scan-page .scan-summary-title {
    font-size: 16px;
    font-weight: 800;
  }

  .mobile-scan-page .scan-count {
    background: #0f766e;
    border-radius: 999px;
    color: white;
    font-size: 13px;
    font-weight: 800;
    padding: 6px 10px;
  }

  .mobile-scan-page #scannedListBox {
    max-height: 340px;
    overflow-y: auto;
  }

  .mobile-scan-page .scan-empty {
    color: var(--scan-muted);
    padding: 18px 8px;
    text-align: center;
  }

  .mobile-scan-page .scan-empty i {
    display: block;
    font-size: 28px;
    margin-bottom: 8px;
    opacity: .7;
  }

  .mobile-scan-page .list-item {
    align-items: center;
    background: var(--scan-card-soft);
    border: 1px solid rgba(148, 163, 184, .18);
    border-radius: 11px;
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    min-height: 62px;
    padding: 9px 9px 9px 12px;
  }

  .mobile-scan-page .list-left {
    min-width: 0;
  }

  .mobile-scan-page .barcode {
    display: block;
    font-size: 17px;
    font-weight: 800;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .mobile-scan-page .desc {
    align-items: center;
    color: var(--scan-muted);
    display: flex;
    font-size: 12px;
    gap: 6px;
    margin-top: 4px;
  }

  .mobile-scan-page .qty-input {
    display: inline-block;
    font-size: 16px;
    height: 38px;
    padding: 5px 7px;
    text-align: center;
    width: 68px;
  }

  .mobile-scan-page .small-btn-x {
    align-items: center;
    background: rgba(239, 68, 68, .12);
    border: 1px solid rgba(239, 68, 68, .25);
    border-radius: 10px;
    color: #ff8f8f;
    display: flex;
    font-size: 25px;
    height: 44px;
    justify-content: center;
    width: 44px;
  }

  .mobile-scan-page .scan-actions {
    background: rgba(25, 35, 47, .95);
    border: 1px solid var(--scan-border);
    border-radius: 14px;
    bottom: 8px;
    box-shadow: 0 10px 30px rgba(2, 8, 23, .35);
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-top: 12px;
    padding: 10px;
    position: sticky;
    z-index: 20;
  }

  .mobile-scan-page .btn-wide {
    align-items: center;
    border: 0;
    border-radius: 11px;
    display: flex !important;
    font-size: 17px;
    font-weight: 800;
    gap: 8px;
    height: 56px;
    justify-content: center;
    line-height: 1;
    margin: 0;
    padding: 0 12px;
  }

  .mobile-scan-page .btn-wide .fa {
    line-height: 1;
    margin: 0;
    text-align: center;
    width: 18px;
  }

  .mobile-scan-page .btn-clear {
    background: #3b4654 !important;
    color: #ffd0d0 !important;
  }

  .mobile-scan-page .btn-send {
    background: linear-gradient(135deg, #0f9f83, #0f766e) !important;
    color: white !important;
  }

  .mobile-scan-page .progress {
    background: #182330;
    border-radius: 999px;
    display: none;
    height: 22px;
    margin-top: 12px;
    overflow: hidden;
  }

  .mobile-scan-page #status {
    font-size: 15px;
    margin-top: 10px;
  }

  .mobile-scan-page .movements-card {
    background: var(--scan-card);
    border: 1px solid var(--scan-border);
    border-radius: 16px;
    margin-top: 14px;
    overflow: hidden;
  }

  .mobile-scan-page .movements-card h4 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    padding: 14px;
  }

  .mobile-scan-page .movements-scroll {
    overflow: visible;
  }

  .mobile-scan-page .movements-scroll table {
    font-size: 12px;
    margin: 0;
    min-width: 0;
    width: 100%;
  }

  @media (max-width: 380px) {
    .mobile-scan-page {
      padding-left: 7px;
      padding-right: 7px;
    }

    .mobile-scan-page .scan-card {
      padding: 12px;
    }

    .mobile-scan-page .scan-actions {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

    .mobile-scan-page .movements-scroll {
      overflow: visible;
    }

    .mobile-scan-page #example0_wrapper,
    .mobile-scan-page #example0 {
      min-width: 0 !important;
      width: 100% !important;
    }

    .mobile-scan-page #example0_wrapper > .row {
      margin-left: 0;
      margin-right: 0;
    }

    .mobile-scan-page #example0_wrapper > .row > [class*="col-"] {
      padding-left: 0;
      padding-right: 0;
    }

    .mobile-scan-page #example0 {
      border: 0;
      display: block;
    }

    .mobile-scan-page #example0 thead {
      display: none;
    }

    .mobile-scan-page #example0 tbody {
      display: block;
    }

    .mobile-scan-page #example0 tbody tr {
      background: #263443 !important;
      border: 1px solid rgba(148, 163, 184, .18);
      border-left: 4px solid #64748b;
      border-radius: 11px;
      display: grid;
      gap: 0;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      margin: 0 10px 10px;
      overflow: hidden;
      padding: 4px;
      width: calc(100% - 20px);
    }

    .mobile-scan-page #example0 tbody tr.success {
      border-left-color: #22c55e;
    }

    .mobile-scan-page #example0 tbody tr.danger {
      border-left-color: #ef4444;
    }

    .mobile-scan-page #example0 tbody td {
      background: transparent !important;
      border: 0 !important;
      color: #edf4fb;
      display: block;
      font-size: 13px;
      min-width: 0;
      overflow-wrap: anywhere;
      padding: 7px 9px !important;
      text-align: left !important;
      width: auto !important;
    }

    .mobile-scan-page #example0 tbody td::before {
      color: #8fa1b4;
      content: attr(data-label);
      display: block;
      font-size: 9px;
      font-weight: 800;
      letter-spacing: .06em;
      margin-bottom: 2px;
      text-transform: uppercase;
    }

    .mobile-scan-page #example0 tbody td:nth-child(4) {
      grid-column: 1 / -1;
    }

    .mobile-scan-page #example0 tbody td.dataTables_empty {
      grid-column: 1 / -1;
      padding: 18px 10px !important;
      text-align: center !important;
    }

    .mobile-scan-page #example0 tbody td.dataTables_empty::before {
      display: none;
    }

    .mobile-scan-page #example0_wrapper .dataTables_info {
      color: var(--scan-muted);
      font-size: 11px;
      padding: 2px 14px 12px;
    }
</style>
<link rel="stylesheet" href="assets/css/mobile-stock-scanner.css">



<div class="container-fluid mobile-scan-page standard-scan">

  <div class="scan-hero">
    <h2><i class="fa fa-barcode"></i> Stock Scanner</h2>
    <p>Scan the order, shelf and box labels in sequence.</p>
    <div class="scan-mode">
      <span class="scan-mode-dot"></span>
      Standard stock operation
    </div>
  </div>

  <?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']);
    unset($_SESSION['success']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']);
    unset($_SESSION['error']); ?></div>
  <?php endif; ?>
  <input type="hidden" id="scanType" value="standard">

  <div class="scan-card">
    <div class="scan-step">
      <label class="scan-step-label" for="orderId">
        <span class="scan-step-number">1</span>
        Order number
      </label>
      <div class="input-wrap">
          <input id="orderId" class="form-control" placeholder="Enter order number (or scan order QR)"
            autocomplete="off" />
        <span class="clear-x" role="button" aria-label="Clear order number" onclick="$('#orderId').val('').focus(); saveState();">×</span>
      </div>
    </div>

    <div class="scan-step">
      <label class="scan-step-label" for="shelfLocation">
        <span class="scan-step-number">2</span>
        Bay / Shelf
      </label>
      <div class="input-wrap">
        <input id="shelfLocation" class="form-control" placeholder="Scan shelf QR (e.g. A010)" autocomplete="off" />
        <span class="clear-x" role="button" aria-label="Clear shelf" onclick="$('#shelfLocation').val('').focus(); saveState();">×</span>
      </div>
    </div>

    <div class="scan-step">
      <label class="scan-step-label" for="scanInput">
        <span class="scan-step-number">3</span>
        Scan box barcode
      </label>
      <input id="scanInput" class="form-control scan-input" placeholder="Ready to scan…"
        autocomplete="off" inputmode="none" autofocus />
      <div class="scan-feedback" id="scanFeedback" aria-live="polite">
        <i class="fa fa-wifi"></i>
        Scanner ready — each Enter adds the barcode.
      </div>
      <div class="scan-hint">
        <i class="fa fa-info-circle"></i>
        <span>Keep this field active while scanning. Duplicate barcodes automatically increase quantity.</span>
      </div>
    </div>
  </div>

  <div class="scan-card">
    <div class="scan-summary">
      <div class="scan-summary-title"><i class="fa fa-list"></i> Scanned items</div>
      <div class="scan-count"><span id="scanLineCount">0</span> lines · <span id="scanUnitCount">0</span> pcs</div>
    </div>
    <div id="scannedListBox">
      <div id="scannedList"></div>
    </div>

    <div class="progress">
      <div id="progressBar" class="progress-bar progress-bar-striped active" role="progressbar" style="width:0%"></div>
    </div>

    <div id="status"></div>

    <div class="scan-actions">
      <button id="btnClearList" class="btn btn-clear btn-wide btn-block">
        <i class="fa fa-trash"></i> Clear
      </button>
      <button id="btnSend" class="btn btn-send btn-wide btn-block">
        <i class="fa fa-paper-plane"></i> Send <span id="sendCount"></span>
      </button>
    </div>
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
    const lineCount = $('#scanLineCount');
    const unitCount = $('#scanUnitCount');
    const scanFeedback = $('#scanFeedback');
    let feedbackTimer = null;

    // Load from localStorage
    orderInput.val(localStorage.getItem('scan_order') || '');
    //shelfInput.val(localStorage.getItem('scan_shelf') || '');

    // Internal scanned items array: { barcode, qty }
    let items = JSON.parse(localStorage.getItem('scan_items') || '[]');

    function renderList() {
      listDiv.empty();
      if (items.length === 0) {
        listDiv.append('<div class="scan-empty"><i class="fa fa-barcode"></i>No items scanned yet.</div>');
      } else {
        items.forEach((it, idx) => {
          const $row = $(`
          <div class="list-item" data-idx="${idx}">
            <div class="list-left">
              <span class="barcode">${$('<div>').text(it.barcode).html()}</span>
              <span class="desc">Qty: <input type="number" min="1" class="qty-input form-control" value="${it.qty}" /></span>
            </div>
            <div class="list-right">
              <button type="button" class="small-btn-x remove-item" aria-label="Remove ${$('<div>').text(it.barcode).html()}">×</button>
            </div>
          </div>
        `);
          // wire qty change
          $row.find('.qty-input').on('change', function () {
            const v = parseInt($(this).val()) || 1;
            items[idx].qty = v;
            saveState();
            updateSummary();
          }).on('click', function (e) {
            e.stopPropagation();
          });
          // remove handler
          $row.find('.remove-item').on('click', function (e) {
            e.stopPropagation();
            items.splice(idx, 1);
            saveState();
            renderList();
          });
          listDiv.append($row);
        });
      }
      updateSummary();
    }

    function updateSummary() {
      const units = items.reduce((sum, item) => sum + (parseInt(item.qty) || 1), 0);
      lineCount.text(items.length);
      unitCount.text(units);
      sendCount.text(units ? `(${units})` : '');
      sendBtn.prop('disabled', items.length === 0);
      clearBtn.prop('disabled', items.length === 0);
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
      scanFeedback
        .addClass('is-success')
        .html('<i class="fa fa-check-circle"></i> Added <strong>' + $('<div>').text(barcode).html() + '</strong>');
      clearTimeout(feedbackTimer);
      feedbackTimer = setTimeout(function () {
        scanFeedback
          .removeClass('is-success')
          .html('<i class="fa fa-wifi"></i> Scanner ready — each Enter adds the barcode.');
      }, 1400);
    }

    // Load initial list
    renderList();

    // Persist order/shelf on change
    orderInput.on('input', function () { saveState(); });
    //shelfInput.on('input', function(){ saveState(); });

    orderInput.on('keydown', function (e) {
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        shelfInput.focus();
      }
    });

    shelfInput.on('keydown', function (e) {
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        scanInput.focus();
      }
    });

    // When scanning in the scanInput, add on Enter (or when scanner sends newline)
    function commitScanInput() {
      const code = scanInput.val().trim();
      if (!code) return false;

      addScanned(code);
      scanInput.val('');
      scanInput.focus();

      if (navigator.vibrate) navigator.vibrate(50);
      return true;
    }

    scanInput.on('keydown', function (e) {
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        commitScanInput();
      }
    });

    scanInput.on('change blur', function () {
      commitScanInput();
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
    function formatMovementDate(value) {
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return value || '';
      const pad = number => String(number).padStart(2, '0');
      return `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${String(date.getFullYear()).slice(-2)} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    function refreshMovements() {
      $.getJSON('scripts/movements.php', function (data) {
        const tbody = $('#movementsBody').empty();
        data.forEach(row => {
          const tr = $(`
                <tr class="${row.movement_type === 'IN' ? 'success' : 'danger'}">
                    <td data-label="Date">${formatMovementDate(row.timestamp)}</td>
                    <td data-label="Operator">${row.operator}</td>
                    <td data-label="Order">${row.order_id}</td>
                    <td data-label="Item">${row.item_name}</td>
                    <td data-label="Shelf">${row.shelf_name}</td>
                    <td data-label="Qty" class="text-center">${row.quantity}</td>
                    <td data-label="Type" class="text-center">
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
      scanInput.prop('disabled', false);
      progress.hide();
      progressBar.css('width', '0%').text('');
      updateSummary();
    }

    sendBtn.on('click', function (e) {
      e.preventDefault();
      status.empty();
      commitScanInput();
      const order_id = orderInput.val().trim();
      const shelf = shelfInput.val().trim();
      const scan_type = $('#scanType').val();
      const batchLineCount = items.length;
      const batchUnitCount = items.reduce((sum, item) => sum + (parseInt(item.qty) || 1), 0);

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
          status.html(`<div class="alert alert-success"><strong>Done.</strong> Sent ${batchUnitCount} pcs in ${batchLineCount} lines.</div>`);

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

          unlockForm();
          scanInput.focus();
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
            const returnUrl = window.top.location.pathname + window.top.location.search;
            window.top.location.href = 'login.php?return=' + encodeURIComponent(returnUrl);
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
    }).done(function (resp) {
      if (!resp || resp.status !== 'ok') {
        $('#btnSend').prop('disabled', true);
        $('#scanInput').prop('disabled', true);
        alert('Session expired. Please log in again.');
        const returnUrl = window.top.location.pathname + window.top.location.search;
      window.top.location.href = 'login.php?return=' + encodeURIComponent(returnUrl);
      }
    }).fail(function (xhr) {
      $('#btnSend').prop('disabled', true);
      $('#scanInput').prop('disabled', true);
      alert('Session expired. Please log in again.');
      const returnUrl = window.top.location.pathname + window.top.location.search;
    window.top.location.href = 'login.php?return=' + encodeURIComponent(returnUrl);
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
<div class="movements-card">
<h4><i class="fa fa-history"></i> Last 10 Inventory Movements</h4>
<div class="movements-scroll">
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
        <td data-label="Date"><?= date("d.m.y H:i", strtotime($row['timestamp'])) ?></td>
        <td data-label="Operator"><?= htmlspecialchars($row['operator']) ?></td>
        <td data-label="Order"><?= htmlspecialchars($row['order_id']) ?></td>
        <td data-label="Item"><?= htmlspecialchars($row['item_name']) ?></td>
        <td data-label="Shelf"><?= htmlspecialchars($row['shelf_name']) ?></td>
        <td data-label="Qty" class="text-center"><?= $row['quantity'] ?></td>
        <td data-label="Type" class="text-center">
          <span class="label label-<?= $row['movement_type'] === 'IN' ? 'success' : 'danger' ?>">
            <?= $row['movement_type'] ?>
          </span>
        </td>
      </tr>
    <?php endwhile; ?>
  </tbody>
</table>
</div>
</div>
</div>
