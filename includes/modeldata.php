<section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">Scrub Copmatibility Code</h3>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
<?
//$conn = mysqli_connect("localhost", "root", "123Admin456*", "scrubproduction");

print '<table id="example1" width="100%" class="table table-bordered">';
print '<tr>';
print'<th style="background-color:#444242; color:white;">Code</th>';
print'<th style="background-color:#444242; color:white;">Search</th>';
print '</tr>';
print '<tr>';
print '<td style="width:20em;">';
//print '<h1>Scrub Code</h1>';
//print $_SESSION['permission'];
print '<form method="get">';
print '<input type="hidden" name="page" value="modeldata">';
print '<input type="text" class="form-control" type="text" name="scrubcocode" d="scrubcode" placeholder="Enter Scrub Code">&nbsp;';
print '</td>';
print '<td>';
print '<input type="submit" value="Go Search" name="search" />';
print '</form>';
print '</td>';
print '</tr>';
print '</table>';

// sem predel
?>

</div>
<!-- /.card-body -->
</div>
<!-- /.card -->

 <div class="card">
<div class="card-header">
<h3 class="card-title">Select values from Scrub Database</h3>
</div>
<!-- /.card-header -->
<div class="card-body">
<?
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

@$scrubrand = $_REQUEST['brand'];
@$scrubmodel = $_REQUEST['model'];
@$scrubcode = $_REQUEST['code'];
@$scrubrange = $_REQUEST['range'];
}

@$scrubyear = $_REQUEST['year'];

echo '<div class="info-box shadow">';
 echo '<span class="info-box-icon bg-warning"><i class="fa fa-motorcycle"></i></span>';
  echo '<div class="info-box-content">';
    echo '<h3><span class="info-box-text">';
     if ($scrubcode != '') echo $scrubcode . ' - ';  
     if (isset( $scrubrand)) echo $scrubrand;
     if (isset($scrubmodel)) echo  ' - ' .$scrubmodel;
     if (isset($scrubrange)) echo ' - ' .$scrubrange;
     if (isset($scrubyear)) echo  ' - ' . $scrubyear;     
     
    print '</span></h3>';       
  echo '</div>';
echo '</div>';

// značka

print '<table id="example1" width="100%" class="table table-bordered">';
print '<thead>';
print '<tr>';
print'<th style="background-color:#444242; color:white;">BRAND</th>';
print'<th style="background-color:#444242; color:white;">MODEL</th>';
print'<th style="background-color:#444242; color:white;">RANGE</th>';
print'<th style="background-color:#444242; color:white;">YEAR</th>';
print '</tr>';
print '</thead>';
print '<tbody>';
print '<tr>';
// značka
print '<td>';

print '<select class="form-control" id="brand" name="brand" onchange="this.options[this.selectedIndex].value && (window.location = this.options[this.selectedIndex].value);">';
$selectsql = "SELECT DISTINCT brand FROM scrubdata ORDER BY brand ASC";
$selectquery = $conn->query($selectsql);
while($row = $selectquery->fetch_array()){
echo '<option hidden>Pick Brand</option>';
echo '<option value="index.php?page=modeldata&brand='.$row['brand'].'" '; if($row['brand'] == $scrubrand){print ' selected';} print'>'.$row['brand'].'</option>';		    
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
            echo '<option value="index.php?page=modeldata&brand='.$scrubrand.'&model='.$row['model'].'" '; if($row['model'] == $scrubmodel){print ' selected';} print'>'.$row['model'].'</option>';		    
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
            echo '<option value="index.php?page=modeldata&brand='.$scrubrand.'&model='.$scrubmodel.'&range='.$row['rangeyear'].'" '; if($row['rangeyear'] == $scrubrange){print ' selected';} print'>'.$row['rangeyear'].'</option>';		    
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
            $selectsql = "SELECT DISTINCT exactyear, modelcode  FROM scrubdata WHERE brand = '".$scrubrand."' AND model = '".$scrubmodel."' AND rangeyear='".$scrubrange."'";
            $selectquery = $conn->query($selectsql);
            while($row = $selectquery->fetch_array()){
                $scrubcode = $row['modelcode'];  
            echo '<option hidden>Pick Exact Production Year</option>';
            echo '<option value="index.php?page=modeldata&brand='.$scrubrand.'&model='.$scrubmodel.'&range='.$scrubrange.'&year='.$row['exactyear'].'&scrubcocode='.$scrubcode.'" '; if($row['exactyear'] == $scrubyear){print ' selected';} print'>'.$row['exactyear'].'</option>';		    
            }
            print '</select>'; 
}

print '</td>';

print '</tr>';
print '</tbody>';

