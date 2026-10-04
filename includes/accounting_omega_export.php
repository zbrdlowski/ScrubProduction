<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/accounting/access.php';
require_once __DIR__ . '/../scripts/accounting/omega_export_helpers.php';
require_once __DIR__ . '/../scripts/accounting/ui_helpers.php';

if (!accounting_payout_user_can_access()) {
    echo '<div class="alert alert-danger">Na účtovnú sekciu nemáte oprávnenie.</div>';
    return;
}
$canExportAccounting = accounting_payout_user_can_access('accounting.export');

function omegaExportH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['accounting_payout_csrf'])) {
    $_SESSION['accounting_payout_csrf'] = bin2hex(random_bytes(32));
}

$today = date('Y-m-d');
$from = trim((string) ($_GET['import_from'] ?? $today));
$to = trim((string) ($_GET['import_to'] ?? $today));
$processingDate = trim((string) ($_GET['processing_date'] ?? $today));
foreach (['from' => &$from, 'to' => &$to, 'processingDate' => &$processingDate] as &$date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = $today;
    }
}
unset($date);

$schemaReady = $pdo instanceof PDO && omega_export_schema_ready($pdo);
$candidates = ['ready' => [], 'waiting' => [], 'blocked' => []];
$batches = [];
$selectedBatch = null;
$selectedItems = [];
$currentCustomerNumber = 2602995;
$previewError = '';

