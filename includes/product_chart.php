<style>
#example1 tbody tr:hover {

    background-color: #3c759e !important;
    transition: background-color 0.2s ease;
    cursor: pointer;
}
</style>
<?
$_SESSION['uri'] = $_SERVER['REQUEST_URI'];
if(isset($_GET['scrubcocode'])){
    $cocodsql = "SELECT DISTINCT *  FROM scrubdata WHERE modelcode = '". $_GET['scrubcocode'] ."'";
    $cocodquery = $conn->query($cocodsql);
    while($row = $cocodquery->fetch_array()){
        $scrubrand = $row['brand'];
        $scrubmodel = $row['model'];
        $scrubcode = $_GET['scrubcocode'];        
        $scrubrange = $row['rangeyear'];
        $graphics = $row['graphics'];
        $plastics = $row['plastics'];
        $seat_cover = $row['seat_cover'];	    
        }
}else{
@$scrubrand = $_REQUEST['brand'];
@$scrubmodel = $_REQUEST['model'];
@$scrubcode = $_REQUEST['code'];
@$scrubrange = $_REQUEST['range'];
}
@$scrubyear = $_REQUEST['year'];

if (empty($scrubrand)){$nadpis = 'Select Brand';}else{
   if (empty($scrubmodel)){$nadpis = 'Select Model';}else{
     if (empty($scrubrange)){$nadpis = 'Select Production Years';}else{
        $nadpis = 'Selected Result:';
     }
   } 
}

?>
<script>
    $(document).ready(function() {
    // Setup - add a text input to each footer cell
    $('#example tfoot th').each( function () {
        var title = $('#example thead th').eq( $(this).index() ).text();
        $(this).html( '<input type="text" placeholder="Search '+title+'" />' );
    } );
 
    // DataTable
    var table = $('#example').DataTable();
 
    // Apply the search
    table.columns().every( function () {
        var that = this;
 
        $( 'input', this.footer() ).on( 'keyup change', function () {
            that
                .search( this.value )
                .draw();
        } );
    } );
} );
</script>
<section class="content">

<!-- /.card -->

 <div class="card">
<div class="card-header">
<h3 class="card-title"><? echo $nadpis; ?></h3>
</div>
<!-- /.card-header -->
<div class="card-body">

<?
print '<table width="100%" class="table table-bordered">';
print'<th style="background-color:#444242; color:white;">BRAND</th>';
print'<th style="background-color:#444242; color:white;">MODEL</th>';
print'<th style="background-color:#444242; color:white;">RANGE</th>';
//print'<th style="background-color:#444242; color:white;">YEAR</th>';
print'<th style="background-color:#444242; color:white;">RESET</th>';
print '<tr>';
// select naznačku
print '<td>';

print '<select class="form-control" id="brand" name="brand" onchange="this.options[this.selectedIndex].value && (window.location = this.options[this.selectedIndex].value);">';
$selectsql = "SELECT DISTINCT brand FROM scrubdata ORDER BY brand ASC";
$sql2 = "SELECT DISTINCT brand, model, rangeyear, modelcode, graphics, plastics, seat_cover, web_g, web_p, web_s FROM scrubdata ORDER BY brand ASC"; 
$selectquery = $conn->query($selectsql);
while($row = $selectquery->fetch_array()){
echo '<option hidden>Pick Brand</option>';
echo '<option value="index.php?page=product_chart&brand='.$row['brand'].'" '; if($row['brand'] == $scrubrand){print ' selected';} print'>'.$row['brand'].'</option>';		    
}
print '</select>';

print '</td>';

// model
print '<td>';
if(empty($scrubrand)){
    print '<div class="box-body"> Model</div>';
}else{

        print '<select class="form-control" id="brand" name="brand" onchange="this.options[this.selectedIndex].value && (window.location = this.options[this.selectedIndex].value);">';
            $selectsql = "SELECT DISTINCT model FROM scrubdata WHERE brand = '".$scrubrand."'";
            // query na hlavnu tabulku
            $sql2 = "SELECT DISTINCT brand, model, rangeyear, modelcode, graphics, plastics, seat_cover, web_g, web_p, web_s FROM scrubdata WHERE brand = '".$scrubrand."' ORDER BY rangeyear DESC"; 
            $selectquery = $conn->query($selectsql);
            while($row = $selectquery->fetch_array()){
            echo '<option hidden>Pick Model</option>';
            echo '<option value="index.php?page=product_chart&brand='.$scrubrand.'&model='.$row['model'].'" '; if($row['model'] == $scrubmodel){print ' selected';} print'>'.$row['model'].'</option>';		    
            }
            print '</select>'; 
}
print '</td>';

