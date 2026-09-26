<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/accounting/access.php';

if (!accounting_payout_user_can_access()) {
    echo '<div class="alert alert-danger">Na účtovnú sekciu nemáte oprávnenie.</div>';
    return;
}

/** @var PDO $pdo */
function accountingPayoutH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function accountingPayoutTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function accountingPayoutColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ?');
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
}

if (empty($_SESSION['accounting_payout_csrf'])) {
    $_SESSION['accounting_payout_csrf'] = bin2hex(random_bytes(32));
}

$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}
$type = strtoupper(trim((string) ($_GET['type'] ?? 'ORDER')));
$allowedTypes = ['ALL', 'ORDER', 'REFUND', 'OTHER_FEE'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'ORDER';
}
$match = strtolower(trim((string) ($_GET['match'] ?? 'all')));
if (!in_array($match, ['all', 'matched', 'unmatched'], true)) {
    $match = 'all';
}

$from = $month . '-01';
$to = date('Y-m-d', strtotime($from . ' +1 month'));
$schemaReady = $pdo instanceof PDO
    && accountingPayoutTableExists($pdo, 'accounting_payout_imports')
    && accountingPayoutTableExists($pdo, 'accounting_payout_transactions');

$stats = [
    'transactions' => 0,
    'orders' => 0,
    'refunds' => 0,
    'other_fees' => 0,
    'unmatched_orders' => 0,
    'gross' => 0.0,
    'fees' => 0.0,
    'net' => 0.0,
];
$rows = [];
$imports = [];

