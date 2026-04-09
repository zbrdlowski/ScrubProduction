<script>
function orderPrepareExportFormatter(data, row, column, node) {
  // ak je v bunke input/select/textarea, exportuj jeho value
  var $input = $(node).find('input, select, textarea');

  if ($input.length) {
    return $input.first().val();
  }

  // inak normálne text
  return $(node).text().trim();
}

$("#orderPrepareTable").DataTable({
  responsive: true,
  lengthChange: true,
  autoWidth: false,
  pageLength: 100,
  info: true,
  dom: 'Bfrtip',
  buttons: [
    {
      extend: 'copy',
      text: 'Copy',
      exportOptions: {
        format: {
          body: orderPrepareExportFormatter
        }
      }
    },
    {
      extend: 'excel',
      text: 'XLSX',
      exportOptions: {
        format: {
          body: orderPrepareExportFormatter
        }
      }
    }
  ]
}).buttons().container().appendTo('#orderPrepareTable_wrapper .col-md-6:eq(0)');
  $(function () {
    $("#example0").DataTable({
      "responsive": true,
      "searching": false,
      "paging": false,
      "ordering.indicators": true,
      "lengthChange": false,
      "autoFill.editor" : true,
      "autoWidth": false,
      "pageLength": 10,
      "info": true,
      "order": [[0, "desc"]] // 👈 Set default sort column and direction      
    })
    $("#example1").DataTable({
      "responsive": true,
      "ordering.indicators": true,
      "lengthChange": false,
      "autoFill.editor" : true,
      "autoWidth": false,
      "pageLength": 50,
      "info": true,
      "order": [[0, "desc"]], // 👈 Set default sort column and direction
      "buttons": ["copy", "csv", "excel", "pdf", "print"]
    }).buttons().container().appendTo('#example1_wrapper .col-md-6:eq(0)');
    $('#example2').DataTable({
      "paging": true,
      "lengthChange": true,
      "searching": true,
      "ordering": true,
      "info": true,
      "autoWidth": false,
      "responsive": true,
      "pageLength": 50,
      "buttons": ["copy", "csv", "excel", "pdf", "print"]
    }).buttons().container().appendTo('#example2_wrapper .col-md-6:eq(0)');
    $("#example3").DataTable({
      "responsive": true, 
      "lengthChange": false, 
      "autoWidth": false,
      "pageLength": 7,
      "info": true,
      "buttons": ["copy", "csv", "excel", "pdf", "print"]
    }).buttons().container().appendTo('#example3_wrapper .col-md-6:eq(0)');
    $("#example4").DataTable({
      "paging": true,
      "responsive": true, 
      "searching": true,
      "ordering": true,
      "lengthChange": true,
      "autoWidth": true, 
      "autoWidth": true,
      "pageLength": 100,
      "info": true,
      "buttons": ["copy", "csv", "excel", "pdf", "print"]
    }).buttons().container().appendTo('#example4_wrapper .col-md-6:eq(0)');
    $("#example5").DataTable({
      "responsive": true,
      "info": true,
      "ordering.indicators": true,
      "lengthChange": false,
      "autoFill.editor" : true,
      "autoWidth": true,
      "pageLength": 50,     
      "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
    }).buttons().container().appendTo('#example5_wrapper .col-md-6:eq(0)');

    // formatter for export (uses full scrubcode from data-export)
function scrubExportFormatter(data, row, column, node) {
  const el = node && node.querySelector ? node.querySelector('.scrubcode-cell') : null;
  if (el && el.dataset && el.dataset.export) {
    return el.dataset.export;
  }
  return data;
}

$("#example6").DataTable({
  responsive: true,
  info: true,
  "ordering.indicators": true,
  lengthChange: false,
  "autoFill.editor": true,
  autoWidth: true,
  searching: true,
  pageLength: 50,
  dom: 'Bfrtip',

  buttons: [
  {
    extend: "copy",
    exportOptions: {
      columns: function (idx, data, node) { return idx >= 2; }, // ✅ skip first 2 cols
      format: { body: scrubExportFormatter }
    }
  },
  {
    extend: "csv",
    exportOptions: {
      columns: function (idx, data, node) { return idx >= 2; },
      format: { body: scrubExportFormatter }
    }
  },
  {
    extend: "excel",
    exportOptions: {
      columns: function (idx, data, node) { return idx >= 2; },
      format: { body: scrubExportFormatter }
    }
  },
  {
    extend: "pdf",
    exportOptions: {
      columns: function (idx, data, node) { return idx >= 2; },
      format: { body: scrubExportFormatter }
    }
  },
  {
    extend: "print",
    exportOptions: {
      columns: function (idx, data, node) { return idx >= 2; },
      format: { body: scrubExportFormatter }
    }
  },
  "colvis"
],

  columnDefs: [
    { targets: [12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22], visible: false }
  ]

}).buttons().container().appendTo('#example6_wrapper .col-md-6:eq(0)');

$("#example6-1").DataTable({
  responsive: true,
  info: true,
  "ordering.indicators": true,
  lengthChange: false,
  "autoFill.editor": true,
  autoWidth: true,
  searching: true,
  pageLength: 50,
  dom: 'Bfrtip',

  buttons: [
  {
    extend: "copy",
    exportOptions: {
      columns: function (idx, data, node) { return idx >= 2; }, // ✅ skip first 2 cols
      format: { body: scrubExportFormatter }
    }
  },
  {
    extend: "csv",
    exportOptions: {
      columns: function (idx, data, node) { return idx >= 2; },
      format: { body: scrubExportFormatter }
    }
  },
  {
    extend: "excel",
    exportOptions: {
      columns: function (idx, data, node) { return idx >= 2; },
      format: { body: scrubExportFormatter }
    }
  },
  {
    extend: "pdf",
    exportOptions: {
      columns: function (idx, data, node) { return idx >= 2; },
      format: { body: scrubExportFormatter }
    }
  },
  {
    extend: "print",
    exportOptions: {
      columns: function (idx, data, node) { return idx >= 2; },
      format: { body: scrubExportFormatter }
    }
  },
  "colvis"
],

columnDefs: [
  { targets: [1, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24], visible: false }
]

}).buttons().container().appendTo('#example6_wrapper .col-md-6:eq(0)');


    $("#example7").DataTable({
      "responsive": true,
      "info": true,
      "ordering.indicators": true,
      "lengthChange": false,
      "autoFill.editor" : true,
      "autoWidth": true,
      "pageLength": 5000,
      "dom": 'Bfrtip', // <-- enables buttons             
      "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"],
      columnDefs: [
      { targets: [ 10, 11, 12, 13, 14, 15, 16, 17, 18, 19], visible: false } // Defaultne skryje Scrubcode a všetky info o externých part numberách a kódoch 
    ],    
    }).buttons().container().appendTo('#example7_wrapper .col-md-6:eq(0)');
    $("#example8").DataTable({
    responsive: true,
    "ordering.indicators": true,
    lengthChange: false,
    autoFill: { editor: true },
    autoWidth: false,
    pageLength: 50000,
    info: true,
    searching: false, // because custom filter is used
    dom: 'Bfrtip',
    buttons: [
      {
        extend: 'copy',
        exportOptions: { rows: ':visible' }
      },
      {
        extend: 'csv',
        exportOptions: { rows: ':visible' }
      },
      {
        extend: 'excel',
        exportOptions: { rows: ':visible' }
      },
      {
        extend: 'pdf',
        exportOptions: { rows: ':visible' }
      },
      {
        extend: 'print',
        exportOptions: { rows: ':visible' }
      }
    ]
}).buttons().container().appendTo('#example8_wrapper .col-md-6:eq(0)');
   
    $('#example').dataTable( {
      "pageLength": 10,
      "info": true,
      "responsive": true,
      "ordering": true,
      "searching": true,
      "lengthChange": true,
      "buttons": ["copy", "csv", "excel", "pdf", "print"]
    }).buttons().container().appendTo('#example_wrapper .col-md-6:eq(0)');    
  } );   
</script>
