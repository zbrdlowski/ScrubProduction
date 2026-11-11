<?php
declare(strict_types=1);
require_once __DIR__ . '/i18n.php';
$GLOBALS['pbLanguage'] = pb_resolve_language();
$_SESSION['product_builder_languages'][(int) ($_SESSION['user_id'] ?? 0)] = pb_language();
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/specs.php';
if (!isset($_SESSION['permission'])) { http_response_code(401); echo '<div class="alert alert-danger">' . htmlspecialchars(pb_t('Please sign in to the application.'), ENT_QUOTES, 'UTF-8') . '</div>'; return; }
$pbPageMode = in_array($pbPageMode ?? '', ['builder','listings','controls'], true) ? $pbPageMode : 'builder';
$pbShowBuilder = $pbPageMode === 'builder';
$pbShowListings = $pbPageMode !== 'controls';
$pbShowLibraries = $pbPageMode !== 'listings';
$pbTitle = ['builder'=>'Product Builder','listings'=>'Listings','controls'=>'Product Controls'][$pbPageMode];
$pbDescription = ['builder'=>'Build a product, select its compatibility and create a unique SKU.','listings'=>'','controls'=>'Manage categories, designs, versions and boxes.'][$pbPageMode];
if ($pbPageMode !== 'listings' && !auth_can('products.view')) { http_response_code(403); echo '<div class="alert alert-danger">' . htmlspecialchars(pb_t('You do not have permission to view products.'), ENT_QUOTES, 'UTF-8') . '</div>'; return; }
$pbReady = pb_schema_ready($pdo);
$pbCanEdit = pb_can_manage_products();
$pbEditProductId = $pbShowBuilder && $pbCanEdit ? (filter_var($_GET['edit'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0) : 0;
if (empty($_SESSION['product_builder_csrf'])) $_SESSION['product_builder_csrf'] = bin2hex(random_bytes(32));
if (!function_exists('pb_h')) { function pb_h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); } }
?>
<link rel="stylesheet" href="plugins/select2/css/select2.min.css">
<link rel="stylesheet" href="assets/product_builder.css?v=29">
<div class="container-fluid pb-page" id="product-builder" lang="<?= pb_language() ?>">
  <div class="pb-language-bar"><div class="btn-group btn-group-sm" role="group" aria-label="<?= pb_h(pb_t('Language')) ?>">
    <?php foreach (['sk'=>'SK','en'=>'EN'] as $language=>$languageLabel): $query=['page'=>'product_'.$pbPageMode,'pb_lang'=>$language]; if($pbEditProductId>0)$query['edit']=$pbEditProductId; ?>
    <a class="btn <?= pb_language()===$language?'btn-info':'btn-outline-light' ?>" href="index.php?<?= pb_h(http_build_query($query)) ?>" data-pb-language="<?= $language ?>" lang="<?= $language ?>" hreflang="<?= $language ?>" aria-pressed="<?= pb_language()===$language?'true':'false' ?>" title="<?= $language==='sk'?'Slovenčina':'English' ?>"><?= $languageLabel ?></a>
    <?php endforeach; ?>
  </div></div>
  <header class="pb-header">
    <div><h1 class="h3 mb-1"><?= pb_h(pb_t($pbTitle)) ?></h1><?php if ($pbDescription !== ''): ?><div class="pb-muted"><?= pb_h(pb_t($pbDescription)) ?></div><?php endif; ?></div>
    <?php if ($pbShowBuilder): ?><div class="pb-actions"><button class="btn btn-outline-info" data-toggle="modal" data-target="#pb-uni-modal" <?= !$pbReady ? 'disabled' : '' ?>><i class="fas fa-layer-group mr-1"></i> <?= pb_h(pb_t('UNI categories')) ?></button>
    <?php foreach (['G'=>'Graphics','V'=>'Graphics versions','S'=>'Seat Covers'] as $designType=>$designTitle): ?><button type="button" class="btn btn-outline-info" data-design-manager="<?= $designType ?>" <?= !$pbReady ? 'disabled' : '' ?>><i class="fas fa-palette mr-1" aria-hidden="true"></i> <?= pb_h(pb_t($designTitle)) ?></button><?php endforeach; ?>
    <button type="button" class="btn btn-outline-info" data-toggle="modal" data-target="#pb-box-modal" <?= !$pbReady ? 'disabled' : '' ?>><i class="fas fa-box mr-1" aria-hidden="true"></i> <?= pb_h(pb_t('Boxes')) ?></button>
    <?php if ($pbCanEdit): ?><button type="button" class="btn btn-outline-info" id="pb-refresh-libraries" disabled title="<?= pb_h(pb_t('Refresh categories, designs, versions and boxes without reloading the page.')) ?>"><i class="fas fa-sync-alt mr-1" aria-hidden="true"></i> <?= pb_h(pb_t('Refresh libraries')) ?></button><button type="button" class="btn btn-success" id="pb-new" <?= !$pbReady ? 'disabled' : '' ?>><i class="fas fa-plus mr-1"></i> <?= pb_h(pb_t('New product')) ?></button><?php endif; ?></div><?php endif; ?>
  </header>
  <?php if (!$pbReady): ?><div class="alert alert-warning"><?= pb_h(pb_t('The product database needs to be installed or updated. Ask an administrator to import the product builder SQL installation or update.')) ?></div><?php return; endif; ?>
  <?php if ($pbShowBuilder): ?><div class="pb-stats" aria-label="<?= pb_h(pb_t('Product statistics')) ?>">
    <?php foreach (['total'=>'Products','specific'=>'Specific listings','universal'=>'Universal listings','categories'=>'UNI categories'] as $key=>$label): ?>
    <span class="pb-stat"><span class="pb-muted"><?= pb_h(pb_t($label)) ?></span><strong id="pb-stat-<?= $key ?>">—</strong></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($pbShowBuilder && $pbCanEdit): ?><div id="pb-library-status" class="small pb-muted mb-2" role="status" hidden></div><?php endif; ?>
  <div id="pb-message" class="alert" role="status" hidden></div>
  <?php if ($pbShowBuilder && $pbCanEdit): ?>
  <form id="pb-product-form">
    <header class="pb-editor-header"><h2 id="pb-editor-title"><i class="fas fa-pen mr-2" aria-hidden="true"></i><?= pb_h(pb_t('Product editor')) ?></h2><span class="pb-editor-mode" id="pb-edit-label" role="status"><?= pb_h(pb_t('New product')) ?></span></header>
    <div class="pb-top-grid">
      <section class="card pb-panel" id="pb-type-panel">
        <div class="card-header"><h2 class="card-title"><span class="pb-step">1</span> <?= pb_h(pb_t('Product type')) ?></h2></div>
        <div class="card-body">
          <div class="form-row">
            <div class="col-md-6 form-group mb-md-0"><label for="pb-bundle"><?= pb_h(pb_t('Bundle type')) ?> <span class="text-info">*</span></label>
              <select id="pb-bundle" class="form-control form-control-sm" required><option value=""><?= pb_h(pb_t('Select a product type…')) ?></option>
              <?php foreach (pb_bundle_types() as $bundle): $labels = []; foreach (pb_bundle_component_types($bundle) as $letter) $labels[] = pb_component_types()[$letter]; $bundleLabel = implode(' + ', array_map('pb_t', $labels)); if (strpos($bundle, 'T') !== false) $bundleLabel = pb_t('Trim Kit') . ' (' . $bundleLabel . ')'; ?>
              <option value="<?= $bundle ?>"><?= $bundle ?> · <?= pb_h($bundleLabel) ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-6 form-group mb-0"><label for="pb-name"><?= pb_h(pb_t('Product name')) ?> <span class="text-info">*</span></label><input class="form-control form-control-sm" id="pb-name" maxlength="255" required placeholder="<?= pb_h(pb_t('e.g. KTM Graphics + Plastics kit')) ?>"></div>
          </div>
        </div>
      </section>
      <section class="card pb-panel" id="pb-category-panel">
        <div class="card-header"><h2 class="card-title"><span class="pb-step">2</span> <?= pb_h(pb_t('Category & compatibility')) ?></h2></div>
        <div class="card-body">
          <div class="pb-category-options mb-3">
            <label><input type="radio" name="category_kind" value="specific" checked><span><strong><?= pb_h(pb_t('Specific listing')) ?></strong><small><?= pb_h(pb_t('Brand, model and years')) ?></small></span></label>
            <label><input type="radio" name="category_kind" value="universal"><span><strong><?= pb_h(pb_t('Universal listing')) ?></strong><small><?= pb_h(pb_t('A UNI category of models')) ?></small></span></label>
          </div>
          <div id="pb-specific"><button type="button" class="btn btn-sm btn-outline-info pb-category-trigger w-100" data-toggle="modal" data-target="#pb-model-modal"><i class="fas fa-motorcycle mr-2"></i><span id="pb-specific-label"><?= pb_h(pb_t('Category Info · Select brand / model / years')) ?></span><i class="fas fa-chevron-right ml-auto"></i></button></div>
          <div id="pb-universal" hidden>
            <div class="form-row"><div class="col-md-5 form-group mb-2"><label for="pb-uni-brand"><?= pb_h(pb_t('Brand')) ?></label><select id="pb-uni-brand" class="form-control form-control-sm"><option value=""><?= pb_h(pb_t('Select a brand…')) ?></option></select></div>
              <div class="col-md-7 form-group mb-2"><label for="pb-uni-category"><?= pb_h(pb_t('Category name')) ?></label> <button type="button" class="pb-info" id="pb-uni-info" aria-label="<?= pb_h(pb_t('Included models')) ?>" hidden><i class="fas fa-info-circle"></i></button><select id="pb-uni-category" class="form-control form-control-sm" disabled><option value=""><?= pb_h(pb_t('Select a brand first…')) ?></option></select></div></div>
            <div id="pb-uni-empty" class="pb-muted" hidden><?= pb_h(pb_t('No UNI categories yet. Add them in UNI category management.')) ?></div>
            <div id="pb-uni-meta" class="pb-muted small"></div>
          </div>
        </div>
      </section>
    </div>
    <div id="pb-start" class="pb-empty mb-3"><i class="fas fa-cubes mr-2"></i> <?= pb_h(pb_t('Select a bundle type to add its components.')) ?></div>
    <div id="pb-form-body" hidden>
      <section class="card pb-panel pb-components-panel">
        <div class="card-header"><h2 class="card-title"><span class="pb-step">3</span> <?= pb_h(pb_t('Product components')) ?></h2><span class="pb-muted small float-right"><?= pb_h(pb_t('All components use the listing category')) ?></span></div>
        <div class="card-body" id="pb-components"></div>
      </section>
      <section class="card pb-panel"><div class="card-body"><label for="pb-notes"><?= pb_h(pb_t('Internal listing note')) ?></label><textarea id="pb-notes" class="form-control form-control-sm" rows="2" maxlength="10000" placeholder="<?= pb_h(pb_t('Additional product information')) ?>"></textarea></div></section>
    </div>
    <details class="pb-summary" id="pb-summary">
      <summary><i class="fas fa-list-ul mr-2" aria-hidden="true"></i> <?= pb_h(pb_t('Product summary')) ?> <span class="pb-muted small ml-2"><?= pb_h(pb_t('Show details')) ?></span></summary>
      <div class="pb-summary-grid"><div><span class="pb-muted"><?= pb_h(pb_t('Bundle type')) ?></span><strong id="pb-summary-bundle"><?= pb_h(pb_t('Not selected')) ?></strong></div><div><span class="pb-muted"><?= pb_h(pb_t('Category')) ?></span><span id="pb-summary-category"><?= pb_h(pb_t('Not selected')) ?></span></div><div><span class="pb-muted">Modelcode</span><code id="pb-summary-code">—</code></div></div>
    </details>
    <div class="pb-save-bar">
      <div class="pb-sku-control"><label for="pb-sku"><?= pb_h(pb_t('Product SKU')) ?> <i class="fas fa-info-circle pb-muted ml-1" title="<?= pb_h(pb_t('Enter a shop SKU, leave blank for a new product, or use the wand to generate a replacement. Save the product to apply the change.')) ?>" aria-hidden="true"></i></label><div class="pb-sku-inputs"><input id="pb-sku" class="form-control form-control-sm" maxlength="80" placeholder="<?= pb_h(pb_t('Generate automatically')) ?>" aria-describedby="pb-sku-help"><button type="button" id="pb-generate-sku" class="btn btn-sm btn-outline-info" disabled title="<?= pb_h(pb_t('Generate new SKU')) ?>" aria-label="<?= pb_h(pb_t('Generate new SKU')) ?>"><i class="fas fa-magic" aria-hidden="true"></i></button></div><span id="pb-sku-status" class="sr-only" role="status"></span></div>
      <small class="sr-only" id="pb-sku-help"><?= pb_h(pb_t('Enter a shop SKU, leave blank for a new product, or use the wand to generate a replacement. Save the product to apply the change.')) ?></small>
      <div class="pb-pricing-grid" id="pb-pricing-panel" role="group" aria-label="<?= pb_h(pb_t('Product prices')) ?>">
          <?php foreach (pb_price_fields() as $priceKey=>$priceLabel): ?>
          <div class="pb-price-field"><label for="pb-<?= $priceKey ?>-price"><?= pb_h(pb_t($priceLabel)) ?><?php if ($priceKey === 'recommended'): ?> <span class="text-info">*</span><?php endif; ?></label>
            <div class="pb-price-inputs"><input id="pb-<?= $priceKey ?>-price" class="form-control form-control-sm" type="text" inputmode="decimal" pattern="[0-9]{1,8}([.,][0-9]{1,2})?" maxlength="11" placeholder="<?= $priceKey === 'recommended' ? '0.00' : pb_h(pb_t('Not set')) ?>" title="<?= pb_h(pb_t('Enter a non-negative amount with up to two decimal places.')) ?>" <?= $priceKey === 'recommended' ? 'required' : '' ?>>
              <select id="pb-<?= $priceKey ?>-currency" class="form-control form-control-sm" aria-label="<?= pb_h(pb_t('{label} currency', ['label'=>pb_t($priceLabel)])) ?>"><?php foreach (pb_price_currencies() as $currency): ?><option value="<?= $currency ?>"><?= $currency ?></option><?php endforeach; ?></select>
            </div>
          </div><?php endforeach; ?>
      </div>
      <div class="pb-packaging-controls" role="group" aria-label="<?= pb_h(pb_t('Packaging')) ?>">
        <div><label for="pb-product-box"><?= pb_h(pb_t('Box')) ?></label><select id="pb-product-box" class="form-control form-control-sm" disabled><option value=""><?= pb_h(pb_t('No box selected')) ?></option></select></div>
        <div><label for="pb-product-weight" title="<?= pb_h(pb_t('Total packed weight, including the box')) ?>"><?= pb_h(pb_t('Weight (kg)')) ?></label><input id="pb-product-weight" class="form-control form-control-sm" type="text" inputmode="decimal" pattern="[0-9]{1,6}([.,][0-9]{1,3})?" maxlength="10" placeholder="<?= pb_h(pb_t('Not set')) ?>" title="<?= pb_h(pb_t('Enter the total packed weight, including the box, in kg. Up to three decimal places.')) ?>"></div>
      </div>
      <div class="pb-actions"><button type="button" class="btn btn-sm btn-outline-secondary" id="pb-cancel" hidden><?= pb_h(pb_t('Cancel editing')) ?></button><button type="submit" class="btn btn-sm btn-success" id="pb-save" disabled><?= pb_h(pb_t('Save product and generate SKU')) ?></button></div>
    </div>
  </form> <?php endif; ?>
  <?php if ($pbPageMode === 'controls'): ?>
  <div class="pb-controls-grid" aria-label="<?= pb_h(pb_t('Product libraries')) ?>">
    <?php foreach ([
      ['title'=>'UNI categories','icon'=>'layer-group','description'=>'Brands, categories, modelcodes and included models.','target'=>'#pb-uni-modal','group'=>'','tone'=>'cyan'],
      ['title'=>'Graphics designs','icon'=>'palette','description'=>'Design names used by Graphics components.','target'=>'','group'=>'G','tone'=>'green'],
      ['title'=>'Graphics versions','icon'=>'tags','description'=>'ST, LE, R1–R10 and other graphics versions.','target'=>'','group'=>'V','tone'=>'blue'],
      ['title'=>'Seat cover designs','icon'=>'swatchbook','description'=>'Design names used by Seat Cover components.','target'=>'','group'=>'S','tone'=>'gold'],
      ['title'=>'Boxes','icon'=>'box','description'=>'Packaging names, dimensions and empty-box weights.','target'=>'#pb-box-modal','group'=>'','tone'=>'orange'],
    ] as $control): ?>
    <button type="button" class="pb-control-tile pb-control-<?= $control['tone'] ?>" <?php if ($control['target'] !== ''): ?>data-toggle="modal" data-target="<?= $control['target'] ?>"<?php else: ?>data-design-manager="<?= $control['group'] ?>"<?php endif; ?>>
      <span class="pb-control-icon"><i class="fas fa-<?= $control['icon'] ?>" aria-hidden="true"></i></span>
      <span class="pb-control-copy"><strong><?= pb_h(pb_t($control['title'])) ?></strong><span><?= pb_h(pb_t($control['description'])) ?></span></span>
      <i class="fas fa-chevron-right pb-control-arrow" aria-hidden="true"></i>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($pbShowListings): ?>
  <?php $pbRenderPagination = static function (string $position): void { ?>
    <div class="pb-list-pagination <?= $position === 'bottom' ? 'card-footer' : 'pb-pagination-top' ?>" data-list-pagination>
      <span class="pb-muted pb-list-range" data-list-range role="status"><?= pb_h(pb_t('Loading products…')) ?></span>
      <label class="pb-page-size"><?= pb_h(pb_t('Rows per page')) ?><select class="form-control form-control-sm" data-list-page-size disabled><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>"<?= $size === 100 ? ' selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></label>
      <nav class="pb-page-navigation" aria-label="<?= pb_h(pb_t('Listing pages')) ?>">
        <?php foreach (['first'=>['First page','angle-double-left'],'previous'=>['Previous page','angle-left']] as $action=>$button): ?><button type="button" class="btn btn-sm btn-outline-info" data-list-page="<?= $action ?>" title="<?= pb_h(pb_t($button[0])) ?>" aria-label="<?= pb_h(pb_t($button[0])) ?>" disabled><i class="fas fa-<?= $button[1] ?>" aria-hidden="true"></i></button><?php endforeach; ?>
        <label class="pb-page-number"><?= pb_h(pb_t('Page')) ?><input type="number" class="form-control form-control-sm" data-list-page-number min="1" max="1" step="1" value="1" aria-label="<?= pb_h(pb_t('Go to page')) ?>" disabled><span data-list-page-count><?= pb_h(pb_t('of {pages}', ['pages'=>1])) ?></span></label>
        <?php foreach (['next'=>['Next page','angle-right'],'last'=>['Last page','angle-double-right']] as $action=>$button): ?><button type="button" class="btn btn-sm btn-outline-info" data-list-page="<?= $action ?>" title="<?= pb_h(pb_t($button[0])) ?>" aria-label="<?= pb_h(pb_t($button[0])) ?>" disabled><i class="fas fa-<?= $button[1] ?>" aria-hidden="true"></i></button><?php endforeach; ?>
      </nav>
    </div>
  <?php }; ?>
  <section class="card pb-panel pb-list-panel" id="pb-saved-products" aria-labelledby="pb-list-title"><div class="card-header pb-list-header"><h2 class="card-title" id="pb-list-title"><i class="fas fa-table mr-2" aria-hidden="true"></i><?= pb_h(pb_t('Saved products')) ?></h2><input type="search" id="pb-search" class="form-control form-control-sm" maxlength="150" aria-label="<?= pb_h(pb_t('Search products')) ?>" placeholder="<?= pb_h(pb_t('Name, SKU, modelcode or category…')) ?>"></div>
    <span id="pb-channel-status-message" class="sr-only" role="status" aria-live="polite"></span>
    <span id="pb-img-status-message" class="sr-only" role="status" aria-live="polite"></span>
    <span id="pb-copy-status" class="sr-only" role="status" aria-live="polite"></span>
    <?php $pbRenderPagination('top'); ?>
    <div class="table-responsive"><table class="table table-bordered table-hover table-sm mb-0 pb-table pb-products-table"><thead><tr><th class="pb-created-column" title="<?= pb_h(pb_t('Listing creation date')) ?>"><?= pb_h(pb_t('Created')) ?></th><th><?= pb_h(pb_t('Product / SKU')) ?></th><th><?= pb_h(pb_t('Bundle')) ?></th><th><?= pb_h(pb_t('Category')) ?></th><th class="pb-img-heading">IMG</th><?php foreach (pb_price_fields() as $priceLabel): ?><th class="pb-price-column"><?= pb_h(pb_t($priceLabel)) ?></th><?php endforeach; ?><th><?= pb_h(pb_t('Box')) ?></th><th><?= pb_h(pb_t('Components')) ?></th><th><?= pb_h(pb_t('Actions')) ?></th></tr></thead><tbody id="pb-products"><tr><td colspan="12" class="pb-muted"><?= pb_h(pb_t('Loading products…')) ?></td></tr></tbody></table></div>
    <?php $pbRenderPagination('bottom'); ?>
  </section>
  <?php endif; ?>
