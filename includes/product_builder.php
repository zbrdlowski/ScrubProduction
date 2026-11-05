<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../scripts/product_builder/helpers.php';
require_once __DIR__ . '/../scripts/product_builder/specs.php';
if (!auth_can('products.view')) { echo '<div class="alert alert-danger">You do not have permission to view products.</div>'; return; }
$pbReady = pb_schema_ready($pdo);
$pbCanEdit = auth_can('products.manage');
if (empty($_SESSION['product_builder_csrf'])) $_SESSION['product_builder_csrf'] = bin2hex(random_bytes(32));
function pb_h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<link rel="stylesheet" href="plugins/select2/css/select2.min.css">
<link rel="stylesheet" href="assets/product_builder.css?v=15">
<div class="container-fluid pb-page" id="product-builder">
  <header class="pb-header">
    <div><h1 class="h3 mb-1">Products & listings</h1><div class="pb-muted">Build a product, select its compatibility and create a unique SKU.</div></div>
    <div class="pb-actions"><button class="btn btn-outline-info" data-toggle="modal" data-target="#pb-uni-modal" <?= !$pbReady ? 'disabled' : '' ?>><i class="fas fa-layer-group mr-1"></i> UNI categories</button>
    <?php foreach (['G'=>'Graphics','V'=>'Graphics versions','S'=>'Seat Covers'] as $designType=>$designTitle): ?><button type="button" class="btn btn-outline-info" data-design-manager="<?= $designType ?>" <?= !$pbReady ? 'disabled' : '' ?>><i class="fas fa-palette mr-1" aria-hidden="true"></i> <?= $designTitle ?></button><?php endforeach; ?>
    <button type="button" class="btn btn-outline-info" data-toggle="modal" data-target="#pb-box-modal" <?= !$pbReady ? 'disabled' : '' ?>><i class="fas fa-box mr-1" aria-hidden="true"></i> Boxes</button>
    <?php if ($pbCanEdit): ?><button type="button" class="btn btn-success" id="pb-new" <?= !$pbReady ? 'disabled' : '' ?>><i class="fas fa-plus mr-1"></i> New product</button><?php endif; ?></div>
  </header>
  <?php if (!$pbReady): ?><div class="alert alert-warning">The product database needs to be installed or updated. Ask an administrator to import the product builder SQL installation or update.</div><?php return; endif; ?>
  <div class="pb-stats" aria-label="Product statistics">
    <?php foreach (['total'=>'Products','specific'=>'Specific listings','universal'=>'Universal listings','categories'=>'UNI categories'] as $key=>$label): ?>
    <span class="pb-stat"><span class="pb-muted"><?= $label ?></span><strong id="pb-stat-<?= $key ?>">—</strong></span>
    <?php endforeach; ?>
  </div>
  <div id="pb-message" class="alert" role="status" hidden></div>
  <?php if ($pbCanEdit): ?>
  <form id="pb-product-form">
    <header class="pb-editor-header"><h2 id="pb-editor-title"><i class="fas fa-pen mr-2" aria-hidden="true"></i>Product editor</h2><span class="pb-editor-mode" id="pb-edit-label" role="status">New product</span></header>
    <div class="pb-top-grid">
      <section class="card pb-panel" id="pb-type-panel">
        <div class="card-header"><h2 class="card-title"><span class="pb-step">1</span> Product type</h2></div>
        <div class="card-body">
          <div class="form-row">
            <div class="col-md-6 form-group mb-md-0"><label for="pb-bundle">Bundle type <span class="text-info">*</span></label>
              <select id="pb-bundle" class="form-control form-control-sm" required><option value="">Select a product type…</option>
              <?php foreach (pb_bundle_types() as $bundle): $labels = []; foreach (str_split($bundle) as $letter) $labels[] = pb_component_types()[$letter]; ?>
              <option value="<?= $bundle ?>"><?= $bundle ?> · <?= pb_h(implode(' + ', $labels)) ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-6 form-group mb-0"><label for="pb-name">Product name <span class="text-info">*</span></label><input class="form-control form-control-sm" id="pb-name" maxlength="255" required placeholder="e.g. KTM Graphics + Plastics kit"></div>
          </div>
        </div>
      </section>
      <section class="card pb-panel" id="pb-category-panel">
        <div class="card-header"><h2 class="card-title"><span class="pb-step">2</span> Category & compatibility</h2></div>
        <div class="card-body">
          <div class="pb-category-options mb-3">
            <label><input type="radio" name="category_kind" value="specific" checked><span><strong>Specific listing</strong><small>Brand, model and years</small></span></label>
            <label><input type="radio" name="category_kind" value="universal"><span><strong>Universal listing</strong><small>A UNI category of models</small></span></label>
          </div>
          <div id="pb-specific"><button type="button" class="btn btn-sm btn-outline-info pb-category-trigger w-100" data-toggle="modal" data-target="#pb-model-modal"><i class="fas fa-motorcycle mr-2"></i><span id="pb-specific-label">Category Info · Select brand / model / years</span><i class="fas fa-chevron-right ml-auto"></i></button></div>
          <div id="pb-universal" hidden>
            <div class="form-row"><div class="col-md-5 form-group mb-2"><label for="pb-uni-brand">Brand</label><select id="pb-uni-brand" class="form-control form-control-sm"><option value="">Select a brand…</option></select></div>
              <div class="col-md-7 form-group mb-2"><label for="pb-uni-category">Category name</label> <button type="button" class="pb-info" id="pb-uni-info" aria-label="Included models" hidden><i class="fas fa-info-circle"></i></button><select id="pb-uni-category" class="form-control form-control-sm" disabled><option value="">Select a brand first…</option></select></div></div>
            <div id="pb-uni-empty" class="pb-muted" hidden>No UNI categories yet. Add them in UNI category management.</div>
            <div id="pb-uni-meta" class="pb-muted small"></div>
          </div>
        </div>
      </section>
    </div>
    <div id="pb-start" class="pb-empty mb-3"><i class="fas fa-cubes mr-2"></i> Select a bundle type to add its components.</div>
    <div id="pb-form-body" hidden>
      <section class="card pb-panel pb-components-panel">
        <div class="card-header"><h2 class="card-title"><span class="pb-step">3</span> Product components</h2><span class="pb-muted small float-right">All components use the listing category</span></div>
        <div class="card-body" id="pb-components"></div>
      </section>
      <section class="card pb-panel"><div class="card-body"><label for="pb-notes">Internal listing note</label><textarea id="pb-notes" class="form-control form-control-sm" rows="2" maxlength="10000" placeholder="Additional product information"></textarea></div></section>
    </div>
    <details class="pb-summary" id="pb-summary">
      <summary><i class="fas fa-list-ul mr-2" aria-hidden="true"></i> Product summary <span class="pb-muted small ml-2">Show details</span></summary>
      <div class="pb-summary-grid"><div><span class="pb-muted">Bundle type</span><strong id="pb-summary-bundle">Not selected</strong></div><div><span class="pb-muted">Category</span><span id="pb-summary-category">Not selected</span></div><div><span class="pb-muted">Modelcode</span><code id="pb-summary-code">—</code></div></div>
    </details>
    <div class="pb-save-bar">
      <div class="pb-sku-control"><label for="pb-sku">Product SKU <i class="fas fa-info-circle pb-muted ml-1" title="Enter an existing shop SKU, or leave blank to generate one for a new product." aria-hidden="true"></i></label><input id="pb-sku" class="form-control form-control-sm" maxlength="80" placeholder="Generate automatically" aria-describedby="pb-sku-help"></div>
      <small class="sr-only" id="pb-sku-help">Enter an existing shop SKU, or leave blank to generate one for a new product.</small>
      <div class="pb-pricing-grid" id="pb-pricing-panel" role="group" aria-label="Product prices">
          <?php foreach (pb_price_fields() as $priceKey=>$priceLabel): ?>
          <div class="pb-price-field"><label for="pb-<?= $priceKey ?>-price"><?= pb_h($priceLabel) ?><?php if ($priceKey === 'recommended'): ?> <span class="text-info">*</span><?php endif; ?></label>
            <div class="pb-price-inputs"><input id="pb-<?= $priceKey ?>-price" class="form-control form-control-sm" type="text" inputmode="decimal" pattern="[0-9]{1,8}([.,][0-9]{1,2})?" maxlength="11" placeholder="<?= $priceKey === 'recommended' ? '0.00' : 'Not set' ?>" title="Enter a non-negative amount with up to two decimal places." <?= $priceKey === 'recommended' ? 'required' : '' ?>>
              <select id="pb-<?= $priceKey ?>-currency" class="form-control form-control-sm" aria-label="<?= pb_h($priceLabel) ?> currency"><?php foreach (pb_price_currencies() as $currency): ?><option value="<?= $currency ?>"><?= $currency ?></option><?php endforeach; ?></select>
            </div>
          </div><?php endforeach; ?>
      </div>
      <div class="pb-packaging-controls" role="group" aria-label="Packaging">
        <div><label for="pb-product-box">Box</label><select id="pb-product-box" class="form-control form-control-sm" disabled><option value="">No box selected</option></select></div>
        <div><label for="pb-product-weight" title="Total packed weight, including the box">Weight (kg)</label><input id="pb-product-weight" class="form-control form-control-sm" type="text" inputmode="decimal" pattern="[0-9]{1,6}([.,][0-9]{1,3})?" maxlength="10" placeholder="Not set" title="Enter the total packed weight, including the box, in kg. Up to three decimal places."></div>
      </div>
      <div class="pb-actions"><button type="button" class="btn btn-sm btn-outline-secondary" id="pb-cancel" hidden>Cancel editing</button><button type="submit" class="btn btn-sm btn-success" id="pb-save" disabled>Save product and generate SKU</button></div>
    </div>
  </form> <?php endif; ?>
  <section class="card pb-panel pb-list-panel" id="pb-saved-products" aria-labelledby="pb-list-title"><div class="card-header pb-list-header"><h2 class="card-title" id="pb-list-title"><i class="fas fa-table mr-2" aria-hidden="true"></i>Saved products</h2><input type="search" id="pb-search" class="form-control form-control-sm" maxlength="150" aria-label="Search products" placeholder="Name, SKU or modelcode…"></div>
    <div class="table-responsive"><table class="table table-bordered table-hover table-sm mb-0 pb-table pb-products-table"><thead><tr><th class="pb-created-column" title="Listing creation date">Created</th><th>Product / SKU</th><th>Bundle</th><th>Category</th><th>Modelcode</th><?php foreach (pb_price_fields() as $priceLabel): ?><th class="pb-price-column"><?= pb_h($priceLabel) ?></th><?php endforeach; ?><th>Box</th><th class="pb-price-column" title="Total packed weight, including the box">Weight (kg)</th><th>Components</th><th>Actions</th></tr></thead><tbody id="pb-products"><tr><td colspan="13" class="pb-muted">Loading products…</td></tr></tbody></table></div>
    <div class="card-footer small pb-muted">Showing up to 100 of the most recent results.</div>
  </section>
