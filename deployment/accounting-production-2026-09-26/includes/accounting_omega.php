<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/accounting/access.php';

if (!accounting_payout_user_can_access()) {
    echo '<div class="alert alert-danger">Na účtovnú sekciu nemáte oprávnenie.</div>';
    return;
}

/** @var PDO $pdo */
function accountingOmegaH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function accountingOmegaTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

if (empty($_SESSION['accounting_payout_csrf'])) {
    $_SESSION['accounting_payout_csrf'] = bin2hex(random_bytes(32));
}

$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}
$match = strtolower(trim((string) ($_GET['match'] ?? 'all')));
if (!in_array($match, ['all', 'matched', 'production', 'custom', 'unmatched'], true)) {
    $match = 'all';
}
$multiInvoiceOnly = !empty($_GET['multi']);
$summaryView = strtolower(trim((string) ($_GET['summary'] ?? ''))) === 'total' ? 'total' : '';
$paymentType = trim((string) ($_GET['payment_type'] ?? ''));

$schemaReady = $pdo instanceof PDO
    && accountingOmegaTableExists($pdo, 'accounting_omega_imports')
    && accountingOmegaTableExists($pdo, 'accounting_omega_invoices')
    && accountingOmegaTableExists($pdo, 'accounting_omega_invoice_items');

$stats = [
    'invoices' => 0,
    'matched_orders' => 0,
    'matched_custom_orders' => 0,
    'unmatched' => 0,
    'total_amount' => 0.0,
    'multi_invoice_orders' => 0,
];
$rows = [];
$imports = [];
$paymentTypes = [];

