<section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
            <?
            $napis = 'Ordes'; 
                if(isset($_GET['status'])){
                    switch ($_GET['status']) {
                        case  'nok':
                            $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%y') AS niceDate FROM orders_".$append." WHERE status = 'Shipped' ORDER BY date DESC";
                            $napis = 'Shipped Orders';
                            $color = 'Black';
                            break;
                            case 'ok':
                                $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%y') AS niceDate FROM orders_".$append." WHERE status = 'Delivered' ORDER BY date DESC";
                                $napis = 'Delivered Ordes';
                                $color = 'White';
                                break;                                
                        default:
                        $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%y') AS niceDate FROM orders_".$append." WHERE status = 'Shipped' OR status = 'Delivered' ORDER BY date DESC";
                        $napis = 'Delivered Orders'; 
                        $color = 'White';
                            break;
                    }
                }else{
                    $sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%y') AS niceDate FROM orders_".$append." ORDER BY date DESC"; 
                }
                ?>
                <h1><? echo $napis; ?></h1>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
             
            <?
           //print '<style>';          
           //print '#Shipped { Background-color:#0fc7ae; color:black;}';
           //print '#Delivered { Background-color:darkgreen; color:white; }';
            //print '</style>';
            //$sql = "SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders ORDER BY status DESC"; 
            //$rows = $conn->query("SELECT *, DATE_FORMAT(date,'%d.%m.%y') AS niceDate FROM orders ORDER BY status DESC");
            //<td id="'.$row["gfp"].'">            
            
            $query = $conn->query($sql);            
            print '
                <table id="example1" class="table table-bordered table-striped">';
            print '<thead>';
                print'<tr>';
            print'<th style="background-color:#444242; color:white; text-align: center;">DATE</th>';
            print'<th style="background-color:#444242; color:white; text-align: center;">NAME</th>';
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
                
                print '<tr id="'.$row["status"].'"><td align="center">';
                print $row['niceDate'] .' </td><td align="center"> '. $row['customer'] .' </td><td align="center"> '. $row['order_nr'].' </td><td align="center"> '. $row['gfp'].' </td><td align="center"> '. $row['country'].' </td><td align="center"> '. $row['courier'].' </td><td align="center"> '. $row['status'].'</td><td align="center"><a target="top" href="https://www.fedex.com/wtrk/track/?trknbr='. $row['tracking'].'"> '. $row['tracking'].'</a></td>';
                print '</td></tr>';
            }
            
                print '<tfoot>';
                print '<tr>';
                print'<th style="background-color:#444242; color:white; text-align: center;">DATE</th>';
                print'<th style="background-color:#444242; color:white; text-align: center;">NAME</th>';
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
