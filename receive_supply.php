<!-- ================= DEPENDENCIES ================= -->
<script src="js/jquery-3.6.0.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<link rel="stylesheet" href="js/jquery.dataTables.min.css">
<script src="js/jquery.dataTables.min.js"></script>
<script>
  function loadOrdersForSupplier() {
  const supplier = $('#supplierSelect').val();
  const sel = $('#orderSelect');

  sel.empty().append('<option value="">-- Choose Order --</option>');

  if (!supplier) return;

  $.getJSON('scripts/get_supplier_orders.php', { supplier }, function (data) {
    data.forEach(o => {
      sel.append(`<option value="${o.order_number}">${o.order_number}</option>`);
    });
  });
}
</script>
<div class="container-fluid">
  <div class="row">
    <div class="col-md-12">

      <div class="card">
        <div class="card-header">
          <h2>Order Receiving Form (Príjemka tovaru)</h2>
        </div>

        <div class="card-body">

<!-- ================= CONTROLS ================= -->

<div class="form-group">

  <label><strong>Select Supplier:</strong></label>
  <select id="supplierSelect" class="form-control">
    <option value="">-- Choose Supplier --</option>
  </select>

  <label class="mt-2"><strong>Receiving Method:</strong></label>
  <select id="methodSelect" class="form-control">
    <option value="">-- Choose method --</option>
    <option value="FIFO">FIFO (Auto allocate)</option>
    <option value="ORDER">Specific Order</option>
  </select>

  <label class="mt-2"><strong>Select Order:</strong></label>
  <select id="orderSelect" class="form-control" disabled>
    <option value="">-- Choose Order --</option>
  </select>

</div>

<!-- ================= ORDER TABLE ================= -->

<table id="orderTable" class="table table-bordered table-striped" style="display:none;">
  <thead>
    <tr>
      <th>Barcode</th>
      <th>Item Name</th>
      <th>Notes</th>
      <th>Ordered Qty</th>
      <th>Received Qty</th>
    </tr>
  </thead>
  <tbody></tbody>
</table>

<div id="bulkSubmit" style="display:none;">
  <button class="btn btn-success" id="receiveAllBtn">
    Receive Entire Order
  </button>
</div>

<!-- ================= FILE UPLOAD ================= -->

<div id="uploadSection" class="card mt-3" style="display:none;">
  <div class="card-header">
    <strong>Upload Receiving File (.csv or .xlsx)</strong>
  </div>

  <div class="card-body">

    <div class="row">

      <div class="col-md-5">
        <label><strong>Select file</strong></label>
        <div class="input-group">
          <input type="file" id="receiveFile" accept=".csv,.xlsx" style="display:none;">
          <span class="input-group-btn">
            <button class="btn btn-info" id="chooseFileBtn">Choose File</button>
          </span>
          <input type="text" id="selectedFileName" class="form-control" readonly>
        </div>
      </div>

      <div class="col-md-2">
        <label><strong>Shelf location</strong></label>
        <input type="text" id="fileShelf" class="form-control" value="A010">
      </div>

      <div class="col-md-3">
        <label style="visibility:hidden;">buttons</label>
        <div>
          <button id="previewFileBtn" class="btn btn-info">Preview</button>
          <button id="uploadFileBtn" class="btn btn-success">Upload & Receive</button>
        </div>
      </div>

    </div>

    <div id="filePreview" style="display:none;margin-top:15px;">
      <h5>Preview</h5>
      <table class="table table-bordered table-sm">
        <thead><tr><th>Barcode</th><th>Quantity</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>

  </div>
</div>

        </div>
      </div>

    </div>
  </div>
</div>

