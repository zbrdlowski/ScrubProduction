    <?
    // ✅ Render the table
    echo '<div class="table-responsive" style="overflow-x:auto;">';
    echo '<table id="example6" class="table table-bordered table-striped" style="width:100%;">';
    echo '<thead><tr style="background-color:#333940;">';
    echo '<th>ACTION</th><th>ADD</th><th>PART NUMBER</th><th>Brand</th><th>SCRUBCODE</th><th>MODEL</th><th>PART</th><th>COLOR</th><th>QUANTITY</th><th>ORDERED</th><th>OPT</th><th>MOQ</th><th>SUPPLIER</th><th>UFO P/N</th><th>UFO CODE</th><th>RT P/N</th><th>RT CODE</th><th>PS P/N</th><th>PS CODE</th><th>AC P/N</th><th>AC CODE</th><th>OTHER P/N</th><th>OTHER CODE</th>';
    echo '</tr></thead><tbody>';

    while ($row = $query->fetch_array()) {
        echo '<tr>';
        echo '<td><form method="get" action="index.php" style="margin:0;"><input type="hidden" name="page" value="edit_item"><input type="hidden" name="id" value="'.$row['id'].'"><button type="submit" class="btn btn-sm btn-warning">Edit</button></form></td>';
        echo '<td>
        <button class="btn btn-sm btn-primary addToOrderBtn"
            data-barcode="'.$row['barcode'].'"
            data-name="'.htmlspecialchars($row['name']).'"
            data-color="'.htmlspecialchars($row['color']).'"
            data-brand="'.htmlspecialchars($row['brand']).'"
            data-description="'.htmlspecialchars($row['description']).'">
            Add To Order
        </button>
      </td>';
        echo '<td>'.$row['barcode'].'</td>';
        echo '<td>'.$row['brand'].'</td>';
        echo '<td>'.$row['scrubcode'].'</td>';
        echo '<td>'.$row['name'].'</td>';
        echo '<td>'.$row['description'].'</td>';
        echo '<td>'.$row['color'].'</td>';
        echo '<td align="center">'.$row['quantity'].'</td>';
        echo '<td align="center" title="Order No: '.$row['order_number'].'">'.$row['quantity_sent'].'</td>';
        echo '<td align="center">'.$row['optimum'].'</td>';
        echo '<td align="center">'.$row['moq'].'</td>';
        echo '<td>'.$row['main_supplier'].'</td>';
        echo '<td>'.$row['ufo_pn'].'</td>';
        echo '<td>'.$row['ufo_barcode'].'</td>';
        echo '<td>'.$row['rt_pn'].'</td>';
        echo '<td>'.$row['rt_barcode'].'</td>';
        echo '<td>'.$row['ps_pn'].'</td>';
        echo '<td>'.$row['ps_barcode'].'</td>';
        echo '<td>'.$row['ac_pn'].'</td>';
        echo '<td>'.$row['ac_barcode'].'</td>';
        echo '<td>'.$row['other_pn'].'</td>';
        echo '<td>'.$row['other_barcode'].'</td>';
        echo '</tr>';
        echo '</table>';
        echo '</div>';
    }
        ?>