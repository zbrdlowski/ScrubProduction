<?php
// DB connection
$pdo = new PDO('mysql:host=localhost;dbname=your_db;charset=utf8', 'username', 'password');

// Define column mappings: table => [column indexes]
$columnMap = [
    'tableA' => [0, 1, 2, 3, 4],
    'tableB' => [5, 6, 7, 8, 9, 10],
    'tableC' => [11, 12, 13, 14, 15, 16],
    // Add more as needed
];

// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $file = $_FILES['csv']['tmp_name'];
    if (($handle = fopen($file, 'r')) !== false) {
        $rowCount = 0;
        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
            $rowCount++;
            foreach ($columnMap as $table => $indexes) {
                $values = array_map(fn($i) => $row[$i] ?? null, $indexes);
                $placeholders = implode(',', array_fill(0, count($values), '?'));
                $sql = "INSERT INTO `$table` VALUES ($placeholders)";
                $stmt = $pdo->prepare($sql);
                try {
                    $stmt->execute($values);
                } catch (PDOException $e) {
                    error_log("Row $rowCount insert into $table failed: " . $e->getMessage());
                }
            }
        }
        fclose($handle);
        echo "Upload complete. $rowCount rows processed.";
    } else {
        echo "Failed to open CSV file.";
    }
} else {
    echo "No file uploaded.";
}
?>
