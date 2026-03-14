        <div class="box">
            <div class="box-header">
              <h3 class="box-title">Data Table With Full Features</h3>
            </div>
            <!-- /.box-header -->
            <div class="box-body">

            <?
           
            $sql = "SELECT DISTINCT plastics_stock.*, scrubdata.model, scrubdata.rangeyear FROM plastics_stock LEFT JOIN scrubdata ON plastics_stock.scrubcode = scrubdata.modelcode"; 
             
            //$sql = "SELECT * FROM plastics_stock";             
            
            $query = $conn->query($sql);
            print '<h3>Selected Model</h3>';
            print '<table id="example" width="100%" class="table table-bordered">';
            print '<thead>';
                print'<tr>';
            print'<th style="background-color:#444242; color:white;">BRAND</th>';
            print'<th style="background-color:#444242; color:white;">CODE</th>';
            print'<th style="background-color:#444242; color:white;">COMPAT CODE</th>';
            print'<th style="background-color:#444242; color:white;">MODEL</th>';
            print'<th style="background-color:#444242; color:white;">YEAR RANGE</th>';
            print'<th style="background-color:#444242; color:white;">PART</th>';
            print'<th style="background-color:#444242; color:white;">SUPPLIER</th>';
            print '</tr>';
                print '</thead>';
                print '<tbody>';
            while($row = $query->fetch_array()){
                $scrubcode = $row['scrubcode'];
                print '<tr><td>';
                print $row['brand'] .' </td><td> '. $row['partnumber'].' </td><td> '. $scrubcode.' </td><td> '. $row['model'].' </td><td> '. $row['rangeyear'].' </td><td> '. $row['part'].' </td><td> '. $row['main_supplier'].'</td>';
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
                print'<th style="background-color:#444242; color:white;">SUPPLIER</th>';
                print '</tr>';
                print '</tfoot>';
                print '</table>';
                print '</tbody>';
              ?>
            </div>
            <!-- /.box-body -->
        </div>