</div>
<?php if ($pbShowBuilder): ?>
<div class="modal fade pb-modal" lang="<?= pb_language() ?>" id="pb-model-modal" tabindex="-1" role="dialog" aria-labelledby="pb-model-title"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
  <div class="modal-header"><div><h3 class="modal-title h5" id="pb-model-title"><?= pb_h(pb_t('Category Info')) ?></h3><small class="pb-muted"><?= pb_h(pb_t('Select a specific model from Models Database.')) ?></small></div><button type="button" class="close" data-dismiss="modal" aria-label="<?= pb_h(pb_t('Close')) ?>">&times;</button></div>
  <div class="modal-body"><div id="pb-model-error" class="alert alert-danger" role="alert" hidden></div><div class="form-row">
    <div class="col-md-4 form-group"><label for="pb-model-brand"><?= pb_h(pb_t('Brand')) ?></label><select id="pb-model-brand" class="form-control"></select></div>
    <div class="col-md-4 form-group"><label for="pb-model-name"><?= pb_h(pb_t('Model')) ?></label><select id="pb-model-name" class="form-control" disabled></select></div>
    <div class="col-md-4 form-group"><label for="pb-model-years"><?= pb_h(pb_t('Years / modelcode')) ?></label><select id="pb-model-years" class="form-control" disabled></select></div>
  </div><div class="pb-actions"><a class="btn btn-sm btn-outline-success" href="index.php?page=product_chart&amp;add_model=1" target="_blank" rel="noopener"><i class="fas fa-plus mr-1"></i> <?= pb_h(pb_t('New model / modelcode')) ?></a><button class="btn btn-sm btn-outline-secondary" type="button" id="pb-model-refresh"><i class="fas fa-sync mr-1"></i> <?= pb_h(pb_t('Refresh models')) ?></button></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal"><?= pb_h(pb_t('Cancel')) ?></button><button class="btn btn-info" id="pb-model-apply" disabled><?= pb_h(pb_t('Use category')) ?></button></div>
