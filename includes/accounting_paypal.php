<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/accounting/access.php';
require_once __DIR__ . '/../scripts/accounting/paypal_helpers.php';
require_once __DIR__ . '/../scripts/accounting/ui_helpers.php';

if (!accounting_payout_user_can_access()) {
    echo '<div class="alert alert-danger">Na účtovnú sekciu nemáte oprávnenie.</div>';
    return;
}
$canImportAccounting = accounting_payout_user_can_access('accounting.import');
$canExportAccounting = accounting_payout_user_can_access('accounting.export');

function accountingPaypalH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function accountingPaypalInfo(string $text): string
{
    return accountingUiInfo($text);
}

if (empty($_SESSION['accounting_paypal_csrf'])) {
    $_SESSION['accounting_paypal_csrf'] = bin2hex(random_bytes(32));
}
$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}
$matchFilter = strtolower(trim((string) ($_GET['match'] ?? 'all')));
if (!in_array($matchFilter, ['all', 'matched', 'review', 'unmatched'], true)) {
    $matchFilter = 'all';
}
$from = $month . '-01';
$to = date('Y-m-d', strtotime($from . ' +1 month'));
$schemaReady = $pdo instanceof PDO && accounting_paypal_schema_ready($pdo);
$stats = [
    'transactions' => 0,
    'credits' => 0,
    'refunds' => 0,
    'matched' => 0,
    'review' => 0,
    'unmatched' => 0,
    'vycuc_blockers' => 0,
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
          SUM(balance_impact = \'Credit\') AS credits,
          SUM(transaction_type = \'Payment Refund\') AS refunds,
          SUM(export_order_number IS NOT NULL AND export_order_number <> \'\') AS matched,
          SUM(export_order_number IS NOT NULL AND export_order_number <> \'\' AND match_confidence < 90) AS review,
          SUM((export_order_number IS NULL OR export_order_number = \'\') AND transaction_type <> \'User Initiated Withdrawal\') AS unmatched,
          SUM(balance_impact = \'Credit\' AND (
              export_order_number IS NULL OR export_order_number = \'\' OR match_confidence < 90
          )) AS vycuc_blockers,
          COALESCE(SUM(CASE WHEN balance_impact = \'Credit\' THEN gross_amount ELSE 0 END), 0) AS gross,
          COALESCE(SUM(CASE WHEN balance_impact = \'Credit\' THEN fee_amount ELSE 0 END), 0) AS fees,
          COALESCE(SUM(CASE WHEN balance_impact = \'Credit\' THEN net_amount ELSE 0 END), 0) AS net
        FROM accounting_paypal_transactions
        WHERE transaction_date >= ? AND transaction_date < ?
          AND balance_impact IS NOT NULL AND balance_impact <> \'\'
    ');
    $statsStmt->execute([$from, $to]);
    $stats = array_merge($stats, $statsStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $where = ['transaction_date >= ?', 'transaction_date < ?', 'balance_impact IS NOT NULL', 'balance_impact <> \'\''];
    $params = [$from, $to];
    if ($matchFilter === 'matched') {
        $where[] = 'export_order_number IS NOT NULL AND export_order_number <> \'\' AND match_confidence >= 90';
    } elseif ($matchFilter === 'review') {
        $where[] = 'export_order_number IS NOT NULL AND export_order_number <> \'\' AND match_confidence < 90';
    } elseif ($matchFilter === 'unmatched') {
        $where[] = '(export_order_number IS NULL OR export_order_number = \'\')';
    }
    $list = $pdo->prepare('
        SELECT id, transaction_date, transaction_time, payer_name, payer_email, transaction_type,
               currency, gross_amount, fee_amount, net_amount, transaction_id, reference_transaction_id,
               raw_invoice_number, item_title, subject_text, note_text, balance_impact,
               matched_order_id, matched_custom_order_id, matched_custom_code, export_order_number,
               match_method, match_confidence, manual_override
        FROM accounting_paypal_transactions
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY transaction_date DESC, transaction_time DESC, id DESC
    ');
    $list->execute($params);
    $rows = $list->fetchAll(PDO::FETCH_ASSOC);
    $imports = $pdo->query('
        SELECT original_filename, source_row_count, imported_row_count, duplicate_row_count,
               matched_transaction_count, review_transaction_count, imported_at
        FROM accounting_paypal_imports
        ORDER BY imported_at DESC, id DESC
        LIMIT 15
    ')->fetchAll(PDO::FETCH_ASSOC);
}

$baseUrl = 'index.php?page=accounting_paypal&month=' . rawurlencode($month);
$rawExportReady = (int) $stats['unmatched'] === 0 && (int) $stats['review'] === 0;
?>
<style>
  .paypal-accounting .table td, .paypal-accounting .table th { vertical-align:top; }
  .paypal-accounting .table thead th { white-space:nowrap; }
  .paypal-accounting .form-control { background:#17212b; border-color:#4a5a68; color:#fff; }
  .paypal-accounting .clue { max-width:360px; white-space:normal; font-size:.82rem; color:#c7d1da; }
  .paypal-accounting .money { font-variant-numeric:tabular-nums; white-space:nowrap; }
  .paypal-accounting .btn[disabled] { cursor:not-allowed; }
  .accounting-page-header { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; min-height:52px; margin-bottom:1rem; }
  .accounting-page-heading { min-width:0; }
  .accounting-page-heading h1 { line-height:1.15; }
  .accounting-page-subtitle { min-height:1.25em; line-height:1.25; }
  .accounting-page-actions { display:flex; flex:0 0 auto; align-items:flex-start; gap:.5rem; margin-left:1rem; }
  .accounting-upload-zone { border:2px dashed #5f6b78; border-radius:.35rem; padding:1.5rem; text-align:center; cursor:pointer; transition:border-color .15s ease,background-color .15s ease; }
  .accounting-upload-zone:hover, .accounting-upload-zone.is-dragover { border-color:#17a2b8; background-color:rgba(23,162,184,.12); }
  .accounting-muted { color:#adb5bd; }
  .accounting-overview-row { align-items:stretch; }
  .accounting-overview-column { display:flex; flex-direction:column; }
  .accounting-overview-column > .card { flex:1 1 auto; }
  .accounting-summary-column { display:flex; flex-direction:column; height:100%; }
  .accounting-stats-row { flex:1 1 auto; }
  .accounting-stats-row > div { display:flex; }
  .accounting-stat-card { --accounting-accent:#17a2b8; width:100%; min-height:104px; margin-bottom:1rem; overflow:hidden; color:#f8f9fa!important; background:#414950!important; border-top:3px solid var(--accounting-accent); box-shadow:0 1px 3px rgba(0,0,0,.24); text-decoration:none!important; transition:background-color .15s ease,box-shadow .15s ease,transform .15s ease; }
  .accounting-stat-card:hover { color:#fff!important; background:#4a535b!important; transform:translateY(-1px); }
  .accounting-stat-card:focus { color:#fff!important; outline:2px solid var(--accounting-accent); outline-offset:2px; }
  .accounting-stat-card.is-active { box-shadow:inset 0 0 0 2px var(--accounting-accent),0 2px 6px rgba(0,0,0,.3); }
  .accounting-stat-card .inner { padding:13px 12px; }
  .accounting-stat-card .inner h3 { margin-bottom:.2rem; font-size:1.75rem; line-height:1.05; }
  .accounting-stat-card .inner p { margin:0; color:#e9ecef; white-space:nowrap; }
  .accounting-stat-card .icon { top:9px; right:12px; color:var(--accounting-accent); opacity:.32; }
  .accounting-stat-card .icon > i { font-size:58px; }
  .paypal-stat-all { --accounting-accent:#20c6d8; }
  .paypal-stat-matched { --accounting-accent:#20c997; }
  .paypal-stat-review { --accounting-accent:#ffc107; }
  .paypal-stat-unmatched { --accounting-accent:#dc3545; }
  .accounting-totals-card { flex:0 0 auto; }
  .paypal-secondary-actions { display:flex; align-items:center; justify-content:flex-end; flex-wrap:wrap; gap:.5rem; }
  #accountingPaypalTable_wrapper .accounting-table-toolbar { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.65rem .75rem; }
  #accountingPaypalTable_wrapper .accounting-table-toolbar .dt-buttons, #accountingPaypalTable_wrapper .accounting-table-toolbar .dataTables_filter { float:none; margin:0; }
  #accountingPaypalTable_wrapper .accounting-table-toolbar .dataTables_filter label { display:flex; align-items:center; gap:.45rem; margin:0; white-space:nowrap; }
  #accountingPaypalTable_wrapper .accounting-table-toolbar .dataTables_filter input { margin-left:0; }
  @media(max-width:991.98px){.accounting-summary-column{height:auto}}
  @media(max-width:767.98px){.accounting-page-header{flex-direction:column;min-height:0;gap:.75rem}.accounting-page-actions{flex-wrap:wrap;margin-left:0}.paypal-secondary-actions{justify-content:flex-start}#accountingPaypalTable_wrapper .accounting-table-toolbar{align-items:stretch;flex-direction:column}}
</style>
<div class="container-fluid paypal-accounting">
  <header class="accounting-page-header">
    <div class="accounting-page-heading">
      <h1 class="h3 mb-1">PayPal platby<?= accountingPaypalInfo('Denný import prekrývajúcich sa PayPal CSV, automatické párovanie platieb a export podkladov do OMEGY.') ?></h1>
      <div class="accounting-page-subtitle text-muted">Pôvodné CSV zostáva nezmenené. CO sa používa na dohľadanie leadu, export vždy použije aktuálne SO.</div>
    </div>
    <div class="accounting-page-actions">
      <div class="btn-group" role="group" aria-label="Účtovné sekcie">
        <a class="btn btn-outline-secondary" href="?page=accounting_payouts">eBay payouty</a>
        <a class="btn btn-success active" href="?page=accounting_paypal" aria-current="page">PayPal platby</a>
        <a class="btn btn-outline-secondary" href="?page=accounting_omega">OMEGA faktúry</a>
        <a class="btn btn-outline-secondary" href="?page=accounting_omega_export">OMEGA TXT export</a>
      </div>
      <?= accountingUiHelpButton('paypal') ?>
      <?php if ($schemaReady && $canExportAccounting): ?>
        <a class="btn btn-outline-success" href="scripts/accounting/export_paypal_vycuc.php?month=<?= rawurlencode($month) ?>"><i class="fas fa-file-export mr-1"></i> Vycuc PayPal<?= accountingPaypalInfo('Vytvorí OMEGA výcuc iba z potvrdených prijatých platieb. Nespárované položky sa automaticky vynechajú.') ?></a>
      <?php endif; ?>
    </div>
  </header>

  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= accountingPaypalH($_GET['error']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['existing'])): ?><div class="alert alert-info">Tento PayPal súbor už bol importovaný.</div><?php endif; ?>
  <?php if (isset($_GET['imported'])): ?><div class="alert alert-success">Importovaných <?= (int) $_GET['imported'] ?> riadkov, duplicít <?= (int) ($_GET['duplicates'] ?? 0) ?>.<?php if ((int) ($_GET['payments_added'] ?? 0) > 0): ?> Automaticky pridaných platieb: <?= (int) $_GET['payments_added'] ?>.<?php endif; ?><?php if ((int) ($_GET['statuses_updated'] ?? 0) > 0): ?> Stavov zmenených z Lead na Deposit paid: <?= (int) $_GET['statuses_updated'] ?>.<?php endif; ?></div><?php endif; ?>
  <?php if (!empty($_GET['refreshed'])): ?><div class="alert alert-success">Kontrola dokončená: spárovaných <?= (int) ($_GET['matched'] ?? 0) ?>, zostáva nespárovaných <?= (int) ($_GET['unmatched'] ?? 0) ?>. Nové platby: <?= (int) ($_GET['payments_added'] ?? 0) ?>, doplnené PayPal ID k existujúcej platbe: <?= (int) ($_GET['payments_linked'] ?? 0) ?>, zmenené stavy Lead → Deposit paid: <?= (int) ($_GET['statuses_updated'] ?? 0) ?>.<?php if ((int) ($_GET['payment_conflicts'] ?? 0) > 0): ?> <strong>Nejednoznačné platby na ručnú kontrolu: <?= (int) $_GET['payment_conflicts'] ?>.</strong><?php endif; ?></div><?php endif; ?>
  <?php if (!empty($_GET['saved'])): ?><div class="alert alert-success">Manuálna exportná referencia bola uložená.</div><?php endif; ?>

  <?php if (!$schemaReady): ?>
    <div class="alert alert-warning">Spustite migráciu <code>db/accounting_paypal.sql</code>. Potom bude dostupný import, párovanie a export.</div>
  <?php else: ?>
    <div class="row accounting-overview-row">
      <div class="col-lg-4 accounting-overview-column">
        <div class="card card-outline card-info">
          <div class="card-header"><h3 class="card-title">Import pôvodného PayPal CSV<?= accountingPaypalInfo('Nahrajte neupravený PP.CSV priamo z PayPalu. Môže obsahovať posledné dva týždne; už uložené transakcie systém bezpečne preskočí.') ?></h3></div>
          <div class="card-body">
            <?php if ($canImportAccounting): ?>
              <form method="post" action="scripts/accounting/import_paypal.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= accountingPaypalH($_SESSION['accounting_paypal_csrf']) ?>">
                <label class="accounting-upload-zone d-block" id="paypalDropZone" for="paypal-file">
                  <i class="fas fa-cloud-upload-alt fa-2x text-info mb-2"></i><br>
                  Pretiahnite sem alebo vyberte pôvodný PayPal CSV<br>
                  <small class="accounting-muted">Denný súbor môže obsahovať posledné dva týždne. Duplicity sa preskočia.</small>
                </label>
                <input class="form-control-file mt-2" id="paypal-file" type="file" name="paypal_file" accept=".csv,text/csv" required>
                <div class="small accounting-muted mt-2" id="paypalFileSelection" aria-live="polite">Nie je vybraný žiadny súbor.</div>
                <button class="btn btn-info btn-block mt-3" type="submit"><i class="fas fa-upload mr-1"></i> Importovať PayPal CSV</button>
              </form>
            <?php else: ?>
              <div class="alert alert-secondary mb-0"><i class="fas fa-lock mr-1"></i> Máte prístup iba na prezeranie.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-8 accounting-overview-column">
        <div class="accounting-summary-column">
          <?php
          $cards = [
              ['all', 'Transakcie', (int) $stats['transactions'], 'fa-exchange-alt', 'paypal-stat-all', 'Skutočné finančné PayPal pohyby. Shopping Cart Item detail sa nepočíta ako ďalšia platba.'],
              ['matched', 'Spárované', max(0, (int) $stats['matched'] - (int) $stats['review']), 'fa-link', 'paypal-stat-matched', 'Platby s jednoznačnou referenciou alebo presným Transaction ID. Nevyžadujú ručný zásah.'],
              ['review', 'Na potvrdenie', (int) $stats['review'], 'fa-user-check', 'paypal-stat-review', 'Systém našiel pravdepodobnú objednávku podľa identity. Skontrolujte SO a uložte ho.'],
              ['unmatched', 'Nespárované', (int) $stats['unmatched'], 'fa-unlink', 'paypal-stat-unmatched', 'Chýba finálna referencia. Platbu treba dohľadať alebo počkať na pridelenie SO.'],
          ];
          ?>
          <div class="row accounting-stats-row">
            <?php foreach ($cards as [$filter, $label, $value, $icon, $style, $help]): ?>
              <div class="col-6 col-md-3">
                <a class="small-box accounting-stat-card <?= $style ?> <?= $matchFilter === $filter ? 'is-active' : '' ?>" href="<?= accountingPaypalH($baseUrl . '&match=' . $filter) ?>" aria-label="Zobraziť <?= accountingPaypalH($label) ?>">
                  <div class="inner"><h3><?= $value ?></h3><p><?= accountingPaypalH($label) ?><?= accountingPaypalInfo($help) ?></p></div>
                  <div class="icon"><i class="fas <?= $icon ?>"></i></div>
                </a>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="card accounting-totals-card">
            <div class="card-body py-3">
              <div class="row text-center">
                <div class="col-md-4"><div class="accounting-muted">Hrubá suma prijatých platieb</div><div class="h4 money mb-0"><?= number_format((float) $stats['gross'], 2, ',', ' ') ?> €</div></div>
                <div class="col-md-4"><div class="accounting-muted">PayPal poplatky</div><div class="h4 money text-warning mb-0"><?= number_format((float) $stats['fees'], 2, ',', ' ') ?> €</div></div>
                <div class="col-md-4"><div class="accounting-muted">Netto prijaté platby</div><div class="h4 money mb-0"><?= number_format((float) $stats['net'], 2, ',', ' ') ?> €</div></div>
              </div>
              <div class="d-flex flex-wrap align-items-center justify-content-between mt-3" style="gap:.5rem">
                <small class="<?= (int) $stats['vycuc_blockers'] > 0 ? 'text-warning' : 'accounting-muted' ?>">Prijaté platby: <?= (int) $stats['credits'] ?>, refundácie: <?= (int) $stats['refunds'] ?>. <?= (int) $stats['vycuc_blockers'] > 0 ? (int) $stats['vycuc_blockers'] . ' nespárované alebo nepotvrdené platby sa vo výcucu vynechajú.' : 'Všetky prijaté platby sú pripravené.' ?></small>
                <div class="paypal-secondary-actions">
                  <?php if ($canImportAccounting): ?>
                    <form method="post" action="scripts/accounting/refresh_paypal_matches.php" class="mb-0">
                      <input type="hidden" name="csrf_token" value="<?= accountingPaypalH($_SESSION['accounting_paypal_csrf']) ?>"><input type="hidden" name="month" value="<?= accountingPaypalH($month) ?>"><input type="hidden" name="match" value="<?= accountingPaypalH($matchFilter) ?>">
                      <button class="btn btn-sm btn-outline-info"><i class="fas fa-search mr-1"></i> Vykonať kontrolu<?= accountingPaypalInfo('Znovu vyhľadá zhody podľa aktuálnych SO a CO. Pri jednoznačnej Custom Order platbe doplní chýbajúci záznam bez duplicity; iba stav Lead zmení na Deposit paid.') ?></button>
                    </form>
                  <?php endif; ?>
                  <?php if ($canExportAccounting): ?>
                    <?php if ($rawExportReady): ?>
                      <a class="btn btn-sm btn-outline-primary" href="scripts/accounting/export_paypal.php?month=<?= rawurlencode($month) ?>"><i class="fas fa-file-csv mr-1"></i> Export PayPal CSV<?= accountingPaypalInfo('Auditná kópia pôvodného PayPal exportu. Zachová pôvodné stĺpce a doplní Invoice Number.') ?></a>
                    <?php else: ?>
                      <button class="btn btn-sm btn-outline-secondary" disabled><i class="fas fa-lock mr-1"></i> Export PayPal CSV</button>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card card-outline card-primary">
      <div class="card-header">
        <form class="form-inline" id="accountingPaypalFilters" method="get">
          <input type="hidden" name="page" value="accounting_paypal">
          <label class="mr-2" for="paypal-month">Mesiac<?= accountingPaypalInfo('Určuje obdobie zobrazenej tabuľky aj oboch PayPal exportov.') ?></label>
          <input id="paypal-month" type="month" name="month" value="<?= accountingPaypalH($month) ?>" class="form-control form-control-sm mr-3 paypal-auto-filter">
          <select class="form-control form-control-sm mr-3 paypal-auto-filter" name="match">
            <option value="all" <?= $matchFilter === 'all' ? 'selected' : '' ?>>Všetky stavy</option>
            <option value="matched" <?= $matchFilter === 'matched' ? 'selected' : '' ?>>Spárované</option>
            <option value="review" <?= $matchFilter === 'review' ? 'selected' : '' ?>>Na potvrdenie</option>
            <option value="unmatched" <?= $matchFilter === 'unmatched' ? 'selected' : '' ?>>Nespárované</option>
          </select>
          <span class="accounting-muted"><?= count($rows) ?> riadkov</span>
          <noscript><button class="btn btn-sm btn-primary ml-2" type="submit">Filtrovať</button></noscript>
        </form>
      </div>
      <div class="card-body table-responsive p-0">
        <table class="table table-sm table-hover table-striped mb-0" id="accountingPaypalTable" data-export-title="PayPal platby <?= accountingPaypalH($month) ?>">
          <thead><tr>
            <th>Dátum<?= accountingPaypalInfo('Dátum a čas finančnej PayPal transakcie.') ?></th>
            <th>Typ<?= accountingPaypalInfo('Credit je prijatá platba. Payment Refund je vratka. Shopping Cart detail sa v tejto tabuľke nezobrazuje.') ?></th>
            <th>Zákazník<?= accountingPaypalInfo('Meno a e-mail z PayPalu. Nemusia sa zhodovať so zákazníkom v objednávke, preto sa používajú iba ako pomocná stopa.') ?></th>
            <th class="text-right">Hrubá suma<?= accountingPaypalInfo('Hrubá prijatá suma pred odpočítaním PayPal poplatku.') ?></th>
            <th>PayPal ID<?= accountingPaypalInfo('Jedinečný Transaction ID. Je to najsilnejší spôsob párovania, ak je uložený v Custom Orders.') ?></th>
            <th>Stopy v PayPal<?= accountingPaypalInfo('Invoice Number, Item Title, Subject a Note z pôvodného CSV. Systém v nich hľadá SO, CO a ďalšie objednávkové čísla.') ?></th>
            <th>CO<?= accountingPaypalInfo('Interný lead kód použitý na dohľadanie Custom Order. CO sa nikdy nepoužije ako finálna referencia vo Vycuc PayPal.') ?></th>
            <th>Exportná referencia<?= accountingPaypalInfo('Finálne SO alebo platná e-shop/eBay/SK/SC referencia. Pri žltom návrhu číslo skontrolujte a tlačidlom uložiť ho potvrďte.') ?></th>
            <th>Zhoda<?= accountingPaypalInfo('Metóda a percento istoty. Zelené zhody sú automatické, žlté treba potvrdiť a červené treba dohľadať alebo počkať na SO.') ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $row):
              $confidence = (int) $row['match_confidence'];
              $hasExport = trim((string) $row['export_order_number']) !== '';
              $badge = !$hasExport ? 'danger' : ($confidence < 90 ? 'warning' : 'success');
              $clues = array_values(array_unique(array_filter([
                  trim((string) $row['raw_invoice_number']), trim((string) $row['item_title']),
                  trim((string) $row['subject_text']), trim((string) $row['note_text']),
              ])));
          ?>
            <tr>
              <td class="text-nowrap"><?= accountingPaypalH($row['transaction_date'] ? date('d.m.Y', strtotime((string) $row['transaction_date'])) : '-') ?><br><small class="text-muted"><?= accountingPaypalH($row['transaction_time'] ?? '') ?></small></td>
              <td><span class="badge badge-<?= $row['balance_impact'] === 'Credit' ? 'success' : 'warning' ?>"><?= accountingPaypalH($row['transaction_type']) ?></span></td>
              <td><?= accountingPaypalH($row['payer_name'] ?: '-') ?><br><small class="text-muted"><?= accountingPaypalH($row['payer_email'] ?: '') ?></small></td>
              <td class="text-right money"><?= $row['gross_amount'] !== null ? number_format((float) $row['gross_amount'], 2, ',', ' ') . ' ' . accountingPaypalH($row['currency']) : '-' ?></td>
              <td><code><?= accountingPaypalH($row['transaction_id'] ?: '-') ?></code><?php if (!empty($row['reference_transaction_id'])): ?><br><small>ref: <?= accountingPaypalH($row['reference_transaction_id']) ?></small><?php endif; ?></td>
              <td class="clue"><?= $clues ? accountingPaypalH(implode(' | ', $clues)) : '-' ?></td>
              <td><?= accountingPaypalH($row['matched_custom_code'] ?: '-') ?></td>
              <td>
                <?php if ($canImportAccounting): ?>
                  <form method="post" action="scripts/accounting/update_paypal_match.php" class="form-inline flex-nowrap">
                    <input type="hidden" name="csrf_token" value="<?= accountingPaypalH($_SESSION['accounting_paypal_csrf']) ?>"><input type="hidden" name="transaction_row_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="month" value="<?= accountingPaypalH($month) ?>"><input type="hidden" name="match" value="<?= accountingPaypalH($matchFilter) ?>">
                    <input class="form-control form-control-sm mr-1" style="width:125px" name="export_order_number" value="<?= accountingPaypalH($row['export_order_number'] ?? '') ?>" placeholder="SO / e-shop / eBay">
                    <button class="btn btn-outline-light btn-sm" title="Uložiť"><i class="fas fa-save"></i></button>
                  </form>
                <?php else: ?><b><?= accountingPaypalH($row['export_order_number'] ?: '-') ?></b><?php endif; ?>
              </td>
              <td><span class="badge badge-<?= $badge ?>"><?= accountingPaypalH($row['match_method'] ?: 'NESPÁROVANÉ') ?></span><br><small><?= $confidence ?> %<?= !empty($row['manual_override']) ? ' · ručne' : '' ?></small></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card collapsed-card">
      <div class="card-header"><h3 class="card-title">Posledné importy<?= accountingPaypalInfo('História nahratých CSV. Duplicity sú očakávané pri dennom exporte za posledné dva týždne a neznamenajú chybu.') ?></h3><div class="card-tools"><button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-plus"></i></button></div></div>
      <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Čas</th><th>Súbor</th><th>Riadky</th><th>Import</th><th>Duplicity</th><th>Spárované</th><th>Kontrola</th></tr></thead><tbody>
      <?php foreach ($imports as $import): ?><tr><td><?= accountingPaypalH(date('d.m.Y H:i', strtotime((string) $import['imported_at']))) ?></td><td><?= accountingPaypalH($import['original_filename']) ?></td><td><?= (int) $import['source_row_count'] ?></td><td><?= (int) $import['imported_row_count'] ?></td><td><?= (int) $import['duplicate_row_count'] ?></td><td><?= (int) $import['matched_transaction_count'] ?></td><td><?= (int) $import['review_transaction_count'] ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
  <?php endif; ?>
</div>
<script>
(function () {
  const dropZone = document.getElementById('paypalDropZone');
  const fileInput = document.getElementById('paypal-file');
  const selection = document.getElementById('paypalFileSelection');
  if (dropZone && fileInput && selection) {
    const showSelection = function () {
      const file = fileInput.files && fileInput.files[0];
      selection.classList.remove('text-danger');
      selection.textContent = file ? 'Vybraný: ' + file.name : 'Nie je vybraný žiadny súbor.';
    };
    ['dragenter', 'dragover'].forEach(function (eventName) {
      dropZone.addEventListener(eventName, function (event) {
        event.preventDefault();
        dropZone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function (eventName) {
      dropZone.addEventListener(eventName, function (event) {
        event.preventDefault();
        dropZone.classList.remove('is-dragover');
      });
    });
    dropZone.addEventListener('drop', function (event) {
      const files = event.dataTransfer && event.dataTransfer.files;
      if (!files || !files.length) return;
      if (!String(files[0].name || '').toLowerCase().endsWith('.csv')) {
        fileInput.value = '';
        selection.classList.add('text-danger');
        selection.textContent = 'Povolený je iba pôvodný PayPal CSV súbor.';
        return;
      }
      try { fileInput.files = files; showSelection(); } catch (error) {
        selection.classList.add('text-danger');
        selection.textContent = 'Súbor vyberte kliknutím do importnej plochy.';
      }
    });
    fileInput.addEventListener('change', showSelection);
  }

  const filters = document.getElementById('accountingPaypalFilters');
  if (filters) {
    filters.querySelectorAll('.paypal-auto-filter').forEach(function (field) {
      field.addEventListener('change', function () {
        if (typeof filters.requestSubmit === 'function') filters.requestSubmit(); else filters.submit();
      });
    });
  }
}());

window.addEventListener('load', function () {
  const $ = window.jQuery;
  if (!$ || !$.fn.DataTable || !document.getElementById('accountingPaypalTable')) return;
  if ($.fn.DataTable.isDataTable('#accountingPaypalTable')) return;
  const table = $('#accountingPaypalTable');
  const title = table.data('export-title') || 'PayPal platby';
  const exportOptions = { modifier:{search:'applied',order:'applied',page:'all'}, columns:':not(.no-export)' };
  table.DataTable({
    responsive:false, searching:true, ordering:true, order:[], lengthChange:true,
    autoWidth:false, pageLength:100, info:true,
    dom:'<"accounting-table-toolbar"Bf>rtip',
    language:{emptyTable:'Pre zvolený filter nie sú žiadne PayPal transakcie.'},
    buttons:[
      {extend:'copy',text:'Kopírovať',title:title,exportOptions:exportOptions},
      {extend:'csv',text:'CSV',title:title,filename:title,exportOptions:exportOptions},
      {extend:'excel',text:'Excel',title:title,filename:title,exportOptions:exportOptions},
      {extend:'print',text:'Tlačiť',title:title,exportOptions:exportOptions}
    ]
  });
});
</script>
<?= accountingUiHelpModal('paypal') ?>
<?= accountingUiAssets() ?>
