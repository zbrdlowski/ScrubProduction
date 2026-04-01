<?php
	session_start();
	include 'conn.php';
		// Store referrer (fallback if not set)
	$redirect = $_SERVER['HTTP_REFERER'] ?? '../index.php?page=employee';
	$return_to = $_POST['return_to'] ?? '';

	if ($return_to === '') {
		$return_to = '../index.php?page=employee';
	} elseif (
		strpos($return_to, 'http://') !== 0 &&
		strpos($return_to, 'https://') !== 0 &&
		strpos($return_to, '/') !== 0 &&
		strpos($return_to, '../') !== 0
	) {
		$return_to = '../' . ltrim($return_to, '/');
	}

	if(!isset($_SESSION['permission'])){
		$_SESSION['error'] = 'Unauthorized access';
		header("Location: " . $redirect);
		exit;
	}

	$editor_permission = (int)$_SESSION['permission'];

	// User -> no access
	if($editor_permission < 300){
		$_SESSION['error'] = 'You do not have permission to edit employee';
		header("Location: " . $redirect);
		exit;
	}

	if(isset($_POST['empid'])){
		$empid = (int)$_POST['empid'];

		// Load current employee values from DB first
		$sql_current = "SELECT * FROM employees WHERE id = '$empid'";
		$current_result = $conn->query($sql_current);

		if(!$current_result || $current_result->num_rows == 0){
			$_SESSION['error'] = 'Employee not found';
			header("Location: " . $redirect);
			exit;
		}

		$current = $current_result->fetch_assoc();

		// Posted values
		$firstname   = $_POST['firstname'];
		$lastname    = $_POST['lastname'];
		$address     = $_POST['address'];
		$birthdate   = $_POST['birthdate'];
		$contact     = $_POST['contact_info'];
		$gender      = $_POST['gender'];
		$position    = $_POST['position_id'];
		$schedule    = $_POST['schedule_id'];
		$active      = $_POST['active'];
		$personal    = $_POST['personal'];
		$created_on  = $_POST['created_on'];
		$chat        = $_POST['chat'];
		$password    = $_POST['password'];
		$permission  = (int)$_POST['permission'];

		// Permission ceiling
		if($editor_permission == 300 && $permission > 300){
			$permission = 300;
		}
		if($editor_permission == 500 && $permission > 500){
			$permission = 500;
		}
		if($editor_permission >= 900 && $permission > 900){
			$permission = 900;
		}

		// Moderator -> only position + permission can be changed
		if($editor_permission == 300){
			$firstname  = $current['firstname'];
			$lastname   = $current['lastname'];
			$address    = $current['address'];
			$birthdate  = $current['birthdate'];
			$contact    = $current['contact_info'];
			$gender     = $current['gender'];
			$schedule   = $current['schedule_id'];
			$active     = $current['active'];
			$personal   = $current['personal'];
			$created_on = $current['created_on'];
			$chat       = $current['chat'];

			// moderator cannot change password
			$password = '';
		}

		// Build SQL
		if($password != '' && $editor_permission >= 500){
			$sql = "UPDATE employees 
					SET firstname = '$firstname',
						lastname = '$lastname',
						address = '$address',
						birthdate = '$birthdate',
						contact_info = '$contact',
						gender = '$gender',
						position_id = '$position',
						schedule_id = '$schedule',
						created_on = '$created_on',
						chat = '$chat',
						active = '$active',
						personal = '$personal',
						permission = '$permission',
						password = PASSWORD('$password')
					WHERE id = '$empid'";
			$krokodyl = 'Pass';
		}
		else{
			$sql = "UPDATE employees 
					SET firstname = '$firstname',
						lastname = '$lastname',
						address = '$address',
						birthdate = '$birthdate',
						contact_info = '$contact',
						gender = '$gender',
						position_id = '$position',
						schedule_id = '$schedule',
						created_on = '$created_on',
						chat = '$chat',
						active = '$active',
						personal = '$personal',
						permission = '$permission'
					WHERE id = '$empid'";
			$krokodyl = 'No Pass';
		}

		if($conn->query($sql)){
			$_SESSION['success'] = 'Zmeny úspešne uložené ' . $krokodyl;
		}
		else{
			$_SESSION['error'] = $conn->error;
		}
	}
	else{
		$_SESSION['error'] = 'Najprv vyber, koho treba upraviť';
	}

	header("Location: " . $return_to);
exit;
?>