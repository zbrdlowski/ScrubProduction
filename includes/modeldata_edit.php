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
/*

ALTER TABLE `scrubdata` ADD `graphics` ENUM('true','false','','') NOT NULL DEFAULT 'false' AFTER `modelcode`, ADD `plastics` ENUM('true','false','','') NOT NULL DEFAULT 'false' AFTER `graphics`, ADD `seatcover` ENUM('true','false','','') NOT NULL DEFAULT 'false' AFTER `plastics`;

*/ 
if(isset($_GET['scrubcocode'])){ // ak máme kod modelu

    if(isset($_GET['model'])){ // pre správne fungovanie potrebujeme aj názov modelu, ináč to hodí prvú zhodu
        $cocodsql = "SELECT DISTINCT brand, model, rangeyear, modelcode, graphics, plastics, seat_cover, web_g, web_p, web_s 
             FROM scrubdata 
             WHERE modelcode = '". $_GET['scrubcocode'] ."' 
             AND model = '". $_GET['model'] ."'";
    }else{
        $cocodsql = "SELECT DISTINCT brand, model, rangeyear, modelcode, graphics, plastics, seat_cover, web_g, web_p, web_s 
             FROM scrubdata 
             WHERE modelcode = '". $_GET['scrubcocode'] ."'";
    }
    
    $cocodquery = $conn->query($cocodsql);
    while($row = $cocodquery->fetch_array()){
        $scrubrand = $row['brand'];
        $scrubmodel = $row['model'];
        $scrubcode = $_GET['scrubcocode'];        
        $scrubrange = $row['rangeyear'];
        $graphics = $row['graphics'];
        $plastics = $row['plastics'];
        $seat_cover = $row['seat_cover'];
        $web_g = $row['web_g'];
        $web_p = $row['web_p'];
        $web_s = $row['web_s'];	    
        }
}else{

@$scrubrand = $_REQUEST['brand'];
@$scrubmodel = $_REQUEST['model'];
@$scrubcode = $_REQUEST['code'];
@$scrubrange = $_REQUEST['range'];
@$web_g = $_REQUEST['web_g'];
@$web_p = $_REQUEST['web_p'];
@$web_s = $_REQUEST['web_s'];

if(empty($web_g)) $web_g = 'no';
if(empty($web_p)) $web_p = 'no';
if(empty($web_s)) $web_s = 'no';
if(empty($graphics)) $graphics = 'no';
if(empty($plastics)) $plastics = 'no';
if(empty($seat_cover)) $seat_cover = 'no';
}

@$scrubyear = $_REQUEST['year'];
print '<table id="example1" width="100%" class="table table-bordered">';
//print'<th style="background-color:#444242; color:white;">Code</th>';
print'<th style="background-color:#444242; color:white;">Result</th>';

print '<tr>';
/*
print '<td>';

print '<form method="get">';
print '<input type="hidden" name="page" value="modeldata_edit">';
print '<input type="text" name="scrubcocode" size="30">&nbsp;';
print '<input type="submit" value="Go Search" name="search" />';
print '</form>';
print '</td>';

*/

print '<td>';

if(!empty($scrubcode)){
    print '<h2>  '. $scrubcode .' - '. $scrubrand .'  -  '. $scrubmodel .'  -  '. $scrubrange .'</h2>';   
    }

print '</td>';



print '</tr>';
print '</table>';

// sem predel
?>
</table>
</div>
<!-- /.card-body -->
</div>

<!-- /.card -->

<div class="card">
<div class="card-header">
<h3 class="card-title">Edit Scrub Subproducts</h3>
</div>
<!-- /.card-header -->
<div class="card-body">
<style>
/* Layout */
.sd-panel{
  display:flex;
  gap:1rem;
  flex-wrap:wrap;
}
.sd-left{
  flex: 1 1 520px;
}
.sd-right{
  flex: 0 0 260px;
}

/* Toggle grid */
.sd-toggles{
  display:grid;
  grid-template-columns: repeat(3, minmax(160px, 1fr));
  gap: .75rem;
}

/* Hide the inputs, show only labels */
.sd-toggles input{
  position:absolute;
  opacity:0;
  pointer-events:none;
}

