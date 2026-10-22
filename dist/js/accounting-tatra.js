(function () {
  const dropZone = document.getElementById('tatraDropZone');
  const fileInput = document.getElementById('tatraFiles');
  const selection = document.getElementById('tatraFileSelection');
  if (dropZone && fileInput && selection) {

  function showSelection() {
    const files = Array.from(fileInput.files || []);
    selection.classList.remove('text-danger');
    selection.textContent = files.length
      ? 'Vybrané: ' + files.map(function (file) { return file.name; }).join(', ')
      : 'Nie je vybraný žiadny súbor.';
  }

  ['dragenter', 'dragover'].forEach(function (eventName) {
    dropZone.addEventListener(eventName, function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
      dropZone.classList.add('is-dragover');
    });
  });

  ['dragleave', 'drop'].forEach(function (eventName) {
    dropZone.addEventListener(eventName, function (event) {
      event.preventDefault();
      event.stopPropagation();
      dropZone.classList.remove('is-dragover');
    });
  });

  dropZone.addEventListener('drop', function (event) {
    const droppedFiles = Array.from((event.dataTransfer && event.dataTransfer.files) || []);
    if (!droppedFiles.length) return;

    const invalidFile = droppedFiles.find(function (file) {
      return !file.name.toLowerCase().endsWith('.csv');
    });
    if (invalidFile) {
      fileInput.value = '';
      selection.classList.add('text-danger');
      selection.textContent = 'Povolené sú iba CSV súbory. Nepodarilo sa pridať: ' + invalidFile.name;
      return;
    }

    try {
      fileInput.files = event.dataTransfer.files;
      showSelection();
    } catch (error) {
      selection.classList.add('text-danger');
      selection.textContent = 'Prehliadač nepovolil vloženie súborov. Vyberte ich kliknutím do plochy.';
    }
  });

  fileInput.addEventListener('change', showSelection);

  }
  const filterForm = document.getElementById('accountingTatraFilters');
  if (filterForm) {
    filterForm.querySelectorAll('.accounting-auto-filter').forEach(function (field) {
      field.addEventListener('change', function () {
        if (typeof filterForm.requestSubmit === 'function') {
          filterForm.requestSubmit();
        } else {
          filterForm.submit();
        }
      });
    });
  }
}());

// The shared datatable.js contains initializers for many unrelated pages. Some
// legacy initializers can fail when their table is absent, so this page owns its
// DataTable setup and starts it only after all DataTables extensions are loaded.
window.addEventListener('load', function () {
  const $ = window.jQuery;
  if (!$ || !$.fn.DataTable || !document.getElementById('accountingTatraTable')) return;
  if ($.fn.DataTable.isDataTable('#accountingTatraTable')) return;

  const table = $('#accountingTatraTable');
  const exportTitle = table.data('export-title') || 'Tatra B. Payouts';
  const exportOptions = {
    columns: ':not(.no-export)',
    modifier: { search: 'applied', order: 'applied', page: 'all' },
    format: {
      body: function (data) {
        const cell = document.createElement('div');
        cell.innerHTML = String(data);
        cell.querySelectorAll('.tatra-actions, .accounting-info').forEach(function (item) { item.remove(); });
        cell.querySelectorAll('br').forEach(function (item) { item.replaceWith(' '); });
        const text = (cell.textContent || '').replace(/\s+/g, ' ').trim();
        return /^[=+@\-\t\r\n]/.test(text) ? "'" + text : text;
      }
    }
  };

  table.DataTable({
    responsive: true,
    columnDefs: [
      { targets: [0, 6], responsivePriority: 1 },
      { targets: 4, responsivePriority: 2 },
      { targets: 8, responsivePriority: 3 }
    ],
    searching: true,
    ordering: true,
    order: [],
    lengthChange: true,
    autoWidth: false,
    pageLength: 100,
    info: true,
    dom: '<"accounting-table-toolbar"Bf>rtip',
    language: {
      emptyTable: 'Pre zvolený filter nie sú žiadne payout transakcie.'
    },
    buttons: [
      { extend: 'copy', text: 'Kopírovať', title: exportTitle, exportOptions: exportOptions },
      { extend: 'csv', text: 'CSV', title: exportTitle, filename: exportTitle, exportOptions: exportOptions },
      { extend: 'excel', text: 'Excel', title: exportTitle, filename: exportTitle, exportOptions: exportOptions },
      {
        extend: 'pdf',
        text: 'PDF',
        title: exportTitle,
        filename: exportTitle,
        orientation: 'landscape',
        pageSize: 'A3',
        exportOptions: exportOptions,
        customize: function (doc) {
          doc.defaultStyle.fontSize = 7;
          doc.styles.tableHeader.fontSize = 8;
        }
      },
      { extend: 'print', text: 'Tlačiť', title: exportTitle, exportOptions: exportOptions }
    ]
  });
});