if ($schemaReady) {
    $statsStmt = $pdo->prepare('
        SELECT
          COUNT(*) AS transactions,
          SUM(transaction_type = \'ORDER\') AS orders,
          SUM(transaction_type = \'REFUND\') AS refunds,
          SUM(transaction_type = \'OTHER_FEE\') AS other_fees,
          SUM(transaction_type = \'ORDER\' AND matched_order_id IS NULL) AS unmatched_orders,
          COALESCE(SUM(CASE WHEN transaction_type = \'ORDER\' THEN gross_payout_amount ELSE 0 END), 0) AS gross,
          COALESCE(SUM(CASE WHEN transaction_type = \'ORDER\' THEN fee_payout_amount ELSE 0 END), 0) AS fees,
          COALESCE(SUM(net_amount), 0) AS net
        FROM accounting_payout_transactions
        WHERE transaction_date >= :from_date AND transaction_date < :to_date
    ');
    $statsStmt->execute([':from_date' => $from, ':to_date' => $to]);
    $stats = array_merge($stats, $statsStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $financialColumnsReady = accountingPayoutColumnExists($pdo, 'orders', 'financial_total_value')
        && accountingPayoutColumnExists($pdo, 'orders', 'financial_total_currency');
    $financialSelect = $financialColumnsReady
        ? 'o.financial_total_value, o.financial_total_currency,'
        : 'NULL AS financial_total_value, NULL AS financial_total_currency,';

    $where = ['t.transaction_date >= :from_date', 't.transaction_date < :to_date'];
    $params = [':from_date' => $from, ':to_date' => $to];
    if ($type !== 'ALL') {
        $where[] = 't.transaction_type = :transaction_type';
        $params[':transaction_type'] = $type;
    }
    if ($match === 'matched') {
        $where[] = 't.matched_order_id IS NOT NULL';
    } elseif ($match === 'unmatched') {
        $where[] = 't.matched_order_id IS NULL';
    }

    $listSql = '
        SELECT
          t.id, t.transaction_date, t.transaction_type, t.transaction_type_raw,
          t.order_number, t.buyer_name, t.post_country, t.gross_payout_amount,
          t.fee_payout_amount, t.net_amount, t.payout_currency, t.exchange_rate,
          t.payout_id, t.reference_id, t.description, t.reconciliation_difference,
          t.matched_order_id, t.source_region,
          ' . $financialSelect . '
          o.total AS imported_order_total, o.currency AS imported_order_currency
        FROM accounting_payout_transactions t
        LEFT JOIN orders o ON o.id = t.matched_order_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY t.transaction_date DESC, t.id DESC
    ';
    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $imports = $pdo->query('
        SELECT id, original_filename, source_region, source_row_count, imported_row_count,
               duplicate_row_count, matched_order_count, imported_at
        FROM accounting_payout_imports
        ORDER BY imported_at DESC, id DESC
        LIMIT 15
    ')->fetchAll(PDO::FETCH_ASSOC);
}

$typeLabels = [
    'ALL' => 'Všetky transakcie',
    'ORDER' => 'Objednávky',
    'REFUND' => 'Refundácie',
    'OTHER_FEE' => 'Ostatné poplatky',
];
$accountingCardUrl = static function (string $cardType, string $cardMatch = 'all') use ($month): string {
    return 'index.php?' . http_build_query([
        'page' => 'accounting_payouts',
        'month' => $month,
        'type' => $cardType,
        'match' => $cardMatch,
    ]);
};
?>

<style>
  .accounting-payout-table th { white-space: nowrap; }
  .accounting-payout-table td { vertical-align: middle; }
  .accounting-money { font-variant-numeric: tabular-nums; white-space: nowrap; }
  .accounting-upload-zone { border: 2px dashed #5f6b78; border-radius: .35rem; padding: 1.5rem; text-align: center; cursor: pointer; transition: border-color .15s ease, background-color .15s ease; }
  .accounting-upload-zone:hover,
  .accounting-upload-zone.is-dragover { border-color: #17a2b8; background-color: rgba(23, 162, 184, .12); }
  .accounting-muted { color: #adb5bd; }
  .accounting-page-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; min-height: 52px; margin-bottom: 1rem; }
  .accounting-page-heading { min-width: 0; }
  .accounting-page-heading h1 { line-height: 1.15; }
  .accounting-page-subtitle { min-height: 1.25em; line-height: 1.25; }
  .accounting-page-actions { display: flex; flex: 0 0 auto; align-items: flex-start; gap: .5rem; margin-left: 1rem; }
  .accounting-overview-row { align-items: stretch; }
  .accounting-overview-column { display: flex; flex-direction: column; }
  .accounting-overview-column > .card { flex: 1 1 auto; }
  .accounting-summary-column { display: flex; flex-direction: column; height: 100%; }
  .accounting-stats-row { flex: 1 1 auto; }
  .accounting-stats-row > div { display: flex; }
  .accounting-stat-card {
    --accounting-accent: #17a2b8;
    width: 100%;
    min-height: 104px;
    margin-bottom: 1rem;
    overflow: hidden;
    color: #f8f9fa !important;
    background: #414950 !important;
    border-top: 3px solid var(--accounting-accent);
    box-shadow: 0 1px 3px rgba(0, 0, 0, .24);
    text-decoration: none !important;
    transition: background-color .15s ease, box-shadow .15s ease, transform .15s ease;
  }
  .accounting-stat-card:hover { color: #fff !important; background: #4a535b !important; transform: translateY(-1px); }
  .accounting-stat-card:focus { color: #fff !important; outline: 2px solid var(--accounting-accent); outline-offset: 2px; }
  .accounting-stat-card.is-active { box-shadow: inset 0 0 0 2px var(--accounting-accent), 0 2px 6px rgba(0, 0, 0, .3); }
  .accounting-stat-card .inner { padding: 13px 12px; }
  .accounting-stat-card .inner h3 { margin-bottom: .2rem; font-size: 1.75rem; line-height: 1.05; }
  .accounting-stat-card .inner p { margin: 0; color: #e9ecef; white-space: nowrap; }
  .accounting-stat-card .icon { top: 9px; right: 12px; color: var(--accounting-accent); opacity: .32; }
  .accounting-stat-card .icon > i { font-size: 58px; }
  .accounting-stat-orders { --accounting-accent: #20c6d8; }
  .accounting-stat-unmatched { --accounting-accent: #ffc107; }
  .accounting-stat-refunds { --accounting-accent: #adb5bd; }
  .accounting-stat-fees { --accounting-accent: #b47cff; }
  .accounting-totals-card { flex: 0 0 auto; }
  .accounting-payout-table thead th { vertical-align: middle; }
  #accountingPayoutTable_wrapper .accounting-table-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    padding: .65rem .75rem;
  }
  #accountingPayoutTable_wrapper .accounting-table-toolbar .dt-buttons,
  #accountingPayoutTable_wrapper .accounting-table-toolbar .dataTables_filter {
    float: none;
    margin: 0;
  }
  #accountingPayoutTable_wrapper .accounting-table-toolbar .dataTables_filter label {
    display: flex;
    align-items: center;
    gap: .45rem;
    margin: 0;
    white-space: nowrap;
  }
  #accountingPayoutTable_wrapper .accounting-table-toolbar .dataTables_filter input {
    margin-left: 0;
  }
  @media (max-width: 991.98px) {
    .accounting-summary-column { height: auto; }
  }
  @media (max-width: 575.98px) {
    #accountingPayoutTable_wrapper .accounting-table-toolbar {
      align-items: stretch;
      flex-direction: column;
    }
    #accountingPayoutTable_wrapper .accounting-table-toolbar .dataTables_filter label {
      justify-content: space-between;
    }
  }
  @media (max-width: 767.98px) {
    .accounting-page-header { flex-direction: column; min-height: 0; gap: .75rem; }
    .accounting-page-actions { flex-wrap: wrap; margin-left: 0; }
  }
</style>

<div class="container-fluid">
  <header class="accounting-page-header">
    <div class="accounting-page-heading">
      <h1 class="h3 mb-1">eBay payouts</h1>
      <div class="accounting-page-subtitle accounting-muted">Import payoutov, párovanie objednávok a podklady pre OMEGU</div>
    </div>
    <div class="accounting-page-actions">
      <div class="btn-group" role="group" aria-label="Účtovné sekcie">
        <a class="btn btn-success active" href="?page=accounting_payouts" aria-current="page">eBay payouts</a>
        <a class="btn btn-outline-secondary" href="?page=accounting_omega">OMEGA faktúry</a>
      </div>
      <?php if ($schemaReady): ?>
        <a class="btn btn-outline-success"
           href="scripts/accounting/export_vycuc.php?month=<?= accountingPayoutH($month) ?>">
          <i class="fas fa-file-csv mr-1"></i> Export Vycuc
        </a>
      <?php endif; ?>
    </div>
  </header>

  <?php if (!$schemaReady): ?>
    <div class="alert alert-warning">
      Účtovné tabuľky ešte nie sú nainštalované. Spustite migráciu
      <code>db/accounting_payouts.sql</code> a stránku obnovte.
    </div>
  <?php endif; ?>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= accountingPayoutH($_GET['error']) ?></div>
  <?php elseif (isset($_GET['imported'])): ?>
    <div class="alert alert-success">
      Importované riadky: <b><?= (int) $_GET['imported'] ?></b>,
      spárované s objednávkou: <b><?= (int) ($_GET['matched'] ?? 0) ?></b>,
      preskočené duplicity: <b><?= (int) ($_GET['duplicates'] ?? 0) ?></b>.
      <?php if ((int) ($_GET['existing_files'] ?? 0) > 0): ?>
        Už importované súbory: <b><?= (int) $_GET['existing_files'] ?></b>.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="row accounting-overview-row">
    <div class="col-lg-4 accounting-overview-column">
      <div class="card card-outline card-info">
        <div class="card-header"><h3 class="card-title">Import surového payout CSV</h3></div>
        <div class="card-body">
          <form method="post" action="scripts/accounting/import_payout.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= accountingPayoutH($_SESSION['accounting_payout_csrf']) ?>">
            <label class="accounting-upload-zone d-block" id="payoutDropZone" for="payoutFiles">
              <i class="fas fa-cloud-upload-alt fa-2x text-info mb-2"></i><br>
              Pretiahnite sem alebo vyberte UK a/alebo DE payout CSV<br>
              <small class="accounting-muted">Súbor sa po importe neuchováva na disku.</small>
            </label>
            <input class="form-control-file mt-2" id="payoutFiles" type="file" name="payout_files[]" accept=".csv,text/csv" multiple required>
            <div class="small accounting-muted mt-2" id="payoutFileSelection" aria-live="polite">Nie je vybraný žiadny súbor.</div>
            <button class="btn btn-info btn-block mt-3" type="submit" <?= $schemaReady ? '' : 'disabled' ?>>
              <i class="fas fa-upload mr-1"></i> Importovať payout
            </button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-8 accounting-overview-column">
      <div class="accounting-summary-column">
      <div class="row accounting-stats-row">
        <div class="col-6 col-md-3"><a href="<?= accountingPayoutH($accountingCardUrl('ORDER')) ?>" class="small-box accounting-stat-card accounting-stat-orders <?= $type === 'ORDER' && $match === 'all' ? 'is-active' : '' ?>" aria-label="Zobraziť objednávky"><div class="inner"><h3><?= (int) $stats['orders'] ?></h3><p>Objednávky</p></div><div class="icon"><i class="fas fa-shopping-cart"></i></div></a></div>
        <div class="col-6 col-md-3"><a href="<?= accountingPayoutH($accountingCardUrl('ORDER', 'unmatched')) ?>" class="small-box accounting-stat-card accounting-stat-unmatched <?= $type === 'ORDER' && $match === 'unmatched' ? 'is-active' : '' ?>" aria-label="Zobraziť nespárované objednávky"><div class="inner"><h3><?= (int) $stats['unmatched_orders'] ?></h3><p>Nespárované</p></div><div class="icon"><i class="fas fa-unlink"></i></div></a></div>
        <div class="col-6 col-md-3"><a href="<?= accountingPayoutH($accountingCardUrl('REFUND')) ?>" class="small-box accounting-stat-card accounting-stat-refunds <?= $type === 'REFUND' && $match === 'all' ? 'is-active' : '' ?>" aria-label="Zobraziť refundácie"><div class="inner"><h3><?= (int) $stats['refunds'] ?></h3><p>Refundácie</p></div><div class="icon"><i class="fas fa-undo"></i></div></a></div>
        <div class="col-6 col-md-3"><a href="<?= accountingPayoutH($accountingCardUrl('OTHER_FEE')) ?>" class="small-box accounting-stat-card accounting-stat-fees <?= $type === 'OTHER_FEE' && $match === 'all' ? 'is-active' : '' ?>" aria-label="Zobraziť ostatné poplatky"><div class="inner"><h3><?= (int) $stats['other_fees'] ?></h3><p>Ostatné poplatky</p></div><div class="icon"><i class="fas fa-receipt"></i></div></a></div>
      </div>
      <div class="card accounting-totals-card">
        <div class="card-body py-3">
          <div class="row text-center">
            <div class="col-md-4"><div class="accounting-muted">Hrubá suma objednávok</div><div class="h4 accounting-money mb-0"><?= number_format((float) $stats['gross'], 2, ',', ' ') ?> €</div></div>
            <div class="col-md-4"><div class="accounting-muted">Poplatky objednávok</div><div class="h4 accounting-money text-warning mb-0"><?= number_format((float) $stats['fees'], 2, ',', ' ') ?> €</div></div>
            <div class="col-md-4"><div class="accounting-muted">Čistý pohyb všetkých transakcií</div><div class="h4 accounting-money mb-0"><?= number_format((float) $stats['net'], 2, ',', ' ') ?> €</div></div>
          </div>
        </div>
      </div>
      </div>
    </div>
  </div>

  <div class="card card-outline card-primary">
    <div class="card-header">
      <form class="form-inline" id="accountingPayoutFilters" method="get">
        <input type="hidden" name="page" value="accounting_payouts">
        <label class="mr-2" for="accountingMonth">Mesiac</label>
        <input class="form-control form-control-sm mr-3 accounting-auto-filter" id="accountingMonth" type="month" name="month" value="<?= accountingPayoutH($month) ?>">
        <select class="form-control form-control-sm mr-3 accounting-auto-filter" name="type">
          <?php foreach ($typeLabels as $value => $label): ?>
            <option value="<?= accountingPayoutH($value) ?>" <?= $type === $value ? 'selected' : '' ?>><?= accountingPayoutH($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-control form-control-sm mr-3 accounting-auto-filter" name="match">
          <option value="all" <?= $match === 'all' ? 'selected' : '' ?>>Všetky párovania</option>
          <option value="matched" <?= $match === 'matched' ? 'selected' : '' ?>>Spárované</option>
          <option value="unmatched" <?= $match === 'unmatched' ? 'selected' : '' ?>>Nespárované</option>
        </select>
        <noscript><button class="btn btn-sm btn-primary" type="submit">Filtrovať</button></noscript>
      </form>
    </div>
    <div class="card-body table-responsive p-0">
      <table class="table table-sm table-hover table-striped accounting-payout-table mb-0" id="accountingPayoutTable" data-export-title="eBay payouts <?= accountingPayoutH($month) ?>">
        <thead>
          <tr>
            <th>Dátum</th><th>Typ</th><th>Objednávka</th><th>Zákazník</th><th>Krajina</th>
            <th class="text-right">Suma EUR</th><th class="text-right">Poplatok EUR</th><th class="text-right">Netto EUR</th>
            <th>DS objednávka</th><th>Aktuálna fakturovaná suma</th><th>Payout</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $typeClass = $row['transaction_type'] === 'ORDER' ? 'badge-success' : ($row['transaction_type'] === 'REFUND' ? 'badge-warning' : 'badge-secondary');
              $matched = !empty($row['matched_order_id']);
              $currentFinancial = $row['financial_total_value'];
              $currentCurrency = trim((string) ($row['financial_total_currency'] ?? ''));
            ?>
            <tr>
              <td data-order="<?= accountingPayoutH($row['transaction_date'] ?? '') ?>"><?= $row['transaction_date'] ? accountingPayoutH(date('d.m.Y', strtotime((string) $row['transaction_date']))) : '-' ?></td>
              <td><span class="badge <?= $typeClass ?>"><?= accountingPayoutH($row['transaction_type']) ?></span></td>
              <td><b><?= accountingPayoutH($row['order_number'] ?: '-') ?></b></td>
              <td><?= accountingPayoutH($row['buyer_name'] ?: '-') ?></td>
              <td><?= accountingPayoutH($row['post_country'] ?: '-') ?></td>
              <td class="text-right accounting-money" data-order="<?= accountingPayoutH($row['gross_payout_amount'] ?? '') ?>"><?= $row['gross_payout_amount'] !== null ? number_format((float) $row['gross_payout_amount'], 2, ',', ' ') : '-' ?></td>
              <td class="text-right accounting-money text-warning" data-order="<?= accountingPayoutH($row['fee_payout_amount'] ?? '') ?>"><?= $row['fee_payout_amount'] !== null ? number_format((float) $row['fee_payout_amount'], 2, ',', ' ') : '-' ?></td>
              <td class="text-right accounting-money" data-order="<?= accountingPayoutH($row['net_amount'] ?? '') ?>"><?= $row['net_amount'] !== null ? number_format((float) $row['net_amount'], 2, ',', ' ') : '-' ?></td>
              <td>
                <?php if ($matched): ?>
                  <a class="badge badge-info" href="index.php?page=orders&q=<?= rawurlencode((string) $row['order_number']) ?>">#<?= (int) $row['matched_order_id'] ?></a>
                <?php elseif (!empty($row['order_number'])): ?>
                  <span class="badge badge-danger">Nenájdená</span>
                <?php else: ?>-
                <?php endif; ?>
              </td>
              <td class="accounting-money">
                <?php if ($currentFinancial !== null): ?>
                  <?= number_format((float) $currentFinancial, 2, ',', ' ') ?> <?= accountingPayoutH($currentCurrency ?: 'EUR') ?>
                <?php else: ?>
                  <span class="accounting-muted">Nenastavená</span>
                <?php endif; ?>
              </td>
              <td><?= accountingPayoutH($row['payout_id'] ?: '-') ?><br><small class="accounting-muted"><?= accountingPayoutH($row['source_region']) ?></small></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($imports): ?>
    <div class="card collapsed-card">
      <div class="card-header">
        <h3 class="card-title">História importov</h3>
        <div class="card-tools"><button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-plus"></i></button></div>
      </div>
      <div class="card-body table-responsive p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Dátum</th><th>Súbor</th><th>Región</th><th>Zdrojové</th><th>Importované</th><th>Duplicity</th><th>Spárované</th></tr></thead>
          <tbody>
            <?php foreach ($imports as $import): ?>
              <tr>
                <td><?= accountingPayoutH(date('d.m.Y H:i', strtotime((string) $import['imported_at']))) ?></td>
                <td><?= accountingPayoutH($import['original_filename']) ?></td>
                <td><?= accountingPayoutH($import['source_region']) ?></td>
                <td><?= (int) $import['source_row_count'] ?></td>
                <td><?= (int) $import['imported_row_count'] ?></td>
                <td><?= (int) $import['duplicate_row_count'] ?></td>
                <td><?= (int) $import['matched_order_count'] ?></td>
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
  const dropZone = document.getElementById('payoutDropZone');
  const fileInput = document.getElementById('payoutFiles');
  const selection = document.getElementById('payoutFileSelection');
  if (!dropZone || !fileInput || !selection) return;

  function showSelection() {
    const files = Array.from(fileInput.files || []);
    selection.classList.remove('text-danger');
    selection.textContent = files.length
      ? 'Vybrané: ' + files.map(function (file) { return file.name; }).join(', ')
      : 'Nie je vybraný žiadny súbor.';
  }

  ['dragenter', 'dragover'].forEach(function (eventName) {
    dropZone.addEventListener(eventName, function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
      dropZone.classList.add('is-dragover');
    });
  });

  ['dragleave', 'drop'].forEach(function (eventName) {
    dropZone.addEventListener(eventName, function (event) {
      event.preventDefault();
      event.stopPropagation();
      dropZone.classList.remove('is-dragover');
    });
  });

  dropZone.addEventListener('drop', function (event) {
    const droppedFiles = Array.from((event.dataTransfer && event.dataTransfer.files) || []);
    if (!droppedFiles.length) return;

    const invalidFile = droppedFiles.find(function (file) {
      return !file.name.toLowerCase().endsWith('.csv');
    });
    if (invalidFile) {
      fileInput.value = '';
      selection.classList.add('text-danger');
      selection.textContent = 'Povolené sú iba CSV súbory. Nepodarilo sa pridať: ' + invalidFile.name;
      return;
    }

    try {
      fileInput.files = event.dataTransfer.files;
      showSelection();
    } catch (error) {
      selection.classList.add('text-danger');
      selection.textContent = 'Prehliadač nepovolil vloženie súborov. Vyberte ich kliknutím do plochy.';
    }
  });

  fileInput.addEventListener('change', showSelection);

  const filterForm = document.getElementById('accountingPayoutFilters');
  if (filterForm) {
    filterForm.querySelectorAll('.accounting-auto-filter').forEach(function (field) {
      field.addEventListener('change', function () {
        if (typeof filterForm.requestSubmit === 'function') {
          filterForm.requestSubmit();
        } else {
          filterForm.submit();
        }
      });
    });
  }
}());

// The shared datatable.js contains initializers for many unrelated pages. Some
// legacy initializers can fail when their table is absent, so this page owns its
// DataTable setup and starts it only after all DataTables extensions are loaded.
window.addEventListener('load', function () {
  const $ = window.jQuery;
  if (!$ || !$.fn.DataTable || !document.getElementById('accountingPayoutTable')) return;
  if ($.fn.DataTable.isDataTable('#accountingPayoutTable')) return;

  const table = $('#accountingPayoutTable');
  const exportTitle = table.data('export-title') || 'eBay payouts';
  const exportOptions = {
    columns: ':not(.no-export)',
    modifier: { search: 'applied', order: 'applied', page: 'all' }
  };

  table.DataTable({
    responsive: true,
    searching: true,
    ordering: true,
    order: [],
    lengthChange: true,
    autoWidth: false,
    pageLength: 100,
    info: true,
    dom: '<"accounting-table-toolbar"Bf>rtip',
    language: {
      emptyTable: 'Pre zvolený filter nie sú žiadne payout transakcie.'
    },
    buttons: [
      { extend: 'copy', text: 'Copy', title: exportTitle, exportOptions: exportOptions },
      { extend: 'csv', text: 'CSV', title: exportTitle, filename: exportTitle, exportOptions: exportOptions },
      { extend: 'excel', text: 'Excel', title: exportTitle, filename: exportTitle, exportOptions: exportOptions },
      {
        extend: 'pdf',
        text: 'PDF',
        title: exportTitle,
        filename: exportTitle,
        orientation: 'landscape',
        pageSize: 'A3',
        exportOptions: exportOptions,
        customize: function (doc) {
          doc.defaultStyle.fontSize = 7;
          doc.styles.tableHeader.fontSize = 8;
        }
      },
      { extend: 'print', text: 'Print', title: exportTitle, exportOptions: exportOptions }
    ]
  });
});
</script>