</div>
<div class="modal fade pb-modal" id="pb-model-modal" tabindex="-1" role="dialog" aria-labelledby="pb-model-title"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
  <div class="modal-header"><div><h3 class="modal-title h5" id="pb-model-title">Category Info</h3><small class="pb-muted">Select a specific model from Models Database.</small></div><button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button></div>
  <div class="modal-body"><div id="pb-model-error" class="alert alert-danger" role="alert" hidden></div><div class="form-row">
    <div class="col-md-4 form-group"><label for="pb-model-brand">Brand</label><select id="pb-model-brand" class="form-control"></select></div>
    <div class="col-md-4 form-group"><label for="pb-model-name">Model</label><select id="pb-model-name" class="form-control" disabled></select></div>
    <div class="col-md-4 form-group"><label for="pb-model-years">Years / modelcode</label><select id="pb-model-years" class="form-control" disabled></select></div>
  </div><div class="pb-actions"><a class="btn btn-sm btn-outline-success" href="index.php?page=product_chart&amp;add_model=1" target="_blank" rel="noopener"><i class="fas fa-plus mr-1"></i> New model / modelcode</a><button class="btn btn-sm btn-outline-secondary" type="button" id="pb-model-refresh"><i class="fas fa-sync mr-1"></i> Refresh models</button></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button class="btn btn-info" id="pb-model-apply" disabled>Use category</button></div>
