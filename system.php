<?php
$conn = new mysqli('localhost', 'root', '123Admin456*', 'scrubproduction');
$rows = $conn->query("SELECT *, DATE_FORMAT(date,'%d.%m.%y') AS niceDate FROM orders ORDER BY status DESC");

print '<style>';
      print '#GFP { Background-color:#a1cbf0 }';
      print '#P { Background-color:SILVER; }';
      print '#G { Background-color:#deb887; }';
      print '#S { Background-color:grey; color:white; }';
      print '#TFP { Background-color:#0fc7ae; color:white; }';
print '</style>';

// hlavička
      print '<h1>Scrub Order Management</h1>';
      print '<table id="example1" width="100%" class="table table-bordered">';
      print '<thead>';
      print '<tr style="background-color:grey;color:white;">';
      print '<th>Date</th>';
      print '<th>Source</th>';
      print '<th>GFP</th>';
      print '<th>Order No.</th>';
      print '<th>SKU</th>';
      print '<th>Order Status</th>';
      print '<th>Customer</th>';                 
      print '<th>Country</th>';
      print '<th>Courier</th>';                                           
      print '<th>Invoice</th>';
      print '<th>Tracking</th>';
      print '<th>Bike</th>';
      print '<th>Model</th>';
      print '<th>Year</th>';
                    
      print '</tr>';
      print '</thead>';
      print '<tbody>';

      //Výpis
 $i = 1; 
  foreach($rows as $row) : 
    print'<tr>';
    
    print '<td id="'.$row["gfp"].'">'.$row["niceDate"].'</td>';
    print '<td id="'.$row["gfp"].'">'.$row["order_type"].'</td>';
    print '<td id="'.$row["gfp"].'">'.$row["gfp"].'</td>';
    //print '<td id="'.$row['gfp'].'"><a href="includes/minimodal.php" target="popup" onclick="window.open('includes/minimodal.php','popup','width=600,height=600'); return false;"'.$row["order_nr"].'</a>';
    print '<td id="'.$row["gfp"].'">'.$row["order_nr"].'</td>';
    print '<td id="'.$row["gfp"].'">'.$row["sku"].'</td>';
    print '<td id="'.$row["gfp"].'">'.$row["status"].'</td>';
    print '<td id="'.$row["gfp"].'">'.$row["customer"].'</td>';  
    print '<td id="'.$row["gfp"].'">'.$row["country"].'</td>';
    print '<td id="'.$row["gfp"].'">'.$row["courier"].'</td>';
    print '<td id="'.$row["gfp"].'">'.$row["invoice"].'</td>';
    print '<td id="'.$row["gfp"].'">'.$row["tracking"].'</td>';
    print '<td id="'.$row["gfp"].'">'.$row["bike"].'</td>';
    print '<td id="'.$row["gfp"].'">'.$row["model"].'</td>';
    print '<td id="'.$row["gfp"].'">'.$row["year"].'</td>';              


    print'</tr>';
  endforeach; 
print '</table>';
?>
