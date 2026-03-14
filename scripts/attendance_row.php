<?php 
	include '../includes/conn.php';
	session_start();
	header('Content-Type: application/json; charset=utf-8');
	$attdn_table = 'attdn_'.$_REQUEST['year'];
	
	if(isset($_POST['id'])){
		$id = $_POST['id'];
		$sql = "SELECT *, ".$attdn_table.".id as attid FROM ".$attdn_table." LEFT JOIN employees ON employees.id=".$attdn_table.".employee_id WHERE ".$attdn_table.".id = '$id'";
		//$sql = "SELECT *, attdn_2025.id as attid FROM attdn_2025 LEFT JOIN employees ON employees.id=attdn_2025.employee_id WHERE attdn_2025.id = '$id'";
		$query = $conn->query($sql);
		$row = $query->fetch_assoc();

		echo json_encode($row);
	}
?>