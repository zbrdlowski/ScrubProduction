<!DOCTYPE html>
	</script>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SRUB Production App</title>

  <!-- Google Font: Source Sans Pro -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
</head>
<?php
	session_start();
	include 'includes/conn.php';

	if(isset($_POST['login'])){
		$username = $_POST['username'];
		$password = $_POST['password'];

		if(empty($_POST['username'] ||  $_POST['password'])){
			$_SESSION['error'] = 'Input  credentials first';
		}

		$sql = "SELECT * FROM employees WHERE username = '".$username."' AND password = PASSWORD('".$password."')";
		$query = $conn->query($sql);

		if($query->num_rows < 1){
			$_SESSION['error'] = 'Cannot find account with such credentials';

		}
		else{
			$row = $query->fetch_assoc();
			//if(password_verify($password, $row['password'])){

				$_SESSION['admin'] = $row['id']; // len kvoli dochadzke
				$_SESSION['user_id'] = $row['id'];
				$_SESSION['permission'] = $row['permission'];
				$_SESSION['dpt'] = $row['position_id'];
				$_SESSION['user_photo'] = $row['photo'];
				$_SESSION['name'] = $row['firstname'].' '.$row['lastname'];
				$_SESSION['username'] = $username;

				$sql_dpt = "SELECT description FROM position WHERE id = '".$_SESSION['dpt']."'";
				$query_dpt = $conn->query($sql_dpt);

                    while($row_dpt = $query_dpt->fetch_assoc()){
						$_SESSION['dpt_name'] = $row_dpt['description'];	
			
		}
		
	}
	header('location: index.php?page=profile&tab=online'); 
}
	else{
		
		?>		
<body class="hold-transition login-page dark-mode">
<div class="login-box">
  	<div class="login-logo">
  		<b>Scrub User Login</b>
  	</div>
  
  	<div class="login-box-body">
    	<p class="login-box-msg">Sign in to start your session</p>

    	<form action="login.php" method="POST">
      		<div class="form-group has-feedback">
        		<input type="text" class="form-control" name="username" placeholder="input Username" required autofocus>
        		<span class="glyphicon glyphicon-user form-control-feedback"></span>
      		</div>
          <div class="form-group has-feedback">
            <input type="password" class="form-control" name="password" placeholder="input Password" required>
            <span class="glyphicon glyphicon-lock form-control-feedback"></span>
          </div>
      		<div class="row">
    			<div class="col-xs-4">
          			<button type="submit" class="btn btn-primary btn-block btn-flat" name="login"><i class="fa fa-sign-in"></i> Sign In</button>
        		</div>
      		</div>
    	</form>
  	</div>
	<br/><br />
  	<?php
  		if(isset($_SESSION['error'])){
  			echo "
  				<div class='callout callout-danger text-center mt20'>
			  		<p>".$_SESSION['error']."</p> 
			  	</div>
  			";
  			unset($_SESSION['error']);
  		}
  	?>
</div>
	


		<?
	}

	//header('location: index.php');

?>
</body>
</html>