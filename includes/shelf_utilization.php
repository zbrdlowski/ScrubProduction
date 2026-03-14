<!-- 🔥 Shelf Utilization Heatmap -->
<div class="dashboard-section">
  <div class="panel panel-default">
    <div class="panel-heading"><i class="fa fa-thermometer-half"></i> Shelf Utilization Heatmap</div>
    <div class="panel-body">
      <table class="table table-bordered text-center">
        <thead>
          <tr>
            <th>Shelf</th>
            <th>Quantity</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $stmt = $pdo->query("
            SELECT shelf_name, SUM(quantity) AS total
            FROM stock_levels
            GROUP BY shelf_name
            ORDER BY shelf_name
          ");
          while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $qty = (int)$row['total'];
            $status = '';
            $class = '';

            if ($qty == 0) {
              $status = 'Empty';
              $class = 'danger';
            } elseif ($qty < 20) {
              $status = 'Low';
              $class = 'warning';
            } elseif ($qty < 100) {
              $status = 'Medium';
              $class = 'info';
            } else {
              $status = 'High';
              $class = 'success';
            }

            echo "<tr class='$class'>
              <td>{$row['shelf_name']}</td>
              <td>$qty</td>
              <td>$status</td>
            </tr>";
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
