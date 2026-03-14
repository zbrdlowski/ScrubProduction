<?
session_start();
include '../includes/conn.php';
/*
echo "<pre>"; print_r($_POST) ;  echo "</pre>";
echo $attdn_table;
*/

if(isset($_POST['add'])){
    $employee = $_POST['employee'];
    $time_in = $_POST['time_in'];
    $time_in = date('H:i:s', strtotime($time_in));
    $time_out = $_POST['time_out'];
    $time_out = date('H:i:s', strtotime($time_out));
    $movement = $_POST['movement'];
    $redirect = $_POST['redirect'];
$checkboxes = isset($_POST['copy']) ? $_POST['copy'] : array();
foreach($checkboxes as $value) {

    // here you can use $value
    $sql = "INSERT INTO ".$attdn_table." (employee_id, date, time_in, time_out, movement) VALUES ('".$employee."','".$_POST['year']."-".$_POST['month']."-".$value."',
    '".$time_in."','".$time_out."','".$movement."')";
    
    $query = $conn->query($sql);
    
    echo $sql;
    }
}

header('Location: ' . $_SERVER['HTTP_REFERER']);
exit;

?>