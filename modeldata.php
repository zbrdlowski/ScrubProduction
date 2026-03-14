<?
//$conn = mysqli_connect("localhost", "root", "123Admin456*", "scrubproduction");

print '<table id="example1" width="100%" class="table table-bordered">';
print'<th style="background-color:#444242; color:white;">Code</th>';
print '<tr>';
print '<td>';
print '<h1>Scrub Code</h1>';
//print $_SESSION['permission'];
print '<form method="get">';
print '<input type="hidden" name="page" value="modeldata">';
print '<input type="text" name="scrubcocode" size="30">&nbsp;';
print '<input type="submit" value="Go Search" name="search" />';
print '</form>';
print '</td>';
print '</tr>';
print '</table>';

// sem predel

print '            </div>';
print '        </div>';
print '    </div>';
print '</div>';
print '<div class="row">';
print '    <div class="col-xs-12">';
print '        <div class="box"> ';       
print '            <div class="box-body">';

print '<h1>Scrub Model Compatibility Chart </h1>';
# Zbrdlowski 2025 - July 24

if(isset($_GET['scrubcocode'])){
    $cocodsql = "SELECT DISTINCT *  FROM scrubdata WHERE modelcode = '". $_GET['scrubcocode'] ."'";
    $cocodquery = $conn->query($cocodsql);
    while($row = $cocodquery->fetch_array()){
        $scrubrand = $row['brand'];
        $scrubmodel = $row['model'];
        $scrubcode = $_GET['scrubcocode'];        
        $scrubrange = $row['rangeyear'];	    
        }
}else{

$scrubrand = $_REQUEST['brand'];
$scrubmodel = $_REQUEST['model'];
$scrubcode = $_REQUEST['code'];
$scrubrange = $_REQUEST['range'];
}

$scrubyear = $_REQUEST['year'];
/*
echo 'kód: '. $scrubcode . '<br />';
echo 'rok: '. $scrubyear . '<br />';
*/
// značka



print '<table id="example1" width="100%" class="table table-bordered">';
print'<th style="background-color:#444242; color:white;">BRAND</th>';
print'<th style="background-color:#444242; color:white;">MODEL</th>';
print'<th style="background-color:#444242; color:white;">RANGE</th>';
print'<th style="background-color:#444242; color:white;">YEAR</th>';
print '<tr>';
// značka
print '<td>';

