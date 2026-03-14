<?php
require_once('includes/conn.php');

// Get today's totals
$stmt = $pdo->query("SELECT COUNT(DISTINCT order_id) AS orders,
         COUNT(*) AS items,
         SUM(quantity) AS total_qty
  FROM inventory_movements
  WHERE movement_type = 'IN' AND DATE(timestamp) = CURDATE()
");
$today = $stmt->fetch(PDO::FETCH_ASSOC);

// Get weekly trend
$trend = $pdo->query("SELECT DATE(timestamp) AS day, SUM(quantity) AS qty
  FROM inventory_movements
  WHERE movement_type = 'IN' AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
  GROUP BY DATE(timestamp)
  ORDER BY day ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="info-box bg-green">
  <span class="info-box-icon"><i class="fa fa-truck"></i></span>
  <div class="info-box-content">
    <span class="info-box-text">Today's Receiving</span>
    <span class="info-box-number"><?= $today['total_qty'] ?> units</span>
    <div class="progress">
      <div class="progress-bar" style="width:100%"></div>
    </div>
    <span class="progress-description">
      <?= $today['orders'] ?> orders / <?= $today['items'] ?> items
    </span>
  </div>
</div>

<!-- Optional: Weekly Chart -->
<canvas id="receivingTrend" height="100"></canvas>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('receivingTrend').getContext('2d');
const chart = new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($trend, 'day')) ?>,
    datasets: [{
      label: 'Units Received',
      data: <?= json_encode(array_column($trend, 'qty')) ?>,
      backgroundColor: 'rgba(92,184,92,0.2)',
      borderColor: '#5cb85c',
      borderWidth: 2,
      fill: true,
      tension: 0.3
    }]
  },
  options: {
    scales: {
      y: { beginAtZero: true }
    }
  }
});
</script>
