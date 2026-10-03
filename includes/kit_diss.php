<div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h1>Dissasembled Kits parts (Kit diss)</h1>
              </div>
              <div class="card-body">  
  <button class="btn btn-primary" data-toggle="modal" data-target="#addKitModal">
  + Add Disassembled Kit
</button>
  <table id="kitsTable" class="table table-bordered table-striped table-hover">
    <thead class="text-white" style="background-color:#333940;">
      <tr>
        <th>Date Time</th>
        <th>User</th>
        <th>Diss P/N</th>
        <th>Model</th>
        <th>Part</th>
        <th>Color</th>
        <th>Missing P/N</th>
        <th>For Model</th>
        <th>Missing Part</th>
        <th>Order</th>        
        <th>Supplier</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>
</div>

  <!-- Delete Confirmation Modal -->
  <div class="modal fade" id="confirmDelete" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-body">Are you sure you want to delete this record?</div>
        <div class="modal-footer">          
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <button id="deleteConfirmBtn" class="btn btn-danger">Delete</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    let deleteId = null;

    $(document).ready(function () {
      let table = null;

      function reloadKitsTable() {
        if (table) {
          table.ajax.reload(null, false);
        } else {
          window.location.reload();
        }
      }

      function hideKitModal(selector) {
        const modalEl = document.querySelector(selector);
        if (!modalEl) return;

        if ($.fn.modal) {
          $(modalEl).modal('hide');
        }

        if (window.bootstrap && window.bootstrap.Modal) {
          const instance = typeof window.bootstrap.Modal.getInstance === 'function'
            ? window.bootstrap.Modal.getInstance(modalEl)
            : null;
          if (instance) {
            instance.hide();
          } else if (typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
          }
        }

        setTimeout(function () {
          modalEl.classList.remove('show');
          modalEl.style.display = 'none';
          modalEl.setAttribute('aria-hidden', 'true');
          modalEl.removeAttribute('aria-modal');
          document.body.classList.remove('modal-open');
          document.body.style.removeProperty('padding-right');
          document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
            backdrop.remove();
          });
        }, 180);
      }

      $(document).on('submit', '#addKitForm', function (e) {
        e.preventDefault();

        const form = this;
        const $form = $(form);
        const $submit = $form.find('button[type="submit"]');
        const originalText = $submit.html();

        $submit.prop('disabled', true).text('Adding...');

        $.ajax({
          url: 'scripts/add_kit.php',
          method: 'POST',
          dataType: 'json',
          data: $form.serialize()
        })
          .done(function (response) {
            if (!response || !response.ok) {
              alert(response && response.error ? response.error : 'Unable to add disassembled kit.');
              return;
            }

            hideKitModal('#addKitModal');
            form.reset();
            reloadKitsTable();
          })
          .fail(function (xhr) {
            const response = xhr.responseJSON || {};
            alert(response.error || xhr.responseText || 'Add request failed.');
          })
          .always(function () {
            $submit.prop('disabled', false).html(originalText);
          });
      });

      if (!$.fn.DataTable) {
        alert('DataTables plugin is not loaded. The Kit Diss table cannot start.');
        return;
      }

      table = $('#kitsTable').DataTable({
        ajax: 'scripts/fetch_kits.php',
        columns: [
        { data: 'timestamp' },
        { data: 'user' },
        { data: 'barcode', className: 'editable' },
        { data: 'name' },
        { data: 'description' },
        { data: 'color' },
        { data: 'missing_barcode', className: 'editable' },
        { data: 'missing_name' },
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

      $('#kitsTable').on('click', '.editable', function () {
  const cell = table.cell(this);
  const oldValue = cell.data();
  const colIndex = cell.index().column;
  const fieldMap = {
    2: 'barcode',
    6: 'missing_barcode',
    9: 'order_number'
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
      }, () => table.ajax.reload());
    } else {
      cell.data(oldValue).draw();
    }
  });
});

      $('#kitsTable').on('click', '.delete-btn', function () {
        deleteId = $(this).data('id');
        $('#confirmDelete').modal('show');
      });

      $('#deleteConfirmBtn').click(function () {
        $.post('scripts/delete_kits.php', { id: deleteId }, () => {
          $('#confirmDelete').modal('hide');
          reloadKitsTable();
        });
      });
    });
  </script>
  <!-- Add New Disassembled Kit Modal -->

<div class="modal fade" id="addKitModal" tabindex="-1" role="dialog" aria-labelledby="addKitLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form id="addKitForm">
        <div class="modal-header">        
        <h4 class="modal-title text-left" id="addKitLabel">Add Disassembled Kit</h4>
        </div>
        <div class="modal-body">
          <input type="hidden" name="user" value="<?php echo $_SESSION['name'] ?? 'admin'; ?>">

          <div class="form-group">
            <label>Kit P/N</label>
            <input type="text" name="barcode" class="form-control" required>
          </div>

          <div class="form-group">
            <label>Missing part P/N</label>
            <input type="text" name="missing_barcode" class="form-control" required>
          </div>
          
            <input type="hidden" name="quantity" value="1">          

          <div class="form-group">
            <label>Order Number</label>
            <input type="text" name="order_number" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Add</button>
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
  <!-- /.card-body -->
    </div>
      <!-- /.card -->
        </div>
          <!-- /.col -->
             </div>
