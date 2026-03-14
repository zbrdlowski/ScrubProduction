
<!-- backup pre orders -->
<section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
            <?
            $napis = 'Ordes'; 
                if(isset($_GET['type'])){
                    switch ($_GET['type']) {
                        case  'g':
                            $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE gfp LIKE '%G%' AND NOT status = 'Delivered' AND NOT status = 'Shipped' OR gfp LIKE '%T%' AND NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY date DESC";
                            $napis = 'Open Graphics Ordes';                            
                            break;
                            case 'p':
                                $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE gfp LIKE '%P%' AND NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY date DESC";
                                $napis = 'Open Plastics Ordes';

                                break;
                                case  's':
                                    $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE gfp LIKE '%s%' AND NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY date DESC";
                                    $napis = 'Open Seat Covers Ordes';
                                    break;
                                    case  'f':
                                        $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE gfp LIKE '%F%' AND NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY date DESC";
                                        $napis = 'Open Fittings';
                                        break;
                        default:
                        $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY status DESC";
                        $napis = 'Ordes'; 
                            break;
                    }
                }else{
                    $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY status DESC"; 
                }
                ?>
                <h1><? echo $napis; ?></h1>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
             
            <?
           print '<style>';
           print '#GFP { Background-color:#a1cbf0 color:black;}';
           print '#P { Background-color:SILVER; color:black;}';
           print '#G { Background-color:#deb887; color:black;}';
           print '#S { Background-color:grey; color:white; }';
           print '#TFP { Background-color:#0fc7ae; color:white; }';
            print '</style>';
            //$sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders ORDER BY status DESC"; 
            //$rows = $conn->query("SELECT *, DATE_FORMAT(date,'%d.%m.%y') AS niceDate FROM orders ORDER BY status DESC");
            //<td id="'.$row["gfp"].'">            
            
            $query = $conn->query($sql);            
            print '<table id="example1" class="table table-bordered table-striped">';
            print '<thead>';
                print'<tr>';
            print'<th width="10%" style="background-color:#444242; color:white; text-align: center;">SCRUB</th>';
            print'<th style="background-color:#444242; color:white; text-align: center;">DATE</th>';
            print'<th style="background-color:#444242; color:white; text-align: center;">SOURCE</th>';
            print'<th style="background-color:#444242; color:white; text-align: center;">ORDER NUMBER</th>';
            print'<th style="background-color:#444242; color:white; text-align: center;">ORDER TYPE</th>';
            print'<th style="background-color:#444242; color:white; text-align: center;">COUNTRY</th>';
            print'<th style="background-color:#444242; color:white; text-align: center;">COURIER</th>';
            print'<th style="background-color:#444242; color:white; text-align: center;">STATUS</th>';
            print'<th style="background-color:#444242; color:white; text-align: center;">TRACKING</th>';
            print '</tr>';
                print '</thead>';
                print '<tbody>';
            while($row = $query->fetch_array()){
                
            print '<tr id="'.$row["gfp"].'">'; 
            print '<td align="center">';
                switch (@$_GET['type']) {
                    case  'g':
                        $sql2 = "SELECT firstname, lastname, photo FROM employees WHERE id = '".$row['assign_g']."'";
                        $query2 = $conn->query($sql2); 
                        if($query2->num_rows === 0) {
                                print 'scrub user </td>';}
                            else{                            
                            while($row2 = $query2->fetch_array()){
                                //print '<td align="center">';
                                print '<img src="images/'.@$row2['photo'].'" alt="makač 1 " class="img-circle img-size-32 mr-2" title="' . $row2['firstname'] . '  ' . $row2['lastname'] . '">';
                                print '</td>';
                            }                           
                        };                            
                        break;
                        case  'p':
                            $sql2 = "SELECT firstname, lastname, photo FROM employees WHERE id = '".$row['assign_p']."'";
                            $query2 = $conn->query($sql2); 
                        if($query2->num_rows === 0) {
                                print 'scrub user </td>';}
                            else{                            
                            while($row2 = $query2->fetch_array()){
                                //print '<td align="center">';
                                print '<img src="images/'.@$row2['photo'].'" alt="makač 1 " class="img-circle img-size-32 mr-2" title="' . $row2['firstname'] . '  ' . $row2['lastname'] . '">';
                                print '</td>';
                            }                           
                        };break;
                            case  's':
                                $sql2 = "SELECT firstname, lastname, photo FROM employees WHERE id = '".$row['assign_s']."'";
                                $query2 = $conn->query($sql2); 
                                if($query2->num_rows === 0) {
                                    print 'scrub user </td>';}
                                else{                            
                                while($row2 = $query2->fetch_array()){
                                //print '<td align="center">';
                                print '<img src="images/'.@$row2['photo'].'" alt="makač 1 " class="img-circle img-size-32 mr-2" title="' . $row2['firstname'] . '  ' . $row2['lastname'] . '">';
                                print '</td>';
                                }                           
                            };                            
                                break;
                    default: 
                    $photoSQL = "SELECT *, assign_g AS grafik FROM `orders_".$append."`LEFT JOIN employees ON employees.id=orders_".$append.".assign_g WHERE orders_".$append.".order_nr = '" .$row['order_nr'] ."'";
                    $photoquery = $conn->query($photoSQL); 
                    
                    while($photorow = $photoquery->fetch_array()){
                            if($photorow['grafik'] != '0'){
                                print '<img src="images/'.$photorow['photo'].'" alt="makač 1 " class="img-circle img-size-32 mr-2" title="'.$photorow['firstname'].' '.$photorow['lastname'].'">';
                            }
                            
                    } 
                    $photoSQL = "SELECT *, assign_p AS plastik FROM `orders_".$append."`LEFT JOIN employees ON employees.id=orders_".$append.".assign_p WHERE orders_".$append.".order_nr = '" .$row['order_nr'] ."'";
                    $photoquery = $conn->query($photoSQL); 
                   
                    while($photorow = $photoquery->fetch_array()){
                            if($photorow['plastik'] != '0'){
                                print '<img src="images/'.$photorow['photo'].'" alt="makač 1 " class="img-circle img-size-32 mr-2" title="'.$photorow['firstname'].' '.$photorow['lastname'].'">';
                            }
                            
                    }
                    $photoSQL = "SELECT *, assign_s AS sedlak FROM `orders_".$append."`LEFT JOIN employees ON employees.id=orders_".$append.".assign_s WHERE orders_".$append.".order_nr = '" .$row['order_nr'] ."'";
                    $photoquery = $conn->query($photoSQL); 
                    
                    while($photorow = $photoquery->fetch_array()){
                            if($photorow['sedlak'] != '0'){
                                print '<img src="images/'.$photorow['photo'].'" alt="makač 1 " class="img-circle img-size-32 mr-2" title="'.$photorow['firstname'].' '.$photorow['lastname'].'">';
                            }
                            
                    } 
                                                                 
                    }
                    print '</td>'; 

            //print '<td align="center"> '.$scrubuser.' </td>';

                print'<td align="center">'. $row['niceDate'] .' </td>';
                print'<td align="center"> '. $row['order_type'] .' </td>';

                
                    
                print '<td align="center"> '. $row['order_nr'].' </td>';
                print '<td align="center"> '. $row['gfp'].' </td>';
                print '<td align="center"> '. $row['country'].' </td>';
                print '<td align="center"> '. $row['courier'].' </td>';
                print '<td align="center"> '. $row['status'].'</td>';
                print '<td align="center"> '. $row['tracking'].'</td>';
                print '</td></tr>';
            }
            
                print '<tfoot>';
                print '<tr>';
                print'<th style="background-color:#444242; color:white; text-align: center;">SCRUB</th>';
                print'<th style="background-color:#444242; color:white; text-align: center;">DATE</th>';
                print'<th style="background-color:#444242; color:white; text-align: center;">SOURCE</th>';
                print'<th style="background-color:#444242; color:white; text-align: center;">ORDER NUMBER</th>';
                print'<th style="background-color:#444242; color:white; text-align: center;">ORDER TYPE</th>';
                print'<th style="background-color:#444242; color:white; text-align: center;">COUNTRY</th>';
                print'<th style="background-color:#444242; color:white; text-align: center;">COURIER</th>';
                print'<th style="background-color:#444242; color:white; text-align: center;">STATUS</th>';
                print'<th style="background-color:#444242; color:white; text-align: center;">TRACKING</th>';
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

