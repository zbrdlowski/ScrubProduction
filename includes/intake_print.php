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
<link rel="stylesheet" href="assets/css/plastics-workflow.css">
<section class="content plastics-workflow-page">
 <div class="container-fluid">
  <div class="plastics-workflow-hero">
    <div>
      <h1>Print Intake Labels</h1>
      <p>Review pending intake labels, print the selected batch and add one-off labels when needed.</p>
    </div>
  </div>
  <div class="box box-primary">
    <div class="box-header with-border">
      <i class="fas fa-print" aria-hidden="true"></i><span>Labels waiting to be printed</span>
    </div>

    <div class="box-body">        
      <table id="intakeLabels" class="table table-bordered table-striped" style="width: 100%;">
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
    
      <div class="plastics-workflow-actions mt-3">
        <button id="markPrinted" class="btn btn-success">
          <i class="fas fa-check mr-1"></i>Mark as Printed
        </button>

        <button class="btn btn-primary" data-toggle="modal" data-target="#manualLabelModal">
          <i class="fa fa-plus"></i> Add Label Manually
        </button>
      </div>

    </div>
  </div>
 </div>
</section>
<div class="modal fade plastics-workflow-page" id="manualLabelModal" tabindex="-1">
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
    responsive: false,
    scrollX: true,
    pageLength: 50,
    lengthMenu: [[25, 50, 100, 250], [25, 50, 100, 250]],
    order: [[7, 'asc']],
    dom: "<'plastics-workflow-dt-toolbar'Bf>rt<'plastics-workflow-dt-footer'lip>",
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