</div></div></div>
<?php endif; ?>
<?php if ($pbShowLibraries): ?>
<div class="modal fade pb-modal" lang="<?= pb_language() ?>" id="pb-uni-modal" tabindex="-1" role="dialog" aria-labelledby="pb-uni-title"><div class="modal-dialog modal-xl" role="document"><div class="modal-content">
  <div class="modal-header"><div><h3 class="modal-title h5" id="pb-uni-title"><?= pb_h(pb_t('UNI categories')) ?></h3><small class="pb-muted"><?= pb_h(pb_t('Brands, categories, included models and prices.')) ?></small></div><button type="button" class="close" data-dismiss="modal" aria-label="<?= pb_h(pb_t('Close')) ?>">&times;</button></div>
  <div class="modal-body"><div id="pb-uni-message" class="alert" role="status" hidden></div><div class="row"><div class="<?= $pbCanEdit ? 'col-lg-7' : 'col-12' ?>">
    <input type="search" class="form-control mb-3" id="pb-uni-search" placeholder="<?= pb_h(pb_t('Search brand, category, model or modelcode…')) ?>" aria-label="<?= pb_h(pb_t('Search UNI categories')) ?>">
    <div class="pb-uni-scroll"><table class="table table-sm table-hover pb-table"><thead><tr><th><?= pb_h(pb_t('Brand / category')) ?></th><th>Modelcode</th><th><?= pb_h(pb_t('Models')) ?></th><th></th></tr></thead><tbody id="pb-uni-rows"></tbody></table></div>
    
    </div><?php if ($pbCanEdit): ?><div class="col-lg-5"><form id="pb-uni-form" class="pb-uni-editor"><div class="d-flex justify-content-between mb-3"><h4 class="h6 mb-0" id="pb-uni-edit-title"><?= pb_h(pb_t('New UNI category')) ?></h4><button type="button" class="btn btn-xs btn-outline-info" id="pb-uni-new"><?= pb_h(pb_t('+ New')) ?></button></div>
      <input type="hidden" name="id"><input type="hidden" name="version">
      <div class="form-group"><label for="pb-edit-brand"><?= pb_h(pb_t('Brand')) ?></label><input id="pb-edit-brand" name="brand" class="form-control" maxlength="100" required list="pb-brand-list"><datalist id="pb-brand-list"></datalist></div>
      <div class="form-group"><label for="pb-edit-category"><?= pb_h(pb_t('Category name')) ?></label><input id="pb-edit-category" name="category_name" class="form-control" maxlength="150" required></div>
      <div class="form-group"><label for="pb-edit-code">Modelcode</label><div class="input-group"><input id="pb-edit-code" name="modelcode" class="form-control" readonly placeholder="<?= pb_h(pb_t('Generated when saved')) ?>"><div class="input-group-append"><button type="button" class="btn btn-outline-info" id="pb-generate-code" title="<?= pb_h(pb_t('Generate modelcode')) ?>" aria-label="<?= pb_h(pb_t('Generate modelcode')) ?>"><i class="fas fa-magic"></i></button></div></div></div>
      <div class="form-group"><label for="pb-edit-models"><?= pb_h(pb_t('Included models')) ?> <i class="fas fa-info-circle text-info" title="<?= pb_h(pb_t('This text appears in the category information tooltip.')) ?>"></i></label><textarea id="pb-edit-models" name="included_models" class="form-control" rows="3" maxlength="10000" placeholder="<?= pb_h(pb_t('You can enter each model on a separate line.')) ?>"></textarea></div>
      <div class="form-row"><div class="col-6 form-group"><label for="pb-edit-unlicensed"><?= pb_h(pb_t('No license')) ?></label><input type="number" min="0" max="99999999.99" step="0.01" id="pb-edit-unlicensed" name="price_unlicensed" class="form-control"></div><div class="col-6 form-group"><label for="pb-edit-licensed"><?= pb_h(pb_t('Licensed')) ?></label><input type="number" min="0" max="99999999.99" step="0.01" id="pb-edit-licensed" name="price_licensed" class="form-control"></div></div>
      <div class="pb-actions"><button type="submit" class="btn btn-success"><?= pb_h(pb_t('Save category')) ?></button><button type="button" class="btn btn-outline-danger" id="pb-uni-delete" hidden><?= pb_h(pb_t('Delete')) ?></button></div>
    </form>
    <form id="pb-import-form" class="pb-import mt-3"><label for="pb-csv"><?= pb_h(pb_t('Import UNI categories from CSV')) ?></label><div class="custom-file mb-2"><input type="file" id="pb-csv" accept=".csv,text/csv" class="custom-file-input" lang="<?= pb_language() ?>" aria-label="<?= pb_h(pb_t('Choose a CSV file')) ?>" required><label class="custom-file-label" for="pb-csv" id="pb-csv-label" data-browse="<?= pb_h(pb_t('Browse')) ?>"><?= pb_h(pb_t('Choose a CSV file')) ?></label></div><small class="pb-muted d-block mb-2"><?= pb_h(pb_t('Adds missing categories. Existing data and modelcodes are preserved. Maximum 2 MB.')) ?></small><button type="submit" class="btn btn-sm btn-outline-info"><?= pb_h(pb_t('Import CSV')) ?></button></form>
    </div><?php endif; ?></div></div>
