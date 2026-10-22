<?php
declare(strict_types=1);
require_once __DIR__ . '/../scripts/accounting/access.php';
require_once __DIR__ . '/../scripts/accounting/ui_helpers.php';
require_once __DIR__ . '/../scripts/accounting/tatra_helpers.php';
if (!accounting_payout_user_can_access()) {
    echo '<div class="alert alert-danger">Na účtovnú sekciu nemáte oprávnenie.</div>';
    return;
}
$canImportAccounting = accounting_payout_user_can_access('accounting.import');
$canExportAccounting = accounting_payout_user_can_access('accounting.export');
if (empty($_SESSION['accounting_tatra_csrf'])) $_SESSION['accounting_tatra_csrf'] = bin2hex(random_bytes(32));
$month = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) $month = date('Y-m');
$typeLabels = ['ALL' => 'Všetky transakcie', 'ORDER' => 'Objednávky', 'REFUND' => 'Refundácie',
    'FEE' => 'Poplatky', 'PAYOUT' => 'Odoslané Shoptet Pay', 'TRANSFER' => 'Prijaté prevody payoutov', 'OTHER' => 'Ostatné pohyby'];
$type = (string) ($_GET['type'] ?? 'ORDER');
if (!isset($typeLabels[$type])) $type = 'ORDER';
$sourceLabels = ['all' => 'Oba zdroje', 'shoptet' => 'Shoptet Pay', 'bank' => 'Tatra banka'];
$source = (string) ($_GET['source'] ?? 'all');
if (!isset($sourceLabels[$source])) $source = 'all';
$matchLabels = ['all' => 'Všetky párovania', 'matched' => 'Spárované', 'unmatched' => 'Nespárované',
    'pending' => 'Objednávky PENDING', 'excluded' => 'Vynechané z objednávok'];
