<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/add_item_helpers.php';

$addItemRules = add_item_rules();
$addItemState = $_SESSION['add_item_form'] ?? [];
$addItemInput = $addItemState['input'] ?? [];
$addItemErrors = $addItemState['errors'] ?? [];
unset($_SESSION['add_item_form']);

$addItemEscape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$addItemAttributes = static function (string $field) use ($addItemRules, $addItemInput, $addItemErrors, $addItemEscape): string {
    $rule = $addItemRules[$field];
    $number = ($rule['type'] ?? '') === 'integer';
    $value = $addItemInput[$field] ?? ($number ? '0' : '');
    $attributes = 'class="form-control form-control-sm' . (isset($addItemErrors[$field]) ? ' is-invalid' : '') . '"';
    $attributes .= ' value="' . $addItemEscape($value) . '"';
    $attributes .= ' aria-describedby="' . $field . '-error"';
    if (isset($addItemErrors[$field])) {
        $attributes .= ' aria-invalid="true"';
    }
    if ($number) {
        $attributes .= ' min="0" max="' . $rule['max'] . '" step="1" inputmode="numeric"';
    } else {
        $attributes .= ' maxlength="' . $rule['maxLength'] . '"';
        if (!empty($rule['required'])) {
            $attributes .= ' required pattern=".*\S.*"';
        }
    }
    return $attributes;
};
$addItemFeedback = static function (string $field) use ($addItemErrors, $addItemEscape): string {
    return '<div class="invalid-feedback" id="' . $field . '-error">'
        . $addItemEscape($addItemErrors[$field] ?? '') . '</div>';
};
?>
<link rel="stylesheet" href="js/jquery-ui.css">
<script src="plugins/jquery-ui/jquery-ui.min.js"></script>

<style>
/* jQuery UI Autocomplete – AdminLTE Dark Skin */
.ui-autocomplete {
  background: #222d32;
  border: 1px solid #374850;
  color: #ecf0f5;
  max-height: 250px;
  overflow-y: auto;
  overflow-x: hidden;
  z-index: 1051;
  box-shadow: 0 4px 10px rgba(0,0,0,.6);
  font-size: 14px;
}

.ui-autocomplete .ui-menu-item-wrapper {
  padding: 8px 12px;
  cursor: pointer;
}

.ui-autocomplete .ui-menu-item-wrapper.ui-state-active {
  background: #3c8dbc;
  color: #fff;
  border: none;
}

.ui-corner-all {
  border-radius: 0;
}

/* Compact Add Item form for AdminLTE 3.2 dark mode */
.add-item-page {
  padding: 8px 12px 12px;
}

.add-item-page .page-title {
  margin: 0 0 10px;
  font-size: 22px;
  font-weight: 600;
}

.add-item-card {
  margin-bottom: 0;
}

.add-item-card .card-body {
  padding: 12px 14px;
}

.add-item-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px 14px;
}

.add-item-column {
  min-width: 0;
}

.add-item-section-title {
  margin: 0 0 8px;
  padding-bottom: 5px;
  border-bottom: 1px solid #4b545c;
  color: #adb5bd;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
}

.add-item-page .form-group {
  margin-bottom: 7px;
}

.add-item-page label {
  margin-bottom: 2px;
  font-size: 12px;
  line-height: 1.15;
  color: #ced4da;
}

.add-item-page .form-control {
  height: 31px;
  padding: 4px 8px;
  font-size: 13px;
}

.add-item-actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid #4b545c;
}

.add-item-actions .btn {
  min-width: 120px;
}

.add-item-page footer {
  padding: 8px 0 0 !important;
  font-size: 12px;
}