</div></div></div>
<div class="modal fade pb-modal" lang="<?= pb_language() ?>" id="pb-design-modal" tabindex="-1" role="dialog" aria-labelledby="pb-design-title"><div class="modal-dialog modal-xl" role="document"><div class="modal-content">
  <div class="modal-header"><h3 class="modal-title h5" id="pb-design-title"><?= pb_h(pb_t('Graphics designs')) ?></h3><button type="button" class="close" data-dismiss="modal" aria-label="<?= pb_h(pb_t('Close')) ?>">&times;</button></div>
  <div class="modal-body"><div id="pb-design-message" class="alert" role="status" hidden></div><div class="row"><div class="<?= $pbCanEdit ? 'col-lg-7' : 'col-12' ?>">
    <div class="d-flex align-items-center mb-3"><input type="search" class="form-control mr-2" id="pb-design-search" placeholder="<?= pb_h(pb_t('Search designs…')) ?>" aria-label="<?= pb_h(pb_t('Search designs')) ?>"><button type="button" class="btn btn-outline-info" id="pb-design-refresh" aria-label="<?= pb_h(pb_t('Refresh designs')) ?>" title="<?= pb_h(pb_t('Refresh designs')) ?>"><i class="fas fa-sync" aria-hidden="true"></i></button></div>
    <div class="pb-uni-scroll"><table class="table table-sm table-hover pb-table"><thead><tr><th id="pb-design-column"><?= pb_h(pb_t('Design name')) ?></th><th><?= pb_h(pb_t('Listings')) ?></th><th></th></tr></thead><tbody id="pb-design-rows"></tbody></table></div><small class="pb-muted" id="pb-design-count"></small>
    </div><?php if ($pbCanEdit): ?><div class="col-lg-5"><form id="pb-design-form" class="pb-uni-editor">
      <div class="d-flex justify-content-between mb-3"><h4 class="h6 mb-0" id="pb-design-edit-title"><?= pb_h(pb_t('New design')) ?></h4><button type="button" class="btn btn-xs btn-outline-info" id="pb-design-new"><?= pb_h(pb_t('+ New')) ?></button></div>
      <input type="hidden" name="id"><input type="hidden" name="version"><input type="hidden" name="component_type">
      <div class="form-group"><label for="pb-design-name" id="pb-design-name-label"><?= pb_h(pb_t('Design name')) ?></label><input id="pb-design-name" name="name" class="form-control" maxlength="255" required></div>
      <div class="form-group" id="pb-version-order-group" hidden><label for="pb-version-order"><?= pb_h(pb_t('Display order')) ?></label><input type="number" min="0" max="9999" step="1" id="pb-version-order" name="sort_order" class="form-control" value="0" disabled required></div>
      <div class="pb-actions"><button type="submit" class="btn btn-success" id="pb-design-save"><?= pb_h(pb_t('Save design')) ?></button><button type="button" class="btn btn-outline-danger" id="pb-design-delete" hidden><?= pb_h(pb_t('Delete')) ?></button></div>
      <small class="pb-muted d-block mt-2" id="pb-design-usage"></small>
    </form></div><?php endif; ?></div></div>
