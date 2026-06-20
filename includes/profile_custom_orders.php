<?php
declare(strict_types=1);

require_once __DIR__ . '/conn.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);

$sql = "
  SELECT
    co.id,
    co.internal_code,
    co.official_order_number,
    co.status,
    co.source_channel,
    co.social_platform,
    co.social_handle,
    co.customer_name,
    co.customer_country,
    co.complexity_level,
    co.updated_at,
    co.next_followup_at,
    co.owner_assigned_at,
    COUNT(DISTINCT coi.id) AS item_count,
    SUM(CASE WHEN cop.payment_kind IN ('DEPOSIT', 'EXTRA_DEPOSIT') THEN cop.amount ELSE 0 END) AS deposit_total
  FROM custom_orders co
  LEFT JOIN custom_order_items coi ON coi.custom_order_id = co.id
  LEFT JOIN custom_order_payments cop ON cop.custom_order_id = co.id
  WHERE co.owner_employee_id = ?
  GROUP BY co.id
  ORDER BY
    CASE
      WHEN co.status IN ('DEPOSIT_PENDING', 'DEPOSIT_PAID', 'IN_PROGRESS', 'READY_TO_EXPORT') THEN 0
      ELSE 1
    END,
    co.updated_at DESC,
    co.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();

function profileCustomStatusBadge(string $status): string
{
  $status = strtoupper(trim($status));
  switch ($status) {
    case 'READY_TO_EXPORT':
      return 'badge-success';
    case 'IN_PROGRESS':
    case 'DEPOSIT_PAID':
      return 'badge-warning';
    case 'DEPOSIT_PENDING':
    case 'LEAD':
      return 'badge-info';
    case 'CANCELLED':
    case 'DEAD':
      return 'badge-danger';
    case 'EXPORTED':
      return 'badge-secondary';
    default:
      return 'badge-light';
  }
}
?>
<div class="card card-outline card-info">
  <div class="card-header">
    <h3 class="card-title">My Custom Leads</h3>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm table-dark table-hover mb-0">
      <thead>
        <tr>
          <th width="14%">Code</th>
          <th width="14%">Status</th>
          <th>Customer</th>
          <th width="10%">Country</th>
          <th width="10%">Complexity</th>
          <th width="10%">Items</th>
          <th width="12%">Deposits</th>
          <th width="15%">Updated</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = $res->fetch_assoc()): ?>
          <tr>
            <td>
              <a href="index.php?page=custom_orders&custom_order_id=<?= (int) $row['id'] ?>">
                <?= htmlspecialchars((string) ($row['official_order_number'] ?: $row['internal_code']), ENT_QUOTES, 'UTF-8') ?>
              </a>
            </td>
            <td><span class="badge <?= profileCustomStatusBadge((string) $row['status']) ?>"><?= htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
            <td>
              <?= htmlspecialchars((string) ($row['customer_name'] ?: $row['social_handle'] ?: 'Unnamed lead'), ENT_QUOTES, 'UTF-8') ?>
              <div class="text-muted small">
                <?= htmlspecialchars(trim(implode(' | ', array_filter([(string) $row['source_channel'], (string) $row['social_platform']]))), ENT_QUOTES, 'UTF-8') ?>
              </div>
            </td>
            <td><?= htmlspecialchars((string) $row['customer_country'], ENT_QUOTES, 'UTF-8') ?></td>
            <td>C<?= (int) $row['complexity_level'] ?></td>
            <td><?= (int) $row['item_count'] ?></td>
            <td><?= number_format((float) ($row['deposit_total'] ?? 0), 2) ?></td>
            <td>
              <?= htmlspecialchars((string) $row['updated_at'], ENT_QUOTES, 'UTF-8') ?>
              <?php if (!empty($row['next_followup_at'])): ?>
                <div class="text-warning small">FU <?= htmlspecialchars((string) $row['next_followup_at'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
