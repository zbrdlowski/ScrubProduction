<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/conn.php';

// The connection-local temporary table shadows orders; application data is never modified.
$conn->query('CREATE TEMPORARY TABLE orders (
  id INT PRIMARY KEY, status VARCHAR(32), order_date DATETIME, imported_at DATETIME,
  production_started_at DATETIME NULL, payment_received_amount DECIMAL(12,2) NULL,
  status_override TINYINT DEFAULT 1, status_override_by INT NULL,
  status_override_at DATETIME NULL, status_override_note VARCHAR(255) NULL,
  priority INT DEFAULT 0, priority_date DATETIME NULL
)');

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
  $checks++;
  if (!$condition) throw new RuntimeException($message);
};

try {
  // Freeze only this connection's clock to reproduce an October 22 order paid on October 23.
  $conn->query("SET timestamp = UNIX_TIMESTAMP('2026-10-23 10:15:00')");
  $conn->query("INSERT INTO orders (id, status, order_date, imported_at) VALUES
    (1, 'PENDING', '2026-10-22 08:00:00', '2026-10-22 08:05:00'),
    (2, 'NEW', '2026-10-22 09:00:00', '2026-10-22 09:05:00'),
    (3, 'NEW', '2026-10-23 09:00:00', '2026-10-23 09:05:00'),
    (4, 'PENDING', '2026-10-22 10:00:00', '2026-10-22 10:05:00')");

  // Execute the actual update from the endpoint, rather than duplicating its date assignments.
  $endpoint = file_get_contents(dirname(__DIR__) . '/scripts/orders/confirm_order_payment.php');
  $assert((bool) preg_match("/UPDATE orders\s+SET status = 'NEW',.*?LIMIT 1/s", $endpoint, $update), 'Payment release update exists');
  $conn->begin_transaction();
  $stmt = $conn->prepare($update[0]);
  $amount = 149.90;
  $orderId = 1;
  $stmt->bind_param('di', $amount, $orderId);
  $stmt->execute();
  $stmt->close();
  $conn->commit();

  $released = $conn->query('SELECT * FROM orders WHERE id = 1')->fetch_assoc();
  $assert($released['status'] === 'NEW', 'Paid Pending order enters production');
  $assert($released['imported_at'] === '2026-10-23 10:15:00', 'Import timestamp is the payment confirmation time');
  $assert($released['production_started_at'] === $released['imported_at'], 'Import and production timestamps match exactly');
  $assert($released['order_date'] === '2026-10-22 08:00:00', 'Original order date is retained');
  $assert($released['payment_received_amount'] === '149.90', 'Received payment remains recorded');
  $assert((int) $released['status_override'] === 0, 'Automatic workflow is enabled');

  // Use the real Production ordering to verify the released order does not jump ahead.
  $listing = file_get_contents(dirname(__DIR__) . '/includes/orders.php');
  $assert((bool) preg_match('/ORDER BY\s+CASE\s+WHEN o\.priority >= 20.*?o\.id ASC/s', $listing, $sort), 'Production sorting is available');
  $rows = $conn->query("SELECT o.id, DATE(o.imported_at) AS import_day FROM orders o WHERE o.status <> 'PENDING' " . $sort[0])->fetch_all(MYSQLI_ASSOC);
  $assert(array_map('intval', array_column($rows, 'id')) === [2, 3, 1], 'Paid order follows both yesterday\'s and earlier today\'s production orders');
  $assert($rows[2]['import_day'] === '2026-10-23', 'Paid order belongs to the payment day group');

  $unpaid = $conn->query('SELECT status, imported_at FROM orders WHERE id = 4')->fetch_assoc();
  $assert($unpaid['status'] === 'PENDING' && $unpaid['imported_at'] === '2026-10-22 10:05:00', 'Other unpaid orders remain unchanged');
} finally {
  $conn->query('SET timestamp = DEFAULT');
  $conn->query('DROP TEMPORARY TABLE IF EXISTS orders');
}

echo 'orders_payment_release: ', $checks, " checks passed\n";