</div></div></div>
<div class="modal fade pb-modal" lang="<?= pb_language() ?>" id="pb-box-modal" tabindex="-1" role="dialog" aria-labelledby="pb-box-title"><div class="modal-dialog modal-xl" role="document"><div class="modal-content">
  <div class="modal-header"><h3 class="modal-title h5" id="pb-box-title"><?= pb_h(pb_t('Boxes')) ?></h3><button type="button" class="close" data-dismiss="modal" aria-label="<?= pb_h(pb_t('Close')) ?>">&times;</button></div>
  <div class="modal-body"><div id="pb-box-message" class="alert" role="status" hidden></div><div class="row"><div class="<?= $pbCanEdit ? 'col-lg-7' : 'col-12' ?>">
    <div class="d-flex align-items-center mb-3"><input type="search" class="form-control mr-2" id="pb-box-search" maxlength="150" placeholder="<?= pb_h(pb_t('Search boxes…')) ?>" aria-label="<?= pb_h(pb_t('Search boxes')) ?>"><button type="button" class="btn btn-outline-info" id="pb-box-refresh" aria-label="<?= pb_h(pb_t('Refresh boxes')) ?>" title="<?= pb_h(pb_t('Refresh boxes')) ?>"><i class="fas fa-sync" aria-hidden="true"></i></button></div>
    <div class="pb-uni-scroll table-responsive"><table class="table table-sm table-hover pb-table"><thead><tr><th><?= pb_h(pb_t('Name')) ?></th><th><?= pb_h(pb_t('Length × Width × Height (cm)')) ?></th><th><?= pb_h(pb_t('Weight (kg)')) ?></th><th></th></tr></thead><tbody id="pb-box-rows"></tbody></table></div><small class="pb-muted" id="pb-box-count"></small>
    </div><?php if ($pbCanEdit): ?><div class="col-lg-5"><form id="pb-box-form" class="pb-uni-editor">
      <div class="d-flex justify-content-between mb-3"><h4 class="h6 mb-0" id="pb-box-edit-title"><?= pb_h(pb_t('New box')) ?></h4><button type="button" class="btn btn-xs btn-outline-info" id="pb-box-new"><?= pb_h(pb_t('+ New')) ?></button></div>
      <input type="hidden" name="id"><input type="hidden" name="version">
      <div class="form-group"><label for="pb-box-name"><?= pb_h(pb_t('Box name')) ?></label><input id="pb-box-name" name="name" class="form-control" maxlength="150" required></div>
      <div class="form-row">
        <?php foreach (['length'=>'Length','width'=>'Width','height'=>'Height'] as $dimension=>$label): ?><div class="col-4 form-group"><label for="pb-box-<?= $dimension ?>"><?= pb_h(pb_t($label)) ?> (cm)</label><input id="pb-box-<?= $dimension ?>" name="<?= $dimension ?>_cm" class="form-control" type="text" inputmode="decimal" pattern="[0-9]{1,5}([.,][0-9]{1,2})?" maxlength="8" title="<?= pb_h(pb_t('Enter a dimension greater than zero with up to two decimal places.')) ?>" required></div><?php endforeach; ?>
      </div>
      <div class="form-group"><label for="pb-box-weight"><?= pb_h(pb_t('Weight (kg)')) ?></label><input id="pb-box-weight" name="weight_kg" class="form-control" type="text" inputmode="decimal" pattern="[0-9]{1,6}([.,][0-9]{1,3})?" maxlength="10" value="0" title="<?= pb_h(pb_t('Enter the empty-box weight in kg. Zero is allowed.')) ?>" required></div>
      <div class="pb-actions"><button type="submit" class="btn btn-success" id="pb-box-save"><?= pb_h(pb_t('Save box')) ?></button><button type="button" class="btn btn-outline-danger" id="pb-box-delete" hidden><?= pb_h(pb_t('Delete')) ?></button></div><small class="pb-muted d-block mt-2" id="pb-box-usage"></small>
    </form></div><?php endif; ?></div></div>