// rozsah rokov
print '<td>';

if(empty($scrubmodel)){
    print '<div class="box-body"> Production Years</div>';
}else{

        print '<select class="form-control" id="brand" name="brand" onchange="this.options[this.selectedIndex].value && (window.location = this.options[this.selectedIndex].value);">';
            $selectsql = "SELECT DISTINCT rangeyear FROM scrubdata WHERE brand = '".$scrubrand."' AND model = '".$scrubmodel."' ORDER BY rangeyear DESC";
            // query na hlavnu tabulku
            $sql2 = "SELECT DISTINCT brand, model, rangeyear, modelcode, graphics, plastics, seat_cover, web_g, web_p, web_s FROM scrubdata WHERE brand = '".$scrubrand."' AND model = '".$scrubmodel."' ORDER BY rangeyear DESC";
            $selectquery = $conn->query($selectsql);
            while($row = $selectquery->fetch_array()){
            echo '<option hidden>Pick Prod Year Range</option>';
            echo '<option value="index.php?page=product_chart&brand='.$scrubrand.'&model='.$scrubmodel.'&range='.$row['rangeyear'].'" '; if($row['rangeyear'] == $scrubrange){print ' selected';} print'>'.$row['rangeyear'].'</option>';		    
            }
            print '</select>'; 
}

print '</td>';

// konkrétny rok



if(!empty($scrubrange)){
 $sql2 = "SELECT DISTINCT brand, model, rangeyear, modelcode, graphics, plastics, seat_cover, web_g, web_p, web_s FROM scrubdata WHERE brand = '".$scrubrand."' AND model='".$scrubmodel."' AND rangeyear='".$scrubrange."' ORDER BY rangeyear DESC";
   
}


if(empty($scrubrand)){
    print '<td> <button type="button" class="btn btn-block bg-gradient-secondary btn-sm disabled"> RESET </button></td>';
}else{
    print '<td> <a href="?page=product_chart"><button type="button" class="btn btn-block bg-gradient-primary btn-sm"> RESET </button></a></td>';
}
print '</tr>';
?>
</table>
</div>
<!-- /.card-body -->
</div>
<!-- /.card -->

 <div class="card">
<div class="card-header">
<h3 class="card-title">Results from Scrub Database</h3>
</div>
<!-- /.card-header -->
<div class="card-body">
<?



print '</table>';
 //$sql2 = "SELECT DISTINCT brand, model, rangeyear, modelcode, graphics, plastics, seat_cover FROM scrubdata"; 
//$sql = "SELECT * FROM scrubdata LEFT JOIN scrubcompat ON scrubcompat.compatcode=scrubdata.modelcode WHERE scrubdata.modelcode = '".$scrubcode."'";
$query = $conn->query($sql2);
//print '<h3>Selected Model</h3>';
print '<table id="example1" width="100%" class="table table-bordered">';
print '<thead>';
print '<tr>';
print'<th style="background-color:#444242; color:white;">BRAND</th>';
print'<th style="background-color:#444242; color:white;">MODEL</th>';
print'<th style="background-color:#444242; color:white;">RANGE</th>';
print'<th style="background-color:#444242; color:white;text-align:center;">CODE</th>';
print'<th style="background-color:#444242; color:white;text-align:center;">GRAPHICS</th>';
print'<th style="background-color:#444242; color:white;text-align:center;">WEB G</th>';
print'<th style="background-color:#444242; color:white;text-align:center;">PLASTICS</th>';
print'<th style="background-color:#444242; color:white;text-align:center;">WEB P</th>';
print'<th style="background-color:#444242; color:white;text-align:center;">SEAT COVER</th>';
print'<th style="background-color:#444242; color:white;text-align:center;">WEB S</th>';
print'<th style="background-color:#444242; color:white;text-align:center;">EDIT</th>';
print '</tr>';
print '</thead>';