$match = (string) ($_GET['match'] ?? 'all');
if (!isset($matchLabels[$match])) $match = 'all';
$from = $month . '-01';
$to = date('Y-m-d', strtotime($from . ' +1 month'));
$schemaReady = $pdo instanceof PDO && accounting_tatra_schema_ready($pdo);
$bankReady = $schemaReady && accounting_tatra_bank_schema_ready($pdo);
$allRows = $rows = $imports = $exportRows = $totals = $receivedTotals = [];
$stats = array_fill_keys(['ORDER', 'REFUND', 'FEE', 'PAYOUT', 'TRANSFER', 'OTHER', 'unmatched', 'pending', 'excluded'], 0);
if ($schemaReady) {
    if ($source !== 'bank') {
        $stmt = $pdo->prepare('SELECT * FROM accounting_tatra_transactions
            WHERE transaction_date >= ? AND transaction_date < ?');
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['source'] = 'shoptet';
            $row['excluded'] = 0;
            $allRows[] = $row;
        }
    }
    if ($bankReady && $source !== 'shoptet') {
        $stmt = $pdo->prepare('SELECT * FROM accounting_tatra_bank_transactions
            WHERE transaction_date >= ? AND transaction_date < ?');
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['source'] = 'bank';
            $row['settlement_amount'] = $row['amount'];
            $row['settlement_currency'] = $row['currency'];
            $row['state'] = $row['type_raw'];
            $row['order_number'] = $row['variable_symbol'];
            $allRows[] = $row;
        }
    }
    usort($allRows, static function (array $a, array $b): int {
        return strcmp($b['transaction_date'], $a['transaction_date']) ?: $b['id'] <=> $a['id'];
    });
    $targets = [];
    foreach ($allRows as $row) {
        $targetKey = ($row['matched_order_id'] ?? '') . ':' . ($row['matched_custom_order_id'] ?? '');
        if (!array_key_exists($targetKey, $targets)) $targets[$targetKey] = accounting_tatra_bank_target($pdo, $row);
        $row['target'] = $targets[$targetKey];
        $target = $row['target'];
        $isMatched = $target && $target['reference'] !== '' && !preg_match('/^CO[ -]?\d/i', $target['reference']);
        $row['is_matched'] = $isMatched;
        $excluded = (bool) $row['excluded'];
        $rowType = $row['transaction_type'];
        if ($excluded) $stats['excluded']++;
        else $stats[$rowType]++;
        if ($rowType === 'ORDER' && !$excluded && !$isMatched) $stats['unmatched']++;
        if ($rowType === 'ORDER' && !$excluded && $target && $target['status'] === 'PENDING') $stats['pending']++;
        $currency = $row['settlement_currency'];
        if (!isset($totals[$currency])) $totals[$currency] = ['gross' => 0, 'fees' => 0, 'payouts' => 0, 'transfers' => 0];
        $amount = (int) round((float) $row['settlement_amount'] * 100);
        if ($rowType === 'ORDER' && !$excluded && $amount > 0
            && ($row['source'] === 'bank' || in_array($row['state'], ['APPROVED', 'BILLING', 'BILLED'], true))) $totals[$currency]['gross'] += $amount;
        if ($rowType === 'FEE') $totals[$currency]['fees'] += $amount;
        if ($rowType === 'PAYOUT') $totals[$currency]['payouts'] += $amount;
        if ($rowType === 'TRANSFER') $totals[$currency]['transfers'] += $amount;
        if ($type !== 'ALL' && $rowType !== $type) continue;
        if ($match === 'matched' && (!$isMatched || $excluded)) continue;
        if ($match === 'unmatched' && ($excluded || !in_array($rowType, ['ORDER', 'REFUND'], true) || $isMatched)) continue;
        if ($match === 'pending' && ($rowType !== 'ORDER' || $excluded || !$target || $target['status'] !== 'PENDING')) continue;
        if ($match === 'excluded' && !$excluded) continue;
        if ($target && $isMatched) {
            $key = $target['kind'] . ':' . $target['id'];
            if (!isset($receivedTotals[$key])) $receivedTotals[$key] = accounting_tatra_received_totals($pdo, $target);
            $row['received'] = $receivedTotals[$key];
        }
        if ($row['source'] === 'bank') $row['transfer_check'] = accounting_tatra_bank_transfer_check($pdo, $row);
        $rows[] = $row;
    }
    $exportRows = accounting_tatra_export_rows($pdo, $from, $to, $source);
    $imports = $pdo->query('SELECT * FROM accounting_tatra_imports ORDER BY imported_at DESC, id DESC LIMIT 15')->fetchAll(PDO::FETCH_ASSOC);
}
$cardUrl = static function (string $cardType, string $cardMatch = 'all') use ($month, $source): string {
    return 'index.php?' . http_build_query(['page' => 'accounting_tatra', 'month' => $month, 'source' => $source, 'type' => $cardType, 'match' => $cardMatch]);
};
?>
<link rel="stylesheet" href="dist/css/accounting-tatra.css">
<div class="container-fluid">
  <header class="accounting-page-header">
    <div class="accounting-page-heading">
      <h1 class="h3 mb-1">Tatra B. Payouts</h1>
      <div class="accounting-page-subtitle accounting-muted">Shoptet Pay a Tatra banka – párovanie platieb a podklady pre OMEGU</div>
    </div>
    <div class="accounting-page-actions">
      <div class="btn-group" role="group" aria-label="Účtovné sekcie">
        <a class="btn btn-outline-secondary" href="?page=accounting_payouts">eBay payouts</a>
        <a class="btn btn-outline-secondary" href="?page=accounting_paypal">PayPal platby</a>
        <a class="btn btn-success active" href="?page=accounting_tatra" aria-current="page">Tatra B. Payouts</a>
        <a class="btn btn-outline-secondary" href="?page=accounting_omega">OMEGA faktúry</a>
        <a class="btn btn-outline-secondary" href="?page=accounting_omega_export">OMEGA TXT export</a>
      </div>
      <?= accountingUiHelpButton('tatra') ?>
      <?php if ($schemaReady && $canExportAccounting): ?>
        <a class="btn btn-outline-success" href="scripts/accounting/export_tatra_vycuc.php?<?= accountingUiH(http_build_query(['month' => $month, 'source' => $source])) ?>"><i class="fas fa-file-csv mr-1"></i> Vycuc Tatra B.<?= accountingUiInfo('Výcuc za zvolený mesiac a zdroj obsahuje spárované prijaté platby v EUR. Prevody payoutov, refundácie, poplatky a ručne vynechané pohyby neexportuje.') ?></a>
      <?php endif; ?>
    </div>
  </header>
  <?php if (!$schemaReady): ?>
    <div class="alert alert-warning">Najprv spustite <code>php scripts/accounting/install_tatra.php --apply</code> a stránku obnovte.</div>
  <?php elseif (!$bankReady): ?>
    <div class="alert alert-warning">Pre výpisy priamo z banky doplňte migráciu: <code>php scripts/accounting/install_tatra.php --apply</code>. Import Shoptet Pay je pripravený.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= accountingUiH($_GET['error']) ?></div>
  <?php elseif (isset($_GET['imported'])): ?>
    <div class="alert alert-success">Importované riadky: <b><?= (int) $_GET['imported'] ?></b>, spárované: <b><?= (int) ($_GET['matched'] ?? 0) ?></b>, duplicity: <b><?= (int) ($_GET['duplicates'] ?? 0) ?></b>. Dodatočne spárované už uložené platby: <b><?= (int) ($_GET['rematched'] ?? 0) ?></b>.</div>
  <?php elseif (isset($_GET['checked'])): ?>
    <div class="alert alert-success">Kontrola dokončená. Nové zhody: <b><?= (int) $_GET['checked'] ?></b>.</div>
  <?php elseif (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Párovanie platby bolo uložené. Stav objednávky zostáva nezmenený.</div>
  <?php endif; ?>
  <div class="row accounting-overview-row">
    <div class="col-lg-4 accounting-overview-column">
      <div class="card card-outline card-info">
        <div class="card-header"><h3 class="card-title">Import CSV – Shoptet Pay / Tatra banka<?= accountingUiInfo('Formát sa rozpozná automaticky. Denné aj prekrývajúce sa exporty možno nahrať opakovane.') ?></h3></div>
        <div class="card-body">
          <?php if ($canImportAccounting): ?>
            <form method="post" action="scripts/accounting/import_tatra.php" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= accountingUiH($_SESSION['accounting_tatra_csrf']) ?>">
              <input type="hidden" name="month" value="<?= accountingUiH($month) ?>">
              <label class="accounting-upload-zone d-block" id="tatraDropZone" for="tatraFiles">
                <i class="fas fa-cloud-upload-alt fa-2x text-info mb-2"></i><br>Pretiahnite sem alebo vyberte CSV<br>
                <small class="accounting-muted">Shoptet Pay alebo výpis priamo z Tatra banky. Pôvodné súbory sa po spracovaní neuchovávajú.</small>
              </label>
              <input class="form-control-file mt-2" id="tatraFiles" type="file" name="tatra_files[]" accept=".csv,text/csv" multiple required>
              <div class="small accounting-muted mt-2" id="tatraFileSelection" aria-live="polite">Nie je vybraný žiadny súbor.</div>
              <button class="btn btn-info btn-block mt-3" type="submit" <?= $schemaReady ? '' : 'disabled' ?>><i class="fas fa-upload mr-1"></i> Importovať platby</button>
            </form>
          <?php else: ?>
            <div class="alert alert-secondary mb-0">Máte prístup iba na prezeranie.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-8 accounting-overview-column">
      <div class="accounting-summary-column">
        <div class="row accounting-stats-row">
          <?php foreach ([
              ['ORDER', 'all', 'orders', 'Objednávky', 'shopping-cart', $stats['ORDER']],
              ['ORDER', 'unmatched', 'unmatched', 'Nespárované', 'unlink', $stats['unmatched']],
              ['REFUND', 'all', 'refunds', 'Refundácie', 'undo', $stats['REFUND']],
              ['FEE', 'all', 'fees', 'Poplatky', 'receipt', $stats['FEE']],
          ] as [$cardType, $cardMatch, $class, $label, $icon, $count]): ?>
            <div class="col-6 col-md-3"><a href="<?= accountingUiH($cardUrl($cardType, $cardMatch)) ?>" class="small-box accounting-stat-card accounting-stat-<?= $class ?> <?= $type === $cardType && $match === $cardMatch ? 'is-active' : '' ?>"><div class="inner"><h3><?= (int) $count ?></h3><p><?= $label ?></p></div><div class="icon"><i class="fas fa-<?= $icon ?>"></i></div></a></div>
          <?php endforeach; ?>
        </div>
        <div class="card accounting-totals-card">
          <div class="card-body py-3">
            <?php foreach ($totals ?: ['EUR' => ['gross' => 0, 'fees' => 0, 'payouts' => 0, 'transfers' => 0]] as $currency => $total): ?>
              <div class="row text-center mb-2">
                <div class="col-md-4"><div class="accounting-muted">Prijaté priame platby</div><div class="h4 accounting-money mb-0"><?= number_format($total['gross'] / 100, 2, ',', ' ') ?> <?= accountingUiH($currency) ?></div></div>
                <div class="col-md-4"><div class="accounting-muted">Súhrnné poplatky</div><div class="h4 accounting-money text-warning mb-0"><?= number_format($total['fees'] / 100, 2, ',', ' ') ?> <?= accountingUiH($currency) ?></div></div>
                <div class="col-md-4"><div class="accounting-muted"><?= $source === 'bank' ? 'Prijaté prevody payoutov' : 'Odoslané Shoptet Pay' ?></div><div class="h4 accounting-money mb-0"><?= number_format(($source === 'bank' ? $total['transfers'] : -$total['payouts']) / 100, 2, ',', ' ') ?> <?= accountingUiH($currency) ?></div></div>
              </div>
            <?php endforeach; ?>
            <div class="d-flex align-items-center justify-content-between flex-wrap mt-3">
              <span class="small accounting-muted">Do výcucu: <b><?= count($exportRows) ?></b> platieb · <?= accountingUiH($sourceLabels[$source]) ?></span>
              <?php if ($schemaReady && $canImportAccounting): ?>
                <form method="post" action="scripts/accounting/import_tatra.php">
                  <input type="hidden" name="csrf_token" value="<?= accountingUiH($_SESSION['accounting_tatra_csrf']) ?>">
                  <input type="hidden" name="month" value="<?= accountingUiH($month) ?>"><input type="hidden" name="action" value="refresh">
                  <input type="hidden" name="source" value="<?= accountingUiH($source) ?>">
                  <button class="btn btn-sm btn-outline-info" type="submit"><i class="fas fa-sync mr-1"></i> Vykonať kontrolu</button>
                </form>
              <?php endif; ?>
            </div>
            <div class="small mt-2 tatra-summary-links">
              <a href="<?= accountingUiH($cardUrl('ORDER', 'pending')) ?>">PENDING: <?= $stats['pending'] ?></a>
              <a href="<?= accountingUiH($cardUrl('PAYOUT')) ?>">Odoslané payouty: <?= $stats['PAYOUT'] ?></a>
              <a href="<?= accountingUiH($cardUrl('TRANSFER')) ?>">Prijaté payouty: <?= $stats['TRANSFER'] ?></a>
              <a href="<?= accountingUiH($cardUrl('ORDER', 'excluded')) ?>">Vynechané: <?= $stats['excluded'] ?></a>
            </div>
            <div class="small accounting-muted mt-2">Prevody zo Shoptetu, PayPalu a eBay sa do úhrad objednávok nezapočítajú. Vo výcuce je Poplatok 0,00; súhrnné poplatky sa nerozpočítavajú.</div>
            <?php if ($stats['pending']): ?><div class="small text-warning mt-2">PENDING objednávky potvrďte ručne v detaile objednávky. Import ich neuvoľňuje do výroby.</div><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="card card-outline card-primary">
    <div class="card-header">
      <form class="form-inline" id="accountingTatraFilters" method="get">
        <input type="hidden" name="page" value="accounting_tatra">
        <label class="mr-2" for="tatraMonth">Mesiac<?= accountingUiInfo('Mesiac a zdroj určujú tabuľku aj výcuc. Banka používa dátum zúčtovania. Typ a párovanie menia iba tabuľku.') ?></label>
        <input class="form-control form-control-sm mr-3 accounting-auto-filter" id="tatraMonth" type="month" name="month" value="<?= accountingUiH($month) ?>">
        <select class="form-control form-control-sm mr-3 accounting-auto-filter" name="source" aria-label="Zdroj platieb">
          <?php foreach ($sourceLabels as $value => $label): ?><option value="<?= $value ?>" <?= $source === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select>
        <select class="form-control form-control-sm mr-3 accounting-auto-filter" name="type" aria-label="Typ transakcie">
          <?php foreach ($typeLabels as $value => $label): ?><option value="<?= $value ?>" <?= $type === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select>
        <select class="form-control form-control-sm mr-3 accounting-auto-filter" name="match" aria-label="Párovanie">
          <?php foreach ($matchLabels as $value => $label): ?><option value="<?= $value ?>" <?= $match === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm btn-primary" type="submit">Filtrovať</button></noscript>
      </form>
    </div>
    <div class="card-body table-responsive p-0">
      <table class="table table-sm table-hover table-striped accounting-payout-table mb-0" id="accountingTatraTable" data-export-title="Tatra B. Payouts <?= accountingUiH($month) ?>">
        <thead><tr><th>Dátum</th><th>Zdroj / typ / stav</th><th>Referencia platby</th><th>Zákazník</th><th class="text-right">Suma</th><th class="text-right">Vyrovnaná suma</th><th>DS objednávka / párovanie</th><th>Fakturované / prijaté</th><th>Správa / vyúčtovanie</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $row):
              $isBank = $row['source'] === 'bank';
              $target = $row['target'];
              $orderUrl = $target ? ($target['kind'] === 'custom'
                  ? 'index.php?page=custom_orders&custom_order_id=' . $target['id'] . '&direct_open=1'
                  : 'index.php?page=orders&q=' . rawurlencode($target['reference'])) : '';
          ?>
            <tr class="<?= $row['excluded'] ? 'tatra-excluded' : '' ?>">
              <td class="text-nowrap" data-order="<?= accountingUiH($row['transaction_date']) ?>"><?= date('d.m.Y', strtotime($row['transaction_date'])) ?><br><small class="accounting-muted"><?= $isBank ? 'Zúčtovanie' : date('H:i:s', strtotime($row['transaction_date'])) ?></small>
                <?php if ($isBank && $row['processing_date'] !== substr($row['transaction_date'], 0, 10)): ?><br><small>Sprac. <?= date('d.m.Y', strtotime($row['processing_date'])) ?></small><?php endif; ?>
              </td>
              <td><b class="small"><?= $isBank ? 'Tatra banka' : 'Shoptet Pay' ?></b><br><span class="badge badge-<?= $row['transaction_type'] === 'ORDER' ? 'success' : ($row['transaction_type'] === 'REFUND' ? 'warning' : 'secondary') ?>"><?= accountingUiH($typeLabels[$row['transaction_type']] ?? $row['type_raw']) ?></span><br><small><?= accountingUiH($row['state']) ?></small></td>
              <td class="tatra-reference">
                <?php if ($isBank): ?>
                  <b><?= $row['variable_symbol'] !== '' ? 'VS: ' . accountingUiH($row['variable_symbol']) : '—' ?></b><br><small><?= accountingUiH($row['payer_reference']) ?></small>
                <?php else: ?><b><?= accountingUiH($row['order_number'] ?: '—') ?></b><?php endif; ?>
              </td>
              <td class="tatra-reference"><?= accountingUiH($row['payer_name'] ?: '—') ?><br><small class="accounting-muted"><?= accountingUiH($isBank ? $row['counterparty_account'] . ($row['counterparty_bank'] ? ' / ' . $row['counterparty_bank'] : '') : ($row['service'] ?: '')) ?></small></td>
              <td class="text-right accounting-money" data-order="<?= accountingUiH($row['amount']) ?>"><?= number_format((float) $row['amount'], 2, ',', ' ') ?> <?= accountingUiH($row['currency']) ?></td>
              <td class="text-right accounting-money" data-order="<?= accountingUiH($row['settlement_amount']) ?>"><?= number_format((float) $row['settlement_amount'], 2, ',', ' ') ?> <?= accountingUiH($row['settlement_currency']) ?></td>
              <td class="tatra-match-cell">
                <?php if ($row['excluded']): ?>
                  <span class="badge badge-secondary">Nie je objednávka</span>
                <?php elseif ($row['is_matched']): ?>
                  <a class="badge badge-info" href="<?= accountingUiH($orderUrl) ?>"><?= accountingUiH($target['reference']) ?></a>
                  <?php if ($target['status'] === 'PENDING'): ?><br><a class="badge badge-warning" href="<?= accountingUiH($orderUrl) ?>">PENDING – potvrdiť ručne</a>
                  <?php elseif ($target['status'] !== ''): ?><br><small class="accounting-muted"><?= accountingUiH($target['status']) ?></small><?php endif; ?>
                  <?php if ($isBank && $row['manual_override']): ?><br><small class="text-info">Ručne priradené</small><?php endif; ?>
                <?php elseif (in_array($row['transaction_type'], ['ORDER', 'REFUND'], true)): ?>
                  <span class="badge badge-danger"><?= $isBank && $row['match_method'] === 'WAITING_SO' ? 'Čaká na SO číslo' : 'Nenájdená / nejednoznačná' ?></span>
                  <?php if ($target && $target['kind'] === 'custom'): ?><br><a href="<?= accountingUiH($orderUrl) ?>">Otvoriť zákazku</a><?php endif; ?>
                <?php else: ?><span class="accounting-muted">—</span><?php endif; ?>
                <?php if ($isBank && $row['transaction_type'] === 'ORDER' && $canImportAccounting): ?>
                  <details class="tatra-actions mt-2"><summary class="small">Upraviť párovanie</summary>
                    <form class="tatra-match-form mt-2" method="post" action="scripts/accounting/update_tatra_bank_match.php">
                      <input type="hidden" name="csrf_token" value="<?= accountingUiH($_SESSION['accounting_tatra_csrf']) ?>">
                      <input type="hidden" name="type" value="<?= accountingUiH($type) ?>">
                      <input type="hidden" name="transaction_id" value="<?= (int) $row['id'] ?>">
                      <input type="hidden" name="month" value="<?= accountingUiH($month) ?>">
                      <input type="hidden" name="source" value="<?= accountingUiH($source) ?>">
                      <input type="hidden" name="match" value="<?= accountingUiH($match) ?>">
                      <label class="small mb-1">Číslo objednávky / SO
                        <input class="form-control form-control-sm" name="reference" maxlength="128" value="<?= accountingUiH($row['is_matched'] ? $target['reference'] : '') ?>" placeholder="napr. 2026001933 alebo SO20921">
                      </label>
                      <button class="btn btn-sm btn-outline-info" name="action" value="match" type="submit">Priradiť</button>
                      <?php if (!$row['excluded']): ?><button class="btn btn-sm btn-outline-secondary" name="action" value="exclude" type="submit">Nie je objednávka</button><?php endif; ?>
                      <?php if ($row['manual_override']): ?><button class="btn btn-sm btn-outline-warning" name="action" value="reset" type="submit">Obnoviť automatické párovanie</button><?php endif; ?>
                      <small class="d-block accounting-muted mt-1">Mení iba párovanie tejto platby.</small>
                    </form>
                  </details>
                <?php endif; ?>
              </td>
              <td>
                <span class="accounting-money"><?= $target && $target['financial_total_value'] !== null ? number_format((float) $target['financial_total_value'], 2, ',', ' ') . ' ' . accountingUiH($target['financial_total_currency'] ?: 'EUR') : '<span class="accounting-muted">—</span>' ?></span>
                <?php if (!empty($row['received'])): ?>
                  <div class="small accounting-muted mt-2">Prijaté v nahraných výpisoch<?= accountingUiInfo('Súčet spárovaných kladných úhrad Shoptet Pay a Tatra banky za všetky dátumy. Refundácie neodpočítava. Platby z iných modulov ani nenahraných výpisov tu nie sú zahrnuté.') ?>:</div>
                  <?php foreach ($row['received'] as $currency => $received): ?><div class="small accounting-money"><?= number_format($received['cents'] / 100, 2, ',', ' ') ?> <?= accountingUiH($currency) ?> <span class="accounting-muted">(<?= $received['count'] ?>×)</span></div><?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td class="tatra-message">
                <?php if ($isBank): ?>
                  <div class="small tatra-payment-message"><?= nl2br(accountingUiH($row['payment_message'])) ?></div>
                  <?php if ($row['payout_reference']): ?><small class="accounting-muted"><?= accountingUiH($row['payout_reference']) ?></small><?php endif; ?>
                  <?php if ($row['transfer_check']): ?><div class="small <?= $row['transfer_check'] === 'MATCHED' ? 'text-success' : 'text-warning' ?>"><?= [
                      'MATCHED' => 'Sedí s payoutom Shoptet Pay',
                      'MISSING' => 'Payout ešte nie je nahraný zo Shoptetu',
                      'DIFFERENCE' => 'Payout: rozdiel v sume alebo mene',
                      'AMBIGUOUS' => 'Payout: viac zhodných referencií',
                  ][$row['transfer_check']] ?></div><?php endif; ?>
                  <?php if ($row['transaction_type'] === 'TRANSFER'): ?><div class="small accounting-muted">Súhrnný prevod – mimo výcucu objednávok.</div><?php endif; ?>
                <?php else: ?>
                  <?= $row['billing_date'] ? date('d.m.Y', strtotime($row['billing_date'])) : '—' ?><br><small><?= accountingUiH($row['report_id'] ?: '') ?></small><br><small class="accounting-muted"><?= accountingUiH($row['payout_reference'] ?: '') ?></small>
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
      <div class="card-header"><h3 class="card-title">História importov</h3><div class="card-tools"><button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-plus"></i></button></div></div>
      <div class="card-body table-responsive p-0"><table class="table table-sm mb-0">
        <thead><tr><th>Dátum</th><th>Súbor</th><th>Zdrojové</th><th>Importované</th><th>Duplicity</th><th>Spárované</th></tr></thead>
        <tbody><?php foreach ($imports as $import): ?><tr>
          <td><?= date('d.m.Y H:i', strtotime($import['imported_at'])) ?></td><td><?= accountingUiH($import['original_filename']) ?></td>
          <td><?= (int) $import['source_row_count'] ?></td><td><?= (int) $import['imported_row_count'] ?></td><td><?= (int) $import['duplicate_row_count'] ?></td><td><?= (int) $import['matched_order_count'] ?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
    </div>
  <?php endif; ?>
</div>
<script src="dist/js/accounting-tatra.js"></script>
<?= accountingUiHelpModal('tatra') ?>
<?= accountingUiAssets() ?>
