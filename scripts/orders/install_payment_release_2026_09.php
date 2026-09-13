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

$columns = [
  'production_started_at' => "
    ALTER TABLE orders
    ADD COLUMN production_started_at DATETIME NULL
    COMMENT 'Time the order entered the production queue; original order_date remains unchanged'
    AFTER order_date
  ",
  'payment_received_amount' => "
    ALTER TABLE orders
    ADD COLUMN payment_received_amount DECIMAL(12,2) NULL
    COMMENT 'Actual amount entered when payment was confirmed; orders.total remains unchanged'
    AFTER total
  ",
];

foreach ($columns as $column => $sql) {
  $escapedColumn = $conn->real_escape_string($column);
  $result = $conn->query("SHOW COLUMNS FROM orders LIKE '{$escapedColumn}'");
  if (!$result) {
    throw new RuntimeException($conn->error);
  }

  $exists = $result->num_rows > 0;
  $result->free();

  if ($exists) {
    echo "OK - orders.{$column} already exists.\n";
    continue;
  }

  if (!$conn->query($sql)) {
    throw new RuntimeException($conn->error);
  }

  echo "OK - orders.{$column} was created.\n";
}