</div></div></div>
<div class="modal fade pb-modal" id="pb-uni-modal" tabindex="-1" role="dialog" aria-labelledby="pb-uni-title"><div class="modal-dialog modal-xl" role="document"><div class="modal-content">
  <div class="modal-header"><div><h3 class="modal-title h5" id="pb-uni-title">UNI categories</h3><small class="pb-muted">Brands, categories, included models and prices.</small></div><button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button></div>
  <div class="modal-body"><div id="pb-uni-message" class="alert" role="status" hidden></div><div class="row"><div class="<?= $pbCanEdit ? 'col-lg-7' : 'col-12' ?>">
    <input type="search" class="form-control mb-3" id="pb-uni-search" placeholder="Search brand, category, model or modelcode…" aria-label="Search UNI categories">
    <div class="pb-uni-scroll"><table class="table table-sm table-hover pb-table"><thead><tr><th>Brand / category</th><th>Modelcode</th><th>Models</th><th></th></tr></thead><tbody id="pb-uni-rows"></tbody></table></div>
    
    </div><?php if ($pbCanEdit): ?><div class="col-lg-5"><form id="pb-uni-form" class="pb-uni-editor"><div class="d-flex justify-content-between mb-3"><h4 class="h6 mb-0" id="pb-uni-edit-title">New UNI category</h4><button type="button" class="btn btn-xs btn-outline-info" id="pb-uni-new">+ New</button></div>
      <input type="hidden" name="id"><input type="hidden" name="version">
      <div class="form-group"><label for="pb-edit-brand">Brand</label><input id="pb-edit-brand" name="brand" class="form-control" maxlength="100" required list="pb-brand-list"><datalist id="pb-brand-list"></datalist></div>
      <div class="form-group"><label for="pb-edit-category">Category name</label><input id="pb-edit-category" name="category_name" class="form-control" maxlength="150" required></div>
      <div class="form-group"><label for="pb-edit-code">Modelcode</label><div class="input-group"><input id="pb-edit-code" name="modelcode" class="form-control" readonly placeholder="Generated when saved"><div class="input-group-append"><button type="button" class="btn btn-outline-info" id="pb-generate-code" title="Generate modelcode" aria-label="Generate modelcode"><i class="fas fa-magic"></i></button></div></div></div>
      <div class="form-group"><label for="pb-edit-models">Included models <i class="fas fa-info-circle text-info" title="This text appears in the category information tooltip."></i></label><textarea id="pb-edit-models" name="included_models" class="form-control" rows="3" maxlength="10000" placeholder="You can enter each model on a separate line."></textarea></div>
      <div class="form-row"><div class="col-6 form-group"><label for="pb-edit-unlicensed">No license</label><input type="number" min="0" max="99999999.99" step="0.01" id="pb-edit-unlicensed" name="price_unlicensed" class="form-control"></div><div class="col-6 form-group"><label for="pb-edit-licensed">Licensed</label><input type="number" min="0" max="99999999.99" step="0.01" id="pb-edit-licensed" name="price_licensed" class="form-control"></div></div>
      <div class="pb-actions"><button type="submit" class="btn btn-success">Save category</button><button type="button" class="btn btn-outline-danger" id="pb-uni-delete" hidden>Delete</button></div>
    </form>
    <form id="pb-import-form" class="pb-import mt-3"><label for="pb-csv">Import UNI categories from CSV</label><div class="custom-file mb-2"><input type="file" id="pb-csv" accept=".csv,text/csv" class="custom-file-input" lang="en" aria-label="Choose a CSV file" required><label class="custom-file-label" for="pb-csv" id="pb-csv-label" data-browse="Browse">Choose a CSV file</label></div><small class="pb-muted d-block mb-2">Adds missing categories. Existing data and modelcodes are preserved. Maximum 2 MB.</small><button type="submit" class="btn btn-sm btn-outline-info">Import CSV</button></form>
    </div><?php endif; ?></div></div>