while($row = $query->fetch_array()){
    $scrubcode = $row['modelcode'];

    $graphics_web = ($row['graphics'] == 'yes' && $row['web_g'] == 'yes')
        ? '<i class="text-success fa-lg web-status-ok">✔</i>'
        : '<i class="text-danger fa-lg web-status-no">✘</i>';

    $plastics_web = ($row['plastics'] == 'yes' && $row['web_p'] == 'yes')
        ? '<i class="text-success fa-lg web-status-ok">✔</i>'
        : '<i class="text-danger fa-lg web-status-no">✘</i>';

    $plastics_web = ($row['plastics'] == 'yes' && $row['web_p'] == 'yes')
        ? '<i class="text-success fa-lg web-status-ok">✔</i>'
        : '<i class="text-danger fa-lg web-status-no">✘</i>';

    $seat_web = ($row['seat_cover'] == 'yes' && $row['web_s'] == 'yes')
        ? '<i class="text-success fa-lg web-status-ok">✔</i>'
        : '<i class="text-danger fa-lg web-status-no">✘</i>';

    print '<tr>';

    print '<td class="text-center">'. $row['brand'] .'</td>';
    print '<td>'. $row['model'].'</td>';
    print '<td>'. $row['rangeyear'].'</td>';
    print '<td class="text-center">'. $row['modelcode'].'</td>';

    # GRAPHICS
    if($row['graphics'] == 'no'){
        print '<td class="text-center">
                <button type="button" class="btn btn-block bg-gradient-danger btn-sm disabled">NO</button>
               </td>';
    } else {
        print '<td class="text-center">
                <a href="#">
                    <button type="button" class="btn btn-block bg-gradient-success btn-sm">YES</button>
                </a>
               </td>';
    }
    print '<td class="text-center align-middle">'.$graphics_web.'</td>';

    # PLASTICS
    if($row['plastics'] == 'no'){
        print '<td class="text-center">
                <button type="button" class="btn btn-block bg-gradient-danger btn-sm disabled">NO</button>
               </td>';
    } else {
        print '<td class="text-center">
                <a href="index.php?page=scrublistings&modelcode='.$scrubcode.'">
                    <button type="button" class="btn btn-block bg-gradient-success btn-sm">YES</button>
                </a>
               </td>';
    }
    print '<td class="text-center align-middle">'.$plastics_web.'</td>';

    # SEAT COVER
    if($row['seat_cover'] == 'no'){
        print '<td class="text-center">
                <button type="button" class="btn btn-block bg-gradient-danger btn-sm disabled">NO</button>
               </td>';
    } else {
        print '<td class="text-center">
                <a href="#">
                    <button type="button" class="btn btn-block bg-gradient-success btn-sm">YES</button>
                </a>
               </td>';
    }
    print '<td class="text-center align-middle">'.$seat_web.'</td>';

    print '<td>
            <a href="index.php?page=modeldata_edit&scrubcocode='.$row["modelcode"].'&model='.$row["model"].'">
                <button type="button" class="btn btn-block bg-gradient-primary btn-sm">Edit</button>
            </a>
           </td>';

    print '</tr>';
}

print '<thead>';
print '<tr>';
print'<th style="background-color:#444242; color:white;">BRAND</th>';
print'<th style="background-color:#444242; color:white;">MODEL</th>';
print'<th style="background-color:#444242; color:white;">RANGE</th>';
print'<th style="background-color:#444242; color:white;">CODE</th>';
print'<th style="background-color:#444242; color:white;">GRAPHICS</th>';
print'<th style="background-color:#444242; color:white;">WEB G</th>';
print'<th style="background-color:#444242; color:white;">PLASTICS</th>';
print'<th style="background-color:#444242; color:white;">WEB P</th>';
print'<th style="background-color:#444242; color:white;">SEAT COVER</th>';
print'<th style="background-color:#444242; color:white;">WEB S</th>';
print'<th style="background-color:#444242; color:white;">EDIT</th>';
print '</tr>';
print '</thead>';
print '</table>';

// detaily okolo zvoleného modelu

// otický predel medzi blokmi
?>
</div>
<!-- /.card-body -->
</div>
<!-- /.card-body -->
</div>
</section>