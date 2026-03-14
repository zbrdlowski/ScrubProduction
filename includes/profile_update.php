<?php
	 session_start();
	include 'conn.php';
	if(isset($_GET['return'])){
		$return = $_GET['return'];
		
	}
	else{
		$return = 'logout.php';
	}

	if(isset($_POST['save'])){
		$curr_password = $_POST['curr_password'];
		$username = $_POST['username'];
		$password = $_POST['password'];
		$firstname = $_POST['firstname'];
		$lastname = $_POST['lastname'];
		@$photo = $_FILES['photo']['name'];
		//if(password_verify($curr_password, $user['password'])){
			if(!empty($photo)){
				move_uploaded_file($_FILES['photo']['tmp_name'], 'images/'.$photo);
				$filename = $photo;	
			}
			else{
				@$filename = $_SESSION['user_photo'];
			}
			/*
			if($password == $user['password']){
				$password = $user['password'];
			}
			else{
				$password = password_hash($password, PASSWORD_DEFAULT);
			}
				*/
			//$sql = "UPDATE employees SET username = '$username', password = '$password', firstname = '$firstname', lastname = '$lastname', photo = '$filename' WHERE id = '".$user['id']."'";
			$sql = "UPDATE employees SET username = '$username', password = PASSWORD('$password'), firstname = '$firstname', lastname = '$lastname', photo = '$filename' WHERE id = '".$_SESSION['user_id']."'";
			if($conn->query($sql)){
				$_SESSION['success'] = 'User profile updated successfully';
			}
			else{
				$_SESSION['error'] = $conn->error;
			}
			/*
			
		}
		else{
			$_SESSION['error'] = 'Incorrect password: curr: '.$curr_password.' vs user: '. $user['password'] . ' vs new: ' . $password;
		}
			*/
	}
	else{
		$_SESSION['error'] = 'Fill up required details first';
	}

	header('location:../'.$return);

?>