</div></div></div>
<?php endif; ?>
<?php if ($pbShowListings): ?>
<div class="modal fade pb-modal" lang="<?= pb_language() ?>" id="pb-img-modal" tabindex="-1" role="dialog" aria-labelledby="pb-img-title"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h3 class="modal-title h5" id="pb-img-title"><?= pb_h(pb_t('Image folder')) ?></h3><button type="button" class="close" data-dismiss="modal" aria-label="<?= pb_h(pb_t('Close')) ?>">&times;</button></div>
  <form id="pb-img-form"><div class="modal-body">
    <div id="pb-img-message" class="alert" role="alert" hidden></div>
    <label for="pb-img-path-input"><?= pb_h(pb_t('Folder path')) ?></label>
    <div class="input-group"><input type="text" id="pb-img-path-input" class="form-control" maxlength="2048" required autocomplete="off" spellcheck="false" placeholder="Y:\E-SHOP\_WEB_2025\GRAPHICS_KIT\HUSQVARNA AT"><div class="input-group-append"><button type="button" class="btn btn-outline-info pb-copy-code" id="pb-img-copy" title="<?= pb_h(pb_t('Copy folder path')) ?>" aria-label="<?= pb_h(pb_t('Copy folder path')) ?>"><i class="far fa-copy" aria-hidden="true"></i></button></div></div>
    <small class="pb-muted"><?= pb_h(pb_t('Spaces in the folder path are supported.')) ?></small>
    <button type="button" class="btn btn-sm btn-outline-info mt-2" id="pb-img-reload" hidden><?= pb_h(pb_t('Reload IMG status')) ?></button>
  </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal"><?= pb_h(pb_t('Close')) ?></button><button type="submit" class="btn btn-success" id="pb-img-save"><?= pb_h(pb_t('Save path')) ?></button></div></form>
