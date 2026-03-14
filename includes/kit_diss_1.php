<link rel="stylesheet" href="js/rowReorder.dataTables.min.css">
<style>
.reorder-handle {
  cursor: move;
  text-align: center;
  
}
#kitsTable thead {
  background-color: #333940 !important;
  color: white;
}
</style>
<div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h1>Dissasembled Kits parts (Kit diss)</h1>
              </div>
              <div class="card-body">  
  <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addKitModal">+ Add Kit</button>

  <table id="kitsTable" class="table table-bordered table-striped table-hover">
    <thead class="table-dark">
      <tr>
        <th style="display:none;">Position</th>
        <th style="width:30px;"><i class="fas fa-grip-lines"></i></th>
        <th>Date Time</th>
        <th>User</th>
        <th>Diss P/N</th>
        <th>Model</th>
        <th>Part</th>
        <th>Color</th>
        <th>Missing P/N</th>
      
        <th>Missing Part</th>
        <th>Order</th>
        <th>Supplier</th>
        <th>Actions</th>
      </tr>
    </thead>
  </table>
</div>



<!-- ✅ JS -->
<script src="https://kit.fontawesome.com/a2e0e9f6fd.js" crossorigin="anonymous"></script>
<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/dataTables.rowReorder.min.js"></script>
<script src="js/bootstrap.min.js"></script>


<script>
$(document).ready(function () {
  let deleteId = null;

  const table = $('#kitsTable').DataTable({
    ajax: 'scripts/fetch_kits.php',
    rowReorder: {
      selector: '.reorder-handle',
      dataSrc: 'position'
    },
    ordering: false,  // Disable column sorting
    columns: [
      { data: 'position', visible: false },
      { data: null, className: 'reorder-handle text-center', orderable: false, defaultContent: '<i class="fas fa-grip-lines"></i>' },
      {
  data: 'timestamp',
  render: function (data) {
    if (!data) return '';

    const d = new Date(data);

    const pad = n => (n < 10 ? '0' + n : n);

    const day = pad(d.getDate());
    const month = pad(d.getMonth() + 1);
    const year = d.getFullYear();

    const hours = pad(d.getHours());
    const minutes = pad(d.getMinutes());
    const seconds = pad(d.getSeconds());

    return `${day}.${month}.${year} ${hours}:${minutes}:${seconds}`;
  }
},
      { data: 'user' },
      { data: 'barcode', className: 'editable' },
      { data: 'name' },
      { data: 'description' },
      { data: 'color' },
      { data: 'missing_barcode', className: 'editable' },
      
      { data: 'missing_description' },
      { data: 'order_number', className: 'editable' },
      { data: 'main_supplier' },
      {
        data: 'id',
        render: function (data) {
          return `<button class="btn btn-sm btn-danger delete-btn" data-id="${data}">Delete</button>`;
        }
      }
    ]
  });

  // Enable inline editing
  $('#kitsTable').on('click', '.editable', function () {
    const cell = table.cell(this);
    const oldValue = cell.data();
    const colIndex = cell.index().column;
    const fieldMap = {
      4: 'barcode',
      8: 'missing_barcode',
      10: 'order_number'
    };
    const field = fieldMap[colIndex];
    if (!field) return;

    const input = $('<input type="text" class="form-control">').val(oldValue);
    $(this).html(input);
    input.focus().blur(function () {
      const newValue = $(this).val();
      if (newValue !== oldValue) {
        const rowData = table.row($(cell.node()).closest('tr')).data();
        $.post('scripts/update_kits.php', {
          id: rowData.id,
          field: field,
          value: newValue
        }, function () {
          // Instead of using invalidate() and draw(), reload the entire table
          table.ajax.reload();
          showToast('Update successful!', 'success');
        }).fail(function () {
          showToast('Error updating. Please try again.', 'danger');
          // If update fails, revert back to old value
          cell.data(oldValue).draw();
        });
      } else {
        // If no change, revert to the old value
        cell.data(oldValue).draw();
      }
    });
  });

  // Add kit
  $('#addKitForm').submit(function (e) {
    e.preventDefault();
    const formData = $(this).serialize();
    $.post('scripts/add_kit.php', formData, function () {
      $('#addKitModal').modal('hide');
      $('#addKitForm')[0].reset();
      table.ajax.reload();
      showToast('Kit added successfully!', 'success');
    }).fail(function () {
      showToast('Error adding kit. Please try again.', 'danger');
    });
  });

  // Delete kit
  $('#kitsTable').on('click', '.delete-btn', function () {
    deleteId = $(this).data('id');
    new bootstrap.Modal(document.getElementById('confirmDelete')).show();
  });

  $('#deleteConfirmBtn').click(function () {
    $.post('scripts/delete_kits.php', { id: deleteId }, function () {
      $('#confirmDelete').modal('hide');
      table.ajax.reload();
      showToast('Kit deleted successfully.', 'warning');
    }).fail(function () {
      showToast('Error deleting kit.', 'danger');
    });
  });

  // Toast helper
  function showToast(message, type = 'info') {
    const toast = $(`<div class="toast align-items-center text-white bg-${type} border-0 position-fixed bottom-0 end-0 m-3" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body">${message}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>`);
    $('body').append(toast);
    const bsToast = new bootstrap.Toast(toast[0], { delay: 2500 });
    bsToast.show();
    toast.on('hidden.bs.toast', () => toast.remove());
  }
});
</script>

  <!-- Add New Disassembled Kit Modal -->
<!-- Add New Disassembled Kit Modal -->
<div class="modal fade" id="addKitModal" tabindex="-1" role="dialog" aria-labelledby="addKitLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form id="addKitForm" novalidate>
        <div class="modal-header">        
          <h4 class="modal-title text-left" id="addKitLabel">Add Disassembled Kit</h4>
        </div>
        <div class="modal-body">
          <input type="hidden" name="user" value="<?php echo $_SESSION['name'] ?? 'admin'; ?>">

          <div class="form-group">
            <label>Kit P/N</label>
            <input type="text" name="barcode" class="form-control" required>
            <div class="invalid-feedback">Please enter the Kit P/N.</div>
          </div>

          <div class="form-group">
            <label>Missing part P/N</label>
            <input type="text" name="missing_barcode" class="form-control" required>
            <div class="invalid-feedback">Please enter the missing part P/N.</div>
          </div>

          <input type="hidden" name="quantity" value="1">          

          <div class="form-group">
            <label>Order Number</label>
            <input type="text" name="order_number" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Add</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ✅ Delete Modal -->
<div class="modal fade" id="confirmDelete" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-body">Are you sure you want to delete this record?</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button id="deleteConfirmBtn" class="btn btn-danger">Delete</button>
      </div>
    </div>
  </div>
</div>
<!-- /.card-body -->
    </div>
      <!-- /.card -->
         </div>
          <!-- /.col -->
            </div>