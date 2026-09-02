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

$todayOrders = dash_scalar($conn, "SELECT COUNT(*) FROM orders
  WHERE DATE(order_date) = CURDATE()
");

$inProgress = dash_scalar($conn, "SELECT COUNT(*) FROM orders
  WHERE status = 'IN_PROGRESS'
");

$readyToInvoice = dash_scalar($conn, "SELECT COUNT(*) FROM orders
  WHERE status = 'READY_TO_INVOICE'
");

$readyToShip = dash_scalar($conn, "SELECT COUNT(*) FROM orders
  WHERE status = 'READY_TO_SHIP'
");

$waitingBlocked = dash_scalar($conn, "SELECT COUNT(*) FROM orders
  WHERE traffic_light IN ('ORANGE','RED')
    AND status NOT IN ('SHIPPED','CANCELLED')
");

$shippedToday = dash_scalar($conn, "SELECT COUNT(DISTINCT order_id)
  FROM order_activity
  WHERE DATE(created_at) = CURDATE()
    AND (
      action = 'order_status_changed'
      OR action = 'status_changed'
    )
    AND (
      note LIKE '%SHIPPED%'
      OR payload LIKE '%SHIPPED%'
    )
");

$deptBlocked = [
  'G' => dash_scalar($conn, "SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (traffic_summary_json LIKE '%\"G\":\"ORANGE\"%' OR traffic_summary_json LIKE '%\"G\":\"RED\"%')
  "),
  'P' => dash_scalar($conn, "SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (traffic_summary_json LIKE '%\"P\":\"ORANGE\"%' OR traffic_summary_json LIKE '%\"P\":\"RED\"%')
  "),
  'F' => dash_scalar($conn, "SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (traffic_summary_json LIKE '%\"F\":\"ORANGE\"%' OR traffic_summary_json LIKE '%\"F\":\"RED\"%')
  "),
  'S' => dash_scalar($conn, "SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND (traffic_summary_json LIKE '%\"S\":\"ORANGE\"%' OR traffic_summary_json LIKE '%\"S\":\"RED\"%')
  "),
];

$deptActive = [
  'G' => dash_scalar($conn, "SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND traffic_summary_json LIKE '%\"G\":%'
  "),
  'P' => dash_scalar($conn, "SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND traffic_summary_json LIKE '%\"P\":%'
  "),
  'F' => dash_scalar($conn, "SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND traffic_summary_json LIKE '%\"F\":%'
  "),
  'S' => dash_scalar($conn, "SELECT COUNT(*) FROM orders
    WHERE status NOT IN ('SHIPPED','CANCELLED')
      AND traffic_summary_json LIKE '%\"S\":%'
  "),
];

$workload = dash_rows($conn, "SELECT 
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

$readyInvoiceRows = dash_rows($conn, "SELECT id, order_number, status, order_date
  FROM orders
  WHERE status = 'READY_TO_INVOICE'
  ORDER BY order_date ASC
  LIMIT 10
");

$readyShipRows = dash_rows($conn, "SELECT id, order_number, status, order_date, shipping_method
  FROM orders
  WHERE status = 'READY_TO_SHIP'
  ORDER BY order_date ASC
  LIMIT 10
");

$blockedRows = dash_rows($conn, "SELECT 
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

$countryMapRows = dash_rows($conn, "SELECT
    COALESCE(oa_ship.country, oa_bill.country, '??') AS country,
    COUNT(DISTINCT o.id) AS cnt
  FROM orders o
  LEFT JOIN order_addresses oa_ship
    ON oa_ship.order_id = o.id AND UPPER(oa_ship.type) = 'SHIPPING'
  LEFT JOIN order_addresses oa_bill
    ON oa_bill.order_id = o.id AND UPPER(oa_bill.type) = 'BILLING'
  WHERE o.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    AND COALESCE(UPPER(o.status), '') <> 'CANCELLED'
  GROUP BY country
  ORDER BY cnt DESC
");
$countryRows = array_slice($countryMapRows, 0, 10);
$countryMapValues = [];
foreach ($countryMapRows as $countryMapRow) {
  $countryCode = strtoupper(trim((string) ($countryMapRow['country'] ?? '')));
  if ($countryCode === 'UK') {
    $countryCode = 'GB';
  } elseif ($countryCode === 'UM') {
    $countryCode = 'US';
  }
  if (preg_match('/^[A-Z]{2}$/', $countryCode)) {
    $countryMapValues[strtolower($countryCode)] = (int) ($countryMapRow['cnt'] ?? 0);
  }
}

$dailyRows = dash_rows($conn, "SELECT DATE(order_date) AS d, COUNT(*) AS cnt
  FROM orders
  WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
  GROUP BY DATE(order_date)
  ORDER BY d ASC
");

/*
 * Seat Cover / Mid Fork sales origin statistics.
 * Checkbox upsells are explicitly tagged by the unified importer in
 * options_json._auto_generated. Everything else is a deliberately ordered
 * product: Seat Covers use department S and Mid Forks use the canonical G_MF
 * prefix from department_config.php. Bundle-created S items therefore remain
 * generic sales, while only SHOPTET checkbox items count as upsells.
 */
$addonSalesRows = dash_rows($conn, "SELECT
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    SUM(CASE
      WHEN oi.item_type_code = 'S'
       AND COALESCE(oi.options_json, '') NOT LIKE '%SHOPTET_AUTO_SEATCOVER%'
      THEN COALESCE(oi.qty, 1) ELSE 0 END) AS seat_generic,
    SUM(CASE
      WHEN COALESCE(oi.options_json, '') LIKE '%SHOPTET_AUTO_SEATCOVER%'
      THEN COALESCE(oi.qty, 1) ELSE 0 END) AS seat_upsell,
    SUM(CASE
      WHEN oi.item_type_code = 'G'
       AND (
         LEFT(UPPER(TRIM(COALESCE(oi.custom_label, ''))), 4) = 'G_MF'
         OR LEFT(UPPER(TRIM(COALESCE(oi.sku, ''))), 4) = 'G_MF'
       )
       AND COALESCE(oi.options_json, '') NOT LIKE '%SHOPTET_AUTO_MIDFORKS%'
      THEN COALESCE(oi.qty, 1) ELSE 0 END) AS mid_generic,
    SUM(CASE
      WHEN COALESCE(oi.options_json, '') LIKE '%SHOPTET_AUTO_MIDFORKS%'
      THEN COALESCE(oi.qty, 1) ELSE 0 END) AS mid_upsell
    ,SUM(CASE
      WHEN oi.item_type_code = 'G'
       AND (
         LEFT(UPPER(TRIM(COALESCE(oi.custom_label, ''))), 4) = 'GFP_'
         OR LEFT(UPPER(TRIM(COALESCE(oi.sku, ''))), 4) = 'GFP_'
       )
      THEN COALESCE(oi.qty, 1) ELSE 0 END) AS gfp_generic
    ,SUM(CASE
      WHEN COALESCE(oi.options_json, '') LIKE '%SHOPTET_AUTO_FITTING%'
      THEN COALESCE(oi.qty, 1) ELSE 0 END) AS gfp_upsell
  FROM order_items oi
  INNER JOIN orders o ON o.id = oi.order_id
  WHERE oi.deleted_at IS NULL
    AND COALESCE(UPPER(o.status), '') <> 'CANCELLED'
    AND o.order_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
  GROUP BY DATE_FORMAT(o.order_date, '%Y-%m')
  ORDER BY month_key ASC
");

$addonSalesByMonth = [];
foreach ($addonSalesRows as $addonRow) {
  $addonSalesByMonth[(string) ($addonRow['month_key'] ?? '')] = $addonRow;
}

$addonChartLabels = [];
$seatGenericData = [];
$seatUpsellData = [];
$midGenericData = [];
$midUpsellData = [];
$gfpGenericData = [];
$gfpUpsellData = [];
$addonTotals = [
  'seat_generic' => 0,
  'seat_upsell' => 0,
  'mid_generic' => 0,
  'mid_upsell' => 0,
  'gfp_generic' => 0,
  'gfp_upsell' => 0,
];
$monthCursor = new DateTimeImmutable('first day of this month');
$monthCursor = $monthCursor->modify('-11 months');
for ($monthIndex = 0; $monthIndex < 12; $monthIndex++) {
  $monthKey = $monthCursor->format('Y-m');
  $monthRow = $addonSalesByMonth[$monthKey] ?? [];
  $seatGeneric = (int) ($monthRow['seat_generic'] ?? 0);
  $seatUpsell = (int) ($monthRow['seat_upsell'] ?? 0);
  $midGeneric = (int) ($monthRow['mid_generic'] ?? 0);
  $midUpsell = (int) ($monthRow['mid_upsell'] ?? 0);
  $gfpGeneric = (int) ($monthRow['gfp_generic'] ?? 0);
  $gfpUpsell = (int) ($monthRow['gfp_upsell'] ?? 0);

  $addonChartLabels[] = $monthCursor->format('m/Y');
  $seatGenericData[] = $seatGeneric;
  $seatUpsellData[] = $seatUpsell;
  $midGenericData[] = $midGeneric;
  $midUpsellData[] = $midUpsell;
  $gfpGenericData[] = $gfpGeneric;
  $gfpUpsellData[] = $gfpUpsell;
  $addonTotals['seat_generic'] += $seatGeneric;
  $addonTotals['seat_upsell'] += $seatUpsell;
  $addonTotals['mid_generic'] += $midGeneric;
  $addonTotals['mid_upsell'] += $midUpsell;
  $addonTotals['gfp_generic'] += $gfpGeneric;
  $addonTotals['gfp_upsell'] += $gfpUpsell;
  $monthCursor = $monthCursor->modify('+1 month');
}

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

<style>
  .addon-sales-card-header {
    gap: 10px;
  }

  .addon-sales-card-title {
    flex: 1 1 280px;
    font-weight: 700;
  }

  .addon-sales-stats {
    display: flex;
    flex: 2 1 520px;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 8px;
  }

  .addon-sales-stat-group {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 36px;
    padding: 5px 9px;
    border: 1px solid rgba(255, 255, 255, .28);
    border-radius: 7px;
    background: #26323b;
    color: #f8f9fa;
    box-shadow: 0 2px 5px rgba(0, 0, 0, .18);
  }

  .addon-sales-stat-title {
    padding-right: 8px;
    border-right: 1px solid rgba(255, 255, 255, .20);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .02em;
    white-space: nowrap;
  }

  .addon-sales-stat-group.is-seat .addon-sales-stat-title {
    color: #61e586;
  }

  .addon-sales-stat-group.is-mid .addon-sales-stat-title {
    color: #5bd8eb;
  }

  .addon-sales-stat-group.is-gfp .addon-sales-stat-title {
    color: #d7a8ff;
  }

  .addon-sales-metric {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #d8dee3;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
  }

  .addon-sales-metric strong {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    padding: 3px 7px;
    border-radius: 10px;
    background: #f8f9fa;
    color: #17212a;
    font-size: 13px;
    line-height: 1.1;
  }

  .addon-sales-metric.is-upsell strong {
    background: #ffc107;
    color: #211a00;
  }

  .orders-world-map {
    position: relative;
    width: 100%;
    height: 360px;
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 6px;
    background: #252f36;
  }

  .jqvmap-label {
    z-index: 1060;
    background: #111827;
    color: #f8f9fa;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 4px;
    padding: 6px 8px;
    font-size: 12px;
    line-height: 1.2;
    box-shadow: 0 8px 18px rgba(0, 0, 0, .28);
  }

  .orders-world-map .jqvmap-zoomin,
  .orders-world-map .jqvmap-zoomout {
    box-sizing: content-box;
    z-index: 2;
    width: 16px;
    height: 16px;
    line-height: 16px;
    border: 1px solid rgba(255, 255, 255, .24);
    background: rgba(17, 24, 39, .92);
    color: #fff;
    font-weight: 700;
    user-select: none;
  }
  .orders-map-legend {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 8px;
    color: #adb5bd;
    font-size: 11px;
  }

  .orders-map-legend-gradient {
    width: 150px;
    height: 10px;
    border: 1px solid rgba(255, 255, 255, .22);
    border-radius: 5px;
    background: linear-gradient(90deg, #d8f3dc 0%, #69c27d 50%, #003b1f 100%);
  }

  @media (max-width: 767.98px) {
    .addon-sales-stats {
      flex-basis: 100%;
      justify-content: flex-start;
    }

    .orders-world-map {
      height: 280px;
    }
  }
</style>

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

    <!-- LEFT COLUMN -->
    <div class="col-md-4 d-flex flex-column" style="gap:10px;">

      <div class="card card-info flex-fill">
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

      <div class="card card-warning flex-fill">
        <div class="card-header">
          <h3 class="card-title">Unfinished Work by Department</h3>
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

    <!-- RIGHT COLUMN -->
    <div class="col-md-8 d-flex">

      <div class="card card-info flex-fill">
        <div class="card-header">
          <h3 class="card-title">Orders Last 14 Days</h3>
        </div>
        <div class="card-body d-flex">
          <div style="flex:1; position:relative;">
            <canvas id="orders14Chart"></canvas>
          </div>
        </div>
      </div>

    </div>

  </div>

  <div class="row">
    <div class="col-12">
      <div class="card card-success">
        <div class="card-header d-flex align-items-center flex-wrap addon-sales-card-header">
          <h3 class="card-title addon-sales-card-title">Generic vs Upsell Sales — Last 12 Months</h3>
          <div class="addon-sales-stats">
            <div class="addon-sales-stat-group is-seat">
              <span class="addon-sales-stat-title">Seat Cover</span>
              <span class="addon-sales-metric">Generic <strong><?= $addonTotals['seat_generic'] ?></strong></span>
              <span class="addon-sales-metric is-upsell">Upsell <strong><?= $addonTotals['seat_upsell'] ?></strong></span>
            </div>
            <div class="addon-sales-stat-group is-mid">
              <span class="addon-sales-stat-title">Mid Forks</span>
              <span class="addon-sales-metric">Generic <strong><?= $addonTotals['mid_generic'] ?></strong></span>
              <span class="addon-sales-metric is-upsell">Upsell <strong><?= $addonTotals['mid_upsell'] ?></strong></span>
            </div>
            <div class="addon-sales-stat-group is-gfp">
              <span class="addon-sales-stat-title">GFP / Applying</span>
              <span class="addon-sales-metric">Generic <strong><?= $addonTotals['gfp_generic'] ?></strong></span>
              <span class="addon-sales-metric is-upsell">Upsell <strong><?= $addonTotals['gfp_upsell'] ?></strong></span>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div style="height:320px; position:relative;">
            <canvas id="addonSalesChart"></canvas>
          </div>
          <div class="small text-muted mt-2">
            Generic = deliberately ordered product or bundle component. Upsell = item generated from the Shoptet
            “include Seat Cover / Mid Forks as displayed” or “Applying Graphics” checkbox. GFP generic counts only
            the main G row with a GFP_ SKU/code, so its generated P and F rows are not counted again. Cancelled orders are excluded.
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

  <div class="row align-items-stretch">

    <div class="col-md-6 d-flex">
      <div class="card card-danger flex-fill">
        <div class="card-header">
          <h3 class="card-title">Oldest Waiting / Blocked</h3>
        </div>

        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-hover mb-0">
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

                  <td><?= dashboardFlag((string) ($r['country_code'] ?? '')) ?></td>

                  <td><?= trafficBadgesFromJson($r['traffic_summary_json'] ?? '') ?></td>

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
        </div>
      </div>
    </div>

    <div class="col-md-6 d-flex">
      <div class="card card-secondary flex-fill">
        <div class="card-header">
          <h3 class="card-title">Department Workload</h3>
        </div>

        <div class="card-body d-flex flex-column justify-content-around">
          <?php
          $workloadMax = 0;
          foreach ($workload as $r) {
            $workloadMax = max($workloadMax, (int) $r['cnt']);
          }

          $typeLabels = [
            'G' => ['Graphics', 'badge-info'],
            'P' => ['Plastics', 'badge-primary'],
            'F' => ['Fitting', 'badge-danger'],
            'S' => ['Seat Cover', 'badge-success'],
            'T' => ['Trim Kit', 'badge-warning'],
            'M' => ['Bike Mats', 'badge-warning'],
          ];
          ?>

          <?php foreach ($workload as $r): ?>
            <?php
            $type = strtoupper((string) $r['type']);
            $cnt = (int) $r['cnt'];
            $percent = $workloadMax > 0 ? max(8, round(($cnt / $workloadMax) * 100)) : 0;

            $label = $typeLabels[$type][0] ?? $type;
            $badge = $typeLabels[$type][1] ?? 'badge-secondary';
            ?>

            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <div>
                  <span class="badge <?= $badge ?> mr-1"><?= htmlspecialchars($type) ?></span>
                  <strong><?= htmlspecialchars($label) ?></strong>
                </div>
                <span class="badge badge-light"><?= $cnt ?></span>
              </div>

              <div class="progress progress-sm">
                <div class="progress-bar" role="progressbar" style="width: <?= (int) $percent ?>%"
                  aria-valuenow="<?= (int) $percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>

  <div class="row align-items-stretch">
    <div class="col-md-4 d-flex">
      <div class="card card-info flex-fill">
        <div class="card-header">
          <h3 class="card-title">Top Countries 30d</h3>
        </div>

        <div class="card-body">
          <?php foreach ($countryRows as $r): ?>
            <div class="d-flex justify-content-between align-items-center border-bottom py-1">
              <span><?= dashboardFlag((string) $r['country']) ?></span>
              <b><?= (int) $r['cnt'] ?></b>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col-md-8 d-flex">
      <div class="card card-success flex-fill">
        <div class="card-header">
          <h3 class="card-title">Orders by Country — Last 30 Days</h3>
        </div>
        <div class="card-body p-2">
          <div id="ordersWorldMap" class="orders-world-map" aria-label="World map showing order volume by country"></div>
          <div class="orders-map-legend" aria-hidden="true">
            <span>Fewer orders</span>
            <span class="orders-map-legend-gradient"></span>
            <span>More orders</span>
          </div>
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

    const addonCanvas = document.getElementById('addonSalesChart');
    if (addonCanvas) {
      new Chart(addonCanvas, {
        type: 'bar',
        data: {
          labels: <?= json_encode($addonChartLabels, JSON_UNESCAPED_UNICODE) ?>,
          datasets: [{
            label: 'Seat Cover — Generic',
            stack: 'seat-cover',
            data: <?= json_encode($seatGenericData, JSON_UNESCAPED_UNICODE) ?>,
            backgroundColor: 'rgba(40, 167, 69, 0.82)',
            borderColor: '#28a745',
            borderWidth: 1
          }, {
            label: 'Seat Cover — Upsell',
            stack: 'seat-cover',
            data: <?= json_encode($seatUpsellData, JSON_UNESCAPED_UNICODE) ?>,
            backgroundColor: 'rgba(144, 238, 144, 0.82)',
            borderColor: '#90ee90',
            borderWidth: 1
          }, {
            label: 'Mid Fork — Generic',
            stack: 'mid-forks',
            data: <?= json_encode($midGenericData, JSON_UNESCAPED_UNICODE) ?>,
            backgroundColor: 'rgba(23, 162, 184, 0.82)',
            borderColor: '#17a2b8',
            borderWidth: 1
          }, {
            label: 'Mid Fork — Upsell',
            stack: 'mid-forks',
            data: <?= json_encode($midUpsellData, JSON_UNESCAPED_UNICODE) ?>,
            backgroundColor: 'rgba(91, 216, 235, 0.86)',
            borderColor: '#5bd8eb',
            borderWidth: 1
          }, {
            label: 'GFP — Generic',
            stack: 'gfp-applying',
            data: <?= json_encode($gfpGenericData, JSON_UNESCAPED_UNICODE) ?>,
            backgroundColor: 'rgba(111, 66, 193, 0.82)',
            borderColor: '#8d63d2',
            borderWidth: 1
          }, {
            label: 'Applying Graphics — Upsell',
            stack: 'gfp-applying',
            data: <?= json_encode($gfpUpsellData, JSON_UNESCAPED_UNICODE) ?>,
            backgroundColor: 'rgba(232, 174, 255, 0.86)',
            borderColor: '#e8aeff',
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          legend: {
            labels: {
              fontColor: '#ced4da'
            }
          },
          tooltips: {
            mode: 'index',
            intersect: false
          },
          scales: {
            xAxes: [{
              stacked: true,
              ticks: {
                fontColor: '#ced4da'
              },
              gridLines: {
                color: 'rgba(255,255,255,0.08)'
              }
            }],
            yAxes: [{
              stacked: true,
              ticks: {
                beginAtZero: true,
                precision: 0,
                fontColor: '#ced4da'
              },
              gridLines: {
                color: 'rgba(255,255,255,0.08)'
              },
              scaleLabel: {
                display: true,
                labelString: 'Pieces sold',
                fontColor: '#ced4da'
              }
            }]
          }
        }
      });
    }

    function dashboardOrderCountWord(count) {
      const lastTwo = count % 100;
      const last = count % 10;
      if (count === 1) return 'objednávka';
      if ((lastTwo < 12 || lastTwo > 14) && last >= 2 && last <= 4) return 'objednávky';
      return 'objednávok';
    }
    const countryMapValues = <?= json_encode($countryMapValues, JSON_UNESCAPED_UNICODE) ?>;
    const worldMap = $('#ordersWorldMap');
    if (worldMap.length && typeof $.fn.vectorMap === 'function') {
      try {
        worldMap.vectorMap({
          map: 'world_en',
          backgroundColor: 'transparent',
          color: '#46535c',
          borderColor: '#6c7a84',
          borderWidth: 0.7,
          borderOpacity: 0.55,
          hoverColor: '#4fce72',
          hoverOpacity: 0.9,
          selectedColor: null,
          enableZoom: true,
          showTooltip: true,
          values: countryMapValues,
          scaleColors: ['#d8f3dc', '#003b1f'],
          normalizeFunction: 'polynomial',
          onLabelShow: function (event, label, code) {
            const orderCount = Number(countryMapValues[String(code).toLowerCase()] || 0);
            label.text(label.text() + ': ' + orderCount + ' ' + dashboardOrderCountWord(orderCount));
          },
          onRegionClick: function (event) {
            event.preventDefault();
          }
        });

        const mapElement = worldMap.get(0);
        let wheelZoomLocked = false;

        mapElement.addEventListener('wheel', function (event) {
          const mapObject = worldMap.data('mapObject');
          if (!mapObject || event.deltaY === 0) {
            return;
          }

          const zoomingIn = event.deltaY < 0;
          const canZoom = zoomingIn
            ? mapObject.zoomCurStep < mapObject.zoomMaxStep
            : mapObject.zoomCurStep > 1;

          // At the zoom limits, leave the wheel available for normal page scrolling.
          if (!canZoom) {
            return;
          }

          event.preventDefault();
          if (wheelZoomLocked) {
            return;
          }

          wheelZoomLocked = true;
          if (zoomingIn) {
            mapObject.zoomIn();
          } else {
            mapObject.zoomOut();
          }

          window.setTimeout(function () {
            wheelZoomLocked = false;
          }, 90);
        }, { passive: false });
      } catch (mapError) {
        worldMap.html('<div class="text-muted p-3">World map could not be loaded.</div>');
        console.error(mapError);
      }
    }
  });
</script>