if ($schemaReady) {
    try {
        $candidates = omega_export_collect_candidates($pdo, $from, $to, $processingDate);
        $currentCustomerNumber = max(2602995, (int) $pdo->query('SELECT current_customer_number FROM accounting_omega_export_settings WHERE id = 1')->fetchColumn());
        $batches = $pdo->query('
            SELECT b.*,
                   SUM(i.source_code = \'EBAY\') AS ebay_count,
                   SUM(i.source_code = \'SHOPTET\') AS shoptet_count,
                   SUM(i.source_code = \'CUSTOM\') AS custom_count
            FROM accounting_omega_export_batches b
            LEFT JOIN accounting_omega_export_items i ON i.batch_id = b.id
            GROUP BY b.id
            ORDER BY b.id DESC
            LIMIT 20
        ')->fetchAll(PDO::FETCH_ASSOC);
        $batchId = (int) ($_GET['batch'] ?? 0);
        if ($batchId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM accounting_omega_export_batches WHERE id = ?');
            $stmt->execute([$batchId]);
            $selectedBatch = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($selectedBatch) {
                $stmt = $pdo->prepare('
                    SELECT id, order_id, source_code, readiness_basis, partner_code,
                           exchange_rate, total_eur, payload_json
                    FROM accounting_omega_export_items
                    WHERE batch_id = ? ORDER BY id
                ');
                $stmt->execute([$batchId]);
                $selectedItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) {
        $previewError = $e->getMessage();
    }
}
$readinessLabels = [
    'ORDER_IMPORT' => 'Import objednávky',
    'PAYOUT_IMPORT' => 'Import payoutu',
    'CUSTOM_NOT_EXPORTED' => 'Doteraz neexportovaná Custom',
];
?>

<style>
  .omega-export-muted { color: #adb5bd; }
  .omega-export-header { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; min-height:52px; margin-bottom:1rem; }
  .omega-export-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
  .omega-export-stat { min-height:92px; border-top:3px solid var(--accent); background:#414950; color:#fff; }
  .omega-export-stat .card-body { padding:.8rem; }
  .omega-export-stat h3 { margin:0; font-size:1.65rem; }
  .omega-export-ready { --accent:#20c997; }
  .omega-export-waiting { --accent:#ffc107; }
  .omega-export-custom { --accent:#17a2b8; }
  .omega-export-blocked { --accent:#dc3545; }
  .omega-export-table th { white-space:nowrap; }
  @media(max-width:767.98px){.omega-export-header{flex-direction:column}.omega-export-actions{width:100%}}
</style>

<div class="container-fluid">
  <header class="omega-export-header">
    <div>
      <h1 class="h3 mb-1">OMEGA TXT exporty</h1>
      <div class="omega-export-muted">Zákazníci T04 a došlé objednávky T01 vo Windows-1250</div>
    </div>
    <div class="omega-export-actions btn-group" role="group" aria-label="Účtovné sekcie">
      <a class="btn btn-outline-secondary" href="?page=accounting_payouts">eBay payouts</a>
      <a class="btn btn-outline-secondary" href="?page=accounting_paypal">PayPal platby</a>
      <a class="btn btn-outline-secondary" href="?page=accounting_omega">OMEGA faktúry</a>
      <a class="btn btn-success active" href="?page=accounting_omega_export" aria-current="page">OMEGA TXT export</a>
      <?= accountingUiHelpButton('omega_export') ?>
    </div>
  </header>

  <?php if (!$schemaReady): ?>
    <div class="alert alert-warning">Najprv spustite migráciu <code>db/accounting_omega_exports.sql</code>.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= omegaExportH($_GET['error']) ?></div><?php endif; ?>
  <?php if ($previewError !== ''): ?><div class="alert alert-danger"><?= omegaExportH($previewError) ?></div><?php endif; ?>
  <?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success">Balík bol vytvorený pre <b><?= (int) $_GET['created'] ?></b> objednávok. Teraz stiahnite oba TXT súbory.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['seed_updated'])): ?>
    <div class="alert alert-success">Posledný použitý zákaznícky seed bol nastavený na <b><?= omegaExportH($_GET['seed_updated']) ?></b>.</div>
  <?php endif; ?>

  <div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title">Pripraviť denný balík<?= accountingUiInfo('Najprv skontrolujte výber objednávok. Až potom vytvorte nemenný balík a stiahnite oba TXT súbory.') ?></h3></div>
    <div class="card-body">
      <form class="form-row align-items-end" method="get" id="omegaExportFilter">
        <input type="hidden" name="page" value="accounting_omega_export">
        <div class="form-group col-md-3 mb-2"><label for="omegaImportFrom">Import objednávok od<?= accountingUiInfo('Prvý deň intervalu, v ktorom boli eBay a Shoptet objednávky importované do Darkscrubu.') ?></label><input class="form-control" id="omegaImportFrom" type="date" name="import_from" value="<?= omegaExportH($from) ?>"></div>
        <div class="form-group col-md-3 mb-2"><label for="omegaImportTo">Import objednávok do<?= accountingUiInfo('Posledný deň kontrolovaného intervalu vrátane.') ?></label><input class="form-control" id="omegaImportTo" type="date" name="import_to" value="<?= omegaExportH($to) ?>"></div>
        <div class="form-group col-md-3 mb-2"><label for="omegaProcessingDate">Deň spracovania<?= accountingUiInfo('Dátum, ku ktorému pripravujete tento balík. Custom objednávky sa vyberajú nezávisle od tohto dátumu – zahrnú sa všetky, ktoré ešte neboli exportované.') ?></label><input class="form-control" id="omegaProcessingDate" type="date" name="processing_date" value="<?= omegaExportH($processingDate) ?>"></div>
        <div class="form-group col-md-3 mb-2"><button class="btn btn-primary btn-block" type="submit"><i class="fas fa-search mr-1"></i> Skontrolovať</button></div>
      </form>
      <div class="small omega-export-muted mt-2">
        eBay a Shoptet sa vyberajú podľa dátumu importu. Staršie cudzo-menové eBay objednávky sa doplnia, keď bol payout importovaný v zvolenom období.
        Custom zahŕňa všetky objednávky, ktoré ešte neboli zaradené do žiadneho nemenného balíka.
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-6 col-xl-3"><div class="card omega-export-stat omega-export-ready"><div class="card-body"><h3><?= count($candidates['ready']) ?></h3><div>Pripravené objednávky<?= accountingUiInfo('Majú potrebné údaje a po zaškrtnutí môžu ísť do balíka.') ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card omega-export-stat omega-export-waiting"><div class="card-body"><h3><?= count($candidates['waiting']) ?></h3><div>Čakajú na payout<?= accountingUiInfo('Cudzo-menové eBay objednávky bez importovaného payoutu a kurzu.') ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card omega-export-stat omega-export-custom"><div class="card-body"><h3><?= count(array_filter($candidates['ready'], static function (array $r): bool { return $r['source_code'] === 'CUSTOM'; })) ?></h3><div>Neexportované Custom<?= accountingUiInfo('Všetky Custom objednávky, ktoré ešte neboli zaradené do žiadneho nemenného balíka, bez obmedzenia na konkrétny deň.') ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card omega-export-stat omega-export-blocked"><div class="card-body"><h3><?= count($candidates['blocked']) ?></h3><div>Blokované chybou dát<?= accountingUiInfo('Tieto objednávky nemožno exportovať, kým sa neopraví uvedený dôvod.') ?></div></div></div></div>
  </div>

  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap" style="gap:.75rem">
      <?php if ($canExportAccounting): ?>
      <form method="post" action="scripts/accounting/update_omega_export_seed.php" class="form-inline mb-0">
        <input type="hidden" name="csrf_token" value="<?= omegaExportH($_SESSION['accounting_payout_csrf']) ?>">
        <label class="mr-2" for="omegaCustomerSeed"><b>Posledný použitý seed</b><?= accountingUiInfo('Posledný pridelený kód zákazníka. Bežne ho nemeňte; ďalší zákazník dostane nasledujúce číslo.') ?></label>
        <div class="input-group input-group-sm">
          <input class="form-control" id="omegaCustomerSeed" type="text" name="customer_seed" value="M<?= $currentCustomerNumber ?>" pattern="M[0-9]+" maxlength="10" required style="width:130px">
          <div class="input-group-append"><button class="btn btn-outline-warning" type="submit"><i class="fas fa-save mr-1"></i> Uložiť seed</button></div>
        </div>
        <span class="small omega-export-muted ml-2">Nasledujúci: M<?= $currentCustomerNumber + 1 ?></span>
      </form>
      <form id="omegaCreatePackageForm" method="post" action="scripts/accounting/prepare_omega_export.php" class="mb-0">
        <input type="hidden" name="csrf_token" value="<?= omegaExportH($_SESSION['accounting_payout_csrf']) ?>">
        <input type="hidden" name="import_from" value="<?= omegaExportH($from) ?>">
        <input type="hidden" name="import_to" value="<?= omegaExportH($to) ?>">
        <input type="hidden" name="processing_date" value="<?= omegaExportH($processingDate) ?>">
        <input type="hidden" name="selection_submitted" value="1">
        <span class="small mr-2"><b id="omegaSelectedCount"><?= count($candidates['ready']) ?></b> vybraných</span>
        <button class="btn btn-success" id="omegaCreatePackageButton" type="submit" <?= (!$schemaReady || !$candidates['ready']) ? 'disabled' : '' ?>><i class="fas fa-box mr-1"></i> Vytvoriť nemenný balík<?= accountingUiInfo('Uloží presnú snímku zaškrtnutých objednávok. Obsah a zákaznícke kódy sa už pri ďalšom stiahnutí nezmenia.') ?></button>
      </form>
      <?php else: ?>
        <div class="alert alert-secondary mb-0"><i class="fas fa-lock mr-1"></i> Vytváranie balíkov povoľuje oprávnenie <code>accounting.export</code>.</div>
      <?php endif; ?>
    </div>
    <div class="card-body table-responsive p-0">
      <table class="table table-sm table-hover mb-0 omega-export-table">
        <thead><tr><th class="text-center" style="width:42px"><input id="omegaSelectAll" type="checkbox" checked aria-label="Označiť alebo odznačiť všetky objednávky"></th><th>Import / payout</th><th>Zdroj</th><th>Objednávka</th><th>Zákazník</th><th>Mena</th><th class="text-right">Suma EUR</th><th>Dôvod zaradenia</th></tr></thead>
        <tbody>
        <?php foreach ($candidates['ready'] as $row): ?>
          <tr>
            <td class="text-center"><input class="omega-order-selection" type="checkbox" name="order_ids[]" value="<?= (int) $row['id'] ?>" form="omegaCreatePackageForm" checked <?= $canExportAccounting ? '' : 'disabled' ?> aria-label="Zahrnúť objednávku <?= omegaExportH($row['order_number']) ?> do balíka"></td>
            <td><?= omegaExportH(date('d.m.Y H:i', strtotime((string) $row['imported_at']))) ?></td>
            <td><span class="badge badge-info"><?= omegaExportH($row['source_code']) ?></span></td>
            <td><?= omegaExportH($row['order_number']) ?></td><td><?= omegaExportH($row['customer_name']) ?></td>
            <td><?= omegaExportH($row['currency']) ?></td><td class="text-right"><?= number_format((float) $row['_total_eur'], 2, ',', ' ') ?></td>
            <td><?= omegaExportH($readinessLabels[$row['_readiness_basis']] ?? $row['_readiness_basis']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$candidates['ready']): ?><tr><td colspan="8" class="text-center text-muted py-3">Nie je pripravená žiadna nová objednávka.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($candidates['waiting'] || $candidates['blocked']): ?>
  <div class="card collapsed-card">
    <div class="card-header"><h3 class="card-title">Čakajúce a blokované objednávky</h3><div class="card-tools"><button class="btn btn-tool" type="button" data-card-widget="collapse"><i class="fas fa-plus"></i></button></div></div>
    <div class="card-body table-responsive p-0">
      <table class="table table-sm mb-0"><thead><tr><th>Objednávka</th><th>Zdroj</th><th>Mena</th><th>Dôvod</th></tr></thead><tbody>
      <?php foreach ($candidates['waiting'] as $row): ?><tr><td><?= omegaExportH($row['order_number']) ?></td><td>EBAY</td><td><?= omegaExportH($row['currency']) ?></td><td>V zvolenom období ešte nebol naimportovaný payout s kurzom.</td></tr><?php endforeach; ?>
      <?php foreach ($candidates['blocked'] as $row): ?><tr><td><?= omegaExportH($row['order_number']) ?></td><td><?= omegaExportH($row['source_code']) ?></td><td><?= omegaExportH($row['currency']) ?></td><td><?= omegaExportH($row['block_reason']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($selectedBatch): ?>
  <div class="card card-outline card-success">
    <div class="card-header"><h3 class="card-title">Balík #<?= (int) $selectedBatch['id'] ?></h3><div class="card-tools">
      <?php if ($canExportAccounting): ?>
        <a class="btn btn-sm btn-success" href="scripts/accounting/download_omega_export.php?type=partners&amp;batch=<?= (int) $selectedBatch['id'] ?>"><i class="fas fa-users mr-1"></i> Zákazníci TXT</a>
        <a class="btn btn-sm btn-primary" href="scripts/accounting/download_omega_export.php?type=invoices&amp;batch=<?= (int) $selectedBatch['id'] ?>"><i class="fas fa-file-invoice mr-1"></i> Objednávky TXT</a>
      <?php endif; ?>
    </div></div>
    <div class="card-body table-responsive p-0"><table class="table table-sm mb-0"><thead><tr><th>Zdroj</th><th>Objednávka</th><th>Zákazník</th><th>Kód</th><th class="text-right">EUR</th><th>Kurz</th></tr></thead><tbody>
    <?php foreach ($selectedItems as $item): $payload = json_decode((string) $item['payload_json'], true) ?: []; ?>
      <tr><td><?= omegaExportH($item['source_code']) ?></td><td><?= omegaExportH($payload['order_number'] ?? '') ?></td><td><?= omegaExportH($payload['customer_name'] ?? '') ?></td><td><b><?= omegaExportH($item['partner_code']) ?></b></td><td class="text-right"><?= number_format((float) $item['total_eur'], 2, ',', ' ') ?></td><td><?= omegaExportH($item['exchange_rate']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <?php endif; ?>

  <div class="card collapsed-card">
    <div class="card-header"><h3 class="card-title">História exportných balíkov<?= accountingUiInfo('Starší balík môžete otvoriť a oba jeho súbory stiahnuť znova bez zmeny obsahu.') ?></h3><div class="card-tools"><button class="btn btn-tool" type="button" data-card-widget="collapse"><i class="fas fa-plus"></i></button></div></div>
    <div class="card-body table-responsive p-0"><table class="table table-sm mb-0"><thead><tr><th>Balík</th><th>Vytvorený</th><th>Interval</th><th>eBay</th><th>Shoptet</th><th>Custom</th><th>Partneri</th><th>Objednávky</th><th></th></tr></thead><tbody>
      <?php foreach ($batches as $batch): ?><tr>
        <td>#<?= (int) $batch['id'] ?></td><td><?= omegaExportH(date('d.m.Y H:i', strtotime((string) $batch['created_at']))) ?></td><td><?= omegaExportH(date('d.m.Y', strtotime((string) $batch['import_from']))) ?> – <?= omegaExportH(date('d.m.Y', strtotime((string) $batch['import_to']))) ?></td>
        <td><?= (int) $batch['ebay_count'] ?></td><td><?= (int) $batch['shoptet_count'] ?></td><td><?= (int) $batch['custom_count'] ?></td>
        <td><?= $batch['partners_downloaded_at'] ? '<span class="badge badge-success">stiahnuté</span>' : '<span class="badge badge-secondary">nie</span>' ?></td>
        <td><?= $batch['invoices_downloaded_at'] ? '<span class="badge badge-success">stiahnuté</span>' : '<span class="badge badge-secondary">nie</span>' ?></td>
        <td><a class="btn btn-xs btn-outline-light" href="?page=accounting_omega_export&amp;batch=<?= (int) $batch['id'] ?>">Otvoriť</a></td>
      </tr><?php endforeach; ?>
      <?php if (!$batches): ?><tr><td colspan="9" class="text-center text-muted py-3">Zatiaľ nebol vytvorený žiadny balík.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
</div>

<script>
(function () {
  const selectAll = document.getElementById('omegaSelectAll');
  const selections = Array.prototype.slice.call(document.querySelectorAll('.omega-order-selection'));
  const count = document.getElementById('omegaSelectedCount');
  const createButton = document.getElementById('omegaCreatePackageButton');
  if (!selectAll || !count || !createButton) return;

  function refreshSelection() {
    const checked = selections.filter(function (checkbox) { return checkbox.checked; }).length;
    count.textContent = String(checked);
    createButton.disabled = checked === 0;
    selectAll.checked = selections.length > 0 && checked === selections.length;
    selectAll.indeterminate = checked > 0 && checked < selections.length;
  }

  selectAll.addEventListener('change', function () {
    selections.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
    refreshSelection();
  });
  selections.forEach(function (checkbox) { checkbox.addEventListener('change', refreshSelection); });
  refreshSelection();
}());
</script>
<?= accountingUiHelpModal('omega_export') ?>
<?= accountingUiAssets() ?>
