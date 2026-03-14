<?php
	session_start();
include 'conn.php';
	if(isset($_POST['empid'])){
		$empid = $_POST['empid'];
		$firstname = $_POST['firstname'];
		$lastname = $_POST['lastname'];
		$username = $_POST['username'];
		$address = $_POST['address'];
		$birthdate = $_POST['birthdate'];
		$contact = $_POST['contact_info'];
		$gender = $_POST['gender'];
		$position = $_POST['position_id'];
		$schedule = $_POST['schedule_id'];
		$active = $_POST['active'];
		$personal = $_POST['personal'];
		$password = $_POST['password'];
		$permission = $_POST['permission'];
		if($_POST['password'] != ''){			
			$password = $_POST['password'];
			$sql = "UPDATE employees SET firstname = '$firstname', lastname = '$lastname', address = '$address', birthdate = '$birthdate', contact_info = '$contact', gender = '$gender', 
			position_id = '$position', schedule_id = '$schedule', active = '$active', personal = '$personal', permission='$permission', password = PASSWORD('$password') WHERE id = '$empid'";
			$krokodyl = 'Pass';
		}else{
			$sql = "UPDATE employees SET firstname = '$firstname', lastname = '$lastname', address = '$address', birthdate = '$birthdate', contact_info = '$contact', gender = '$gender', 
			position_id = '$position', schedule_id = '$schedule', active = '$active', personal = '$personal', permission='$permission' WHERE id = '$empid'";
			$krokodyl = 'No Pass';
		} 
		if($conn->query($sql)){
			$_SESSION['success'] = 'Zmeny úspešne uložené '.$krokodyl;
		}
		else{
			$_SESSION['error'] = $conn->error;
		}

	}
	else{
		$_SESSION['error'] = 'Najprv vyber, koho treba upraviť';
	}

	header('location: ../index.php?page=employee');
?>