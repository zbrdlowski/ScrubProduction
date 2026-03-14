<?php
	include('../includes/conn.php');
		session_start();
	if(isset($_POST['delete'])){
		$id = $_POST['id'];
		
		$sql = "DELETE FROM ".$attdn_table." WHERE id = '$id'";
		if($conn->query($sql)){
			$_SESSION['success'] = 'Attendance deleted successfully';
		}
		else{
			$_SESSION['error'] = $conn->error;
		}
	}
	else{
		$_SESSION['error'] = 'Select item to delete first';
	}

	if (!empty($_POST['redirect'])) {
    header('Location: ' . $_POST['redirect']);
} else {
    header('Location: ' . $_SERVER['HTTP_REFERER']);
}
exit;
	
?>