</div></div></div>
<div class="modal fade pb-modal" lang="<?= pb_language() ?>" id="pb-channel-modal" tabindex="-1" role="dialog" aria-labelledby="pb-channel-title"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><div><h3 class="modal-title h5" id="pb-channel-title"><?= pb_h(pb_t('Listed product')) ?></h3><small class="pb-muted" id="pb-channel-product"></small></div><button type="button" class="close" data-dismiss="modal" aria-label="<?= pb_h(pb_t('Close')) ?>">&times;</button></div>
  <form id="pb-channel-form"><div class="modal-body">
    <div id="pb-channel-message" class="alert" role="alert" hidden></div>
    <div class="form-row"><div class="form-group col-sm-8"><label for="pb-channel-price"><?= pb_h(pb_t('Listed price')) ?> *</label><input id="pb-channel-price" class="form-control" type="text" inputmode="decimal" pattern="[0-9]{1,8}([.,][0-9]{1,2})?" maxlength="11" required title="<?= pb_h(pb_t('Enter a non-negative amount with up to two decimal places.')) ?>"></div><div class="form-group col-sm-4"><label for="pb-channel-currency"><?= pb_h(pb_t('Currency')) ?></label><select id="pb-channel-currency" class="form-control" required><?php foreach (pb_price_currencies() as $currency): ?><option value="<?= $currency ?>"><?= $currency ?></option><?php endforeach; ?></select></div></div>
    <div class="form-group"><label for="pb-channel-url"><?= pb_h(pb_t('Listing URL')) ?> *</label><input id="pb-channel-url" class="form-control" type="url" maxlength="2048" required placeholder="https://" autocomplete="off" spellcheck="false"></div>
    <small class="pb-muted"><?= pb_h(pb_t('The listed price is stored separately from the suggested platform price.')) ?></small>
    <button type="button" class="btn btn-sm btn-outline-info mt-2" id="pb-channel-reload" hidden><?= pb_h(pb_t('Reload channel data')) ?></button>
  </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal"><?= pb_h(pb_t('Cancel')) ?></button><button type="submit" class="btn btn-success" id="pb-channel-save"><?= pb_h(pb_t('Save listing')) ?></button></div></form>