</div></div></div>
<div class="modal fade pb-modal" id="pb-design-modal" tabindex="-1" role="dialog" aria-labelledby="pb-design-title"><div class="modal-dialog modal-xl" role="document"><div class="modal-content">
  <div class="modal-header"><h3 class="modal-title h5" id="pb-design-title">Graphics designs</h3><button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button></div>
  <div class="modal-body"><div id="pb-design-message" class="alert" role="status" hidden></div><div class="row"><div class="<?= $pbCanEdit ? 'col-lg-7' : 'col-12' ?>">
    <div class="d-flex align-items-center mb-3"><input type="search" class="form-control mr-2" id="pb-design-search" placeholder="Search designs…" aria-label="Search designs"><button type="button" class="btn btn-outline-info" id="pb-design-refresh" aria-label="Refresh designs" title="Refresh designs"><i class="fas fa-sync" aria-hidden="true"></i></button></div>
    <div class="pb-uni-scroll"><table class="table table-sm table-hover pb-table"><thead><tr><th id="pb-design-column">Design name</th><th>Listings</th><th></th></tr></thead><tbody id="pb-design-rows"></tbody></table></div><small class="pb-muted" id="pb-design-count"></small>
    </div><?php if ($pbCanEdit): ?><div class="col-lg-5"><form id="pb-design-form" class="pb-uni-editor">
      <div class="d-flex justify-content-between mb-3"><h4 class="h6 mb-0" id="pb-design-edit-title">New design</h4><button type="button" class="btn btn-xs btn-outline-info" id="pb-design-new">+ New</button></div>
      <input type="hidden" name="id"><input type="hidden" name="version"><input type="hidden" name="component_type">
      <div class="form-group"><label for="pb-design-name" id="pb-design-name-label">Design name</label><input id="pb-design-name" name="name" class="form-control" maxlength="255" required></div>
      <div class="form-group" id="pb-version-order-group" hidden><label for="pb-version-order">Display order</label><input type="number" min="0" max="9999" step="1" id="pb-version-order" name="sort_order" class="form-control" value="0" disabled required></div>
      <div class="pb-actions"><button type="submit" class="btn btn-success" id="pb-design-save">Save design</button><button type="button" class="btn btn-outline-danger" id="pb-design-delete" hidden>Delete</button></div>
      <small class="pb-muted d-block mt-2" id="pb-design-usage"></small>
    </form></div><?php endif; ?></div></div>
