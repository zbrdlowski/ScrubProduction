<?php
$host = 'localhost';
$dbname = 'scrubproduction';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
    exit;
}

//moje klasické
$conn = new mysqli('localhost', 'root', '', 'scrubproduction');
if(!isset($_REQUEST['year']) OR empty($_REQUEST['year'])){
	$append = date('Y');
	$attdn_table = 'attdn_'.date('Y');}
	else{
	  $append = $_REQUEST['year'];
	  $attdn_table = 'attdn_'.$_REQUEST['year'];} // pre správne tabulky, staré roky budú separátne

	if ($conn->connect_error) {
	    die("Connection failed: " . $conn->connect_error);
	}
	
?>
