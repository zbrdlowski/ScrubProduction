<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/includes/orders_product_filters.php';

// Connection-local temporary tables shadow the real tables. No application
// orders are inserted, updated or deleted by this integration test.
$conn->query('CREATE TEMPORARY TABLE orders (
  id INT PRIMARY KEY, status VARCHAR(32), customer_id INT
)');
$conn->query('CREATE TEMPORARY TABLE order_items (
  order_id INT, item_type_code VARCHAR(8), sku VARCHAR(255), custom_label VARCHAR(255),
  internal_options_json LONGTEXT, options_json LONGTEXT, deleted_at DATETIME NULL
)');

try {
  $fixtures = [
    // Imported kits are split into production departments.
    [1, 'G', 'TFP_SE001', '', null, null, null],
    [1, 'P', 'TFP_SE001', '', null, null, null],
    [1, 'F', 'TFP_SE001', '', null, null, null],
    [2, 'P', '', ' tfps_SE002 | EXACT YEAR W ', '', '', null],
    [3, 'F', 'TF', '', null, null, null],
    [4, ' t ', '', '', null, null, null],
    [5, 'G', 'GFP_SE001', '', null, null, null],
    [6, 'G', 'TFPX_SE001', '', null, null, null],
    [7, 'T', 'TFP_DELETED', '', null, null, '2026-01-01 00:00:00'],
    // Bike mats can be identified from SKU, label or stored metadata.
    [8, 'G', 'G_MC004', '', null, null, null],
    [8, 'P', 'G_MC004', '', null, null, null],
    [9, 'P', '', ' g_mc-CUSTOM | Rider ', null, null, null],
    [10, 'G', 'MANUAL', '', '{"_subcat": " moto_carpet "}', '{}', null],
    [11, 'P', 'MANUAL', '', '{}', '{"_auto_generated": "MOTO_CARPET_AUTO_PLASTICS"}', null],
    [12, ' m ', '', '', null, null, null],
    [13, 'G', 'GXMC004', '', '{}', '{}', null],
    [14, 'G', 'G_ST001', '', '{broken', 'not-json', null],
    [15, 'M', 'G_MC004', '', '{"_subcat":"MOTO_CARPET"}', null, '2026-01-01 00:00:00'],
    // An order containing both families must appear only once.
    [16, 'G', 'TFP_SE001', '', null, null, null],
    [16, 'P', 'G_MC001', '', null, null, null],
    [17, 'P', 'TFP', '', null, null, null],
    [18, 'G', 'G_MC004', '', null, null, null],
    [19, 'G', null, null, null, null, null],
    [20, 'G', 'TFP | EXACT YEAR W', '', null, null, null],
    [21, 'G', 'TFP_SE001', '', null, null, null],
    [22, 'G', 'TFP_SE001', '', null, null, null],
    [23, 'G', 'TFP_SE001', '', null, null, null],
    [24, 'G', 'TFP_SE001', '', null, null, null],
  ];
  $orderInsert = $conn->prepare('INSERT INTO orders (id, status, customer_id) VALUES (?, ?, ?)');
  foreach (array_unique(array_column($fixtures, 0)) as $id) {
    $status = [17 => 'SHIPPED', 21 => 'CANCELLED', 22 => 'PENDING', 23 => 'DELIVERED'][$id] ?? 'IN_PROGRESS';
    $customer = $id === 18 ? 2 : 1;
    $orderInsert->bind_param('isi', $id, $status, $customer);
    $orderInsert->execute();
  }
  $orderInsert->close();

  $itemInsert = $conn->prepare('INSERT INTO order_items VALUES (?, ?, ?, ?, ?, ?, ?)');
  foreach ($fixtures as $fixture) {
    $itemInsert->bind_param('issssss', ...$fixture);
    $itemInsert->execute();
  }
  $itemInsert->close();

  $assertOrders = static function (string $type, array $expected, string $extra = '') use ($conn): void {
    $where = ordersProductFamilyWhere($type);
    $result = $conn->query("SELECT o.id FROM orders o WHERE $where $extra ORDER BY o.id");
    $actual = array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'id'));
    if ($actual !== $expected) {
      throw new RuntimeException($type . $extra . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
  };

  $assertOrders('T', [1, 2, 3, 4, 16, 17, 20, 21, 22, 23, 24]);
  $assertOrders('M', [8, 9, 10, 11, 12, 16, 18]);
  $assertOrders('TM', [1, 2, 3, 4, 8, 9, 10, 11, 12, 16, 17, 18, 20, 21, 22, 23, 24]);
  $assertOrders('TM', [1, 2, 3, 4, 8, 9, 10, 11, 12, 16, 18, 20, 24],
    "AND o.status NOT IN ('SHIPPED', 'CANCELLED', 'PENDING', 'DELIVERED')");
  $assertOrders('M', [18], 'AND o.customer_id = 2');
  $assertOrders('T', [17], "AND o.status = 'SHIPPED'");

  $hiddenStatuses = ['SHIPPED', 'CANCELLED', 'PENDING', 'DELIVERED'];
  foreach ([
    [0, $hiddenStatuses, ['T' => 7, 'M' => 7, 'TM' => 13]],
    [1, $hiddenStatuses, ['T' => 7, 'M' => 6, 'TM' => 12]],
    [2, $hiddenStatuses, ['T' => 0, 'M' => 1, 'TM' => 1]],
    [999, $hiddenStatuses, ['T' => 0, 'M' => 0, 'TM' => 0]],
    [0, [], ['T' => 11, 'M' => 7, 'TM' => 17]],
  ] as [$customerId, $excludedStatuses, $expectedCounts]) {
    $actualCounts = ordersGetProductFamilyCounts($conn, $excludedStatuses, $customerId);
    if ($actualCounts !== $expectedCounts) {
      throw new RuntimeException('Product tab counts: expected ' . json_encode($expectedCounts)
        . ', got ' . json_encode($actualCounts));
    }
  }

  // The row highlight uses the same predicate as the combined filter.
  $where = ordersProductFamilyWhere('TM');
  $highlight = $conn->query("SELECT $where AS has_tm FROM orders o WHERE o.id = 16")->fetch_assoc();
  if ((int) $highlight['has_tm'] !== 1) {
    throw new RuntimeException('Mixed product order must be highlighted');
  }

  echo "orders_product_filters_test: OK\n";
} finally {
  $conn->query('DROP TEMPORARY TABLE order_items');
  $conn->query('DROP TEMPORARY TABLE orders');
}
