<!-- DataTables + Bootstrap 3 CSS -->
<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
<link rel="stylesheet" href="//cdn.datatables.net/1.10.25/css/dataTables.bootstrap.min.css">
<link rel="stylesheet" href="//cdn.datatables.net/buttons/1.7.1/css/buttons.bootstrap.min.css">

<!-- DataTables + Buttons JS -->
<script src="//code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="//cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js"></script>
<script src="//cdn.datatables.net/1.10.25/js/dataTables.bootstrap.min.js"></script>
<script src="//cdn.datatables.net/buttons/1.7.1/js/dataTables.buttons.min.js"></script>
<script src="//cdn.datatables.net/buttons/1.7.1/js/buttons.bootstrap.min.js"></script>
<script src="//cdn.datatables.net/buttons/1.7.1/js/buttons.colVis.min.js"></script>
<script src="//cdn.datatables.net/buttons/1.7.1/js/buttons.html5.min.js"></script>
<script src="//cdn.datatables.net/buttons/1.7.1/js/buttons.print.min.js"></script>

<!-- Optional: spacing fix -->
<style>
  .dt-buttons {
    margin-bottom: 10px;
  }
</style>

<!-- Responsive wrapper -->
<div class="table-responsive">
  <table id="example6" class="table table-striped table-bordered">
    <thead>
      <tr>
        <th>Brand</th>
        <th>Code</th>
        <th>Compat Code</th>
        <th>Model</th>
        <th>Part</th>
        <th>Color</th>
        <th>Quantity</th>
        <th>OPT</th>
        <th>MOQ</th>
        <th>Supplier</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $sql = "SELECT * FROM items";
      $query = $conn->query($sql);
      while($row = $query->fetch_array()){
        echo "<tr>
          <td>{$row['brand']}</td>
          <td>{$row['barcode']}</td>
          <td>{$row['scrubcode']}</td>
          <td>{$row['name']}</td>
          <td>{$row['description']}</td>
          <td>{$row['color']}</td>
          <td>{$row['quantity']}</td>
          <td>{$row['optimum']}</td>
          <td>{$row['moq']}</td>
          <td>{$row['main_supplier']}</td>
        </tr>";
      }
      ?>
    </tbody>
  </table>
</div>

<!-- DataTable config -->
<script>
$(document).ready(function() {
  $('#example6').DataTable({
    dom: "<'row'<'col-sm-6'B><'col-sm-6'f>>" +
         "<'row'<'col-sm-12'tr>>" +
         "<'row'<'col-sm-5'i><'col-sm-7'p>>",
    buttons: ['copy', 'csv', 'excel', 'pdf', 'print', 'colvis'],
    columnDefs: [
      { targets: [2, 7, 8], visible: false } // Hide COMPAT CODE, OPT, MOQ
    ],
    pageLength: 50,
    responsive: true,
    autoWidth: true
  });
});
</script>
