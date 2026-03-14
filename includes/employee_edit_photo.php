<?php
	include 'conn.php';

	if(isset($_POST['upload'])){
		$empid = $_POST['empid'];
		
		
		//echo '<h1> tutoka: ' . $empid . ' + ' . $user_id . ' </h1>';
		
		$filename = $_FILES['photo']['name'];
		if(!empty($filename)){
			move_uploaded_file($_FILES['photo']['tmp_name'], '../images/'.$filename);	
		}
		
		$sql = "UPDATE employees SET photo = '$filename' WHERE id = '$empid'";
		if($conn->query($sql)){
			$_SESSION['success'] = 'Employee photo updated successfully';
			header('location: ../index.php?page=employee_edit&user-id='.$empid.'&sucess=ok-'.$empid);
		}
		else{
			$_SESSION['error'] = $conn->error;
			header('location: ../index.php?page=employee_edit&user-id='.$empid.'&sucess=con_error');
		}
	}

	else{
		$_SESSION['error'] = 'Select employee to update photo first';
		header('location: ../index.php?page=employee_edi&user-id='.$empid.'t&sucess=no_photo');
	}	

?>