<!-- ================= SCRIPT ================= -->
<script>
$(function(){

  /* ---------- UI RESET ---------- */
  function resetUI() {
    $('#orderTable, #bulkSubmit').hide();
    $('#uploadSection').hide();

    $('#orderSelect')
      .prop('disabled', true)
      .empty()
      .append('<option value="">-- Choose Order --</option>');
  }

  resetUI();

  /* ---------- LOAD SUPPLIERS ---------- */
  $.getJSON('scripts/get_open_suppliers.php', function(data){
    data.forEach(s =>
      $('#supplierSelect').append(`<option value="${s}">${s}</option>`)
    );
  });

  /* ---------- METHOD CHANGE ---------- */
$('#methodSelect').on('change', function () {
  const method = this.value;
  resetUI();

  if (!method) return;

  $('#uploadSection').show();

  if (method === 'ORDER') {
    $('#orderSelect').prop('disabled', false);
    loadOrdersForSupplier(); // ✅ LOAD ORDERS HERE
  }
});
  /* ---------- SUPPLIER CHANGE ---------- */
$('#supplierSelect').on('change', function () {
  if ($('#methodSelect').val() === 'ORDER') {
    loadOrdersForSupplier(); // ✅ ALSO LOAD HERE
  }
});

  /* ---------- ORDER CHANGE ---------- */
  $('#orderSelect').on('change', function () {
    const order = this.value;
    if (!order) return;

    $.getJSON('scripts/load_order_items.php', { order_number: order }, function(data){
      const tbody = $('#orderTable tbody').empty();

      data.forEach(i => {
        tbody.append(`
          <tr>
            <td>${i.barcode}</td>
            <td>${i.name || ''}</td>
            <td>${i.note || ''}</td>
            <td>${i.quantity_to_order}</td>
            <td>
              <input type="number"
                     class="form-control receivedQty"
                     data-barcode="${i.barcode}"
                     value="${i.quantity_to_order}">
            </td>
          </tr>
        `);
      });

      $('#orderTable, #bulkSubmit').show();
    });
  });

  /* ---------- FILE PICK ---------- */
  $('#chooseFileBtn').click(() => $('#receiveFile').click());
  $('#receiveFile').change(e =>
    $('#selectedFileName').val(e.target.files[0]?.name || '')
  );

  /* ---------- BULK RECEIVE ---------- */
  $('#receiveAllBtn').click(function () {
    const items = [];

    $('.receivedQty').each(function () {
      const q = parseInt(this.value);
      if (q > 0) {
        items.push({
          barcode: $(this).data('barcode'),
          quantity: q
        });
      }
    });

    if (!items.length) {
      alert('Nothing to receive');
      return;
    }

    $.ajax({
      url: 'scripts/receive_supply.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        order_number: $('#orderSelect').val(),  // Changed from order_id
        shelf_location: $('#fileShelf').val(),
        items
      }),
      success: r => {
        alert(r);
        location.reload();
      }
    });
  });

  /* ---------- FILE UPLOAD ---------- */
  $('#uploadFileBtn').click(function () {
    const file = $('#receiveFile')[0].files[0];
    if (!file) {
      alert('Choose file');
      return;
    }

    const method = $('#methodSelect').val();

    if (method === 'ORDER' && !$('#orderSelect').val()) {
      alert('Select order first');
      return;
    }

    if (!method) {
      alert('Select receiving method first');
      return;
    }

    // Determine endpoint based on method
    let endpoint;
    if (method === 'FIFO') {
      endpoint = 'scripts/receive_supply_fifo.php';
    } else if (method === 'ORDER') {
      endpoint = 'scripts/receive_supply.php';
    } else {
      alert('Invalid receiving method');
      return;
    }

    // Build FormData with all required parameters
    const fd = new FormData();
    fd.append('file', file);
    fd.append('supplier', $('#supplierSelect').val());
    fd.append('shelf_location', $('#fileShelf').val());

    if (method === 'ORDER') {
      fd.append('order_number', $('#orderSelect').val());
    }

    $.ajax({
      url: endpoint,
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      success: r => {
        alert(r);
        location.reload();
      },
      error: e => {
        alert('Receiving failed');
      }
    });
  });

  $('#previewFileBtn').click(function () {
  const file = $('#receiveFile')[0].files[0];
  if (!file) {
    alert('Choose a file first');
    return;
  }
  const reader = new FileReader();
  reader.onload = function (e) {
    const data = e.target.result;
    let rows = [];
    if (file.name.endsWith('.csv')) {
      // Parse all CSV lines (no header skip)
      const csv = data.split('\n').map(line => line.split(','));
      rows = csv.filter(row => row[0] && row[0].trim()); // Only skip empty lines
    } else {
      // XLSX parsing with SheetJS - show all rows
      const workbook = XLSX.read(data, { type: 'binary' });
      const sheet = workbook.Sheets[workbook.SheetNames[0]];
      rows = XLSX.utils.sheet_to_json(sheet, { header: 1 }).filter(row => row[0]);
    }
    const tbody = $('#filePreview tbody').empty();
    rows.forEach(row => {
      tbody.append(`<tr><td>${row[0] || ''}</td><td>${row[1] || ''}</td></tr>`);
    });
    $('#filePreview').show();
  };
  if (file.name.endsWith('.csv')) {
    reader.readAsText(file);
  } else {
    reader.readAsBinaryString(file);
  }
});

});
</script>