</div></div></div>

<div class="modal fade pb-modal" lang="<?= pb_language() ?>" id="pb-detail-modal" tabindex="-1" role="dialog" aria-labelledby="pb-detail-title"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h3 class="modal-title h5" id="pb-detail-title"><?= pb_h(pb_t('Product details')) ?></h3><button type="button" class="close" data-dismiss="modal" aria-label="<?= pb_h(pb_t('Close')) ?>">&times;</button></div><div class="modal-body" id="pb-detail"></div></div></div></div>
<?php endif; ?>
<script>window.productBuilderConfig = <?= json_encode(['language'=>pb_language(),'languageCookie'=>pb_language_cookie(),'translations'=>pb_language()==='sk'?pb_i18n_dictionary():new stdClass(),'pageMode'=>$pbPageMode,'editProductId'=>$pbEditProductId,'csrf'=>$_SESSION['product_builder_csrf'],'canEdit'=>$pbCanEdit,'types'=>pb_component_types(),'bundleComponents'=>array_combine(pb_bundle_types(),array_map('pb_bundle_component_types',pb_bundle_types())),'priceFields'=>pb_price_fields(),'specFields'=>pb_component_spec_fields($conn),'requestKey'=>bin2hex(random_bytes(16))], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script defer src="plugins/select2/js/select2.full.min.js"></script>
<script src="scripts/product_builder/app.js?v=31"></script>
