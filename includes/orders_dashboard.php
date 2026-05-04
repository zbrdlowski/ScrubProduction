<?php
declare(strict_types=1);

/** @var mysqli $conn */
if (!isset($conn) || !$conn instanceof mysqli) {
  require_once __DIR__ . '/conn.php';
}

function dash_scalar(mysqli $conn, string $sql): int {
  $res = $conn->query($sql);
  if (!$res) return 0;
  $row = $res->fetch_row();
  return (int)($row[0] ?? 0);
}

function dash_rows(mysqli $conn, string $sql): array {
  $res = $conn->query($sql);
  if (!$res) return [];
  $rows = [];
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  return $rows;
}

$todayOrders = dash_scalar($conn, "
  SELECT COUNT(*) FROM orders
  WHERE DATE(order_date) = CURDATE()
");

$inProgress = dash_scalar($conn, "
  SELECT COUNT(*) FROM orders
  WHERE status = 'IN_PROGRESS'
");

$readyToInvoice = dash_scalar($conn, "
  SELECT COUNT(*) FROM orders
  WHERE status = 'READY_TO_INVOICE'
");

$readyToShip = dash_scalar($conn, "
  SELECT COUNT(*) FROM orders
  WHERE status = 'READY_TO_SHIP'
");

$waitingBlocked = dash_scalar($conn, "
  SELECT COUNT(*) FROM orders
  WHERE traffic_light IN ('ORANGE','RED')
    AND status NOT IN ('SHIPPED','CANCELLED')
");

$shippedToday = dash_scalar($conn, "
  SELECT COUNT(*) FROM orders
  WHERE status = 'SHIPPED'
    AND DATE(imported_at) = CURDATE()
");

$deptWaiting = [
  'G' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (
        traffic_summary_json LIKE '%\"G\":\"ORANGE\"%'
        OR traffic_summary_json LIKE '%\"G\":\"RED\"%'
      )
  "),
  'P' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (
        traffic_summary_json LIKE '%\"P\":\"ORANGE\"%'
        OR traffic_summary_json LIKE '%\"P\":\"RED\"%'
      )
  "),
  'F' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (
        traffic_summary_json LIKE '%\"F\":\"ORANGE\"%'
        OR traffic_summary_json LIKE '%\"F\":\"RED\"%'
      )
  "),
  'S' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (
        traffic_summary_json LIKE '%\"S\":\"ORANGE\"%'
        OR traffic_summary_json LIKE '%\"S\":\"RED\"%'
      )
  "),
];

$workload = dash_rows($conn, "
  SELECT 
    oi.item_type_code AS type,
    COUNT(*) AS cnt
  FROM order_items oi
  JOIN orders o ON o.id = oi.order_id
  WHERE oi.deleted_at IS NULL
    AND oi.item_type_code IS NOT NULL
    AND oi.item_type_code <> ''
    AND o.status NOT IN ('SHIPPED','CANCELLED')
  GROUP BY oi.item_type_code
  ORDER BY cnt DESC
");

$readyInvoiceRows = dash_rows($conn, "
  SELECT id, order_number, status, order_date
  FROM orders
  WHERE status = 'READY_TO_INVOICE'
  ORDER BY order_date ASC
  LIMIT 10
");

$readyShipRows = dash_rows($conn, "
  SELECT id, order_number, status, order_date, shipping_method
  FROM orders
  WHERE status = 'READY_TO_SHIP'
  ORDER BY order_date ASC
  LIMIT 10
");

$blockedRows = dash_rows($conn, "
  SELECT id, order_number, status, traffic_light, traffic_summary_json, order_date
  FROM orders
  WHERE traffic_light IN ('ORANGE','RED')
    AND status NOT IN ('SHIPPED','CANCELLED')
  ORDER BY order_date ASC
  LIMIT 10
");

$countryRows = dash_rows($conn, "
  SELECT 
    COALESCE(oa_ship.country, oa_bill.country, '??') AS country,
    COUNT(*) AS cnt
  FROM orders o
  LEFT JOIN order_addresses oa_ship
    ON oa_ship.order_id = o.id AND UPPER(oa_ship.type) = 'SHIPPING'
  LEFT JOIN order_addresses oa_bill
    ON oa_bill.order_id = o.id AND UPPER(oa_bill.type) = 'BILLING'
  WHERE o.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  GROUP BY country
  ORDER BY cnt DESC
  LIMIT 10
");

$dailyRows = dash_rows($conn, "
  SELECT DATE(order_date) AS d, COUNT(*) AS cnt
  FROM orders
  WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
  GROUP BY DATE(order_date)
  ORDER BY d ASC
");

$chartLabels = [];
$chartData = [];
foreach ($dailyRows as $r) {
  $chartLabels[] = $r['d'];
  $chartData[] = (int)$r['cnt'];
}

function trafficBadgesFromJson(?string $json): string {
  $summary = json_decode((string)$json, true);
  if (!is_array($summary)) return '';

  $order = ['G','F','P','S'];
  $html = '';

  foreach ($order as $type) {
    if (!isset($summary[$type])) continue;

    $state = strtoupper((string)$summary[$type]);
    $class = 'badge-danger';

    if ($state === 'GREEN') $class = 'badge-success';
    elseif ($state === 'ORANGE') $class = 'badge-warning';

    $html .= '<span class="badge '.$class.' mr-1">'.$type.'</span>';
  }

  return $html;
}
?>

<div class="container-fluid">

  <div class="row">
    <div class="col-lg-2 col-6">
      <div class="small-box bg-info">
        <div class="inner"><h3><?= $todayOrders ?></h3><p>Orders Today</p></div>
        <div class="icon"><i class="fas fa-shopping-cart"></i></div>
      </div>
    </div>

    <div class="col-lg-2 col-6">
      <div class="small-box bg-warning">
        <div class="inner"><h3><?= $inProgress ?></h3><p>In Progress</p></div>
        <div class="icon"><i class="fas fa-cogs"></i></div>
      </div>
    </div>

    <div class="col-lg-2 col-6">
      <div class="small-box bg-orange">
        <div class="inner"><h3><?= $readyToInvoice ?></h3><p>Ready to Invoice</p></div>
        <div class="icon"><i class="fas fa-file-invoice"></i></div>
      </div>
    </div>

    <div class="col-lg-2 col-6">
      <div class="small-box bg-teal">
        <div class="inner"><h3><?= $readyToShip ?></h3><p>Ready to Ship</p></div>
        <div class="icon"><i class="fas fa-truck"></i></div>
      </div>
    </div>

    <div class="col-lg-2 col-6">
      <div class="small-box bg-danger">
        <div class="inner"><h3><?= $waitingBlocked ?></h3><p>Waiting / Blocked</p></div>
        <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
      </div>
    </div>

    <div class="col-lg-2 col-6">
      <div class="small-box bg-success">
        <div class="inner"><h3><?= $shippedToday ?></h3><p>Shipped Today</p></div>
        <div class="icon"><i class="fas fa-check"></i></div>
      </div>
    </div>
  </div>

  <div class="row">

    <div class="col-md-4">
      <div class="card card-warning">
        <div class="card-header"><h3 class="card-title">Production Semaphore</h3></div>
        <div class="card-body">
          <a href="index.php?page=orders&type=G" class="btn btn-block btn-outline-info text-left">
            Graphics <span class="float-right badge badge-warning"><?= $deptWaiting['G'] ?></span>
          </a>
          <a href="index.php?page=orders&type=P" class="btn btn-block btn-outline-primary text-left">
            Plastics <span class="float-right badge badge-warning"><?= $deptWaiting['P'] ?></span>
          </a>
          <a href="index.php?page=orders&type=F" class="btn btn-block btn-outline-danger text-left">
            Fitting <span class="float-right badge badge-warning"><?= $deptWaiting['F'] ?></span>
          </a>
          <a href="index.php?page=orders&type=S" class="btn btn-block btn-outline-success text-left">
            Seat Cover <span class="float-right badge badge-warning"><?= $deptWaiting['S'] ?></span>
          </a>
        </div>
      </div>
    </div>

    <div class="col-md-8">
      <div class="card card-info">
        <div class="card-header"><h3 class="card-title">Orders Last 14 Days</h3></div>
        <div class="card-body">
          <div class="chart" style="height:260px; position:relative;">
          <canvas id="orders14Chart"></canvas>
        </div>
        </div>
      </div>
    </div>

  </div>

  <div class="row">

    <div class="col-md-6">
      <div class="card card-success">
        <div class="card-header"><h3 class="card-title">Ready to Invoice</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-hover">
            <thead><tr><th>Order</th><th>Date</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($readyInvoiceRows as $r): ?>
              <tr>
                <td><a href="index.php?page=orders&q=<?= htmlspecialchars($r['order_number']) ?>"><?= htmlspecialchars($r['order_number']) ?></a></td>
                <td><?= htmlspecialchars((string)$r['order_date']) ?></td>
                <td><span class="badge badge-warning"><?= htmlspecialchars($r['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card card-primary">
        <div class="card-header"><h3 class="card-title">Ready to Ship</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-hover">
            <thead><tr><th>Order</th><th>Date</th><th>Shipping</th></tr></thead>
            <tbody>
            <?php foreach ($readyShipRows as $r): ?>
              <tr>
                <td><a href="index.php?page=orders&q=<?= htmlspecialchars($r['order_number']) ?>"><?= htmlspecialchars($r['order_number']) ?></a></td>
                <td><?= htmlspecialchars((string)$r['order_date']) ?></td>
                <td><?= htmlspecialchars((string)$r['shipping_method']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>

  <div class="row">

    <div class="col-md-6">
      <div class="card card-danger">
        <div class="card-header"><h3 class="card-title">Oldest Waiting / Blocked</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-hover">
            <thead><tr><th>Order</th><th>Traffic</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($blockedRows as $r): ?>
              <tr>
                <td><a href="index.php?page=orders&q=<?= htmlspecialchars($r['order_number']) ?>"><?= htmlspecialchars($r['order_number']) ?></a></td>
                <td><?= trafficBadgesFromJson($r['traffic_summary_json'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['status']) ?></td>
                <td><?= htmlspecialchars((string)$r['order_date']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-md-3">
      <div class="card card-secondary">
        <div class="card-header"><h3 class="card-title">Department Workload</h3></div>
        <div class="card-body">
          <?php foreach ($workload as $r): ?>
            <div class="d-flex justify-content-between border-bottom py-1">
              <span><?= htmlspecialchars((string)$r['type']) ?></span>
              <b><?= (int)$r['cnt'] ?></b>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col-md-3">
      <div class="card card-info">
        <div class="card-header"><h3 class="card-title">Top Countries 30d</h3></div>
        <div class="card-body">
          <?php foreach ($countryRows as $r): ?>
            <div class="d-flex justify-content-between border-bottom py-1">
              <span><?= htmlspecialchars((string)$r['country']) ?></span>
              <b><?= (int)$r['cnt'] ?></b>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>

</div>

<script>
$(function () {
  const ctx = document.getElementById('orders14Chart').getContext('2d');

new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
      label: 'Orders',
      data: <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>,
      fill: false,
      tension: 0.25,
      borderColor: '#17a2b8',
      backgroundColor: '#17a2b8',
      pointBackgroundColor: '#17a2b8',
      pointBorderColor: '#17a2b8'
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    legend: {
      display: false
    },
    scales: {
      xAxes: [{
        ticks: {
          fontColor: '#ced4da'
        },
        gridLines: {
          color: 'rgba(255,255,255,0.08)'
        }
      }],
      yAxes: [{
        ticks: {
          beginAtZero: true,
          precision: 0,
          fontColor: '#ced4da'
        },
        gridLines: {
          color: 'rgba(255,255,255,0.08)'
        }
      }]
    }
  }
});
</script>