</div></div></div>
<div class="modal fade pb-modal" id="pb-box-modal" tabindex="-1" role="dialog" aria-labelledby="pb-box-title"><div class="modal-dialog modal-xl" role="document"><div class="modal-content">
  <div class="modal-header"><h3 class="modal-title h5" id="pb-box-title">Boxes</h3><button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button></div>
  <div class="modal-body"><div id="pb-box-message" class="alert" role="status" hidden></div><div class="row"><div class="<?= $pbCanEdit ? 'col-lg-7' : 'col-12' ?>">
    <div class="d-flex align-items-center mb-3"><input type="search" class="form-control mr-2" id="pb-box-search" maxlength="150" placeholder="Search boxes…" aria-label="Search boxes"><button type="button" class="btn btn-outline-info" id="pb-box-refresh" aria-label="Refresh boxes" title="Refresh boxes"><i class="fas fa-sync" aria-hidden="true"></i></button></div>
    <div class="pb-uni-scroll table-responsive"><table class="table table-sm table-hover pb-table"><thead><tr><th>Name</th><th>Length × Width × Height (cm)</th><th>Weight (kg)</th><th></th></tr></thead><tbody id="pb-box-rows"></tbody></table></div><small class="pb-muted" id="pb-box-count"></small>
    </div><?php if ($pbCanEdit): ?><div class="col-lg-5"><form id="pb-box-form" class="pb-uni-editor">
      <div class="d-flex justify-content-between mb-3"><h4 class="h6 mb-0" id="pb-box-edit-title">New box</h4><button type="button" class="btn btn-xs btn-outline-info" id="pb-box-new">+ New</button></div>
      <input type="hidden" name="id"><input type="hidden" name="version">
      <div class="form-group"><label for="pb-box-name">Box name</label><input id="pb-box-name" name="name" class="form-control" maxlength="150" required></div>
      <div class="form-row">
        <?php foreach (['length'=>'Length','width'=>'Width','height'=>'Height'] as $dimension=>$label): ?><div class="col-4 form-group"><label for="pb-box-<?= $dimension ?>"><?= $label ?> (cm)</label><input id="pb-box-<?= $dimension ?>" name="<?= $dimension ?>_cm" class="form-control" type="text" inputmode="decimal" pattern="[0-9]{1,5}([.,][0-9]{1,2})?" maxlength="8" title="Enter a dimension greater than zero with up to two decimal places." required></div><?php endforeach; ?>
      </div>
      <div class="form-group"><label for="pb-box-weight">Weight (kg)</label><input id="pb-box-weight" name="weight_kg" class="form-control" type="text" inputmode="decimal" pattern="[0-9]{1,6}([.,][0-9]{1,3})?" maxlength="10" value="0" title="Enter the empty-box weight in kg. Zero is allowed." required></div>
      <div class="pb-actions"><button type="submit" class="btn btn-success" id="pb-box-save">Save box</button><button type="button" class="btn btn-outline-danger" id="pb-box-delete" hidden>Delete</button></div><small class="pb-muted d-block mt-2" id="pb-box-usage"></small>
    </form></div><?php endif; ?></div></div>
</div></div></div>
<div class="modal fade pb-modal" id="pb-detail-modal" tabindex="-1" role="dialog" aria-labelledby="pb-detail-title"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h3 class="modal-title h5" id="pb-detail-title">Product details</h3><button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button></div><div class="modal-body" id="pb-detail"></div></div></div></div>
<script>window.productBuilderConfig = <?= json_encode(['csrf'=>$_SESSION['product_builder_csrf'],'canEdit'=>$pbCanEdit,'types'=>pb_component_types(),'priceFields'=>pb_price_fields(),'specFields'=>pb_component_spec_fields($conn),'requestKey'=>bin2hex(random_bytes(16))], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script defer src="plugins/select2/js/select2.full.min.js"></script>
<script src="scripts/product_builder/app.js?v=14"></script>
