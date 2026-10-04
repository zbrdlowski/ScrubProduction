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
?>
<style>
  .paypal-accounting .card { background:#1f2933; border:1px solid #364452; color:#e8edf2; }
  .paypal-accounting .card-header { background:#273542; border-bottom-color:#3c4c5a; }
  .paypal-accounting .table { color:#e8edf2; }
  .paypal-accounting .table td, .paypal-accounting .table th { border-color:#3c4c5a; vertical-align:top; }
  .paypal-accounting .table thead th { background:#22303c; white-space:nowrap; }
  .paypal-accounting .form-control { background:#17212b; border-color:#4a5a68; color:#fff; }
  .paypal-accounting .clue { max-width:360px; white-space:normal; font-size:.82rem; color:#c7d1da; }
  .paypal-accounting .money { font-variant-numeric:tabular-nums; white-space:nowrap; }
  .paypal-accounting .stat-link { color:inherit; text-decoration:none; }
  .paypal-accounting .stat-link:hover .card { border-color:#20c997; }
  .paypal-accounting .paypal-help { color:#6ed3c1; cursor:help; font-size:.82em; }
  .paypal-accounting .btn[disabled] { cursor:not-allowed; }
  .accounting-page-header { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; min-height:52px; margin-bottom:1rem; }
  .accounting-page-heading { min-width:0; }
  .accounting-page-heading h1 { line-height:1.15; }
  .accounting-page-subtitle { min-height:1.25em; line-height:1.25; }
  .accounting-page-actions { display:flex; flex:0 0 auto; align-items:flex-start; gap:.5rem; margin-left:1rem; }
  @media(max-width:767.98px){.accounting-page-header{flex-direction:column;min-height:0;gap:.75rem}.accounting-page-actions{flex-wrap:wrap;margin-left:0}}
</style>
<div class="container-fluid paypal-accounting">
  <header class="accounting-page-header">
    <div class="accounting-page-heading">
      <h1 class="h3 mb-1">PayPal platby<?= accountingPaypalInfo('Denný import prekrývajúcich sa PayPal CSV, automatické párovanie platieb a export podkladov do OMEGY.') ?></h1>
      <div class="accounting-page-subtitle text-muted">Pôvodné CSV zostáva nezmenené. CO sa používa na dohľadanie leadu, export vždy použije aktuálne SO.</div>
      <form method="get" class="form-inline mt-3">
        <input type="hidden" name="page" value="accounting_paypal">
        <label class="mr-2" for="paypal-month">Mesiac<?= accountingPaypalInfo('Určuje obdobie zobrazených transakcií aj oboch exportov. Denný CSV môže obsahovať aj časť predchádzajúceho mesiaca.') ?></label>
        <input id="paypal-month" type="month" name="month" value="<?= accountingPaypalH($month) ?>" class="form-control form-control-sm mr-2">
        <button class="btn btn-outline-light btn-sm">Zobraziť</button>
      </form>
    </div>
    <div class="accounting-page-actions">
      <div class="btn-group" role="group" aria-label="Účtovné sekcie">
        <a class="btn btn-outline-secondary" href="?page=accounting_payouts">eBay payouty</a>
        <a class="btn btn-success active" href="?page=accounting_paypal" aria-current="page">PayPal platby</a>
        <a class="btn btn-outline-secondary" href="?page=accounting_omega">OMEGA faktúry</a>
        <a class="btn btn-outline-secondary" href="?page=accounting_omega_export">OMEGA TXT export</a>
      </div>
      <?= accountingUiHelpButton('paypal') ?>
    </div>
  </header>

  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= accountingPaypalH($_GET['error']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['existing'])): ?><div class="alert alert-info">Tento PayPal súbor už bol importovaný.</div><?php endif; ?>
  <?php if (isset($_GET['imported'])): ?><div class="alert alert-success">Importovaných <?= (int) $_GET['imported'] ?> riadkov, duplicít <?= (int) ($_GET['duplicates'] ?? 0) ?>.</div><?php endif; ?>
  <?php if (!empty($_GET['refreshed'])): ?><div class="alert alert-success">Párovanie bolo obnovené podľa aktuálnych SO čísel.</div><?php endif; ?>
  <?php if (!empty($_GET['saved'])): ?><div class="alert alert-success">Manuálna exportná referencia bola uložená.</div><?php endif; ?>

  <?php if (!$schemaReady): ?>
    <div class="alert alert-warning">Spustite migráciu <code>db/accounting_paypal.sql</code>. Potom bude dostupný import, párovanie a export.</div>
  <?php else: ?>
    <div class="row">
      <?php
      $cards = [
          ['all', 'Transakcie', (int) $stats['transactions'], 'fa-exchange-alt', 'secondary', 'Skutočné finančné PayPal pohyby. Shopping Cart Item detail sa nepočíta ako ďalšia platba.'],
          ['matched', 'Automaticky spárované', max(0, (int) $stats['matched'] - (int) $stats['review']), 'fa-link', 'success', 'Platby s jednoznačnou referenciou alebo presným Transaction ID. Nevyžadujú ručný zásah.'],
          ['review', 'Na potvrdenie', (int) $stats['review'], 'fa-user-check', 'warning', 'Systém našiel jedinú pravdepodobnú objednávku podľa identity. Skontrolujte SO a uložte ho, čím návrh potvrdíte.'],
          ['unmatched', 'Nespárované', (int) $stats['unmatched'], 'fa-unlink', 'danger', 'Chýba finálna referencia. Pri CO počkajte na pridelenie SO a potom použite Obnoviť párovanie.'],
      ];
      foreach ($cards as [$filter, $label, $value, $icon, $color, $help]): ?>
        <div class="col-6 col-lg-3">
          <a class="stat-link" href="<?= accountingPaypalH($baseUrl . '&match=' . $filter) ?>">
            <div class="card"><div class="card-body py-3 d-flex justify-content-between"><div><div class="h3 mb-0"><?= $value ?></div><div><?= accountingPaypalH($label) ?><?= accountingPaypalInfo($help) ?></div></div><i class="fas <?= $icon ?> fa-2x text-<?= $color ?>"></i></div></div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="row mb-3">
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header"><strong>Import pôvodného PayPal CSV</strong><?= accountingPaypalInfo('Nahrajte neupravený PP.CSV priamo z PayPalu. Môže obsahovať posledné dva týždne; už uložené transakcie systém bezpečne preskočí.') ?></div>
          <div class="card-body">
            <?php if ($canImportAccounting): ?>
              <form method="post" action="scripts/accounting/import_paypal.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= accountingPaypalH($_SESSION['accounting_paypal_csrf']) ?>">
                <div class="input-group">
                  <div class="custom-file"><input type="file" class="custom-file-input" id="paypal-file" name="paypal_file" accept=".csv,text/csv" required><label class="custom-file-label" for="paypal-file">PP.CSV</label></div>
                  <div class="input-group-append"><button class="btn btn-success">Importovať</button></div>
                </div>
              </form>
            <?php else: ?><span class="text-muted">Nemáte oprávnenie na import.</span><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card h-100"><div class="card-header"><strong>Súčty prijatých platieb</strong><?= accountingPaypalInfo('Súčty zahŕňajú iba hlavné Credit riadky. Shopping Cart detail, refundácie a výbery sa do týchto troch súm nezapočítajú.') ?></div><div class="card-body">
          <div>Hrubá suma: <b class="float-right money"><?= number_format((float) $stats['gross'], 2, ',', ' ') ?> €</b></div>
          <div>PayPal poplatky: <b class="float-right money text-warning"><?= number_format((float) $stats['fees'], 2, ',', ' ') ?> €</b></div>
          <div>Netto: <b class="float-right money"><?= number_format((float) $stats['net'], 2, ',', ' ') ?> €</b></div>
          <div class="mt-2 text-muted">Prijaté platby: <?= (int) $stats['credits'] ?>, refundácie: <?= (int) $stats['refunds'] ?></div>
        </div></div>
      </div>
      <div class="col-lg-3">
        <div class="card h-100"><div class="card-header"><strong>Akcie</strong><?= accountingPaypalInfo('Obnovenie znovu preverí aktuálne SO v databáze. Exporty sú dostupné až po vyriešení všetkých relevantných platieb.') ?></div><div class="card-body">
          <?php if ($canImportAccounting): ?>
            <form method="post" action="scripts/accounting/refresh_paypal_matches.php" class="mb-2">
              <input type="hidden" name="csrf_token" value="<?= accountingPaypalH($_SESSION['accounting_paypal_csrf']) ?>"><input type="hidden" name="month" value="<?= accountingPaypalH($month) ?>">
              <button class="btn btn-outline-info btn-block"><i class="fas fa-sync-alt mr-1"></i>Obnoviť párovanie</button>
              <small class="text-muted d-block mt-1 mb-2">Použite po pridelení nového SO k leadu.</small>
            </form>
          <?php endif; ?>
          <?php if ($canExportAccounting): ?>
            <?php $rawExportReady = (int) $stats['unmatched'] === 0 && (int) $stats['review'] === 0; ?>
            <?php if ($rawExportReady): ?>
              <a class="btn btn-primary btn-block" href="scripts/accounting/export_paypal.php?month=<?= rawurlencode($month) ?>"><i class="fas fa-file-csv mr-1"></i>Export PayPal CSV</a>
            <?php else: ?>
              <button class="btn btn-secondary btn-block" disabled><i class="fas fa-lock mr-1"></i>Export PayPal CSV</button>
            <?php endif; ?>
            <small class="text-muted d-block mt-1 mb-2">Pôvodný PayPal formát s doplneným Invoice Number.<?= accountingPaypalInfo('Slúži ako auditná kópia PayPal exportu. Zachová všetky pôvodné stĺpce a zmení iba Invoice Number pri spárovaných riadkoch.') ?></small>

            <a class="btn btn-success btn-block" href="scripts/accounting/export_paypal_vycuc.php?month=<?= rawurlencode($month) ?>"><i class="fas fa-file-export mr-1"></i>Vycuc PayPal</a>
            <small class="<?= (int) $stats['vycuc_blockers'] > 0 ? 'text-warning' : 'text-muted' ?> d-block mt-1">
              OMEGA formát. <?= (int) $stats['vycuc_blockers'] > 0 ? (int) $stats['vycuc_blockers'] . ' nespárované alebo nepotvrdené platby sa vynechajú.' : 'Všetky prijaté platby sú pripravené.' ?><?= accountingPaypalInfo('Vytvorí Date, Suma, Poplatok, Order Number, PayPal a IBAN partnera. Zahrnie iba potvrdené prijaté platby. Neobsahuje nespárované položky, Shopping Cart detail, refundácie ani výbery.') ?>
            </small>
          <?php endif; ?>
        </div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between"><strong>Platby a refundácie</strong><span class="text-muted"><?= count($rows) ?> riadkov</span></div>
      <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
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
                    <input type="hidden" name="csrf_token" value="<?= accountingPaypalH($_SESSION['accounting_paypal_csrf']) ?>"><input type="hidden" name="transaction_row_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="month" value="<?= accountingPaypalH($month) ?>">
                    <input class="form-control form-control-sm mr-1" style="width:125px" name="export_order_number" value="<?= accountingPaypalH($row['export_order_number'] ?? '') ?>" placeholder="SO / e-shop / eBay">
                    <button class="btn btn-outline-light btn-sm" title="Uložiť"><i class="fas fa-save"></i></button>
                  </form>
                <?php else: ?><b><?= accountingPaypalH($row['export_order_number'] ?: '-') ?></b><?php endif; ?>
              </td>
              <td><span class="badge badge-<?= $badge ?>"><?= accountingPaypalH($row['match_method'] ?: 'NESPÁROVANÉ') ?></span><br><small><?= $confidence ?> %<?= !empty($row['manual_override']) ? ' · ručne' : '' ?></small></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">Pre zvolený mesiac nie sú importované PayPal transakcie.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><strong>Posledné importy</strong><?= accountingPaypalInfo('História nahratých CSV. Duplicity sú očakávané pri dennom exporte za posledné dva týždne a neznamenajú chybu.') ?></div>
      <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Čas</th><th>Súbor</th><th>Riadky</th><th>Import</th><th>Duplicity</th><th>Spárované</th><th>Kontrola</th></tr></thead><tbody>
      <?php foreach ($imports as $import): ?><tr><td><?= accountingPaypalH(date('d.m.Y H:i', strtotime((string) $import['imported_at']))) ?></td><td><?= accountingPaypalH($import['original_filename']) ?></td><td><?= (int) $import['source_row_count'] ?></td><td><?= (int) $import['imported_row_count'] ?></td><td><?= (int) $import['duplicate_row_count'] ?></td><td><?= (int) $import['matched_transaction_count'] ?></td><td><?= (int) $import['review_transaction_count'] ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
  <?php endif; ?>
</div>
<script>
document.addEventListener('change', function (event) {
  if (event.target && event.target.id === 'paypal-file') {
    var label = event.target.nextElementSibling;
    if (label && event.target.files && event.target.files[0]) label.textContent = event.target.files[0].name;
  }
});
</script>
<?= accountingUiHelpModal('paypal') ?>
<?= accountingUiAssets() ?>
