<?php
declare(strict_types=1);

$plasticsOrdersResultLimit = 500;
$plasticsOrdersStatuses = [
    'created' => 'Created',
    'sent' => 'Sent',
    'received' => 'Received',
];

$plasticsOrdersFilterValue = static function (string $key): string {
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};
$plasticsOrdersValidDate = static function (string $value): string {
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
};
$plasticsOrdersH = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$selectedOrder = $plasticsOrdersFilterValue('order_number');
$selectedSupplier = $plasticsOrdersFilterValue('main_supplier');
$selectedStatus = strtolower($plasticsOrdersFilterValue('status'));
$selectedSearch = $plasticsOrdersFilterValue('q');
$selectedDateFrom = $plasticsOrdersValidDate($plasticsOrdersFilterValue('date_from'));
$selectedDateTo = $plasticsOrdersValidDate($plasticsOrdersFilterValue('date_to'));

if (!isset($plasticsOrdersStatuses[$selectedStatus])) {
    $selectedStatus = '';
}

$hasActiveFilters = $selectedOrder !== ''
    || $selectedSupplier !== ''
    || $selectedStatus !== ''
    || $selectedSearch !== ''
    || $selectedDateFrom !== ''
    || $selectedDateTo !== '';

// These lightweight DISTINCT queries only populate the filter controls. The order rows
// themselves are not queried until the user applies at least one filter.
$orderNumbers = $pdo->query(
    "SELECT DISTINCT order_number FROM plastics_orders WHERE TRIM(COALESCE(order_number, '')) <> '' ORDER BY order_number"
)->fetchAll(PDO::FETCH_COLUMN);
$suppliers = $pdo->query(
    "SELECT DISTINCT main_supplier FROM plastics_orders WHERE TRIM(COALESCE(main_supplier, '')) <> '' ORDER BY main_supplier"
)->fetchAll(PDO::FETCH_COLUMN);

$orders = [];
$matchingOrderCount = 0;

