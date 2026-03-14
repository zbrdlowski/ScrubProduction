<?php
//session_start();
//$conn = new mysqli('localhost', 'root', '', 'scrubproduction');
//$conn = new mysqli('localhost', 'root', '123Admin456*', 'scrubproduction');
//include 'includes/conn.php';
switch ($_SESSION['dpt']) {
  case '2':
     $rows = $conn->query("SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE gfp LIKE '%G%' AND assign_g = '0' AND NOT status = 'Delivered' AND NOT status = 'Shipped' OR gfp LIKE '%T%' AND assign_g = '0' AND NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY date DESC");
    break;
    case '6':
      $rows = $conn->query("SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE gfp LIKE '%P%' AND assign_p = '0' AND NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY status DESC");
     break;
     case '8':
      $rows = $conn->query("SELECT *, DATE_FORMAT(date,'%d.%m.%Y') AS niceDate FROM orders_".$append." WHERE gfp LIKE '%S%' AND assign_s = '0' AND NOT status = 'Delivered' AND NOT status = 'Shipped' ORDER BY status DESC");
     break;
  
  default:
  $rows = $conn->query("SELECT *, DATE_FORMAT(date,'%d.%m.%y') AS niceDate FROM orders_".$append."  WHERE NOT status = 'Shipped' AND NOT status = 'Delivered' AND NOT status = 'In Process' ORDER BY status DESC");
    break;
}
//$rows = $conn->query("SELECT *, DATE_FORMAT(date,'%d.%m.%y') AS niceDate FROM orders_".$append."  WHERE NOT status = 'Shipped' AND NOT status = 'Delivered' AND NOT status = 'In Process' ORDER BY status DESC");
?>
<section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
              <h1>
              <? 
              switch ($_SESSION['dpt']) {
                case '2':
                  print 'Unasigned Graphics Orders';
                  break;
                  case '6':
                    print 'Unasigned Plastics Orders';
                    break;
                    case '8':
                      print 'Unasigned Seat Covers Orders';
                      break;
                      case '9':
                        print 'Unasigned Fittings';
                        break;
                
                default:                
                  print 'Unasigned Orders';                  
                  break;
              }
              ?></h1>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
<?

print '<style>';
      //print '#GFPS { Background-color:grey; color:white; }';
      print '#GFP { Background-color:#a1cbf0 color:black;}';
      //print '#GS { Background-color:grey; color:white; }';
      //print '#PS { Background-color:grey; color:white; }';
      print '#P { Background-color:SILVER; color:black;}';
      print '#G { Background-color:#deb887; color:black;}';
      //print '#S { Background-color:grey; color:white; }';
      print '#TFP { Background-color:#0fc7ae; color:white; }';
print '</style>';

// hlavička      
      print '<table id="example1" class="table table-bordered table-striped">';
      print '<thead>';
      print '<tr style="background-color:grey;color:white;">';
      print '<th>Action</th>';
      print '<th>Date</th>';
      print '<th>Source</th>';
      print '<th>GFP</th>';
      
      print '<th>Order No.</th>';
      print '<th>SKU</th>';
      //print '<th>Order Status</th>';
      print '<th>Customer</th>';                 
      print '<th>Country</th>';
      //print '<th>Courier</th>';                                           
      print '<th>Product</th>';
      //print '<th>Tracking</th>';
      //print '<th>Bike</th>';
      //print '<th>Model</th>';
      //print '<th>Year</th>';
                    
      print '</tr>';
      print '</thead>';
      print '<tbody>';

      //Výpis
 $i = 1; 
  foreach($rows as $row) : 
    //print'<tr id="'.$row["gfp"].'">';
    print'<tr>';
    if($row["status"] == 'Priority'){
    print '<td align="center"><a href="assign.php?orderno='.$row["order_nr"].'"><button type="button" class="btn btn-block bg-gradient-danger btn-sm"> Assign </button></a>';
    }else{
      print '<td align="center"><a href="assign.php?orderno='.$row["order_nr"].'"><button type="button" class="btn btn-block bg-gradient-primary btn-sm"> Assign </button></a>';  
    }
    print '<td>'.$row["niceDate"].'</td>';
    print '<td align="center">'.$row["order_type"].'</td>';
    print '<td align="center">'.$row["gfp"].'</td>';
    # zafarbenie ako v spreadsheete
    if($row["order_type"] == 'WEB'){print '<td align="center"><a href="index.php?page=modeldata&scrubcocode=YAD2"><button type="button" class="btn btn-block btn-outline-warning btn-sm">'.$row["order_nr"].'</button></a></td>';}
    elseif($row["order_type"] == 'eBay'){print '<td align="center"><a href="index.php?page=modeldata&scrubcocode=YAD2"><button type="button" class="btn btn-block btn-outline-danger btn-sm">'.$row["order_nr"].'</button></a></td>';}
    else{print '<td align="center"><a href="index.php?page=modeldata&scrubcocode=YAD2"><button type="button" class="btn btn-block btn-outline-secondary btn-sm">'.$row["order_nr"].'</button></a></td>';}
    print '<td>'.$row["sku"].'</td>';
    //print '<td>'.$row["status"].'</td>';
    print '<td align="center">'.$row["customer"].'</td>';  
    print '<td align="center">'.$row["country"].'</td>';
    //print '<td>'.$row["courier"].'</td>';
    print '<td>'.$row["product_name"].'</td>';
    //print '<td>'.$row["tracking"].'</td>';
    //print '<td>'.$row["bike"].'</td>';
    //print '<td>'.$row["model"].'</td>';
    //print '<td>'.$row["year"].'</td>';              


    print'</tr>';
  endforeach; 
print '</table>';
?>
 </div>
<!-- /.box-body -->
            </div>
        </div>
<!-- /.card-body -->
        </div>
    </div>
</section>