<?php
declare(strict_types=1);

/** @var mysqli $conn */
if (!isset($conn) || !$conn instanceof mysqli) {
    require_once __DIR__ . '/conn.php';
}
require_once __DIR__ . '/orders_status_helpers.php';

$statusDashboardOrderDefinitions = ordersGetOrderStatusDefinitions($conn, true);
$statusDashboardOrderCounts = ordersGetOrderStatusCounts($conn);
$statusDashboardItemCounts = ordersGetItemStatusCounts($conn);
$statusDashboardDepartments = [
    'G' => ['label' => 'Graphics', 'icon' => 'fa-palette'],
    'S' => ['label' => 'Seat Cover', 'icon' => 'fa-chair'],
    'P' => ['label' => 'Plastics', 'icon' => 'fa-boxes'],
    'F' => ['label' => 'Fitting', 'icon' => 'fa-tools'],
];

function statusDashboardOrdersUrl(array $params = []): string
{
    return '?'.http_build_query(array_merge(['page' => 'orders'], $params));
}

function statusDashboardCardStyle(array $definition): string
{
    $color = trim((string)($definition['color'] ?? '')) ?: '#6c757d';
    $textColor = ordersContrastColor($color);
    return '--status-color:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';'
        . '--status-text-color:' . htmlspecialchars($textColor, ENT_QUOTES, 'UTF-8') . ';';
}
?>

<style>
  .status-dashboard-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
  }
  .status-dashboard-header p { margin: 0; color: rgba(255,255,255,.55); }
  .status-dashboard-section {
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 8px;
    background: rgba(255,255,255,.025);
    margin-bottom: 1rem;
    overflow: hidden;
  }
  .status-dashboard-section-header {
    display: flex;
    align-items: center;
    gap: .65rem;
    padding: .8rem 1rem;
    background: rgba(0,0,0,.16);
    border-bottom: 1px solid rgba(255,255,255,.07);
  }
  .status-dashboard-section-header h3 { margin: 0; font-size: 1rem; font-weight: 600; }
  .status-dashboard-section-header .badge { margin-left: auto; }
  .status-dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
    gap: .65rem;
    padding: .8rem;
  }
  .status-dashboard-card {
    min-height: 72px;
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .75rem .8rem;
    color: rgba(255,255,255,.82);
    background: rgba(255,255,255,.045);
    border: 1px solid rgba(255,255,255,.09);
    border-left: 4px solid var(--status-color, #6c757d);
    border-radius: 6px;
    text-decoration: none;
    transition: transform .12s ease, background .12s ease, border-color .12s ease;
  }
  .status-dashboard-card:hover {
    transform: translateY(-1px);
    color: #fff;
    background: rgba(255,255,255,.09);
    border-color: var(--status-color, #6c757d);
    text-decoration: none;
  }
  .status-dashboard-card.is-empty {
    opacity: .38;
    filter: saturate(.35);
  }
  .status-dashboard-card.is-empty:hover,
  .status-dashboard-card.is-empty:focus {
    opacity: .72;
    filter: saturate(.7);
  }
  .status-dashboard-card.has-orders {
    background: rgba(255,255,255,.085);
    border-top-color: rgba(255,255,255,.16);
    border-right-color: rgba(255,255,255,.16);
    border-bottom-color: rgba(255,255,255,.16);
    box-shadow: 0 2px 9px color-mix(in srgb, var(--status-color, #6c757d) 24%, transparent);
  }
  .status-dashboard-card.has-orders .status-dashboard-card-label {
    color: #fff;
    font-weight: 600;
  }
  .status-dashboard-card.has-orders .status-dashboard-count {
    box-shadow: 0 0 0 3px rgba(255,255,255,.14),
                0 0 10px color-mix(in srgb, var(--status-color, #6c757d) 55%, transparent);
  }
  .status-dashboard-card-label {
    min-width: 0;
    flex: 1;
    font-size: .86rem;
    line-height: 1.2;
    overflow-wrap: anywhere;
  }
  .status-dashboard-count {
    flex: 0 0 auto;
    min-width: 32px;
    height: 32px;
    padding: 0 7px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: var(--status-color, #6c757d);
    color: var(--status-text-color, #fff);
    font-size: .78rem;
    font-weight: 700;
    box-shadow: 0 0 0 3px rgba(255,255,255,.06);
  }
  .status-dashboard-overall { border-color: rgba(23,162,184,.35); }
  @media (max-width: 575.98px) {
    .status-dashboard-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .45rem; padding: .55rem; }
    .status-dashboard-card { min-height: 64px; padding: .6rem; }
  }
</style>

<div class="status-dashboard-header">
  <div>
    <h2 class="h4 mb-1">Order Status Dashboard</h2>
    <p>Live overview of overall and department workflows. Click any block to open the filtered order list.</p>
  </div>
  <a class="btn btn-sm btn-outline-light" href="<?= htmlspecialchars(statusDashboardOrdersUrl()) ?>">
    <i class="fas fa-list mr-1"></i> All orders
  </a>
</div>

<section class="status-dashboard-section status-dashboard-overall">
  <div class="status-dashboard-section-header">
    <i class="fas fa-layer-group text-info"></i>
    <h3>Overall</h3>
    <span class="badge badge-info"><?= array_sum($statusDashboardOrderCounts) ?> orders</span>
  </div>
  <div class="status-dashboard-grid">
    <?php foreach ($statusDashboardOrderDefinitions as $code => $definition): ?>
      <?php $count = (int)($statusDashboardOrderCounts[$code] ?? 0); ?>
      <a class="status-dashboard-card <?= $count === 0 ? 'is-empty' : 'has-orders' ?>" style="<?= statusDashboardCardStyle($definition) ?>"
        href="<?= htmlspecialchars(statusDashboardOrdersUrl(['status' => $code])) ?>">
        <span class="status-dashboard-card-label"><?= htmlspecialchars((string)($definition['label'] ?? $code)) ?></span>
        <span class="status-dashboard-count"><?= $count ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php foreach ($statusDashboardDepartments as $department => $departmentMeta): ?>
  <?php
    $definitions = ordersGetItemStatusDefinitions($conn, $department, true);
    $departmentCounts = $statusDashboardItemCounts[$department] ?? [];
  ?>
  <section class="status-dashboard-section">
    <div class="status-dashboard-section-header">
      <i class="fas <?= htmlspecialchars($departmentMeta['icon']) ?> text-warning"></i>
      <h3><?= htmlspecialchars($department) ?> · <?= htmlspecialchars($departmentMeta['label']) ?></h3>
      <span class="badge badge-secondary"><?= count($definitions) ?> statuses</span>
    </div>
    <div class="status-dashboard-grid">
      <?php foreach ($definitions as $code => $definition): ?>
        <?php
          $count = (int)($departmentCounts[$code] ?? 0);
          $ordersUrlParams = [
            'item_department' => $department,
            'item_status' => $code,
            'exclude_status' => 'SHIPPED,CANCELLED,PENDING',
          ];
        ?>
        <a class="status-dashboard-card <?= $count === 0 ? 'is-empty' : 'has-orders' ?>" style="<?= statusDashboardCardStyle($definition) ?>"
          href="<?= htmlspecialchars(statusDashboardOrdersUrl($ordersUrlParams)) ?>">
          <span class="status-dashboard-card-label"><?= htmlspecialchars((string)($definition['label'] ?? $code)) ?></span>
          <span class="status-dashboard-count"><?= $count ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
