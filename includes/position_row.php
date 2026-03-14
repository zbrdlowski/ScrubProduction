<?php
include 'conn.php'; // or your DB connection file

if(isset($_POST['id'])){
  $id = $_POST['id'];
  $sql = "SELECT * FROM position WHERE id = '$id'";
  $query = $conn->query($sql);
  $row = $query->fetch_assoc();

  echo json_encode($row);
}
?>
