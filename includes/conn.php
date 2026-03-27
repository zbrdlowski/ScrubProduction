<?php
$host = 'localhost';
$dbname = 'scrubproduction';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
    exit;
}

// moje klasické
$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

if (!isset($_REQUEST['year']) || empty($_REQUEST['year'])) {
    $append = date('Y');
    $attdn_table = 'attdn_' . date('Y');
} else {
    $append = $_REQUEST['year'];
    $attdn_table = 'attdn_' . $_REQUEST['year'];
}
?>
