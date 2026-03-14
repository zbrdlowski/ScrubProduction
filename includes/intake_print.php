<style>
/* Center checkbox column */
#intakeLabels th:first-child,
#intakeLabels td:first-child {
  text-align: center;
  vertical-align: middle;
  width: 50px;
}

/* Bigger checkboxes */
#intakeLabels input[type="checkbox"] {
  transform: scale(1.6);
  cursor: pointer;
}

/* Fix transform side effects */
#intakeLabels input[type="checkbox"] {
  margin: 0;
}
#intakeLabels td:nth-child(5) {
  font-weight: bold;
  text-transform: capitalize;
}
</style>
<section class="content">
  <div class="box box-primary">
    <div class="box-header with-border">
      <h3 class="box-title">Print Intake Labels</h3>
    </div>

    <div class="box-body">        
      <table id="intakeLabels" class="table table-bordered table-striped">
        <thead>
          <tr>
            <th><input type="checkbox" id="toggleAll"></th>
            <th>Intake Ref</th>
            <th>Barcode</th>
            <th>Item Name</th>
            <th>Description</th>
            <th>Color</th>
            <th>Boxes</th>
            <th>Supplier</th>
            <th>Location</th>
            <th>Created</th>
            </tr>
        </thead>
      </table>
    
      <button id="markPrinted" class="btn btn-success">
        Mark as Printed
      </button>

      <button class="btn btn-primary" data-toggle="modal" data-target="#manualLabelModal">
        <i class="fa fa-plus"></i> Add Label Manually
        </button>

    </div>
  </div>
</section>
<div class="modal fade" id="manualLabelModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        
        <h4 class="modal-title">Add Intake Label Manually</h4>
      </div>

      <div class="modal-body">
        <form id="manualLabelForm">
          <div class="form-group">
            <label>Part Number</label>
            <input type="text" class="form-control" name="barcode" required>
          </div>

          <div class="form-group">
            <label>Intake Reference</label>
            <input type="text" class="form-control" name="intake_ref" required>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
        <button type="button" id="saveManualLabel" class="btn btn-success">
          Save
        </button>
      </div>

    </div>
  </div>
</div>

<script>
$(function () {

  const table = $('#intakeLabels').DataTable({
    ajax: 'scripts/get_intake_labels.php',
    responsive: true,
    pageLength: 50,
    order: [[7, 'asc']],
    dom: 'Bfrtip',
    buttons: ['excel'],
  columns: [
  {
    data: 'id',
    render: id =>
      `<input type="checkbox" class="print-check" value="${id}">`,
    orderable: false,
    searchable: false
  },
  { data: 'intake_ref' },
  { data: 'barcode' },
  { data: 'item_name' },

  { // 👇 NEW DESCRIPTION COLUMN
    data: 'description',
    defaultContent: '',
    render: d => d
      ? `<span title="${d}">${d}</span>`
      : '<span class="text-muted">—</span>'
  },

  { 
    data: 'color',
    defaultContent: '',
    render: c => c ? c : '<span class="text-muted">—</span>'
  },
  { data: 'quantity' },
  { data: 'supplier' },
  { data: 'shelf_location' },
  { data: 'created_at' }
]
  });
  

  /* Toggle all */
  $('#toggleAll').on('change', function () {
    $('#intakeLabels tbody .print-check')
      .prop('checked', this.checked);
  });

  /* Sync header checkbox */
  $('#intakeLabels').on('change', '.print-check', function () {
    const total = $('#intakeLabels tbody .print-check').length;
    const checked = $('#intakeLabels tbody .print-check:checked').length;
    $('#toggleAll').prop('checked', total === checked);
  });

  /* Reset on redraw */
  table.on('draw', function () {
    $('#toggleAll').prop('checked', false);
  });

  /* Mark printed */
  $('#markPrinted').on('click', function () {
    const ids = $('.print-check:checked')
      .map((_, el) => el.value)
      .get();

    if (!ids.length) {
      alert('Nothing selected');
      return;
    }

    $.post('scripts/mark_labels_printed.php', { ids }, function () {
      table.ajax.reload();
    });
  });

});
</script>
<script>
$('#saveManualLabel').on('click', function () {
  const form = $('#manualLabelForm');

  if (!form[0].checkValidity()) {
    form[0].reportValidity();
    return;
  }

  $.post(
    'scripts/add_intake_label_manual.php',
    form.serialize(),
    function () {
      $('#manualLabelModal').modal('hide');
      $('#manualLabelForm')[0].reset();
      $('#intakeLabels').DataTable().ajax.reload();
    }
  ).fail(function (xhr) {
    alert(xhr.responseText || 'Failed to add label');
  });
});
</script>


