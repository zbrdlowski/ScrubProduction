<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__, 2) . '/includes/conn.php';
/** @var mysqli $conn */

if (PHP_SAPI !== 'cli') {
  header('Content-Type: text/plain; charset=utf-8');
  if ((int) ($_SESSION['permission'] ?? 0) < 900) {
    http_response_code(403);
    exit('Unauthorized - administrator permission is required.');
  }
}

$column = 'customs_identifier';
$escapedColumn = $conn->real_escape_string($column);
$result = $conn->query("SHOW COLUMNS FROM orders LIKE '{$escapedColumn}'");
if (!$result) {
  throw new RuntimeException($conn->error);
}

$exists = $result->num_rows > 0;
$result->free();

if ($exists) {
  echo "OK - orders.{$column} already exists.\n";
  exit;
}

$sql = "
  ALTER TABLE orders
  ADD COLUMN customs_identifier VARCHAR(128) NULL
  COMMENT 'Customer customs/tax identifier required by selected destination countries'
  AFTER shipping_method
";

if (!$conn->query($sql)) {
  throw new RuntimeException($conn->error);
}

echo "OK - orders.{$column} was created.\n";

