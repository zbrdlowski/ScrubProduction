<section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">Scrub Designz Plastics Stock</h3>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
             <?
           
            $sql = "SELECT DISTINCT plastics_stock.*, scrubdata.model, scrubdata.rangeyear FROM plastics_stock LEFT JOIN scrubdata ON plastics_stock.scrubcode = scrubdata.modelcode"; 
             
            //$sql = "SELECT * FROM plastics_stock";             
            
            $query = $conn->query($sql);            
            print '
                <table id="example1" class="table table-bordered table-striped">';
            print '<thead>';
                print'<tr>';
            print'<th style="background-color:#444242; color:white;">BRAND</th>';
            print'<th style="background-color:#444242; color:white;">CODE</th>';
            print'<th style="background-color:#444242; color:white;">COMPAT CODE</th>';
            print'<th style="background-color:#444242; color:white;">MODEL</th>';
            print'<th style="background-color:#444242; color:white;">YEAR RANGE</th>';
            print'<th style="background-color:#444242; color:white;">PART</th>';
            print'<th style="background-color:#444242; color:white;">COLOR</th>';
            print'<th style="background-color:#444242; color:white;">SUPPLIER</th>';
            print '</tr>';
                print '</thead>';
                print '<tbody>';
            while($row = $query->fetch_array()){
                $scrubcode = $row['scrubcode'];
                print '<tr><td>';
                print $row['brand'] .' </td>';
                print '<td> '. $row['partnumber'].' </td>';
                print '<td> '. $scrubcode.' </td>';
                print '<td> '. $row['model'].' </td>';
                print '<td> '. $row['rangeyear'].' </td>';
                print '<td> '. $row['part'].' </td>';
                print '<td> '. $row['color'].' </td>';
                print '<td> '. $row['main_supplier'].'</td>';
                print '</td></tr>';
            }
            
                print '<tfoot>';
                print '<tr>';
                print'<th style="background-color:#444242; color:white;">BRAND</th>';
                print'<th style="background-color:#444242; color:white;">CODE</th>';
                print'<th style="background-color:#444242; color:white;">COMPAT CODE</th>';
                print'<th style="background-color:#444242; color:white;">MODEL</th>';
                print'<th style="background-color:#444242; color:white;">YEAR RANGE</th>';
                print'<th style="background-color:#444242; color:white;">PART</th>';
                print'<th style="background-color:#444242; color:white;">COLOR</th>';
                print'<th style="background-color:#444242; color:white;">SUPPLIER</th>';
                print '</tr>';
                print '</tfoot>';
                print '</table>';
                print '</tbody>';
              ?>
             </div>
<!-- /.box-body -->
            </div>
        </div>
<!-- /.card-body -->
        </div>
    </div>
</section>