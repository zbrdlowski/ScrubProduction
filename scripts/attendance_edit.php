<?php
  	session_start();
	include('../includes/conn.php');
	if(isset($_POST['edit'])){
		$id = $_POST['id'];
		$year = $_REQUEST['year'];
		$date = $_POST['edit_date'];
		$time_in = $_POST['edit_time_in'];
		$time_in = date('H:i:s', strtotime($time_in));
		$time_out = $_POST['edit_time_out'];
		$time_out = date('H:i:s', strtotime($time_out));
		$movement = $_POST['edit_movement'];
		$redirect = $_POST['redirect'];

		$sql = "UPDATE ".$attdn_table." SET date = '$date', time_in = '$time_in', time_out = '$time_out', movement = '$movement', online_status = '1' WHERE id = '$id'";
		if($conn->query($sql)){
			$_SESSION['success'] = 'Attendance updated successfully';

			$sql = "SELECT * FROM ".$attdn_table." WHERE id = '$id'";
			$query = $conn->query($sql);
			$row = $query->fetch_assoc();
			$emp = $row['employee_id'];

			$sql = "SELECT * FROM employees LEFT JOIN schedules ON schedules.id=employees.schedule_id WHERE employees.id = '$emp'";
			$query = $conn->query($sql);
			$srow = $query->fetch_assoc();

			//updates
			$logstatus = ($time_in > $srow['time_in']) ? 0 : 1;
			//

			if($srow['time_in'] > $time_in){
				$time_in = $srow['time_in'];
			}

			if($srow['time_out'] < $time_out){
				$time_out = $srow['time_out'];
			}

			$time_in = new DateTime($time_in);
			$time_out = new DateTime($time_out);
			$interval = $time_in->diff($time_out);
			$hrs = $interval->format('%h');
			$mins = $interval->format('%i');
			$mins = $mins/60;
			$int = $hrs + $mins;
			if($int > 4){
				$int = $int - 1;
			}

			$sql = "UPDATE ".$attdn_table." SET num_hr = '$int', status = '$logstatus' WHERE id = '$id'";
			$conn->query($sql);
		}
		else{
			$_SESSION['error'] = $conn->error;
		}
	}
	else{
		$_SESSION['error'] = 'Fill up edit form first';
	}

	header('location:'.$redirect.'');
	// ak bude Danka chieť hodiť na Calendar
	// header('location:'.$CalLink.'');

?>