@media (max-width: 1199.98px) {
  .add-item-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 767.98px) {
  .add-item-page {
    padding-left: 6px;
    padding-right: 6px;
  }

  .add-item-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<?php foreach (['error' => 'danger', 'success' => 'success'] as $addItemFlash => $addItemStyle): ?>
  <?php if (isset($_SESSION[$addItemFlash])): ?>
    <div class="alert alert-<?= $addItemStyle ?> alert-dismissible" role="alert">
      <button type="button" class="close" data-dismiss="alert" aria-label="Close">&times;</button>
      <?= $addItemEscape($_SESSION[$addItemFlash]) ?>
    </div>
    <?php unset($_SESSION[$addItemFlash]); ?>
  <?php endif; ?>
<?php endforeach; ?>

<div class="dark-section add-item-page">
  <h2 class="text-center text-light page-title">Add New Item to Warehouse</h2>

  <div class="container-fluid px-0">
    <div class="card card-dark add-item-card">
      <div class="card-body">
        <form id="add-item-form" action="scripts/add_item.php" method="POST">
          <input type="hidden" id="page" name="page" value="add_item">
          <input type="hidden" id="baseline" name="baseline" value="0">
          <p class="small text-muted mb-2">Barcode and Model are required. Optimum and Max must be whole numbers of 0 or more; empty values are saved as 0.</p>
          <div id="add-item-validation-summary" class="alert alert-danger" role="alert" <?= $addItemErrors ? '' : 'hidden' ?>>
            Please correct the highlighted fields.
            <?php if ($addItemErrors): ?>
              <ul class="mb-0">
                <?php foreach ($addItemErrors as $addItemError): ?>
                  <li><?= $addItemEscape($addItemError) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>


          <div class="add-item-grid">
            <!-- Column 1: Basic item data -->
            <div class="add-item-column">
              <div class="add-item-section-title">Item</div>

              <div class="form-group">
                <label for="brand">Brand:</label>
                <input type="text" id="brand" name="brand" <?= $addItemAttributes('brand') ?>>
                <?= $addItemFeedback('brand') ?>
              </div>

              <div class="form-group">
                <label for="barcode">Barcode:</label>
                <input type="text" id="barcode" name="barcode" <?= $addItemAttributes('barcode') ?>>
                <?= $addItemFeedback('barcode') ?>
              </div>

              <div class="form-group">
                <label for="scrubcode">Scrubcode:</label>
                <input type="text" id="scrubcode" name="scrubcode" <?= $addItemAttributes('scrubcode') ?>>
                <?= $addItemFeedback('scrubcode') ?>
              </div>

              <div class="form-group">
                <label for="name">Model:</label>
                <input type="text" id="name" name="name" <?= $addItemAttributes('name') ?>>
                <?= $addItemFeedback('name') ?>
              </div>

              <div class="form-group">
                <label for="description">Part:</label>
                <input type="text" id="description" name="description" <?= $addItemAttributes('description') ?>>
                <?= $addItemFeedback('description') ?>
              </div>

              <div class="form-group">
                <label for="color">Color:</label>
                <input type="text" id="color" name="color" <?= $addItemAttributes('color') ?>>
                <?= $addItemFeedback('color') ?>
              </div>
            </div>

            <!-- Column 2: Stock + primary supplier refs -->
            <div class="add-item-column">
              <div class="add-item-section-title">Stock & Supplier</div>

              <div class="form-group">
                <label for="optimum">Optimum:</label>
                <input type="number" id="optimum" name="optimum" <?= $addItemAttributes('optimum') ?>>
                <?= $addItemFeedback('optimum') ?>
              </div>

              <div class="form-group">
                <label for="moq">Max:</label>
                <input type="number" id="moq" name="moq" <?= $addItemAttributes('moq') ?>>
                <?= $addItemFeedback('moq') ?>
              </div>

              <div class="form-group">
                <label for="main_supplier">Main Supplier:</label>
                <input type="text" id="main_supplier" name="main_supplier" <?= $addItemAttributes('main_supplier') ?>>
                <?= $addItemFeedback('main_supplier') ?>
              </div>

              <div class="form-group">
                <label for="ufo_pn">UFO PN:</label>
                <input type="text" id="ufo_pn" name="ufo_pn" <?= $addItemAttributes('ufo_pn') ?>>
                <?= $addItemFeedback('ufo_pn') ?>
              </div>

              <div class="form-group">
                <label for="ufo_barcode">UFO Barcode:</label>
                <input type="text" id="ufo_barcode" name="ufo_barcode" <?= $addItemAttributes('ufo_barcode') ?>>
                <?= $addItemFeedback('ufo_barcode') ?>
              </div>

              <div class="form-group">
                <label for="rt_pn">R-Tech PN:</label>
                <input type="text" id="rt_pn" name="rt_pn" <?= $addItemAttributes('rt_pn') ?>>
                <?= $addItemFeedback('rt_pn') ?>
              </div>

              <div class="form-group">
                <label for="rt_barcode">R-Tech Barcode:</label>
                <input type="text" id="rt_barcode" name="rt_barcode" <?= $addItemAttributes('rt_barcode') ?>>
                <?= $addItemFeedback('rt_barcode') ?>
              </div>
            </div>

            <!-- Column 3: Remaining supplier refs -->
            <div class="add-item-column">
              <div class="add-item-section-title">Supplier References</div>

              <div class="form-group">
                <label for="ps_pn">Polisport PN:</label>
                <input type="text" id="ps_pn" name="ps_pn" <?= $addItemAttributes('ps_pn') ?>>
                <?= $addItemFeedback('ps_pn') ?>
              </div>

              <div class="form-group">
                <label for="ps_barcode">Polisport Barcode:</label>
                <input type="text" id="ps_barcode" name="ps_barcode" <?= $addItemAttributes('ps_barcode') ?>>
                <?= $addItemFeedback('ps_barcode') ?>
              </div>

              <div class="form-group">
                <label for="ac_pn">Acerbis PN:</label>
                <input type="text" id="ac_pn" name="ac_pn" <?= $addItemAttributes('ac_pn') ?>>
                <?= $addItemFeedback('ac_pn') ?>
              </div>

              <div class="form-group">
                <label for="ac_barcode">Acerbis Barcode:</label>
                <input type="text" id="ac_barcode" name="ac_barcode" <?= $addItemAttributes('ac_barcode') ?>>
                <?= $addItemFeedback('ac_barcode') ?>
              </div>

              <div class="form-group">
                <label for="other_pn">Other PN:</label>
                <input type="text" id="other_pn" name="other_pn" <?= $addItemAttributes('other_pn') ?>>
                <?= $addItemFeedback('other_pn') ?>
              </div>

              <div class="form-group">
                <label for="other_barcode">Other Barcode:</label>
                <input type="text" id="other_barcode" name="other_barcode" <?= $addItemAttributes('other_barcode') ?>>
                <?= $addItemFeedback('other_barcode') ?>
              </div>
            </div>
          </div>

          <div class="add-item-actions">
            <button type="submit" class="btn btn-success btn-sm">
              <i class="fas fa-plus mr-1"></i> Add Item
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <footer class="text-center text-light">
    &copy; <?= date('Y') ?> SCRUBDESIGNZ. All rights reserved.
  </footer>
</div>

<script id="add-item-rules" type="application/json"><?= json_encode($addItemRules, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
  const form = document.getElementById('add-item-form');
  const rules = JSON.parse(document.getElementById('add-item-rules').textContent);
  const summary = document.getElementById('add-item-validation-summary');
  const button = form.querySelector('button[type="submit"]');

  function validate(input, rule, normalize) {
    let value = input.value.trim();
    let error = '';
    if (rule.type === 'integer') {
      if (input.validity.badInput || (value !== '' && !/^[0-9]+$/.test(value))) {
        error = rule.label + ': enter a whole number of 0 or more.';
      } else if (Number(value) > rule.max) {
        error = rule.label + ': maximum is ' + rule.max + '.';
      } else if (normalize) {
        value = value.replace(/^0+/, '') || '0';
        input.value = value;
      }
    } else {
      if (rule.required && value === '') {
        error = rule.label + ' is required.';
      } else if (/[\x00-\x1F\x7F]/.test(value)) {
        error = rule.label + ': remove control characters.';
      } else if (/[\u{10000}-\u{10FFFF}]/u.test(value)) {
        error = rule.label + ': this character cannot be stored. Remove emoji or other unsupported symbols.';
      } else if (Array.from(value).length > rule.maxLength) {
        error = rule.label + ': use at most ' + rule.maxLength + ' characters.';
      } else if (rule.maxBytes && new TextEncoder().encode(value).length > rule.maxBytes) {
        error = rule.label + ': the text is too long. Please shorten it.';
      }
      if (normalize) input.value = value;
    }
    input.setCustomValidity(error);
    input.classList.toggle('is-invalid', error !== '');
    input.setAttribute('aria-invalid', error ? 'true' : 'false');
    const feedback = document.getElementById(input.name + '-error');
    if (feedback) feedback.textContent = error;
    return error;
  }

  function refreshSummary() {
    if (!form.querySelector('.is-invalid')) summary.hidden = true;
  }

  Object.keys(rules).forEach(function (name) {
    const input = form.elements.namedItem(name);
    if (!input || input.type === 'hidden') return;
    input.addEventListener('blur', function (event) {
      // Let submit validate everything before feedback can move the clicked button.
      if (event.relatedTarget === button) return;
      validate(input, rules[name], true);
      refreshSummary();
    });
    input.addEventListener('input', function () {
      if (input.classList.contains('is-invalid')) {
        validate(input, rules[name], false);
        refreshSummary();
      }
    });
  });

  form.addEventListener('submit', function (event) {
    const errors = [];
    let firstInvalid = null;
    Object.keys(rules).forEach(function (name) {
      const input = form.elements.namedItem(name);
      if (!input) return;
      const error = validate(input, rules[name], true);
      if (error) {
        errors.push(error);
        if (!firstInvalid && input.type !== 'hidden') firstInvalid = input;
      }
    });
    if (errors.length) {
      event.preventDefault();
      summary.textContent = 'Please correct the highlighted fields.';
      const list = document.createElement('ul');
      list.className = 'mb-0';
      errors.forEach(function (error) {
        const item = document.createElement('li');
        item.textContent = error;
        list.appendChild(item);
      });
      summary.appendChild(list);
      summary.hidden = false;
      if (firstInvalid) firstInvalid.focus();
      return;
    }
    summary.hidden = true;
    button.disabled = true;
  });
  // Native HTML constraints remain active when JavaScript is unavailable.
  form.noValidate = true;
  window.addEventListener('pageshow', function () { button.disabled = false; });

  const firstServerError = form.querySelector('input.is-invalid:not([type="hidden"])');
  if (firstServerError) firstServerError.focus();
})();
</script>

<script>
$(function () {
  $('#main_supplier').autocomplete({
    source: 'scripts/get_suppliers.php',
    minLength: 1,
    delay: 150,
    autoFocus: true
  });
});
</script>
