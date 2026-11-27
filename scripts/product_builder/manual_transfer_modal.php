<?php // Shared manual-transfer editor; only rendered inside Transfer D-base. ?>
<div class="modal fade pb-modal" lang="<?= pb_language() ?>" id="pb-manual-transfer-modal" tabindex="-1" role="dialog" aria-labelledby="pb-manual-transfer-title"><div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h3 class="modal-title h5" id="pb-manual-transfer-title"><?= pb_h(pb_t('New manual transfer')) ?></h3><button type="button" class="close" data-dismiss="modal" aria-label="<?= pb_h(pb_t('Close')) ?>">&times;</button></div>
<form id="pb-manual-transfer-form"><div class="modal-body">
<div id="pb-manual-transfer-error" class="alert alert-danger" role="alert" hidden></div><button type="button" id="pb-manual-transfer-retry" class="btn btn-outline-info mb-3" hidden><?= pb_h(pb_t('Retry')) ?></button>
<div class="row">
<div class="form-group col-md-8"><label for="pb-manual-title"><?= pb_h(pb_t('Product name')) ?> *</label><input class="form-control" id="pb-manual-title" name="title" type="text" maxlength="255" required></div>
<div class="form-group col-md-4"><label for="pb-manual-source_sku"><?= pb_h(pb_t('Order No. / SKU')) ?></label><input class="form-control" id="pb-manual-source_sku" name="source_sku" type="text" maxlength="255"></div>
<div class="form-group col-md-8"><label for="pb-manual-design_name"><?= pb_h(pb_t('Graphics design')) ?></label><input class="form-control" id="pb-manual-design_name" name="design_name" type="text" maxlength="150" list="pb-manual-designs"></div>
<div class="form-group col-md-4"><label for="pb-manual-version_name"><?= pb_h(pb_t('Version')) ?></label><input class="form-control" id="pb-manual-version_name" name="version_name" type="text" maxlength="100" list="pb-manual-versions"></div>
<div class="form-group col-md-12"><label for="pb-manual-category"><?= pb_h(pb_t('Category & compatibility')) ?></label><select class="form-control" id="pb-manual-category"><option value=""><?= pb_h(pb_t('Select a category…')) ?></option></select></div>
</div><datalist id="pb-manual-designs"></datalist><datalist id="pb-manual-versions"></datalist>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal"><?= pb_h(pb_t('Cancel')) ?></button><button type="submit" class="btn btn-success" id="pb-manual-transfer-save"><?= pb_h(pb_t('Save transfer')) ?></button></div></form></div></div></div>