if ($schemaReady) {
    $from = $month . '-01';
    $to = date('Y-m-d', strtotime($from . ' +1 month'));

    $statsStmt = $pdo->prepare(' 
        SELECT
          COUNT(*) AS invoices,
          SUM(matched_order_id IS NOT NULL) AS matched_orders,
          SUM(matched_custom_order_id IS NOT NULL) AS matched_custom_orders,
          SUM(matched_order_id IS NULL AND matched_custom_order_id IS NULL) AS unmatched,
          COALESCE(SUM(total_amount), 0) AS total_amount,
          COUNT(DISTINCT CASE WHEN order_number IN (
            SELECT order_number
            FROM accounting_omega_invoices
            WHERE order_number IS NOT NULL AND order_number <> \'\'
            GROUP BY order_number
            HAVING COUNT(*) > 1
          ) THEN order_number END) AS multi_invoice_orders
        FROM accounting_omega_invoices
        WHERE issue_date >= :from_date AND issue_date < :to_date
    ');
    $statsStmt->execute([':from_date' => $from, ':to_date' => $to]);
    $stats = array_merge($stats, $statsStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $paymentTypes = $pdo->query(' 
        SELECT DISTINCT payment_type
        FROM accounting_omega_invoices
        WHERE payment_type IS NOT NULL AND payment_type <> \'\'
        ORDER BY payment_type
    ')->fetchAll(PDO::FETCH_COLUMN);

    $where = ['oi.issue_date >= :from_date', 'oi.issue_date < :to_date'];
    $params = [':from_date' => $from, ':to_date' => $to];
    if ($match === 'matched') {
        $where[] = '(oi.matched_order_id IS NOT NULL OR oi.matched_custom_order_id IS NOT NULL)';
    } elseif ($match === 'production') {
        $where[] = 'oi.matched_order_id IS NOT NULL';
    } elseif ($match === 'custom') {
        $where[] = 'oi.matched_custom_order_id IS NOT NULL';
    } elseif ($match === 'unmatched') {
        $where[] = 'oi.matched_order_id IS NULL AND oi.matched_custom_order_id IS NULL';
    }
    if ($multiInvoiceOnly) {
        $where[] = 'duplicate_counts.invoice_count_for_order > 1';
    }
    if ($paymentType !== '') {
        $where[] = 'oi.payment_type = :payment_type';
        $params[':payment_type'] = $paymentType;
    }

    $listStmt = $pdo->prepare(' 
        SELECT
          oi.id, oi.invoice_number, oi.issue_date, oi.order_number, oi.payment_type,
          oi.total_amount, oi.currency, oi.document_type, oi.customer_name,
          oi.item_count, oi.matched_order_id, oi.matched_custom_order_id,
          duplicate_counts.invoice_count_for_order
        FROM accounting_omega_invoices oi
        LEFT JOIN (
          SELECT order_number, COUNT(*) AS invoice_count_for_order
          FROM accounting_omega_invoices
          WHERE order_number IS NOT NULL AND order_number <> \'\'
          GROUP BY order_number
        ) duplicate_counts ON duplicate_counts.order_number = oi.order_number
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY oi.issue_date DESC, oi.invoice_number DESC
    ');
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $imports = $pdo->query(' 
        SELECT id, original_filename, detected_encoding, source_row_count,
               invoice_row_count, item_row_count, created_invoice_count,
               updated_invoice_count, unchanged_invoice_count,
               matched_order_count, matched_custom_order_count, imported_at
        FROM accounting_omega_imports
        ORDER BY imported_at DESC, id DESC
        LIMIT 15
    ')->fetchAll(PDO::FETCH_ASSOC);
}

$accountingOmegaCardUrl = static function (
    string $cardMatch = 'all',
    bool $multi = false,
    string $summary = ''
) use ($month, $paymentType): string {
    $query = [
        'page' => 'accounting_omega',
        'month' => $month,
        'match' => $cardMatch,
    ];
    if ($paymentType !== '') {
        $query['payment_type'] = $paymentType;
    }
    if ($multi) {
        $query['multi'] = 1;
    }
    if ($summary !== '') {
        $query['summary'] = $summary;
    }
    return 'index.php?' . http_build_query($query);
};
?>

<style>
  .accounting-omega-muted { color: #adb5bd; }
  .accounting-omega-money { font-variant-numeric: tabular-nums; white-space: nowrap; }
  .accounting-omega-upload-zone { border: 2px dashed #5f6b78; border-radius: .35rem; padding: 1.5rem; text-align: center; cursor: pointer; transition: border-color .15s, background-color .15s; }
  .accounting-omega-upload-zone:hover,
  .accounting-omega-upload-zone.is-dragover { border-color: #20c997; background: rgba(32, 201, 151, .1); }
  .accounting-omega-overview-row { align-items: stretch; }
  .accounting-omega-overview-column { display: flex; flex-direction: column; }
  .accounting-omega-overview-column > .card { flex: 1 1 auto; }
  .accounting-omega-summary-column { display: flex; flex-direction: column; }
  .accounting-omega-stats-row { flex: 1 1 auto; align-content: stretch; }
  .accounting-omega-stats-row > div { display: flex; }
  .accounting-omega-stat { --omega-accent: #20c997; width: 100%; min-height: 0; border-top: 3px solid var(--omega-accent); background: #414950; color: #f8f9fa !important; text-decoration: none !important; transition: background-color .15s ease, box-shadow .15s ease, transform .15s ease; }
  .accounting-omega-stat:hover { color: #fff !important; background: #4a535b; transform: translateY(-1px); }
  .accounting-omega-stat:focus { color: #fff !important; outline: 2px solid var(--omega-accent); outline-offset: 2px; }
  .accounting-omega-stat.is-active { box-shadow: inset 0 0 0 2px var(--omega-accent), 0 2px 6px rgba(0, 0, 0, .3); }
  .accounting-omega-stat-production { --omega-accent: #17a2b8; }
  .accounting-omega-stat-custom { --omega-accent: #28a745; }
  .accounting-omega-stat-unmatched { --omega-accent: #ffc107; }
  .accounting-omega-stat-multi { --omega-accent: #b47cff; }
  .accounting-omega-stat-total { --omega-accent: #20c997; }
  .accounting-omega-stat .card-body { display: flex; flex-direction: column; justify-content: center; padding: .85rem; }
  .accounting-omega-stat h3 { margin: 0 0 .15rem; font-size: 1.65rem; }
  .accounting-omega-table th { white-space: nowrap; }
  .accounting-page-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; min-height: 52px; margin-bottom: 1rem; }
  .accounting-page-heading { min-width: 0; }
  .accounting-page-heading h1 { line-height: 1.15; }
  .accounting-page-subtitle { min-height: 1.25em; line-height: 1.25; }
  .accounting-page-actions { display: flex; flex: 0 0 auto; align-items: flex-start; gap: .5rem; margin-left: 1rem; }
  #accountingOmegaTable_wrapper .accounting-table-toolbar { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .65rem .75rem; }
  #accountingOmegaTable_wrapper .accounting-table-toolbar .dt-buttons,
  #accountingOmegaTable_wrapper .accounting-table-toolbar .dataTables_filter { float: none; margin: 0; }
  @media (max-width: 767.98px) {
    .accounting-page-header { flex-direction: column; min-height: 0; gap: .75rem; }
    .accounting-page-actions { flex-wrap: wrap; margin-left: 0; }
  }
  @media (max-width: 991.98px) {
    .accounting-omega-summary-column { height: auto; }
    .accounting-omega-stat { min-height: 94px; }
  }
</style>

<div class="container-fluid">
  <header class="accounting-page-header">
    <div class="accounting-page-heading">
      <h1 class="h3 mb-1">OMEGA faktúry</h1>
      <div class="accounting-page-subtitle accounting-omega-muted">Faktúry, depozitné faktúry a párovanie na objednávky</div>
    </div>
    <div class="accounting-page-actions">
      <div class="btn-group" role="group" aria-label="Účtovné sekcie">
        <a class="btn btn-outline-secondary" href="?page=accounting_payouts">eBay payouts</a>
        <a class="btn btn-success active" href="?page=accounting_omega" aria-current="page">OMEGA faktúry</a>
      </div>
    </div>
  </header>

  <?php if (!$schemaReady): ?>
    <div class="alert alert-warning">
      OMEGA tabuľky ešte nie sú nainštalované. Spustite migráciu
      <code>db/accounting_omega.sql</code> a stránku obnovte.
    </div>
  <?php endif; ?>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= accountingOmegaH($_GET['error']) ?></div>
  <?php elseif (!empty($_GET['existing_file'])): ?>
    <div class="alert alert-info">Tento súbor už bol importovaný. Databáza zostala bez zmeny.</div>
  <?php elseif (!empty($_GET['omega_imported'])): ?>
    <div class="alert alert-success">
      OMEGA import dokončený. Nové faktúry: <b><?= (int) ($_GET['created'] ?? 0) ?></b>,
      zmenené: <b><?= (int) ($_GET['updated'] ?? 0) ?></b>,
      nezmenené: <b><?= (int) ($_GET['unchanged'] ?? 0) ?></b>,
      položky R02: <b><?= (int) ($_GET['items'] ?? 0) ?></b>,
      spárované production objednávky: <b><?= (int) ($_GET['matched_orders'] ?? 0) ?></b>,
      custom objednávky: <b><?= (int) ($_GET['matched_custom'] ?? 0) ?></b>.
    </div>
  <?php endif; ?>

  <div class="row accounting-omega-overview-row">
    <div class="col-lg-4 accounting-omega-overview-column">
      <div class="card card-outline card-success">
        <div class="card-header"><h3 class="card-title">Import OMEGA TXT/TSV</h3></div>
        <div class="card-body">
          <form method="post" action="scripts/accounting/import_omega.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= accountingOmegaH($_SESSION['accounting_payout_csrf']) ?>">
            <label class="accounting-omega-upload-zone d-block" id="omegaDropZone" for="omegaFile">
              <i class="fas fa-file-upload fa-2x text-success mb-2"></i><br>
              Pretiahnite sem alebo vyberte export z OMEGY<br>
              <small class="accounting-omega-muted">Podporované: .txt a .tsv, maximálne 30 MB. Súbor sa po importe neuchováva.</small>
            </label>
            <input class="form-control-file mt-2" id="omegaFile" type="file" name="omega_file" accept=".txt,.tsv,text/plain,text/tab-separated-values" required>
            <div class="small accounting-omega-muted mt-2" id="omegaFileSelection">Nie je vybraný žiadny súbor.</div>
            <button class="btn btn-success btn-block mt-3" type="submit" <?= $schemaReady ? '' : 'disabled' ?>>
              <i class="fas fa-upload mr-1"></i> Importovať OMEGA faktúry
            </button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-8 accounting-omega-summary-column">
      <div class="row accounting-omega-stats-row">
        <div class="col-6 col-xl-4"><a href="<?= accountingOmegaH($accountingOmegaCardUrl()) ?>" class="card accounting-omega-stat <?= $match === 'all' && !$multiInvoiceOnly && $summaryView === '' ? 'is-active' : '' ?>" aria-label="Zobraziť všetky faktúry v mesiaci"><div class="card-body"><h3><?= (int) $stats['invoices'] ?></h3><div>Faktúry v mesiaci</div></div></a></div>
        <div class="col-6 col-xl-4"><a href="<?= accountingOmegaH($accountingOmegaCardUrl('production')) ?>" class="card accounting-omega-stat accounting-omega-stat-production <?= $match === 'production' && !$multiInvoiceOnly ? 'is-active' : '' ?>" aria-label="Zobraziť faktúry spárované s production objednávkami"><div class="card-body"><h3><?= (int) $stats['matched_orders'] ?></h3><div>Production zhody</div></div></a></div>
        <div class="col-6 col-xl-4"><a href="<?= accountingOmegaH($accountingOmegaCardUrl('custom')) ?>" class="card accounting-omega-stat accounting-omega-stat-custom <?= $match === 'custom' && !$multiInvoiceOnly ? 'is-active' : '' ?>" aria-label="Zobraziť faktúry spárované s custom objednávkami"><div class="card-body"><h3><?= (int) $stats['matched_custom_orders'] ?></h3><div>Custom zhody</div></div></a></div>
        <div class="col-6 col-xl-4"><a href="<?= accountingOmegaH($accountingOmegaCardUrl('unmatched')) ?>" class="card accounting-omega-stat accounting-omega-stat-unmatched <?= $match === 'unmatched' && !$multiInvoiceOnly ? 'is-active' : '' ?>" aria-label="Zobraziť nespárované faktúry"><div class="card-body"><h3><?= (int) $stats['unmatched'] ?></h3><div>Nespárované</div></div></a></div>
        <div class="col-6 col-xl-4"><a href="<?= accountingOmegaH($accountingOmegaCardUrl('all', true)) ?>" class="card accounting-omega-stat accounting-omega-stat-multi <?= $multiInvoiceOnly ? 'is-active' : '' ?>" aria-label="Zobraziť objednávky s viacerými faktúrami"><div class="card-body"><h3><?= (int) $stats['multi_invoice_orders'] ?></h3><div>Objednávky s viacerými faktúrami</div></div></a></div>
        <div class="col-6 col-xl-4"><a href="<?= accountingOmegaH($accountingOmegaCardUrl('all', false, 'total')) ?>" class="card accounting-omega-stat accounting-omega-stat-total <?= $summaryView === 'total' ? 'is-active' : '' ?>" aria-label="Zobraziť faktúry zahrnuté v celkovej sume"><div class="card-body"><h3 class="accounting-omega-money"><?= number_format((float) $stats['total_amount'], 2, ',', ' ') ?> €</h3><div>Suma faktúr</div></div></a></div>
      </div>
    </div>
  </div>

  <div class="card card-outline card-primary">
    <div class="card-header">
      <form class="form-inline" id="accountingOmegaFilters" method="get">
        <input type="hidden" name="page" value="accounting_omega">
        <?php if ($multiInvoiceOnly): ?><input type="hidden" name="multi" value="1"><?php endif; ?>
        <label class="mr-2" for="omegaMonth">Mesiac</label>
        <input class="form-control form-control-sm mr-3 accounting-omega-auto-filter" id="omegaMonth" type="month" name="month" value="<?= accountingOmegaH($month) ?>">
        <select class="form-control form-control-sm mr-3 accounting-omega-auto-filter" name="match">
          <option value="all" <?= $match === 'all' ? 'selected' : '' ?>>Všetky párovania</option>
          <option value="matched" <?= $match === 'matched' ? 'selected' : '' ?>>Spárované</option>
          <option value="production" <?= $match === 'production' ? 'selected' : '' ?>>Production zhody</option>
          <option value="custom" <?= $match === 'custom' ? 'selected' : '' ?>>Custom zhody</option>
          <option value="unmatched" <?= $match === 'unmatched' ? 'selected' : '' ?>>Nespárované</option>
        </select>
        <select class="form-control form-control-sm accounting-omega-auto-filter" name="payment_type">
          <option value="">Všetky spôsoby platby</option>
          <?php foreach ($paymentTypes as $option): ?>
            <option value="<?= accountingOmegaH($option) ?>" <?= $paymentType === $option ? 'selected' : '' ?>><?= accountingOmegaH($option) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm btn-primary ml-2">Filtrovať</button></noscript>
      </form>
    </div>
    <div class="card-body table-responsive p-0">
      <table class="table table-sm table-hover table-striped accounting-omega-table mb-0" id="accountingOmegaTable" data-export-title="OMEGA faktury <?= accountingOmegaH($month) ?>">
        <thead>
          <tr>
            <th>Dátum</th><th>Faktúra</th><th>Objednávka</th><th>Zákazník</th>
            <th>Platba</th><th>Typ</th><th class="text-right">Suma</th><th class="text-center">R02</th><th>Zhoda</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td data-order="<?= accountingOmegaH($row['issue_date']) ?>"><?= accountingOmegaH(date('d.m.Y', strtotime((string) $row['issue_date']))) ?></td>
              <td><b><?= accountingOmegaH($row['invoice_number']) ?></b></td>
              <td>
                <?= accountingOmegaH($row['order_number'] ?: '-') ?>
                <?php if ((int) ($row['invoice_count_for_order'] ?? 0) > 1): ?>
                  <span class="badge badge-warning" title="Počet faktúr s rovnakým číslom objednávky"><?= (int) $row['invoice_count_for_order'] ?>×</span>
                <?php endif; ?>
              </td>
              <td><?= accountingOmegaH($row['customer_name'] ?: '-') ?></td>
              <td><?= accountingOmegaH($row['payment_type'] ?: '-') ?></td>
              <td><?= accountingOmegaH($row['document_type'] ?: '-') ?></td>
              <td class="text-right accounting-omega-money" data-order="<?= accountingOmegaH($row['total_amount']) ?>"><?= number_format((float) $row['total_amount'], 2, ',', ' ') ?> <?= accountingOmegaH($row['currency']) ?></td>
              <td class="text-center"><?= (int) $row['item_count'] ?></td>
              <td>
                <?php if (!empty($row['matched_order_id'])): ?>
                  <a class="badge badge-info" href="?page=orders&q=<?= rawurlencode((string) $row['order_number']) ?>">Production #<?= (int) $row['matched_order_id'] ?></a>
                <?php endif; ?>
                <?php if (!empty($row['matched_custom_order_id'])): ?>
                  <a class="badge badge-success" href="?page=custom_orders&amp;custom_order_id=<?= (int) $row['matched_custom_order_id'] ?>">Custom #<?= (int) $row['matched_custom_order_id'] ?></a>
                <?php endif; ?>
                <?php if (empty($row['matched_order_id']) && empty($row['matched_custom_order_id'])): ?>
                  <span class="badge badge-danger">Nenájdená</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($imports): ?>
    <div class="card collapsed-card">
      <div class="card-header">
        <h3 class="card-title">História OMEGA importov</h3>
        <div class="card-tools"><button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-plus"></i></button></div>
      </div>
      <div class="card-body table-responsive p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Dátum</th><th>Súbor</th><th>Kódovanie</th><th>R01</th><th>R02</th><th>Nové</th><th>Zmenené</th><th>Nezmenené</th><th>Production</th><th>Custom</th></tr></thead>
          <tbody>
            <?php foreach ($imports as $import): ?>
              <tr>
                <td><?= accountingOmegaH(date('d.m.Y H:i', strtotime((string) $import['imported_at']))) ?></td>
                <td><?= accountingOmegaH($import['original_filename']) ?></td>
                <td><?= accountingOmegaH($import['detected_encoding']) ?></td>
                <td><?= (int) $import['invoice_row_count'] ?></td><td><?= (int) $import['item_row_count'] ?></td>
                <td><?= (int) $import['created_invoice_count'] ?></td><td><?= (int) $import['updated_invoice_count'] ?></td><td><?= (int) $import['unchanged_invoice_count'] ?></td>
                <td><?= (int) $import['matched_order_count'] ?></td><td><?= (int) $import['matched_custom_order_count'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(function () {
  const zone = document.getElementById('omegaDropZone');
  const input = document.getElementById('omegaFile');
  const selection = document.getElementById('omegaFileSelection');
  if (zone && input && selection) {
    const showSelection = function () {
      const file = input.files && input.files[0];
      selection.classList.remove('text-danger');
      selection.textContent = file ? 'Vybraný: ' + file.name : 'Nie je vybraný žiadny súbor.';
    };
    ['dragenter', 'dragover'].forEach(function (eventName) {
      zone.addEventListener(eventName, function (event) {
        event.preventDefault();
        zone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function (eventName) {
      zone.addEventListener(eventName, function (event) {
        event.preventDefault();
        zone.classList.remove('is-dragover');
      });
    });
    zone.addEventListener('drop', function (event) {
      const files = event.dataTransfer && event.dataTransfer.files;
      if (!files || !files.length) return;
      const name = String(files[0].name || '').toLowerCase();
      if (!name.endsWith('.txt') && !name.endsWith('.tsv')) {
        selection.classList.add('text-danger');
        selection.textContent = 'Povolené sú iba TXT alebo TSV súbory.';
        return;
      }
      try { input.files = files; showSelection(); } catch (error) {
        selection.classList.add('text-danger');
        selection.textContent = 'Súbor vyberte kliknutím do importnej plochy.';
      }
    });
    input.addEventListener('change', showSelection);
  }

  const filters = document.getElementById('accountingOmegaFilters');
  if (filters) {
    filters.querySelectorAll('.accounting-omega-auto-filter').forEach(function (field) {
      field.addEventListener('change', function () {
        if (typeof filters.requestSubmit === 'function') filters.requestSubmit(); else filters.submit();
      });
    });
  }
}());

window.addEventListener('load', function () {
  const $ = window.jQuery;
  if (!$ || !$.fn.DataTable || !document.getElementById('accountingOmegaTable')) return;
  if ($.fn.DataTable.isDataTable('#accountingOmegaTable')) return;
  const table = $('#accountingOmegaTable');
  const title = table.data('export-title') || 'OMEGA faktury';
  const exportOptions = { modifier: { search: 'applied', order: 'applied', page: 'all' } };
  table.DataTable({
    responsive: true, searching: true, ordering: true, order: [], lengthChange: true,
    autoWidth: false, pageLength: 100, info: true,
    dom: '<"accounting-table-toolbar"Bf>rtip',
    language: { emptyTable: 'Pre zvolený filter nie sú žiadne OMEGA faktúry.' },
    buttons: [
      { extend: 'copy', text: 'Copy', title: title, exportOptions: exportOptions },
      { extend: 'csv', text: 'CSV', title: title, filename: title, exportOptions: exportOptions },
      { extend: 'excel', text: 'Excel', title: title, filename: title, exportOptions: exportOptions },
      { extend: 'print', text: 'Print', title: title, exportOptions: exportOptions }
    ]
  });
});
</script>
