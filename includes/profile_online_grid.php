<?php
// includes/profile_online_grid.php
// Requires: $conn (mysqli)
// Shows employee online status grid (AdminLTE 3 / Bootstrap 4 friendly)

$employees_qry = $conn->query("SELECT e.id, e.firstname, e.lastname, e.online_status, e.photo, e.position_id, p.description
    FROM employees e
    JOIN position p ON p.id = e.position_id
    WHERE e.grid = 1
    ORDER BY e.lastname ASC
") or die(mysqli_error($conn));

function online_status_meta($statusInt) {
    switch ((int)$statusInt) {
        case 1:  return ['label' => 'At work',   'icon' => 'fa-briefcase', 'bg' => 'bg-success'];
        case 2:  return ['label' => 'At home',   'icon' => 'fa-house-user','bg' => 'bg-danger'];
        case 3:  return ['label' => 'Break',     'icon' => 'fa-smoking',   'bg' => 'bg-warning'];
        case 4:  return ['label' => 'Lunch',     'icon' => 'fa-utensils',  'bg' => 'bg-info'];
        default: return ['label' => 'Unknown',   'icon' => 'fa-question',  'bg' => 'bg-secondary'];
    }
}
?>

<style>
  /* Keep it self-contained, dark-mode friendly */
  .emp-grid .info-box { min-height: 92px; }
  .emp-grid .info-box .info-box-icon { width: 76px; }
  .emp-grid .emp-photo {
    width: 56px; height: 56px; object-fit: cover;
    border-radius: 50%;
  }
  .emp-grid .emp-name { font-weight: 600; line-height: 1.1; }
  .emp-grid .emp-pos { font-size: 0.85rem; opacity: .85; }
</style>

<div class="row emp-grid">
  <?php while ($row = $employees_qry->fetch_assoc()): ?>
    <?php $meta = online_status_meta($row['online_status']); ?>
    <div class="col-12 col-sm-6 col-md-4 col-lg-3">
      <div class="info-box mb-3">
        <span class="info-box-icon <?php echo $meta['bg']; ?>">
          <i class="fas <?php echo $meta['icon']; ?>"></i>
        </span>

        <div class="info-box-content">
          <div class="d-flex align-items-center">
            <img
              class="emp-photo mr-2"
              src="<?php echo 'images/' . htmlspecialchars($row['photo'], ENT_QUOTES, 'UTF-8'); ?>"
              alt="<?php echo htmlspecialchars($row['firstname'].' '.$row['lastname'], ENT_QUOTES, 'UTF-8'); ?>"
            />
            <div class="flex-grow-1">
              <div class="emp-name">
                <?php echo htmlspecialchars($row['firstname'].' '.$row['lastname'], ENT_QUOTES, 'UTF-8'); ?>
              </div>
              <div class="emp-pos">
                <?php echo htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8'); ?>
              </div>
            </div>
          </div>

          <div class="mt-2">
            <span class="badge <?php echo $meta['bg']; ?>">
              <?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
          </div>
        </div>
      </div>
    </div>
  <?php endwhile; ?>
</div>
