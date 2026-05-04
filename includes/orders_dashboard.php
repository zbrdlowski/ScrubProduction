<?php
declare(strict_types=1);

/** @var mysqli $conn */
if (!isset($conn) || !$conn instanceof mysqli) {
  require_once __DIR__ . '/conn.php';
}

function dash_scalar(mysqli $conn, string $sql): int
{
  $res = $conn->query($sql);
  if (!$res)
    return 0;
  $row = $res->fetch_row();
  return (int) ($row[0] ?? 0);
}

function dash_rows(mysqli $conn, string $sql): array
{
  $res = $conn->query($sql);
  if (!$res)
    return [];
  $rows = [];
  while ($r = $res->fetch_assoc())
    $rows[] = $r;
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

$deptBlocked = [
  'G' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (traffic_summary_json LIKE '%\"G\":\"ORANGE\"%' OR traffic_summary_json LIKE '%\"G\":\"RED\"%')
  "),
  'P' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (traffic_summary_json LIKE '%\"P\":\"ORANGE\"%' OR traffic_summary_json LIKE '%\"P\":\"RED\"%')
  "),
  'F' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (traffic_summary_json LIKE '%\"F\":\"ORANGE\"%' OR traffic_summary_json LIKE '%\"F\":\"RED\"%')
  "),
  'S' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (traffic_summary_json LIKE '%\"S\":\"ORANGE\"%' OR traffic_summary_json LIKE '%\"S\":\"RED\"%')
  "),
];

$deptActive = [
  'G' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND traffic_summary_json LIKE '%\"G\":%'
  "),
  'P' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND traffic_summary_json LIKE '%\"P\":%'
  "),
  'F' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND traffic_summary_json LIKE '%\"F\":%'
  "),
  'S' => dash_scalar($conn, "
    SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND traffic_summary_json LIKE '%\"S\":%'
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
  SELECT 
    o.id,
    o.order_number,
    o.external_order_id,
    o.status,
    o.traffic_light,
    o.traffic_summary_json,
    o.order_date,
    o.manual_types_override,
    cu.name AS customer_name,
    cu.email AS customer_email,
    COALESCE(oa_ship.country, oa_bill.country) AS country_code,

    (
      SELECT GROUP_CONCAT(DISTINCT oi.item_type_code ORDER BY oi.item_type_code SEPARATOR '')
      FROM order_items oi
      WHERE oi.order_id = o.id
        AND oi.deleted_at IS NULL
        AND oi.item_type_code IS NOT NULL
        AND oi.item_type_code <> ''
    ) AS item_types

  FROM orders o
  LEFT JOIN customers cu ON cu.id = o.customer_id
  LEFT JOIN order_addresses oa_ship
    ON oa_ship.order_id = o.id AND UPPER(oa_ship.type) = 'SHIPPING'
  LEFT JOIN order_addresses oa_bill
    ON oa_bill.order_id = o.id AND UPPER(oa_bill.type) = 'BILLING'
  WHERE o.traffic_light IN ('ORANGE','RED')
    AND o.status NOT IN ('SHIPPED','CANCELLED')
  ORDER BY o.order_date ASC
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
  $chartData[] = (int) $r['cnt'];
}

function trafficBadgesFromJson(?string $json): string
{
  $summary = json_decode((string) $json, true);
  if (!is_array($summary))
    return '';

  $order = ['G', 'F', 'P', 'S'];
  $html = '';

  foreach ($order as $type) {
    if (!isset($summary[$type]))
      continue;

    $state = strtoupper((string) $summary[$type]);
    $class = 'badge-danger';

    if ($state === 'GREEN')
      $class = 'badge-success';
    elseif ($state === 'ORANGE')
      $class = 'badge-warning';

    $html .= '<span class="badge ' . $class . ' mr-1">' . $type . '</span>';
  }

  return $html;
}
function dashboardFlag(string $code): string
{
  $code = strtoupper(trim($code));

  if ($code === '')
    return '—';
  if ($code === 'UK')
    $code = 'GB';
  if ($code === 'UM')
    $code = 'US';

  $lower = strtolower($code);

  return '<span style="white-space:nowrap;">
    <img src="https://flagcdn.com/16x12/' . htmlspecialchars($lower) . '.png"
         alt="' . htmlspecialchars($code) . '"
         style="margin-right:5px; vertical-align:-1px;">
    ' . htmlspecialchars($code) . '
  </span>';
}
?>

<div class="container-fluid">

  <div class="row">
    <div class="col-lg-2 col-6">
      <div class="small-box bg-info">
        <div class="inner">
          <h3><?= $todayOrders ?></h3>
          <p>Orders Today</p>
        </div>
        <div class="icon"><i class="fas fa-shopping-cart"></i></div>
      </div>
    </div>

    <div class="col-lg-2 col-6">
      <div class="small-box bg-warning">
        <div class="inner">
          <h3><?= $inProgress ?></h3>
          <p>In Progress</p>
        </div>
        <div class="icon"><i class="fas fa-cogs"></i></div>
      </div>
    </div>

    <div class="col-lg-2 col-6">
      <div class="small-box bg-orange">
        <div class="inner">
          <h3><?= $readyToInvoice ?></h3>
          <p>Ready to Invoice</p>
        </div>
        <div class="icon"><i class="fas fa-file-invoice"></i></div>
      </div>
    </div>

    <div class="col-lg-2 col-6">
      <div class="small-box bg-teal">
        <div class="inner">
          <h3><?= $readyToShip ?></h3>
          <p>Ready to Ship</p>
        </div>
        <div class="icon"><i class="fas fa-truck"></i></div>
      </div>
    </div>

    <div class="col-lg-2 col-6">
      <div class="small-box bg-danger">
        <div class="inner">
          <h3><?= $waitingBlocked ?></h3>
          <p>Waiting / Blocked</p>
        </div>
        <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
      </div>
    </div>

    <div class="col-lg-2 col-6">
      <div class="small-box bg-success">
        <div class="inner">
          <h3><?= $shippedToday ?></h3>
          <p>Shipped Today</p>
        </div>
        <div class="icon"><i class="fas fa-check"></i></div>
      </div>
    </div>
  </div>

  <div class="row">

    <div class="col-md-4">
      <div class="card card-info">
        <div class="card-header">
          <h3 class="card-title">Active Work by Department</h3>
        </div>
        <div class="card-body">
          <a href="index.php?page=orders&type=G" class="btn btn-block btn-outline-info text-left">
            Graphics <span class="float-right badge badge-info"><?= $deptActive['G'] ?></span>
          </a>
          <a href="index.php?page=orders&type=P" class="btn btn-block btn-outline-primary text-left">
            Plastics <span class="float-right badge badge-primary"><?= $deptActive['P'] ?></span>
          </a>
          <a href="index.php?page=orders&type=F" class="btn btn-block btn-outline-danger text-left">
            Fitting <span class="float-right badge badge-danger"><?= $deptActive['F'] ?></span>
          </a>
          <a href="index.php?page=orders&type=S" class="btn btn-block btn-outline-success text-left">
            Seat Cover <span class="float-right badge badge-success"><?= $deptActive['S'] ?></span>
          </a>
        </div>
      </div>

      <div class="card card-warning">
        <div class="card-header">
          <h3 class="card-title">Waiting / Blocked by Department</h3>
        </div>
        <div class="card-body">
          <a href="index.php?page=orders&type=G" class="btn btn-block btn-outline-warning text-left">
            Graphics <span class="float-right badge badge-warning"><?= $deptBlocked['G'] ?></span>
          </a>
          <a href="index.php?page=orders&type=P" class="btn btn-block btn-outline-warning text-left">
            Plastics <span class="float-right badge badge-warning"><?= $deptBlocked['P'] ?></span>
          </a>
          <a href="index.php?page=orders&type=F" class="btn btn-block btn-outline-warning text-left">
            Fitting <span class="float-right badge badge-warning"><?= $deptBlocked['F'] ?></span>
          </a>
          <a href="index.php?page=orders&type=S" class="btn btn-block btn-outline-warning text-left">
            Seat Cover <span class="float-right badge badge-warning"><?= $deptBlocked['S'] ?></span>
          </a>
        </div>
      </div>
    </div>

    <div class="col-md-8">
      <div class="card card-info">
        <div class="card-header">
          <h3 class="card-title">Orders Last 14 Days</h3>
        </div>
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
        <div class="card-header">
          <h3 class="card-title">Ready to Invoice</h3>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>Order</th>
                <th>Date</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($readyInvoiceRows as $r): ?>
                <tr>
                  <td><a
                      href="index.php?page=orders&q=<?= htmlspecialchars($r['order_number']) ?>"><?= htmlspecialchars($r['order_number']) ?></a>
                  </td>
                  <td><?= htmlspecialchars((string) $r['order_date']) ?></td>
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
        <div class="card-header">
          <h3 class="card-title">Ready to Ship</h3>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>Order</th>
                <th>Date</th>
                <th>Shipping</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($readyShipRows as $r): ?>
                <tr>
                  <td><a
                      href="index.php?page=orders&q=<?= htmlspecialchars($r['order_number']) ?>"><?= htmlspecialchars($r['order_number']) ?></a>
                  </td>
                  <td><?= htmlspecialchars((string) $r['order_date']) ?></td>
                  <td><?= htmlspecialchars((string) $r['shipping_method']) ?></td>
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
        <div class="card-header">
          <h3 class="card-title">Oldest Waiting / Blocked</h3>
        </div>
        <div class="card-body table-responsive p-0">

          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>Order</th>
                <th>Type</th>
                <th>Customer</th>
                <th>Country</th>
                <th>Traffic</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($blockedRows as $r): ?>
                <?php
                $orderNo = $r['order_number'] ?: $r['external_order_id'] ?: $r['id'];

                $customer = trim((string) ($r['customer_name'] ?? ''));
                if ($customer === '') {
                  $customer = (string) ($r['customer_email'] ?? '-');
                }

                $types = strtoupper((string) ($r['manual_types_override'] ?: $r['item_types'] ?: '-'));
                $types = str_replace([' ', ','], '', $types);
                ?>
                <tr>
                  <td>
                    <a href="index.php?page=orders&q=<?= htmlspecialchars((string) $orderNo) ?>">
                      <b><?= htmlspecialchars((string) $orderNo) ?></b>
                    </a>
                  </td>

                  <td>
                    <span class="badge badge-secondary">
                      <?= htmlspecialchars($types) ?>
                    </span>
                  </td>

                  <td><?= htmlspecialchars($customer) ?></td>

                  <td>
                    <?= dashboardFlag((string) ($r['country_code'] ?? '')) ?>
                  </td>

                  <td>
                    <?= trafficBadgesFromJson($r['traffic_summary_json'] ?? '') ?>
                  </td>

                  <td>
                    <span class="badge badge-warning">
                      <?= htmlspecialchars(str_replace('_', ' ', (string) $r['status'])) ?>
                    </span>
                  </td>

                  <td><?= htmlspecialchars((string) $r['order_date']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-md-3">
      <div class="card card-secondary">
        <div class="card-header">
          <h3 class="card-title">Department Workload</h3>
        </div>
        <div class="card-body">
          <?php foreach ($workload as $r): ?>
            <div class="d-flex justify-content-between border-bottom py-1">
              <span><?= htmlspecialchars((string) $r['type']) ?></span>
              <b><?= (int) $r['cnt'] ?></b>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col-md-3">
      <div class="card card-info">
        <div class="card-header">
          <h3 class="card-title">Top Countries 30d</h3>
        </div>
        <div class="card-body">
          <?php $i = 0;
          foreach ($countryRows as $r):
            $i++; ?>
            <div
              class="d-flex justify-content-between border-bottom py-1 <?= $i === 1 ? 'font-weight-bold text-warning' : '' ?>">
              <span>
                <?= dashboardFlag((string) $r['country']) ?>
              </span>
              <b><?= (int) $r['cnt'] ?></b>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>

</div>

<script>
  $(function () {
    const canvas = document.getElementById('orders14Chart');

    if (!canvas) {
      console.log('orders14Chart canvas not found');
      return;
    }

    const chartLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
    const chartData = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;

    console.log('Orders chart labels:', chartLabels);
    console.log('Orders chart data:', chartData);

    new Chart(canvas, {
      type: 'line',
      data: {
        labels: chartLabels,
        datasets: [{
          label: 'Orders',
          data: chartData,
          fill: false,
          lineTension: 0.25,
          borderColor: '#17a2b8',
          backgroundColor: '#17a2b8',
          pointBackgroundColor: '#17a2b8',
          pointBorderColor: '#17a2b8',
          pointRadius: 4,
          pointHoverRadius: 6
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
  });
</script>