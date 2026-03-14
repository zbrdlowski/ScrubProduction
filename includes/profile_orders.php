<section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
            <?

            $napis = 'Ordes'; 
                if(isset($_SESSION['dpt'])){
                    switch ($_SESSION['dpt']) {
                        case  '2': // 2 -> Grafika
                            $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE gfp LIKE '%G%' AND NOT status = 'Delivered' AND NOT status = 'Shipped' OR gfp LIKE '%T%' AND NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY orders_".$append.".date DESC";
                            $napis = 'Open Graphics Ordes';

                            break;
                            case '6': // 6-> Plasty
                                $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE gfp LIKE '%P%' AND NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY orders_".$append.".date DESC";
                                $napis = 'Open Plastics Ordes';

                                break;
                                case  '8': // 8 -> Seat Covers
                                    $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE gfp LIKE '%S%' AND NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY orders_".$append.".date DESC";
                                    $napis = 'Open Seat Covers Ordes';
                                    break;
                                    case  '4': // treba urobiť sekciu pre fitting, 4 je Production
                                        $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE gfp LIKE '%F%' AND NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY orders_".$append.".date DESC";
                                        $napis = 'Open Fittings';
                                        break;
                        default:
                        $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY orders_".$append.".date DESC";
                        $napis = 'Ordes'; 
                            break;
                    }
                }else{
                    $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY orders_".$append.".date DESC";
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
            print '<table id="example2" class="table table-bordered table-striped">';
            print '<thead>';
                print'<tr>';
                print'<th width="3%" style="background-color:#444242; color:white; text-align: center;">DATE</th>';    
                print'<th width="3%" style="background-color:#444242; color:white; text-align: center;">ASSIGN</th>';
                print'<th width="8%" style="background-color:#444242; color:white; text-align: center;">SCRUB</th>';
                print'<th width="1%" style="background-color:#444242; color:white; text-align: center;">COUNTRY</th>';            
                print'<th width="3%" style="background-color:#444242; color:white; text-align: center;">SOURCE</th>';
                print'<th width="10%" style="background-color:#444242; color:white; text-align: center;">ORDER Nr.</th>';
                print'<th width="3%" style="background-color:#444242; color:white; text-align: center;">TYPE</th>';                
                print'<th style="background-color:#444242; color:white; text-align: center;">PRODUCT</th>'; 
                print'<th width="5%" style="background-color:#444242; color:white; text-align: center;">COURIER</th>';
                print'<th width="8%" style="background-color:#444242; color:white; text-align: center;">STATUS</th>';
            
            print '</tr>';
                print '</thead>';
                print '<tbody>';
            while($row = $query->fetch_array()){
                
            print '<tr>'; 
            print '<td  width="5%" align="center">'. $row['niceDate'] .' </td>';
            print '<td width="5%" align="center">';
             switch ($_SESSION['dpt']) {
                case '2':
                    if($row['assign_g'] == '0'){
                        if($row["status"] == 'Priority'){
                            print '<a href="assign.php?orderno='.$row["order_nr"].'"><button type="button" class="btn btn-block bg-gradient-danger btn-sm"> Assign </button></a>';
                            }else{
                              print '<a href="assign.php?orderno='.$row["order_nr"].'"><button type="button" class="btn btn-block bg-gradient-primary btn-sm"> Assign </button></a>';  
                            }
                    }else{
                        print '<button type="button" class="btn btn-block bg-gradient-secondary btn-sm disabled"> Assign </button>';
                    }
                    break;
                        case '6':
                            if($row['assign_p'] == '0'){
                                if($row["status"] == 'Priority'){
                                    print '<a href="assign.php?orderno='.$row["order_nr"].'"><button type="button" class="btn btn-block bg-gradient-danger btn-sm"> Assign </button></a>';
                                    }else{
                                      print '<a href="assign.php?orderno='.$row["order_nr"].'"><button type="button" class="btn btn-block bg-gradient-primary btn-sm"> Assign </button></a>';  
                                    }
                            }else{
                                print '<button type="button" class="btn btn-block bg-gradient-secondary btn-sm disabled"> Assign </button>';
                            }
                        break;
                            case '8':
                                if($row['assign_s'] == '0'){
                                    if($row["status"] == 'Priority'){
                                        print '<a href="assign.php?orderno='.$row["order_nr"].'"><button type="button" class="btn btn-block bg-gradient-danger btn-sm"> Assign </button></a>';
                                        }else{
                                          print '<a href="assign.php?orderno='.$row["order_nr"].'"><button type="button" class="btn btn-block bg-gradient-primary btn-sm"> Assign </button></a>';  
                                        }
                                }else{
                                    print '<button type="button" class="btn btn-block bg-gradient-secondary btn-sm disabled"> Assign </button>';
                                }
                                break;
                
                default:
                    print ' ';
                    break;
             }
            print'</td>';
            print '<td align="center" width="8%">';
                
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
                                                                 
                   
                    print '</td>'; 

            //print '<td align="center"> '.$scrubuser.' </td>';

                print '<td align="center"> '. $row['country'].' </td>';
                print'<td align="center"> '. $row['order_type'] .' </td>';  
                print '<td align="center"> '. $row['order_nr'].' </td>';
                print '<td align="center"> '. $row['gfp'].' </td>';
                print '<td align="left"> '. $row['product_name'].' </td>';  
                print '<td align="center"> '. $row['courier'].' </td>';
                print '<td align="center"> '. $row['status'].'</td>';                
                print '</td></tr>';
            }
            
                print '<tfoot>';
                print '<tr>';
                print'<th width="3%" style="background-color:#444242; color:white; text-align: center;">DATE</th>';    
                print'<th width="3%" style="background-color:#444242; color:white; text-align: center;">ASSIGN</th>';
                print'<th width="8%" style="background-color:#444242; color:white; text-align: center;">SCRUB</th>';
                print'<th width="1%" style="background-color:#444242; color:white; text-align: center;">COUNTRY</th>';            
                print'<th width="3%" style="background-color:#444242; color:white; text-align: center;">SOURCE</th>';
                print'<th width="10%" style="background-color:#444242; color:white; text-align: center;">ORDER Nr.</th>';
                print'<th width="3%" style="background-color:#444242; color:white; text-align: center;">TYPE</th>';                
                print'<th style="background-color:#444242; color:white; text-align: center;">PRODUCT</th>'; 
                print'<th width="5%" style="background-color:#444242; color:white; text-align: center;">COURIER</th>';
                print'<th width="8%" style="background-color:#444242; color:white; text-align: center;">STATUS</th>';
                
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