/* Label as big tile button */
.sd-toggles label{
  cursor:pointer;
  user-select:none;
  border-radius:.5rem;
  height:110px;
  display:flex;
  flex-direction:column;
  justify-content:center;
  align-items:center;
  gap:.35rem;

  border:1px solid rgba(255,255,255,.10);
  background: rgba(255,255,255,.04);
  color: rgba(255,255,255,.9);
  text-align:center;

  transition: transform .08s ease, filter .12s ease, box-shadow .12s ease;
}

.sd-toggles label:hover{ filter: brightness(1.08); }
.sd-toggles label:active{ transform: translateY(1px); }

/* Title */
.sd-title{
  font-size: 1.05rem;
  font-weight: 700;
  letter-spacing:.2px;
}

/* Status chip */
.sd-chip{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width: 64px;
  height: 26px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 800;
  border: 1px solid rgba(255,255,255,.15);
  background: rgba(0,0,0,.25);
}

/* OFF state = danger gradient */
.sd-toggles input:not(:checked) + label{
  background: linear-gradient(180deg, rgba(220,53,69,1) 0%, rgba(167,29,42,1) 100%);
  border-color: rgba(220,53,69,.35);
  box-shadow: 0 0 0 .15rem rgba(220,53,69,.12);
}

/* ON state = success gradient */
.sd-toggles input:checked + label{
  background: linear-gradient(180deg, rgba(40,167,69,1) 0%, rgba(30,126,52,1) 100%);
  border-color: rgba(40,167,69,.45);
  box-shadow: 0 0 0 .15rem rgba(40,167,69,.18);
}

/* Right side: make submit look important */
.sd-submit{
  height: 110px;
  font-size: 22px;
  font-weight: 800;
}

/* Small info box */
.sd-meta{
  border-radius:.5rem;
  border:1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.03);
  padding:.75rem .9rem;
  margin-top:.75rem;
  font-size:.9rem;
  color: rgba(255,255,255,.8);
}
.sd-meta b{ color: rgba(255,255,255,.95); }

/* Responsive */
@media (max-width: 992px){
  .sd-right{ flex: 1 1 100%; }
  .sd-toggles{ grid-template-columns: 1fr; }
}
</style>
<?php
print '<form action="includes/modeldata_update.php" method="get">';
print '<input type="hidden" name="page" value="modeldata_update">';
print '<input type="hidden" name="code" value="'.$scrubcode.'">';
print '<input type="hidden" name="brand" value="'.$scrubrand.'">';
print '<input type="hidden" name="model" value="'.$scrubmodel.'">';
print '<input type="hidden" name="range" value="'.$scrubrange.'">';

$g_checked = ($graphics === 'yes') ? 'checked' : '';
$p_checked = ($plastics === 'yes') ? 'checked' : '';
$s_checked = ($seat_cover === 'yes') ? 'checked' : '';
$wg_checked = ($web_g === 'yes') ? 'checked' : '';
$wp_checked = ($web_p === 'yes') ? 'checked' : '';
$ws_checked = ($web_s === 'yes') ? 'checked' : '';

print '<div class="card card-outline card-primary">';
print '  <div class="card-header">';
print '    <h3 class="card-title"><i class="fas fa-sliders-h mr-2"></i>Model Options</h3>';
print '  </div>';
print '  <div class="card-body">';

print '    <div class="sd-panel">';

print '      <div class="sd-left">';
print '        <div class="sd-toggles">';

print '          <div>';
print '            <input id="graphics" type="checkbox" name="graphics" value="yes" '.$g_checked.'>';
print '            <label for="graphics">';
print '              <div class="sd-title">Graphics</div>';
print '              <div class="sd-chip">'.($graphics==='yes'?'YES':'NO').'</div>';
print '              <small class="text-white-50">Click to toggle</small>';
print '            </label>';
print '          </div>';

print '          <div>';
print '            <input id="plastics" type="checkbox" name="plastics" value="yes" '.$p_checked.'>';
print '            <label for="plastics">';
print '              <div class="sd-title">Plastics</div>';
print '              <div class="sd-chip">'.($plastics==='yes'?'YES':'NO').'</div>';
print '              <small class="text-white-50">Click to toggle</small>';
print '            </label>';
print '          </div>';

