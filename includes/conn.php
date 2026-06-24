<?php
$host = 'localhost';
$dbname = 'scrubproduction';
$username = 'root';
$password = '';

$dbHosts = [];
foreach ([$host, '127.0.0.1'] as $candidateHost) {
    $candidateHost = trim((string) $candidateHost);
    if ($candidateHost === '' || in_array($candidateHost, $dbHosts, true)) {
        continue;
    }
    $dbHosts[] = $candidateHost;
}

$pdo = null;
$conn = null;
$connectionErrors = [];

foreach ($dbHosts as $dbHost) {
    try {
        $candidatePdo = new PDO(
            "mysql:host=$dbHost;dbname=$dbname;charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]
        );

        $candidateConn = @new mysqli($dbHost, $username, $password, $dbname);
        if ($candidateConn->connect_error) {
            $connectionErrors[] = $dbHost . ' (mysqli): ' . $candidateConn->connect_error;
            $candidateConn->close();
            continue;
        }

        $candidateConn->set_charset('utf8mb4');

        $pdo = $candidatePdo;
        $conn = $candidateConn;
        $host = $dbHost;
        break;
    } catch (PDOException $e) {
        $connectionErrors[] = $dbHost . ' (PDO): ' . $e->getMessage();
    }
}

if (!($pdo instanceof PDO) || !($conn instanceof mysqli)) {
    echo 'Connection failed: ' . implode(' | ', $connectionErrors);
    exit;
}

if (!isset($_REQUEST['year']) || empty($_REQUEST['year'])) {
    $append = date('Y');
    $attdn_table = 'attdn_' . date('Y');
} else {
    $append = $_REQUEST['year'];
    $attdn_table = 'attdn_' . $_REQUEST['year'];
}
?>
