<?php
session_start();
require_once('includes/conn.php');
?>
<style>
  .yellow-star {
    color: gold;
  }
.info-box {
  display: block;
  min-height: 180px;
  background: #f7f7f7;
  box-shadow: 0 1px 1px rgba(0,0,0,0.1);
  margin-bottom: 15px;
  border-radius: 4px;
  color: #fff;
  text-align: center;
  overflow: hidden;
}
.info-box-icon {
  height: 60px;
  width: 60px;
  margin: 10px auto;
  font-size: 30px;
  line-height: 60px;
  border-radius: 50%;
  background: rgba(0,0,0,0.2);
}
.info-box-content {
  padding: 10px;
}
.info-box h3 {
  font-size: 16px;
  margin: 10px 0 5px;
  color: #fff;
}
.info-box h5 {
  font-size: 14px;
  margin: 0;
  color: #fff;
}
.bg-blue { background-color: #0073b7; }
.bg-yellow { background-color: #f39c12; }
.bg-red { background-color: #dd4b39; }
.bg-green { background-color: #00a65a; }
.bg-purple { background-color: #605ca8; }
.bg-teal { background-color: #39cccc; }
/* Make suggestions white instead of Bootstrap blue */
#searchResults .search-item {
    background: #fff;
    color: #000;
    border: 1px solid #ddd;
    padding: 8px 12px;
    cursor: pointer;
}

/* Hover highlight */
#searchResults .search-item:hover {
    background: #f5f5f5;
}

/* Keyboard-selected item */
#searchResults .selected {
    background: #e3f2fd !important;
    border-color: #90caf9;
}
canvas {
    max-width: 100% !important;
    max-height: 100% !important;
}
/* Compact info-box for dashboard charts */
.info-box.compact {
    min-height: 60px !important;
    height: 60px !important;
    padding: 5px;
}

.info-box.compact .info-box-icon {
    width: 45px !important;
    font-size: 24px !important;
    line-height: 60px !important;
}

.info-box.compact .info-box-content {
    margin-left: 50px !important;
    padding: 5px 0;
}

.info-box.compact .info-box-text {
    font-size: 11px !important;
    line-height: 12px !important;
}

.info-box.compact .info-box-number {
    font-size: 16px !important;
    font-weight: bold;
}
</style>

<div class="container-fluid">
  <div class="row">
    <div class="col-md-12">
      <div class="card" style="width:100%;">
        <div class="card-header">
          <h2>📊 Warehouse Dashboard</h2>
        </div>

        <div class="card-body">
<?php
session_start();
require_once('includes/conn.php');
?>

  <script src="js/jquery-1.12.4.min.js"></script>
  <script src="bootstrap/js/bootstrap.min.js"></script>
  <style>
    .panel-heading i { margin-right: 8px; }
    .dashboard-section { margin-bottom: 30px; }
    #searchResults {
  position: relative; /* or remove position entirely */
  z-index: auto;
  margin-top: 10px;
}
  </style>
<div class="container">
<!-- /.card-header -->
<div class="form-group" style="width:100%; margin:auto;">
    <input type="text" id="liveSearch" class="form-control" placeholder="Search barcode, shelf, or name">
    <div id="searchResults" class="list-group"></div>
</div>

<script>
$(document).ready(function() {

  // Live search
  $('#liveSearch').on('input', function() {
    const query = $(this).val();

    if (query.length < 2) {
      $('#searchResults').empty();
      return;
    }

    $.get('ajax/search_lookup.php', { q: query }, function(data) {
      $('#searchResults').html(data);
    });
  });

  // Click suggestion → Load details
  $(document).on('click', '.search-item', function(e) {
    e.preventDefault();

    const barcode = $(this).data('barcode');

    $.get('ajax/search_details.php', { barcode: barcode }, function(data) {
      $('#searchResults').html(data);
    });
  });

});
</script>

    </div>
   
 

<div class="row text-center">
  <!-- Total Items -->
  <div class="col-md-2 col-sm-4 col-xs-6">
    <a href="index.php?page=inventory_report">
      <div class="info-box bg-blue">
        <div class="info-box-icon"><i class="fa fa-cubes"></i></div>
        <div class="info-box-content">
          <h3>Total Items in Stock</h3>
          <h5><?php
            $stmt = $pdo->query("SELECT SUM(quantity) AS total FROM items");
            echo $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
          ?></h5>
        </div>
      </div>
    </a>
  </div>

  <!-- Shelf Locations -->
  <div class="col-md-2 col-sm-4 col-xs-6">
    <a href="index.php?page=display_stock">
      <div class="info-box bg-yellow">
        <div class="info-box-icon"><i class="fa fa-th-large"></i></div>
        <div class="info-box-content">
          <h3>Shelf Locations</h3>
          <h5><?php
            $stmt = $pdo->query("SELECT COUNT(DISTINCT shelf_name) AS shelves FROM stock_levels WHERE quantity > 0");
            echo $stmt->fetch(PDO::FETCH_ASSOC)['shelves'] ?? 0;
          ?></h5>
        </div>
      </div>
    </a>
  </div>

  <!-- Low Stock Alerts -->
  <div class="col-md-2 col-sm-4 col-xs-6">
    <a href="index.php?page=order_prepare&supplier=">
      <div class="info-box bg-red">
        <div class="info-box-icon"><i class="fa fa-exclamation-triangle"></i></div>
        <div class="info-box-content">
          <h3>Low Stock Alerts</h3>
          <h5><?php
            $stmt = $pdo->query("SELECT COUNT(*) AS low FROM items WHERE quantity < moq");
            echo $stmt->fetch(PDO::FETCH_ASSOC)['low'] ?? 0;
          ?></h5>
        </div>
      </div>
    </a>
  </div>

  <!-- Alerts & Cleanup -->
  <div class="col-md-2 col-sm-4 col-xs-6">
    <a href="#">
      <div class="info-box bg-purple">
        <div class="info-box-icon"><i class="fa fa-broom"></i></div>
        <div class="info-box-content">
          <h3>Alerts & Cleanup</h3>
          <h5><?php
            $stmt = $pdo->query("SELECT COUNT(*) AS empty_shelves FROM stock_levels WHERE quantity = 0");
            $empty = $stmt->fetch(PDO::FETCH_ASSOC)['empty_shelves'] ?? 0;

            $stmt = $pdo->query("SELECT COUNT(*) AS unassigned FROM items WHERE id NOT IN (SELECT DISTINCT item_id FROM stock_levels)");
            $unassigned = $stmt->fetch(PDO::FETCH_ASSOC)['unassigned'] ?? 0;

            echo "$empty shelves  with zero quantity<br>$unassigned unassigned";
          ?></h5>
        </div>
      </div>
    </a>
  </div>

  <!-- Recent Movements -->
  <div class="col-md-2 col-sm-4 col-xs-6">
    <a href="index.php?page=stock_movements">
      <div class="info-box bg-green">
        <div class="info-box-icon"><i class="fa fa-history"></i></div>
        <div class="info-box-content">
          <h3>Recent Movements</h3>
          <h5><?php
            $stmt = $pdo->query("SELECT COUNT(*) AS recent FROM inventory_movements WHERE timestamp >= NOW() - INTERVAL 7 DAY");
            echo $stmt->fetch(PDO::FETCH_ASSOC)['recent'] ?? 0;
          ?></h5>
        </div>
      </div>
    </a>
  </div>

  <!-- Average Units Capacity -->
  <div class="col-md-2 col-sm-4 col-xs-6">
    <a href="#">
      <div class="info-box bg-teal">
        <div class="info-box-icon"><i class="fa fa-balance-scale"></i></div>
        <div class="info-box-content">
          <h3>Avg Units / Shelf</h3>
          <h5><?php
            $stmt = $pdo->query("SELECT COUNT(DISTINCT shelf_name) AS shelves FROM stock_levels WHERE quantity > 0");
            $shelves = $stmt->fetch(PDO::FETCH_ASSOC)['shelves'] ?? 1;

            $stmt = $pdo->query("SELECT SUM(quantity) AS total FROM stock_levels");
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

            $avg = $shelves > 0 ? round($total / $shelves, 2) : 0;
            echo "$avg units/shelf";
          ?></h5>
        </div>
      </div>
    </a>
  </div>
</div>

<div class="container-fluid">
        <div class="row">
<div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">


<?php

// Receiving (IN)
$inToday = $pdo->query("SELECT COUNT(DISTINCT order_id) AS orders,
         COUNT(*) AS items,
         SUM(quantity) AS total_qty
  FROM inventory_movements
  WHERE movement_type = 'IN' AND DATE(timestamp) = CURDATE()
")->fetch(PDO::FETCH_ASSOC);

$inTrend = $pdo->query("SELECT DATE(timestamp) AS day, SUM(quantity) AS qty
  FROM inventory_movements
  WHERE movement_type = 'IN' AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
  GROUP BY DATE(timestamp)
  ORDER BY day ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Sending (OUT)
$outToday = $pdo->query("SELECT COUNT(DISTINCT order_id) AS orders,
         COUNT(*) AS items,
         SUM(quantity) AS total_qty
  FROM inventory_movements
  WHERE movement_type = 'OUT' AND DATE(timestamp) = CURDATE()
")->fetch(PDO::FETCH_ASSOC);

$outTrend = $pdo->query("SELECT DATE(timestamp) AS day, SUM(quantity) AS qty
  FROM inventory_movements
  WHERE movement_type = 'OUT' AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
  GROUP BY DATE(timestamp)
  ORDER BY day ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="row">

    <!-- Receiving Panel -->
    <div class="col-md-3 col-sm-6">
        <div class="info-box bg-green">
            <span class="info-box-icon"><i class="fa fa-truck-loading"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Today's Receiving</span>
                <span class="info-box-number"><?= $inToday['total_qty'] ?> units</span>
            </div>
        </div>
        <canvas id="receivingTrend" height="100"></canvas>
    </div>

    <!-- Sending Panel -->
    <div class="col-md-3 col-sm-6">
        <div class="info-box bg-red">
            <span class="info-box-icon"><i class="fa fa-truck"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Today's Sending</span>
                <span class="info-box-number"><?= $outToday['total_qty'] ?> units</span>
            </div>
        </div>
        <canvas id="sendingTrend" height="100"></canvas>
    </div>

    <!-- Monthly Movement Trends -->
    <div class="col-md-3 col-sm-6">
        <div class="card">
            <div class="card-header bg-gradient-primary text-white">
                <h4><i class="fa fa-calendar"></i> Monthly Trends</h4>
            </div>
            <div class="card-body">
                <canvas id="movementChart" height="150"></canvas>
            </div>
        </div>
    </div>

    <!-- Supplier Barcode Distribution -->
    <div class="col-md-3 col-sm-6">
        <div class="card">
            <div class="card-header bg-gradient-info text-white">
                <h4><i class="fa fa-industry"></i> Supplier Codes</h4>
            </div>
            <div class="card-body">
                <canvas id="supplierChart" height="150"></canvas>
            </div>
        </div>
    </div>

</div>

<script src="js/chart.js"></script>
<script>
const receivingCtx = document.getElementById('receivingTrend').getContext('2d');
new Chart(receivingCtx, {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($inTrend, 'day')) ?>,
    datasets: [{
      label: 'Units Received',
      data: <?= json_encode(array_column($inTrend, 'qty')) ?>,
      backgroundColor: 'rgba(92,184,92,0.2)',
      borderColor: '#5cb85c',
      borderWidth: 2,
      fill: true,
      tension: 0.3
    }]
  },
  options: {
    scales: { y: { beginAtZero: true } }
  }
});

const sendingCtx = document.getElementById('sendingTrend').getContext('2d');
new Chart(sendingCtx, {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($outTrend, 'day')) ?>,
    datasets: [{
      label: 'Units Sent',
      data: <?= json_encode(array_column($outTrend, 'qty')) ?>,
      backgroundColor: 'rgba(255,99,132,0.2)',
      borderColor: '#d9534f',
      borderWidth: 2,
      fill: true,
      tension: 0.3
    }]
  },
  options: {
    scales: { y: { beginAtZero: true } }
  }
});
</script>
       <div class="card">
        <div class="card-body">
            <!-- 🚚 Movement Tracker -->
            <div class="dashboard-section">
              <div class="panel panel-default">
                <div class="panel-heading"><i class="fa fa-exchange"></i> Recent Movements</div><br />
                <div class="panel-body">
                  <table class="table table-bordered table-striped">
                    <thead>
                      <tr style='background-color:#2a3036;'>
                        <th>Type</th>
                        <th>Barcode</th>
                        <th>Model</th>
                        <th>Barcode</th>
                        <th>Color</th>
                        <th>Shelf</th>
                        <th>Qty</th>
                        <th>Time</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php
                      $stmt = $pdo->query("SELECT im.movement_type, im.item_name, im.shelf_name, im.quantity, im.timestamp, i.name, i.description, i.color
                    FROM inventory_movements im
                    LEFT JOIN items i ON im.item_name = i.barcode
                    ORDER BY im.timestamp DESC LIMIT 10");
                      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo "<tr>
                          <td>{$row['movement_type']}</td>
                          <td>{$row['item_name']}</td>
                          <td>{$row['name']}</td>
                          <td>{$row['description']}</td>
                          <td>{$row['color']}</td>
                          <td>{$row['shelf_name']}</td>
                          <td>{$row['quantity']}</td>
                          <td>{$row['timestamp']}</td>
                        </tr>";
                      }
                      ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
    
       <div class="card">
        <div class="card-body">
            <!-- 📊 Analytics -->
          <div class="dashboard-section">
            <div class="panel panel-default">
              <div class="panel-heading"><i class="fa fa-line-chart"></i> Analytics</div><br />
              <div class="panel-body">
                <div class="row">
                  <!-- Top Movers -->
                  <div class="col-md-12">
                    <h4><i class="fa fa-star yellow-star"></i> Top 10 Items</h4>
                    <table class="table table-bordered table-striped">
                      <thead>
                        <tr style="background-color:#2a3036;">
                          <th>Barcode</th>
                          <th>Model</th>
                          <th>Part</th>
                          <th>Color</th>
                          <th>Total Movements</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php
                        $stmt = $pdo->query("SELECT im.item_name, im.item_id, SUM(im.quantity) AS total, i.name,  i.description, i.color
                      FROM inventory_movements im
                      LEFT JOIN items i ON im.item_id = i.id
                      GROUP BY im.item_name, im.item_id, i.name, i.description, i.color
                      ORDER BY total DESC
                      LIMIT 10;");
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                          echo "<tr><td>{$row['item_name']}</td><td>{$row['name']}</td><td>{$row['description']}</td><td>{$row['color']}</td><td>{$row['total']}</td></tr>";
                        }
                        ?>
                      </tbody>
                    </table>
                  </div>        
                </div>
              </div>

      <div class="row"> 
<div class="col-12">
       <div class="card">
        <div class="card-body">
<?php
$stmt = $pdo->query("SELECT stock_levels.shelf_name, SUM(stock_levels.quantity) AS qty, shelves.capacity  FROM stock_levels
  LEFT JOIN shelves ON stock_levels.shelf_name = shelves.location
  GROUP BY stock_levels.shelf_name, shelves.capacity
  ORDER BY stock_levels.shelf_name");

$shelves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group shelves by first letter
$grouped = [];
foreach ($shelves as $shelf) {
  $firstChar = strtoupper(substr($shelf['shelf_name'], 0, 1));
  $grouped[$firstChar][] = $shelf;
}
?>
<!-- 🗺️ Shelf Grid Map -->
<div class="dashboard-section">
  <div class="panel panel-default">
    <div class="panel-heading"><i class="fa fa-map"></i> Shelf Utilization Grid</div><br />
    <div class="panel-body">
      <?php foreach ($grouped as $letter => $shelfGroup): ?>
        <div class="row">
          <div class="col-xs-12">
            <strong><h5>Rack Group <?= $letter ?></h5></strong>
          </div>
      </div>
      <div class="row">
          <?php foreach ($shelfGroup as $shelf): 
              $qty = (int)$shelf['qty'];
              $name = $shelf['shelf_name'];
              $capacity = (int)$shelf['capacity'];

              // Default if capacity is missing or zero
              $percent = ($capacity > 0) ? ($qty / $capacity) * 100 : 0;

              // Color logic based on utilization
              if ($percent == 0) {
                $color = '#d9534f'; // red
              } elseif ($percent < 25) {
                $color = '#f0ad4e'; // orange
              } elseif ($percent < 75) {
                $color = '#5bc0de'; // blue
              } else {
                $color = '#5cb85c'; // green
              }
            ?>
                      <div class='col-xs-2 text-center' style='margin-bottom:10px;'>
                        <div style='background:<?= $color ?>; padding:10px; border-radius:4px; color:white;'>
                          <strong><?= $name ?></strong><br>
                          <?= $qty ?> / <?= $capacity ?> units
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                 <hr />
                <?php endforeach; ?>
              </div>
            </div>
          </div>
         </div>
        </div>
      </div>

<!-- Chart.js -->
<script src="js/chart.js"></script>
<script>
  $(document).ready(function() {
    $.getJSON('scripts/movement_trends.php', function(data) {
      const ctx = document.getElementById('movementChart').getContext('2d');
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: data.labels,
          datasets: [
            {
              label: 'IN',
              data: data.in,
              backgroundColor: '#5cb85c'
            },
            {
              label: 'OUT',
              data: data.out,
              backgroundColor: '#d9534f'
            },
            {
              label: 'RELOCATE',
              data: data.relocate,
              backgroundColor: '#f0ad4e'
            }
          ]
        },
        options: {
          responsive: true,
          scales: {
            y: { beginAtZero: true }
          }
        }
      });
    });
  });
</script>
<script src="js/chart_1.js"></script>
          <script>
            $(document).ready(function() {
              $.getJSON('scripts/supplier_distribution.php', function(data) {
                const ctx = document.getElementById('supplierChart').getContext('2d');
                new Chart(ctx, {
                  type: 'bar',
                  data: {
                    labels: data.labels,
                    datasets: [{
                      label: 'Unique Barcodes per Supplier',
                      data: data.counts,
                      backgroundColor: '#5bc0de'
                    }]
                  },
                  options: {
                    responsive: true,
                    indexAxis: 'y',
                    plugins: {
                      legend: { display: false },
                      tooltip: {
                        callbacks: {
                          label: function(context) {
                            return `${context.label}: ${context.raw} barcodes`;
                          }
                        }
                      }
                    },
                    scales: {
                      x: {
                        beginAtZero: true,
                        title: { display: true, text: 'Barcode Count' }
                      },
                      y: {
                        title: { display: true, text: 'Supplier' }
                      }
                    }
                  }
                });
              });
            });
          </script>
        </div>
      </div>
    </div>
  </div>