print '          <div>';
print '            <input id="seat_cover" type="checkbox" name="seat_cover" value="yes" '.$s_checked.'>';
print '            <label for="seat_cover">';
print '              <div class="sd-title">Seat Cover</div>';
print '              <div class="sd-chip">'.($seat_cover==='yes'?'YES':'NO').'</div>';
print '              <small class="text-white-50">Click to toggle</small>';
print '            </label>';
print '          </div>';

print '          <div>';
print '            <input id="web_g" type="checkbox" name="web_g" value="yes" '.$wg_checked.'>';
print '            <label for="web_g">';
print '              <div class="sd-title">Listed on web</div>';
print '              <div class="sd-chip">'.($web_g==='yes'?'YES':'NO').'</div>';
print '              <small class="text-white-50">Graphics</small>';
print '            </label>';
print '          </div>';

print '          <div>';
print '            <input id="web_p" type="checkbox" name="web_p" value="yes" '.$wp_checked.'>';
print '            <label for="web_p">';
print '              <div class="sd-title">Listed on web</div>';
print '              <div class="sd-chip">'.($web_p==='yes'?'YES':'NO').'</div>';
print '              <small class="text-white-50">Plastics</small>';
print '            </label>';
print '          </div>';

print '          <div>';
print '            <input id="web_s" type="checkbox" name="web_s" value="yes" '.$ws_checked.'>';
print '            <label for="web_s">';
print '              <div class="sd-title">Listed on web</div>';
print '              <div class="sd-chip">'.($web_s==='yes'?'YES':'NO').'</div>';
print '              <small class="text-white-50">Seat Cover</small>';
print '            </label>';
print '          </div>';

print '        </div> <br />';

print '      <div class="sd-right">';
print '        <button type="submit" class="btn btn-block bg-gradient-info sd-submit" name="submit">';
print '          <i class="fas fa-save mr-2"></i>SUBMIT';
print '        </button>';


print '      </div>';

print '    </div>'; // sd-panel

print '  </div>'; // card-body
print '</div>'; // card

print '</form>';
?>
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

if(empty($_GET['year'])){
    $sql2 = "SELECT * FROM scrubdata WHERE modelcode = '".$scrubcode."'";  
}else{
   $sql2 = "SELECT * FROM scrubdata WHERE brand = '".$scrubrand."' AND model = '".$scrubmodel."' AND exactyear = '".$scrubyear."'"; 
}

//$sql = "SELECT * FROM scrubdata LEFT JOIN scrubcompat ON scrubcompat.compatcode=scrubdata.modelcode WHERE scrubdata.modelcode = '".$scrubcode."'";
$query = $conn->query($sql2);
//print '<h3>Selected Model</h3>';
print '<table id="example2" width="100%" class="table table-bordered">';

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

// otický predel medzi blokmi
?>

</div>
<!-- /.card-body -->
</div>
<!-- /.card -->

 <div class="card">
<div class="card-header">
<h3 class="card-title">All compatible Models</h3>
</div>
<!-- /.card-header -->
<div class="card-body">
<?

if(empty($scrubyear)){
    $sql = "SELECT * FROM scrubcompat WHERE compatcode = '".$scrubcode."'";
}else{
    $sql = "SELECT * FROM scrubcompat WHERE compatcode = '".$scrubcode."' AND compatyear = '".$scrubyear."'";
}
//$sql = "SELECT * FROM scrubdata LEFT JOIN scrubcompat ON scrubcompat.compatcode=scrubdata.modelcode WHERE scrubdata.modelcode = '".$scrubcode."'";
$query = $conn->query($sql);

//print '<h3>Compatible Models</h3>';
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
 </div>
<!-- /.box-body -->
            </div>
        </div>
<!-- /.card-body -->
        </div>
    </div>
</section>
<script>
document.addEventListener('change', function(e){
  if(e.target.matches('#graphics, #plastics, #seat_cover, #web_g, #web_p, #web_s')){
    const label = document.querySelector('label[for="'+e.target.id+'"]');
    if(label){
      const chip = label.querySelector('.sd-chip');
      if(chip){
        chip.textContent = e.target.checked ? 'YES' : 'NO';
      }
    }
  }
});
</script>