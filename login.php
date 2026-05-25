<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>SCRUB Production App</title>

	<!-- Google Font: Source Sans Pro -->
	<link rel="stylesheet"
		href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">

	<!-- Font Awesome Icons -->
	<link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">

	<!-- overlayScrollbars -->
	<link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">

	<!-- Theme style -->
	<link rel="stylesheet" href="dist/css/adminlte.min.css">

	<style>
		.login-logo {
			margin-bottom: 1.25rem;
			text-align: center;
		}

		/* resize large 3000px logo */
		.scrub-login-logo {
			display: block;
			width: 390px;
			max-width: 110%;
			height: auto;
			margin: 0 auto;
		}

		/* keep dark mode inputs even on focus */
		.login-box .form-control,
		.login-box .form-control:focus {
			background-color: #343a40 !important;
			color: #ffffff !important;
			border-color: #6c757d;
			box-shadow: none;
		}

		.login-box .form-control::placeholder {
			color: #ced4da;
			opacity: 1;
		}

		/* Chrome autofill fix */
		.login-box .form-control:-webkit-autofill,
		.login-box .form-control:-webkit-autofill:hover,
		.login-box .form-control:-webkit-autofill:focus,
		.login-box .form-control:-webkit-autofill:active {
			-webkit-text-fill-color: #ffffff !important;
			box-shadow: 0 0 0 1000px #343a40 inset !important;
			caret-color: #ffffff;
			transition: background-color 9999s ease-out 0s;
		}

		@media (max-width: 420px) {
			.scrub-login-logo {
				width: 100%;
				max-width: 100%;
			}
		}
	</style>
</head>

<?php
session_start();
include 'includes/conn.php';

if (isset($_POST['login'])) {
	$username = $_POST['username'];
	$password = $_POST['password'];

	if (empty($_POST['username'] || $_POST['password'])) {
		$_SESSION['error'] = 'Input credentials first';
	}

	$sql = "SELECT * FROM employees WHERE username = '" . $username . "' AND password = PASSWORD('" . $password . "')";
	$query = $conn->query($sql);

	if ($query->num_rows < 1) {
		$_SESSION['error'] = 'Cannot find account with such credentials';

	} else {
		$row = $query->fetch_assoc();

		$_SESSION['admin'] = $row['id'];
		$_SESSION['user_id'] = $row['id'];
		$_SESSION['permission'] = $row['permission'];
		$_SESSION['dpt'] = $row['position_id'];
		$_SESSION['user_photo'] = $row['photo'];
		$_SESSION['name'] = $row['firstname'] . ' ' . $row['lastname'];
		$_SESSION['username'] = $username;
		$_SESSION['personal_orders'] = (int) ($row['personal_orders'] ?? 0);
		$_SESSION['grid'] = (int) ($row['grid'] ?? 0);

		$sql_dpt = "SELECT description FROM position WHERE id = '" . $_SESSION['dpt'] . "'";
		$query_dpt = $conn->query($sql_dpt);

		while ($row_dpt = $query_dpt->fetch_assoc()) {
			$_SESSION['dpt_name'] = $row_dpt['description'];
		}
	}

	$return = $_GET['return'] ?? 'index.php?page=profile&tab=attendance';

	if (
		strpos($return, 'http://') === 0 ||
		strpos($return, 'https://') === 0 ||
		strpos($return, '//') === 0
	) {
		$return = 'index.php?page=profile&tab=attendance';
	}

	header('Location: ' . $return);
	exit;

} else {
?>

<body class="hold-transition login-page dark-mode">

	<div class="login-box">

		<div class="login-logo">
			<img src="images/logo/scrublogo.png"
				alt="Scrub Designz"
				class="scrub-login-logo">
		</div>

		<div class="login-box-body">

			<form action="login.php<?= isset($_GET['return']) ? '?return=' . urlencode($_GET['return']) : '' ?>"
				method="POST">

				<div class="form-group has-feedback">
					<input type="text"
						class="form-control"
						name="username"
						placeholder="input Username"
						required
						autofocus>

					<span class="glyphicon glyphicon-user form-control-feedback"></span>
				</div>

				<div class="form-group has-feedback">
					<input type="password"
						class="form-control"
						name="password"
						placeholder="input Password"
						required>

					<span class="glyphicon glyphicon-lock form-control-feedback"></span>
				</div>

				<div class="row">
					<div class="col-xs-4">
						<button type="submit"
							class="btn btn-primary btn-block btn-flat"
							name="login">

							<i class="fa fa-sign-in"></i> Sign In
						</button>
					</div>
				</div>

			</form>
		</div>

		<br /><br />

		<?php
		if (isset($_SESSION['error'])) {
			echo "
				<div class='callout callout-danger text-center mt20'>
					<p>" . $_SESSION['error'] . "</p>
				</div>
			";
			unset($_SESSION['error']);
		}
		?>

	</div>

<?php
}
?>

</body>
</html>