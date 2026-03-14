<script>
  $(function () {
    $("#example1").DataTable({
    "responsive": true,
    "lengthChange": false,
    "autoWidth": false,
    "pageLength": 50,
    "info": true,
    columnDefs: [
        { type: "date-eu", targets: 0 }
    ],
    order: [[0, "desc"]],
    buttons: ["copy", "csv", "excel", "pdf", "print"]
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
    $("#example6").DataTable({ // KP_GEN
      "scrollX": true,   // ✔ allow horizontal scrolling only inside table
      "autoWidth": false,
      "responsive": true,
      "info": true,
      "ordering.indicators": true,
      "lengthChange": false,
      "autoFill.editor" : true,
      "autoWidth": true,
      "searching": true, // 🔍 disables the search bar
      "pageLength": 50,
      "dom": 'Bfrtip', // <-- enables buttons             
      "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"],
      columnDefs: [
      { targets: [3, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22 ], visible: false } // Defaultne skryje Scrubcode a všetky info o externých part numberách a kódoch 
    ],     
    }).buttons().container().appendTo('#example6_wrapper .col-md-6:eq(0)');
    $("#example7").DataTable({
      "responsive": true,
      "info": true,
      "ordering.indicators": true,
      "lengthChange": false,
      "autoFill.editor" : true,
      "autoWidth": true,
      "pageLength": 50,
      "dom": 'Bfrtip', // <-- enables buttons             
      "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"],
      columnDefs: [
      { targets: [ 10, 11, 12, 13, 14, 15, 16, 17, 18, 19], visible: false } // Defaultne skryje Scrubcode a všetky info o externých part numberách a kódoch 
    ],    
    }).buttons().container().appendTo('#example7_wrapper .col-md-6:eq(0)');
    $("#example8").DataTable({
    "responsive": true,
    "ordering.indicators": true,
    "lengthChange": false,
    "autoFill.editor": true,
    "autoWidth": false,
    "pageLength": 50000,
    "info": true,
    "searching": false, // 🔍 disables the search bar
    "buttons": ["copy", "csv", "excel", "pdf", "print"]
  }).buttons().container().appendTo('#example8_wrapper .col-md-6:eq(0)');
    $("#example9").DataTable({
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
    }).buttons().container().appendTo('#example9_wrapper .col-md-6:eq(0)');
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
