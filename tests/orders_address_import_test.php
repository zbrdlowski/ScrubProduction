<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/order_import_lib.php';
require_once dirname(__DIR__) . '/scripts/import_darkscrub_unified.php';
require_once dirname(__DIR__) . '/includes/conn.php';

$checks = 0;
$assertSame = static function ($expected, $actual, string $message) use (&$checks): void {
  $checks++;
  if ($actual !== $expected) {
    throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
  }
};

foreach ([
  [['source_raw_json' => '{"Buyer State":"CA","Ship To State":"TX"}'], 'BILLING', 'US', 'CA'],
  [['source_raw_json' => '{"Buyer State":"CA","Ship To State":"TX"}'], 'SHIPPING', 'US', 'TX'],
  [['source_raw_json' => '{"post to county":"Texas"}'], 'SHIPPING', 'USA', 'TX'],
  [['source_raw_json' => '{"Buyer county":"New York"}'], 'BILLING', 'US', 'NY'],
  [['delivery_state' => 'US-TX', 'source_raw_json' => '{"Ship To State":"NY"}'], 'SHIPPING', 'US', 'TX'],
  [['bill_state' => 'British Columbia'], 'BILLING', 'CA', 'BC'],
  [['delivery_state' => 'Victoria'], 'SHIPPING', 'AU', 'VIC'],
  [['source' => 'MX_LOCKER', 'source_raw_json' => '{"State Province":"Texas"}'], 'SHIPPING', 'US', 'TX'],
  [['source_raw_json' => '{"Buyer State":"NY","Tax State":"NY"}'], 'SHIPPING', 'US', null],
  [['source_raw_json' => '{"Ship To State":"TX"}'], 'BILLING', 'US', null],
  [['delivery_state' => 'VIC'], 'SHIPPING', 'US', null],
  [['delivery_state' => ['TX'], 'source_raw_json' => '{"Ship To State":["TX"]}'], 'SHIPPING', 'US', null],
  [['source_raw_json' => '{broken'], 'SHIPPING', 'US', null],
  [[], 'SHIPPING', 'US', null],
] as [$record, $type, $country, $expected]) {
  $assertSame($expected, oi_import_address_state($record, $type, $country), 'Source state ' . $type . ' / ' . json_encode($record));
}

// Connection-local temporary storage shadows the real table. No application
// addresses or orders are modified by these import integration tests.
$conn->query('CREATE TEMPORARY TABLE order_addresses (
  id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, type VARCHAR(20),
  name VARCHAR(255), company VARCHAR(255), company_id VARCHAR(50),
  street VARCHAR(255), city VARCHAR(255), zip VARCHAR(50), country VARCHAR(80),
  state VARCHAR(50), email VARCHAR(255), phone VARCHAR(80), UNIQUE KEY (order_id, type)
)');

try {
  // Execute the actual unified-import address writes, without importing products
  // or touching the other application tables.
  $source = file_get_contents(dirname(__DIR__) . '/scripts/import_darkscrub_unified.php');
  $start = strpos($source, "    oi_upsert_address(\$conn, \$orderId, 'BILLING', [");
  $end = strpos($source, '    // Add/update mode: refresh items', $start);
  if ($start === false || $end === false) throw new RuntimeException('Unified address writes not found.');
  $addressWrites = substr($source, $start, $end - $start);
  $save = static function (array $record, int $orderId) use ($conn, $addressWrites): void {
    $first = oi_normalize_unified_import_row($record);
    eval($addressWrites);
  };
  $getAddress = static function (int $id, string $type = 'SHIPPING') use ($conn): array {
    $stmt = $conn->prepare('SELECT * FROM order_addresses WHERE order_id = ? AND type = ?');
    $stmt->bind_param('is', $id, $type);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
  };
  $record = [
    'source' => 'EBAY', 'external_order_id' => 'ADDRESS-REGRESSION',
    'bill_street' => '1 Billing St', 'bill_city' => 'Example', 'bill_zip' => '90210', 'bill_country' => 'US',
    'delivery_street' => '12130 Example St', 'delivery_city' => 'San Antonio',
    'delivery_zip' => '78233-5953', 'delivery_country' => 'US',
    'source_raw_json' => '{"Buyer State":"CA","Ship To State":"TX","Ship To Zip":"78233-5953"}',
  ];
  $save($record, 1);
  $assertSame('TX', $getAddress(1)['state'], 'New order saves source shipping state');
  $assertSame('CA', $getAddress(1, 'BILLING')['state'], 'Billing state stays independent');
  $assertSame('78233-5953', $getAddress(1)['zip'], 'Full ZIP survives import');

  $conn->query("UPDATE order_addresses SET state = 'NY' WHERE order_id = 1 AND type = 'SHIPPING'");
  $save($record, 1);
  $assertSame('TX', $getAddress(1)['state'], 'Explicit source state replaces stale state on update');

  $withoutState = array_replace($record, ['source_raw_json' => '{}']);
  $save($withoutState, 1);
  $assertSame('TX', $getAddress(1)['state'], 'Missing source state preserves a manual state at the same address');
  $save(array_replace($withoutState, ['delivery_zip' => '90210']), 1);
  $assertSame(null, $getAddress(1)['state'], 'ZIP change clears stale state when source has no state');
  $assertSame('CA', ordersResolveAddressState('US', (string) $getAddress(1)['state'], $getAddress(1)['zip']), 'Updated ZIP supplies fallback');

  foreach (['delivery_street' => '2 Different St', 'delivery_city' => 'Different City', 'delivery_country' => 'CA'] as $field => $value) {
    $save($record, 1);
    $save(array_replace($withoutState, [$field => $value]), 1);
    $assertSame(null, $getAddress(1)['state'], 'Changed ' . $field . ' clears old state');
  }

  $save(array_replace($record, ['delivery_zip' => '']), 2);
  $assertSame('TX', $getAddress(2)['state'], 'Explicit source state works without ZIP');
  $save(array_replace($withoutState, ['delivery_zip' => '', 'bill_zip' => '']), 3);
  $assertSame(null, $getAddress(3)['state'], 'Missing state is not guessed from house number');
  $assertSame('', ordersResolveAddressState('US', (string) $getAddress(3)['state'], (string) $getAddress(3)['zip']), 'Unknown address state remains unknown');

  $save(array_replace($record, ['source_raw_json' => '{"Buyer State":"NY"}']), 4);
  $assertSame(null, $getAddress(4)['state'], 'Shipping does not inherit buyer state');

  // Optional verification against a real import, still only in temporary storage.
  if (!empty($argv[1])) {
    $matched = 0;
    foreach (oi_csv_read_assoc($argv[1]) as $row) {
      if (($row['external_order_id'] ?? '') !== '04-15453-93482') continue;
      $save($row, 100);
      $assertSame('TX', $getAddress(100)['state'], 'Original CSV imports TX');
      $assertSame('TX', $getAddress(100, 'BILLING')['state'], 'Original CSV imports billing TX');
      $assertSame('78233-5953', $getAddress(100)['zip'], 'Original CSV ZIP preserved');
      $matched++;
    }
    $assertSame(true, $matched > 0, 'Original order found in supplied CSV');
  }
} finally {
  $conn->query('DROP TEMPORARY TABLE IF EXISTS order_addresses');
}
echo 'orders_address_import_test: ', $checks, " checks passed\n";