if ($hasActiveFilters) {
    $where = [];
    $params = [];

    if ($selectedOrder !== '') {
        $where[] = 'order_number = :order_number';
        $params['order_number'] = $selectedOrder;
    }
    if ($selectedSupplier !== '') {
        $where[] = 'main_supplier = :main_supplier';
        $params['main_supplier'] = $selectedSupplier;
    }
    if ($selectedStatus !== '') {
        $where[] = 'status = :status';
        $params['status'] = $selectedStatus;
    }
    if ($selectedSearch !== '') {
        $where[] = "CONCAT_WS(' ', order_number, main_supplier, brand, barcode, name, description, color,
            ufo_pn, ufo_barcode, rt_pn, rt_barcode, ps_pn, ps_barcode, ac_pn, ac_barcode, other_pn, other_barcode)
            LIKE :search";
        $params['search'] = '%' . $selectedSearch . '%';
    }
    if ($selectedDateFrom !== '') {
        $where[] = 'created_at >= :date_from';
        $params['date_from'] = $selectedDateFrom . ' 00:00:00';
    }
    if ($selectedDateTo !== '') {
        $where[] = 'created_at < :date_to';
        $params['date_to'] = (new DateTimeImmutable($selectedDateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM plastics_orders WHERE {$whereSql}");
    $countStmt->execute($params);
    $matchingOrderCount = (int) $countStmt->fetchColumn();

    $ordersStmt = $pdo->prepare(
        "SELECT * FROM plastics_orders
         WHERE {$whereSql}
         ORDER BY created_at DESC, order_number DESC, main_supplier, name
         LIMIT {$plasticsOrdersResultLimit}"
    );
    $ordersStmt->execute($params);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
}

$showingOrderCount = count($orders);
?>

<style>
  .plastics-all-page {
    --plastics-border: rgba(148, 163, 184, .24);
    --plastics-surface: rgba(30, 41, 59, .72);
    --plastics-muted: #9caec2;
  }

  .plastics-all-page .plastics-hero {
    align-items: center;
    background: linear-gradient(125deg, rgba(15, 118, 110, .28), rgba(30, 64, 175, .18));
    border: 1px solid var(--plastics-border);
    border-radius: 14px;
    display: flex;
    justify-content: space-between;
    margin-bottom: 1rem;
    overflow: hidden;
    padding: 1.25rem 1.4rem;
    position: relative;
  }

  .plastics-all-page .plastics-hero::after {
    background: rgba(45, 212, 191, .12);
    border-radius: 50%;
    content: '';
    height: 190px;
    position: absolute;
    right: -65px;
    top: -105px;
    width: 190px;
  }

  .plastics-all-page .plastics-hero h1 {
    font-size: 1.65rem;
    font-weight: 700;
    margin: 0 0 .3rem;
  }

  .plastics-all-page .plastics-hero p {
    color: var(--plastics-muted);
    margin: 0;
    max-width: 720px;
  }

  .plastics-all-page .plastics-filter-card,
  .plastics-all-page .plastics-results-card {
    background: var(--plastics-surface);
    border: 1px solid var(--plastics-border);
    border-radius: 14px;
    box-shadow: 0 12px 28px rgba(2, 6, 23, .16);
    margin-bottom: 1rem;
    overflow: hidden;
  }

  .plastics-all-page .plastics-filter-card {
    padding: 1.1rem 1.2rem 1.2rem;
  }

  .plastics-all-page .plastics-filter-title {
    align-items: center;
    display: flex;
    font-size: 1rem;
    font-weight: 700;
    gap: .55rem;
    margin-bottom: 1rem;
  }

  .plastics-all-page .plastics-filter-title i { color: #5eead4; }

  .plastics-all-page .plastics-filter-grid {
    display: grid;
    gap: .85rem 1rem;
    grid-template-columns: repeat(6, minmax(145px, 1fr));
  }

  .plastics-all-page .plastics-filter-field label {
    color: #bfd0e2;
    display: block;
    font-size: .78rem;
    font-weight: 700;
    letter-spacing: .03em;
    margin-bottom: .35rem;
    text-transform: uppercase;
  }

  .plastics-all-page .plastics-filter-field .form-control {
    background-color: rgba(15, 23, 42, .72);
    border-color: rgba(148, 163, 184, .32);
    border-radius: 8px;
    color: #edf5fc;
    height: 38px;
  }

  .plastics-all-page .plastics-filter-field .form-control:focus {
    border-color: #2dd4bf;
    box-shadow: 0 0 0 3px rgba(45, 212, 191, .14);
  }

  .plastics-all-page .plastics-filter-actions {
    align-items: center;
    display: flex;
    gap: .65rem;
    justify-content: flex-end;
    margin-top: 1rem;
  }

  .plastics-all-page .plastics-filter-actions .btn {
    border-radius: 8px;
    font-weight: 700;
    min-width: 120px;
    padding: .52rem 1rem;
  }

  .plastics-all-page .plastics-empty-state {
    background: linear-gradient(180deg, rgba(30, 41, 59, .7), rgba(15, 23, 42, .82));
    border: 1px dashed rgba(94, 234, 212, .34);
    border-radius: 14px;
    padding: 3.3rem 1rem;
    text-align: center;
  }

  .plastics-all-page .plastics-empty-state-icon {
    align-items: center;
    background: rgba(45, 212, 191, .12);
    border-radius: 50%;
    color: #5eead4;
    display: inline-flex;
    font-size: 1.6rem;
    height: 58px;
    justify-content: center;
    margin-bottom: 1rem;
    width: 58px;
  }

  .plastics-all-page .plastics-empty-state h2 {
    font-size: 1.2rem;
    font-weight: 700;
    margin-bottom: .45rem;
  }

  .plastics-all-page .plastics-empty-state p {
    color: var(--plastics-muted);
    margin: 0 auto;
    max-width: 580px;
  }

  .plastics-all-page .plastics-results-header {
    align-items: center;
    border-bottom: 1px solid var(--plastics-border);
    display: flex;
    justify-content: space-between;
    padding: .9rem 1.1rem;
  }

  .plastics-all-page .plastics-results-header h2 {
    font-size: 1.05rem;
    font-weight: 700;
    margin: 0;
  }

  .plastics-all-page .plastics-result-count {
    background: rgba(45, 212, 191, .13);
    border: 1px solid rgba(45, 212, 191, .25);
    border-radius: 999px;
    color: #99f6e4;
    font-size: .78rem;
    font-weight: 700;
    padding: .28rem .65rem;
  }

  .plastics-all-page .plastics-table-wrap { padding: 1rem; }

  .plastics-all-page .plastics-datatable-toolbar {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    margin-bottom: 1rem;
    width: 100%;
  }

  .plastics-all-page .plastics-datatable-toolbar .dt-buttons,
  .plastics-all-page .plastics-datatable-toolbar .dataTables_filter {
    float: none;
    margin: 0;
  }

  .plastics-all-page .plastics-datatable-toolbar .dataTables_filter {
    margin-left: auto;
  }

  .plastics-all-page .plastics-datatable-toolbar .dataTables_filter label {
    align-items: center;
    display: flex;
    gap: .55rem;
    margin: 0;
    white-space: nowrap;
  }

  .plastics-all-page .plastics-datatable-toolbar .dataTables_filter input {
    margin-left: 0;
    min-width: 220px;
  }

  .plastics-all-page .plastics-datatable-footer {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    margin-top: 1rem;
  }

  .plastics-all-page .plastics-datatable-footer .dataTables_length,
  .plastics-all-page .plastics-datatable-footer .dataTables_info,
  .plastics-all-page .plastics-datatable-footer .dataTables_paginate {
    float: none;
    margin: 0;
    padding: 0;
  }
  .plastics-all-page #plasticsOrdersAllTable { font-size: .82rem; }

  .plastics-all-page #plasticsOrdersAllTable thead th {
    background: #162235;
    border-color: rgba(148, 163, 184, .22);
    color: #dbeafe;
    white-space: nowrap;
  }

  .plastics-all-page #plasticsOrdersAllTable td {
    border-color: rgba(148, 163, 184, .16);
    vertical-align: middle;
  }

  .plastics-all-page .plastics-status-badge {
    border-radius: 999px;
    display: inline-block;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .02em;
    padding: .25rem .55rem;
    text-transform: uppercase;
  }

  .plastics-all-page .plastics-status-created { background: rgba(239, 68, 68, .18); color: #fca5a5; }
  .plastics-all-page .plastics-status-sent { background: rgba(245, 158, 11, .2); color: #fcd34d; }
  .plastics-all-page .plastics-status-received { background: rgba(34, 197, 94, .18); color: #86efac; }

  @media (max-width: 1250px) {
    .plastics-all-page .plastics-filter-grid { grid-template-columns: repeat(3, minmax(170px, 1fr)); }
  }

  @media (max-width: 720px) {
    .plastics-all-page .plastics-filter-grid { grid-template-columns: 1fr; }
    .plastics-all-page .plastics-filter-actions { align-items: stretch; flex-direction: column-reverse; }
    .plastics-all-page .plastics-filter-actions .btn { width: 100%; }
    .plastics-all-page .plastics-results-header { align-items: flex-start; flex-direction: column; gap: .55rem; }
    .plastics-all-page .plastics-datatable-toolbar,
    .plastics-all-page .plastics-datatable-footer { align-items: stretch; flex-direction: column; }
    .plastics-all-page .plastics-datatable-toolbar .dataTables_filter { margin-left: 0; }
    .plastics-all-page .plastics-datatable-toolbar .dataTables_filter label { justify-content: space-between; }
    .plastics-all-page .plastics-datatable-toolbar .dataTables_filter input { min-width: 0; width: 100%; }
  }
</style>

<section class="content plastics-all-page">
  <div class="container-fluid">
    <?php if (isset($_GET['updated'])): ?>
      <div class="alert alert-<?= !empty($_GET['updated']) ? 'success' : 'warning' ?>">
        <?= !empty($_GET['updated']) ? 'Selected orders marked as sent.' : 'No orders selected.' ?>
      </div>
    <?php endif; ?>

    <div class="plastics-hero">
      <div>
        <h1>All Plastic Orders</h1>
        <p>Search the order history without loading thousands of rows. Results appear only after you apply at least one filter.</p>
      </div>
    </div>

    <form method="get" class="plastics-filter-card" aria-label="Plastic order filters">
      <input type="hidden" name="page" value="plastics_orders_all">

      <div class="plastics-filter-title"><i class="fas fa-filter" aria-hidden="true"></i> Find orders</div>
      <div class="plastics-filter-grid">
        <div class="plastics-filter-field">
          <label for="plastics-order-number">Order #</label>
          <input type="text" name="order_number" id="plastics-order-number" class="form-control"
            list="plastics-order-numbers" value="<?= $plasticsOrdersH($selectedOrder) ?>" placeholder="Select or type">
          <datalist id="plastics-order-numbers">
            <?php foreach ($orderNumbers as $orderNumber): ?>
              <option value="<?= $plasticsOrdersH($orderNumber) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>

        <div class="plastics-filter-field">
          <label for="plastics-main-supplier">Supplier</label>
          <select name="main_supplier" id="plastics-main-supplier" class="form-control">
            <option value="">All suppliers</option>
            <?php foreach ($suppliers as $supplier): ?>
              <option value="<?= $plasticsOrdersH($supplier) ?>" <?= (string) $supplier === $selectedSupplier ? 'selected' : '' ?>>
                <?= $plasticsOrdersH($supplier) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="plastics-filter-field">
          <label for="plastics-status">Status</label>
          <select name="status" id="plastics-status" class="form-control">
            <option value="">All statuses</option>
            <?php foreach ($plasticsOrdersStatuses as $statusCode => $statusLabel): ?>
              <option value="<?= $plasticsOrdersH($statusCode) ?>" <?= $statusCode === $selectedStatus ? 'selected' : '' ?>>
                <?= $plasticsOrdersH($statusLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="plastics-filter-field">
          <label for="plastics-search">Item search</label>
          <input type="search" name="q" id="plastics-search" class="form-control"
            value="<?= $plasticsOrdersH($selectedSearch) ?>" placeholder="Name, barcode, PN…">
        </div>

        <div class="plastics-filter-field">
          <label for="plastics-date-from">From date</label>
          <input type="date" name="date_from" id="plastics-date-from" class="form-control" value="<?= $plasticsOrdersH($selectedDateFrom) ?>">
        </div>

        <div class="plastics-filter-field">
          <label for="plastics-date-to">To date</label>
          <input type="date" name="date_to" id="plastics-date-to" class="form-control" value="<?= $plasticsOrdersH($selectedDateTo) ?>">
        </div>
      </div>

      <div class="plastics-filter-actions">
        <?php if ($hasActiveFilters): ?>
          <a href="?page=plastics_orders_all" class="btn btn-outline-secondary"><i class="fas fa-times mr-1"></i> Reset</a>
        <?php endif; ?>
        <button type="submit" class="btn btn-info"><i class="fas fa-search mr-1"></i> Apply filters</button>
      </div>
    </form>

    <?php if (!$hasActiveFilters): ?>
      <div class="plastics-empty-state">
        <div class="plastics-empty-state-icon"><i class="fas fa-search"></i></div>
        <h2>Choose at least one filter</h2>
        <p>No order rows are loaded yet. Filter by order number, supplier, status, item text or date to keep this page fast.</p>
      </div>
    <?php elseif ($matchingOrderCount === 0): ?>
      <div class="plastics-empty-state">
        <div class="plastics-empty-state-icon"><i class="fas fa-inbox"></i></div>
        <h2>No matching orders</h2>
        <p>Try a different value or remove one of the filters.</p>
      </div>
    <?php else: ?>
      <div class="plastics-results-card">
        <div class="plastics-results-header">
          <h2>Filtered results</h2>
          <span class="plastics-result-count">
            Showing <?= number_format($showingOrderCount) ?> of <?= number_format($matchingOrderCount) ?> rows
          </span>
        </div>

        <?php if ($matchingOrderCount > $plasticsOrdersResultLimit): ?>
          <div class="alert alert-warning rounded-0 border-left-0 border-right-0 mb-0">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            Only the first <?= number_format($plasticsOrdersResultLimit) ?> rows are loaded. Add another filter to narrow the result.
          </div>
        <?php endif; ?>

        <div class="plastics-table-wrap">
          <table id="plasticsOrdersAllTable" class="table table-bordered table-hover table-striped" style="width:100%">
            <thead>
              <tr>
                <th>Date</th><th>Order #</th><th>Supplier</th><th>Brand</th><th>Barcode</th>
                <th>Name</th><th>Description</th><th>Color</th><th>Qty</th><th>UFO PN</th>
                <th>UFO BC</th><th>R-Tech PN</th><th>R-Tech BC</th><th>Polisport PN</th>
                <th>Polisport BC</th><th>Acerbis PN</th><th>Acerbis BC</th><th>Other PN</th>
                <th>Other BC</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orders as $item): ?>
                <?php $itemStatus = strtolower(trim((string) ($item['status'] ?? ''))); ?>
                <tr>
                  <td data-order="<?= $plasticsOrdersH($item['created_at'] ?? '') ?>">
                    <?= !empty($item['created_at']) ? $plasticsOrdersH(date('d.m.Y', strtotime((string) $item['created_at']))) : '—' ?>
                  </td>
                  <td><strong><?= $plasticsOrdersH($item['order_number'] ?? '') ?></strong></td>
                  <td><?= $plasticsOrdersH($item['main_supplier'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['brand'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['barcode'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['name'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['description'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['color'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['quantity_to_order'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['ufo_pn'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['ufo_barcode'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['rt_pn'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['rt_barcode'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['ps_pn'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['ps_barcode'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['ac_pn'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['ac_barcode'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['other_pn'] ?? '') ?></td>
                  <td><?= $plasticsOrdersH($item['other_barcode'] ?? '') ?></td>
                  <td>
                    <span class="plastics-status-badge plastics-status-<?= $plasticsOrdersH($itemStatus) ?>">
                      <?= $plasticsOrdersH($plasticsOrdersStatuses[$itemStatus] ?? ($item['status'] ?? '')) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($hasActiveFilters && $matchingOrderCount > 0): ?>
<script>
  $(function () {
    if (!$.fn.DataTable || $.fn.DataTable.isDataTable('#plasticsOrdersAllTable')) return;

    $('#plasticsOrdersAllTable').DataTable({
      autoWidth: false,
      deferRender: true,
      info: true,
      lengthChange: true,
      pageLength: 50,
      lengthMenu: [[25, 50, 100, 250], [25, 50, 100, 250]],
      order: [[0, 'desc']],
      responsive: false,
      scrollX: true,
      dom: "<'plastics-datatable-toolbar'Bf>rt<'plastics-datatable-footer'lip>",
      buttons: ['copy', 'csv', 'excel', 'colvis'],
      columnDefs: [
        { targets: [9, 10, 11, 12, 13, 14, 15, 16, 17, 18], visible: false }
      ]
    });
  });
</script>
<?php endif; ?>
