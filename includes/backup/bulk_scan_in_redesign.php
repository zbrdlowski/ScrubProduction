<!-- Redesigned mobile-friendly warehouse scan form -->
<style>
  body { padding:10px; background:none; font-size:16px; font-family:"Segoe UI", Arial; }
  table.form-table { width:100%; background:none; border-radius:8px; box-shadow:none; margin-bottom:12px; }
  table.form-table td { padding:12px; vertical-align:middle; }
  label { font-weight:600; }
  .scan-input { font-size:22px; padding:12px; height:50px; }
  .btn-wide { padding:12px 14px; font-size:18px; height:50px; }
  .btn-clear { background:#d9534f !important; color:white !important; border:none; }
  .btn-send { background:#5cb85c !important; color:white !important; border:none; }
  #scannedListBox { background:none; padding:0; max-height:300px; overflow-y:auto; }
  .list-item { padding:8px 6px; margin-bottom:4px; background:none; border-bottom:1px solid #ccc; display:flex; align-items:center; justify-content:space-between; }
  .barcode { font-weight:600; font-size:16px; }
  .desc { color:#555; font-size:13px; margin-top:3px; }
  .qty-input { width:65px; margin-left:4px; display:inline-block; }
  .small-btn-x { background:none; border:none; color:#d9534f; font-size:22px; line-height:20px; font-weight:bold; }
  .clear-x { position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:22px; color:#999; cursor:pointer; }
  .input-wrap { position:relative; }
  .progress { height:20px; border-radius:6px; display:none; margin-top:12px; }
  #status { margin-top:12px; font-size:16px; }
</style>

<div class="container-fluid">

<table class="form-table">
  <tr>
    <td>
      <label for="orderId">Order #</label>
      <div class="input-wrap">
        <input id="orderId" class="form-control" placeholder="Enter order or scan QR" autocomplete="off" />
        <span class="clear-x" onclick="$('#orderId').val(''); saveState();">×</span>
      </div>
    </td>
  </tr>
  <tr>
    <td>
      <label for="shelfLocation">Bay / Shelf</label>
      <div class="input-wrap">
        <input id="shelfLocation" class="form-control" placeholder="Scan shelf QR" autocomplete="off" />
        <span class="clear-x" onclick="$('#shelfLocation').val(''); $('#scanInput').val(''); saveState();">×</span>
      </div>
    </td>
  </tr>
  <tr>
    <td>
      <label for="scanInput">Scan box barcode</label>
      <div class="input-wrap">
        <input id="scanInput" class="form-control scan-input" placeholder="Scan barcode here" autocomplete="off" autofocus />
        <span class="clear-x" onclick="$('#scanInput').val('');">×</span>
      </div>
    </td>
  </tr>
</table>

<div id="scannedListBox">
  <div id="scannedList"></div>
</div>

<div class="row" style="margin-top:12px;">
  <div class="col-xs-6">
    <button id="btnClearList" class="btn btn-clear btn-wide btn-block">Clear</button>
  </div>
  <div class="col-xs-6">
    <button id="btnSend" class="btn btn-send btn-wide btn-block">Send <span id="sendCount"></span></button>
  </div>
</div>

<div class="progress"><div id="progressBar" class="progress-bar progress-bar-striped active" role="progressbar" style="width:0%"></div></div>
<div id="status"></div>

<script src="js/jquery-1.12.4.min.js"></script>
<script>
$(function() {
  const orderInput = $('#orderId');
  const shelfInput = $('#shelfLocation');
  const scanInput = $('#scanInput');
  const listDiv = $('#scannedList');
  const sendCount = $('#sendCount');

  let items = JSON.parse(localStorage.getItem('scan_items') || '[]');
  orderInput.val(localStorage.getItem('scan_order') || '');
  shelfInput.val(localStorage.getItem('scan_shelf') || '');

  function saveState() {
    localStorage.setItem('scan_order', orderInput.val());
    localStorage.setItem('scan_shelf', shelfInput.val());
    localStorage.setItem('scan_items', JSON.stringify(items));
  }

  function renderList() {
    listDiv.empty();
    if (!items.length) return listDiv.append('<div class="text-muted">No items scanned.</div>');

    items.forEach((it, idx) => {
      const row = $(`
        <div class="list-item" data-idx="${idx}">
          <div>
            <div class="barcode">${it.barcode}</div>
            <div class="desc">Qty: <input type="number" min="1" class="qty-input form-control" value="${it.qty}"></div>
          </div>
          <button class="small-btn-x remove-item">×</button>
        </div>`);

      row.find('.qty-input').on('change', function(){ items[idx].qty = parseInt(this.value)||1; saveState(); });
      row.find('.remove-item').on('click', function(){ items.splice(idx,1); saveState(); renderList(); });
      listDiv.append(row);
    });

    sendCount.text(`(${items.length})`);
  }

  renderList();

  orderInput.on('input', saveState);
  shelfInput.on('input', function(){ $('#scanInput').val(''); saveState(); });

  scanInput.on('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      let code = scanInput.val().trim();
      if (!code) return;

      const existing = items.find(x => x.barcode === code);
      if (existing) existing.qty++; else items.push({ barcode: code, qty:1 });

      scanInput.val('');
      if (navigator.vibrate) navigator.vibrate(50);
      saveState();
      renderList();
    }
  });

  // FIXED BUTTON HANDLERS HERE
  $('#btnClearList').on('click', function(){
    items = [];
    saveState();
    renderList();
    $('#status').text('List cleared');
  });

  $('#btnSend').on('click', function(){
    if (!items.length) {
      $('#status').text('Nothing to send.');
      return;
    }

    $('#status').text('Sending...');

    $.ajax({
      url:'scripts/scan_in.php',
      method:'POST',
      data:{
        order: orderInput.val(),
        shelf: shelfInput.val(),
        data: JSON.stringify(items)
      }
    }).done(function(resp){
      $('#status').text('Sent successfully');
      items = [];
      saveState();
      renderList();
    }).fail(function(xhr){
      $('#status').text('Send failed: ' + xhr.status + ' ' + xhr.statusText);
    })(function(){
      $('#status').text('Send failed');
    });
  });

});
</script>
