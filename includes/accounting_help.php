<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/accounting/access.php';
require_once __DIR__ . '/../scripts/accounting/ui_helpers.php';

if (!accounting_payout_user_can_access()) {
    echo '<div class="alert alert-danger">Na účtovnú sekciu nemáte oprávnenie.</div>';
    return;
}

$documents = [
    'payouts' => ['title' => 'eBay payouty', 'file' => 'accounting-payouts.md', 'page' => 'accounting_payouts'],
    'paypal' => ['title' => 'PayPal', 'file' => 'accounting-paypal.md', 'page' => 'accounting_paypal'],
    'omega' => ['title' => 'OMEGA faktúry', 'file' => 'accounting-omega.md', 'page' => 'accounting_omega'],
    'omega_export' => ['title' => 'OMEGA TXT export', 'file' => 'accounting-omega-export.md', 'page' => 'accounting_omega_export'],
];
$key = (string) ($_GET['doc'] ?? 'payouts');
if (!isset($documents[$key])) $key = 'payouts';
$document = $documents[$key];
$path = __DIR__ . '/../docs/' . $document['file'];
$markdown = is_file($path) ? (string) file_get_contents($path) : '# Návod sa nenašiel';
?>
<style>
  .accounting-help-layout { max-width:1100px; margin:0 auto; }
  .accounting-help-document { background:#fff; color:#263238; border-radius:.35rem; padding:2rem 2.35rem; box-shadow:0 2px 10px rgba(0,0,0,.2); }
  .accounting-help-document h1 { font-size:1.8rem; border-bottom:2px solid #20c997; padding-bottom:.65rem; margin-bottom:1.2rem; }
  .accounting-help-document h2 { font-size:1.3rem; margin-top:1.8rem; color:#146c5b; }
  .accounting-help-document h3 { font-size:1.1rem; margin-top:1.35rem; }
  .accounting-help-document p, .accounting-help-document li { line-height:1.65; }
  .accounting-help-document code { color:#9c2f5b; background:#f2f4f5; padding:.12rem .3rem; border-radius:.2rem; }
  .accounting-help-document pre { background:#17212b; color:#f1f5f8; padding:1rem; border-radius:.3rem; overflow:auto; }
  .accounting-help-document pre code { color:inherit; background:none; padding:0; }
  @media(max-width:575.98px){.accounting-help-document{padding:1.25rem}}
</style>
<div class="container-fluid py-3">
  <div class="accounting-help-layout">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3" style="gap:.75rem">
      <div><h1 class="h3 mb-1">Pomocník účtovníctva</h1><div class="text-muted">Praktické slovenské návody pre jednotlivé časti.</div></div>
      <a class="btn btn-outline-light" href="?page=<?= accountingUiH($document['page']) ?>"><i class="fas fa-arrow-left mr-1"></i> Späť na <?= accountingUiH($document['title']) ?></a>
    </div>
    <div class="btn-group d-flex flex-wrap mb-3" role="group" aria-label="Návody účtovníctva">
      <?php foreach ($documents as $docKey => $item): ?>
        <a class="btn <?= $docKey === $key ? 'btn-info' : 'btn-outline-info' ?>" href="?page=accounting_help&amp;doc=<?= accountingUiH($docKey) ?>"><?= accountingUiH($item['title']) ?></a>
      <?php endforeach; ?>
    </div>
    <article class="accounting-help-document"><?= accountingUiRenderMarkdown($markdown) ?></article>
  </div>
</div>