print '<select class="form-control" id="brand" name="brand" onchange="this.options[this.selectedIndex].value && (window.location = this.options[this.selectedIndex].value);">';
$selectsql = "SELECT DISTINCT brand FROM scrubdata";
$selectquery = $conn->query($selectsql);
while($row = $selectquery->fetch_array()){
echo '<option hidden>Pick Brand</option>';
echo '<option value="home.php?page=modeldata&brand='.$row['brand'].'" '; if($row['brand'] == $scrubrand){print ' selected';} print'>'.$row['brand'].'</option>';		    
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
            $selectquery = $conn->query($selectsql);
            while($row = $selectquery->fetch_array()){
            echo '<option hidden>Pick Model</option>';
            echo '<option value="home.php?page=modeldata&brand='.$scrubrand.'&model='.$row['model'].'" '; if($row['model'] == $scrubmodel){print ' selected';} print'>'.$row['model'].'</option>';		    
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
            $selectsql = "SELECT DISTINCT rangeyear FROM scrubdata WHERE brand = '".$scrubrand."' AND model = '".$scrubmodel."'";
            $selectquery = $conn->query($selectsql);
            while($row = $selectquery->fetch_array()){
            echo '<option hidden>Pick Prod Year Range</option>';
            echo '<option value="home.php?page=modeldata&brand='.$scrubrand.'&model='.$scrubmodel.'&range='.$row['rangeyear'].'" '; if($row['rangeyear'] == $scrubrange){print ' selected';} print'>'.$row['rangeyear'].'</option>';		    
            }
            print '</select>'; 
}

print '</td>';

// konkrétny rok
print '<td>';

if(empty($scrubrange)){
    print '<div class="box-body"> Exact Year</div>';
}else{

        print '<select class="form-control" id="brand" name="brand" onchange="this.options[this.selectedIndex].value && (window.location = this.options[this.selectedIndex].value);">';
            $selectsql = "SELECT DISTINCT exactyear  FROM scrubdata WHERE brand = '".$scrubrand."' AND model = '".$scrubmodel."' AND rangeyear='".$scrubrange."'";
            $selectquery = $conn->query($selectsql);
            while($row = $selectquery->fetch_array()){
            echo '<option hidden>Pick Exact Prod Year</option>';
            echo '<option value="home.php?page=modeldata&brand='.$scrubrand.'&model='.$scrubmodel.'&range='.$scrubrange.'&year='.$row['exactyear'].'" '; if($row['exactyear'] == $scrubyear){print ' selected';} print'>'.$row['exactyear'].'</option>';		    
            }
            print '</select>'; 
}

print '</td>';

print '</tr>';
print '</table>';

// otický predel medzi blokmi
print '            </div>';
print '        </div>';
print '    </div>';
print '</div>';
print '<div class="row">';
print '    <div class="col-xs-12">';
print '        <div class="box"> ';       
print '            <div class="box-body">';

if(empty($year)){
    $sql = "SELECT * FROM scrubdata WHERE modelcode = '".$scrubcode."'";  
}else{
   $sql = "SELECT * FROM scrubdata WHERE brand = '".$scrubrand."' AND model = '".$scrubmodel."' AND exactyear = '".$scrubyear."'"; 
}

//$sql = "SELECT * FROM scrubdata LEFT JOIN scrubcompat ON scrubcompat.compatcode=scrubdata.modelcode WHERE scrubdata.modelcode = '".$scrubcode."'";
$query = $conn->query($sql);
print '<h3>Selected Model</h3>';
print '<table id="example1" width="100%" class="table table-bordered">';

print'<th style="background-color:#444242; color:white;">BRAND</th>';
print'<th style="background-color:#444242; color:white;">MODEL</th>';
print'<th style="background-color:#444242; color:white;">RANGE</th>';
print'<th style="background-color:#444242; color:white;">YEAR</th>';
print'<th style="background-color:#444242; color:white;">CODE</th>';

while($row = $query->fetch_array()){
    $scrubcode = $row['modelcode'];
    print '<tr><td>';
    print $row['brand'] .' </td><td> '. $row['model'].' </td><td> '. $row['exactyear'].' </td><td> '. $row['rangeyear'].' </td><td> '. $row['modelcode'].'</td>';
    print '</td></tr>';
}
print '</table>';

// detaily okolo zvoleného modelu

if(!empty($scrubcode)){

    print '<h2>  '. $scrubcode .' - '. $scrubrand .'  -  '. $scrubmodel .'  -  '. $scrubrange .'  -  '. $scrubyear .' </h2>';
    
    print '<div class="box-body">'; 
    print '<img src="https://api.qrserver.com/v1/create-qr-code/?color=000000&amp;bgcolor=FFFFFF&amp;data=' . $scrubcode . '&amp;qzone=1&amp;margin=0&amp;size=190x190&amp;ecc=L" alt="qr code" />';
    print '<a href="#" title="Designs for selectd bike"><img src=../images/bikez/bike.png style="width: 30em;"></a>';
    print '<a href="#" title="Seat cover layouts for cutting"><img src=../images/seatz/strih.png style="width: 30em;"></a>';
    print '<a href="#" title="Plastics Kits"><img src=../images/plasticz/kit.png style="width: 30em;"></a>';
    print '<a href="#" title="corel files"><img src=../images/corel.png style="width: 20em;"></a>';
    print '</div>';

    }

// otický predel medzi blokmi
print '            </div>';
print '        </div>';
print '    </div>';
print '</div>';
print '<div class="row">';
print '    <div class="col-xs-12">';
print '        <div class="box"> ';       
print '            <div class="box-body">';

if(empty($scrubyear)){
    $sql = "SELECT * FROM scrubcompat WHERE compatcode = '".$scrubcode."'";
}else{
    $sql = "SELECT * FROM scrubcompat WHERE compatcode = '".$scrubcode."' AND compatyear = '".$scrubyear."'";
}
//$sql = "SELECT * FROM scrubdata LEFT JOIN scrubcompat ON scrubcompat.compatcode=scrubdata.modelcode WHERE scrubdata.modelcode = '".$scrubcode."'";
$query = $conn->query($sql);

print '<h3>Compatible Models</h3>';
print '<table id="example1" width="100%" class="table table-bordered">';

print'<th style="background-color:#444242; color:white;">CODE</th>';
print'<th style="background-color:#444242; color:white;">BRAND</th>';
print'<th style="background-color:#444242; color:white;">MODEL</th>';
print'<th style="background-color:#444242; color:white;">YEAR</th>';

while($row = $query->fetch_array()){
    print '<tr><td>';
    print $row['1'] .' </td><td> '. $row['2'].' </td><td> '. $row['3'].' </td><td> '. $row['4'].'</td>';
    print '</td></tr>';
}
print '</table>';
?>