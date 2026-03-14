<?php
include ('conn.php');
session_start();

$redirect = $_SESSION['uri'] ?? 'index.php';

if(isset($_REQUEST['code'])){

  $scrubcode  = $_REQUEST['code'];
  $scrubmodel = $_REQUEST['model'];

  // checkboxy: ak existuje parameter, je to YES
  $graphics   = isset($_REQUEST['graphics']) ? 'yes' : 'no';
  $plastics   = isset($_REQUEST['plastics']) ? 'yes' : 'no';
  $seat_cover = isset($_REQUEST['seat_cover']) ? 'yes' : 'no';

  $web_g      = isset($_REQUEST['web_g']) ? 'yes' : 'no';
  $web_p      = isset($_REQUEST['web_p']) ? 'yes' : 'no';
  $web_s      = isset($_REQUEST['web_s']) ? 'yes' : 'no';

  $sql = "UPDATE scrubdata 
          SET graphics = '".$graphics."', 
              plastics = '".$plastics."', 
              seat_cover = '".$seat_cover."',
              web_g = '".$web_g."',
              web_p = '".$web_p."',
              web_s = '".$web_s."'
          WHERE modelcode = '".$scrubcode."' AND model = '".$scrubmodel."'";

  if($conn->query($sql)){
      $_SESSION['success'] = 'Zmeny úspešne uložené';
  } else {
      $_SESSION['error'] = $conn->error;
  }
}

if (strpos($redirect, 'http') === 0) {
    die('Invalid redirect');
}

header("Location: " . $redirect);
exit;
?>