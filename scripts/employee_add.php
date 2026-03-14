<?php
	include '../includes/conn.php';
	session_start();

	if(isset($_POST['add'])){
		$firstname = $_POST['firstname'];
		$lastname = $_POST['lastname'];
		$address = $_POST['address'];
		$birthdate = $_POST['birthdate'];
		$contact = $_POST['contact'];
		$gender = $_POST['gender'];
		$position = $_POST['position'];
		$schedule = $_POST['schedule'];
		$active = $_POST['active'];
		$personal = $_POST['personal'];
		$filename = $_FILES['photo']['name'];
		$passw = '123Admin456';
		if(!empty($filename)){
			move_uploaded_file($_FILES['photo']['tmp_name'], '../images/'.$filename);	
		}
		/*
		//creating employeeid
		$letters = '';
		$numbers = '';
		foreach (range('A', 'Z') as $char) {
		    $letters .= $char;
		}
		for($i = 0; $i < 10; $i++){
			$numbers .= $i;
		}
		$employee_id = substr(str_shuffle($letters), 0, 3).substr(str_shuffle($numbers), 0, 9);
		*/
		$i= 1;
		while($i == 1){
			$employee_id=date('Y') .'-'. mt_rand(1,9999);
				$chk  = $conn->query("SELECT * FROM employees where employee_id = '$employee_id' ")->num_rows;
				if($chk <= 0){
					$i = 0;
				}
			}

		//
		$sql = "INSERT INTO employees (employee_id, firstname, lastname, address, birthdate, contact_info, gender, position_id, schedule_id, photo, created_on, active, personal, username, permission,password) VALUES ('$employee_id', '$firstname', '$lastname', '$address', '$birthdate', '$contact', '$gender', '$position', '$schedule', '$filename', NOW(), '$active', '$personal','user_".$employee_id."','1',PASSWORD('".$passw."'))";
		if($conn->query($sql)){
			$_SESSION['success'] = 'Employee added successfully';
		}
		else{
			$_SESSION['error'] = $conn->error;
		}

	}
	else{
		$_SESSION['error'] = 'Fill up add form first';
	}

	header('location: ../?page=employee');
?>