if($scrubrange != ''){
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
    <div class="row">
        
          <div class="col-md-3 col-sm-6 col-12">
            <a href ="#">
            <div class="info-box bg-gradient-info">
              <span class="info-box-icon"><i class="fa fa-motorcycle"></i></span>

              <div class="info-box-content">
                <span class="info-box-text">Graphics Files</span>
                <span class="info-box-number">Blah Blah</span>

                <div class="progress">
                  <div class="progress-bar" style="width: 95%"></div>
                </div>
                <span class="progress-description">
                  95% Of Something
                </span>
              </div>
              <!-- /.info-box-content -->
            </div>
            </a>
            <!-- /.info-box -->
          </div>
       
          <!-- /.col -->
          <div class="col-md-3 col-sm-6 col-12">
            <a href ="index.php?page=general_items&scrubcode=<? echo $scrubcode ?>">
            <div class="info-box bg-gradient-success">
              <span class="info-box-icon"><i class="fa fa-motorcycle"></i></span>

              <div class="info-box-content">
                <span class="info-box-text">Plastics Stock Of This Model</span>
                <span class="info-box-number">Lorem Ipsum</span>

                <div class="progress">
                  <div class="progress-bar" style="width: 70%"></div>
                </div>
                <span class="progress-description">
                  70% Of Something Else
                </span>
              </div>
              <!-- /.info-box-content -->
            </div>
            </a>
            <!-- /.info-box -->
          </div>
          <!-- /.col -->
          <div class="col-md-3 col-sm-6 col-12">
            <a href ="#">
            <div class="info-box bg-gradient-warning">
              <span class="info-box-icon"><i class="fa fa-motorcycle"></i></span>

              <div class="info-box-content">
                <span class="info-box-text">Seat Cover Files</span>
                <span class="info-box-number">Dolor Sit Amet</span>

                <div class="progress">
                  <div class="progress-bar" style="width: 30%"></div>
                </div>
                <span class="progress-description">
                  30% Of Something Different
                </span>
              </div>
              <!-- /.info-box-content -->
            </div>
            </a>
            <!-- /.info-box -->
          </div>
          <!-- /.col -->
          <div class="col-md-3 col-sm-6 col-12">
            <a href ="#">
            <div class="info-box bg-gradient-danger">
              <span class="info-box-icon"><i class="fa fa-motorcycle"></i></span>

              <div class="info-box-content">
                <span class="info-box-text">Orders Of This Model</span>
                <span class="info-box-number">Repanoma Larum Tox Isid</span>

                <div class="progress">
                  <div class="progress-bar" style="width: 60%"></div>
                </div>
                <span class="progress-description">
                 60% Of Something Completely Different
                </span>
              </div>
              <!-- /.info-box-content -->
            </div>
            </a>
            <!-- /.info-box -->
          </div>
          <!-- /.col -->
        </div>
        <!-- /.row -->
<?}

if(empty($_GET['year'])){
    $sql2 = "SELECT * FROM scrubdata WHERE modelcode = '".$scrubcode."' ORDER BY exactyear ASC";  
}else{
   $sql2 = "SELECT * FROM scrubdata WHERE brand = '".$scrubrand."' AND model = '".$scrubmodel."' AND exactyear = '".$scrubyear."' ORDER BY exactyear ASC"; 
}

//$sql = "SELECT * FROM scrubdata LEFT JOIN scrubcompat ON scrubcompat.compatcode=scrubdata.modelcode WHERE scrubdata.modelcode = '".$scrubcode."'";
$query = $conn->query($sql2);
//print '<h3>Selected Model</h3>';
print '<table id="example2" width="100%" class="table table-bordered">';
print '<thead>';
print '<tr>';
print'<th style="background-color:#444242; color:white;">CODE</th>';
print'<th style="background-color:#444242; color:white;">BRAND</th>';
print'<th style="background-color:#444242; color:white;">MODEL</th>';
print'<th style="background-color:#444242; color:white;">YEAR</th>';
print'<th style="background-color:#444242; color:white;">RANGE</th>';
print '</tr>';
print '</thead>';
print '<tbody>';

while($row = $query->fetch_array()){
    $scrubcode = $row['modelcode'];
    print '<tr>';
    print '<td>'.$row['modelcode'].'</td><td>'. $row['brand'] .' </td><td> '. $row['model'].' </td><td> '. $row['exactyear'].' </td><td> '. $row['rangeyear'].' </td>';
    print '</tr>';
}
print '</tbody>';
print '</table>';

// detaily okolo zvoleného modelu

print '</div>';

print '</div>';


print '<div class="card">';
print '<div class="card-header">';
print '<h3 class="card-title">Compatible Models from Scrub Database</h3>';
print '</div>';

print '<div class="card-body">';
// optický predel medzi blokmi


if(empty($scrubyear)){
    $sql = "SELECT * FROM scrubcompat WHERE compatcode = '".$scrubcode."' ORDER BY compatyear ASC";
}else{
    $sql = "SELECT * FROM scrubcompat WHERE compatcode = '".$scrubcode."' AND compatyear = '".$scrubyear."' ORDER BY compatyear ASC";
}
//$sql = "SELECT * FROM scrubdata LEFT JOIN scrubcompat ON scrubcompat.compatcode=scrubdata.modelcode WHERE scrubdata.modelcode = '".$scrubcode."'";
$query = $conn->query($sql);


//print '<h3>Compatible Models</h3>';
print '<table id="example1" width="100%" class="table table-bordered">';
print '<thead>';
print '<tr>';
print'<th style="background-color:#444242; color:white;">CODE</th>';
print'<th style="background-color:#444242; color:white;">BRAND</th>';
print'<th style="background-color:#444242; color:white;">MODEL</th>';
print'<th style="background-color:#444242; color:white;">YEAR</th>';
print '</tr>';
print '</thead>';
print '<tbody>';
while($row = $query->fetch_array()){
    print '<tr><td>';
    print $row['1'] .' </td><td> '. $row['2'].' </td><td> '. $row['3'].' </td><td> '. $row['4'].'</td>';
    print '</td></tr>';
}
print '</tbody>';
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