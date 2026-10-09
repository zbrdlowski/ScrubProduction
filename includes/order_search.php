<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/order_search_registry.php';

auth_require('orders.view', 'Na tuto stranku nemate opravnenie.');

$orderSearchFieldsJson = json_encode(orderSearchPublicFields(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$orderSearchOperatorsJson = json_encode(orderSearchOperatorLabels(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>
<style>
  .order-search-page {
    color: #e4e6eb;
  }

  .order-search-toolbar,
  .order-search-builder,
  .order-search-results-head {
    background: #242a31;
    border: 1px solid #3c4652;
    border-radius: 6px;
  }

  .order-search-toolbar {
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }

  .order-search-builder {
    padding: 12px;
    margin-top: 12px;
  }

  .order-search-filter-row {
    display: grid;
    grid-template-columns: minmax(220px, 1.4fr) minmax(150px, .85fr) minmax(220px, 1fr) minmax(180px, .8fr) 40px;
    gap: 8px;
    align-items: center;
    padding: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, .08);
  }

  .order-search-filter-row:last-child {
    border-bottom: 0;
  }

  .order-search-filter-row .form-control,
  .order-search-toolbar .form-control {
    background-color: #1f252c;
    border-color: #4b5663;
    color: #f4f6f9;
  }

  .order-search-filter-row .form-control:focus,
  .order-search-toolbar .form-control:focus {
    background-color: #202932;
    border-color: #17a2b8;
    color: #fff;
    box-shadow: 0 0 0 .12rem rgba(23, 162, 184, .2);
  }

  .order-search-results-head {
    padding: 10px 12px;
    margin: 14px 0 8px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }

  #orderSearchTable td {
    vertical-align: middle;
  }

  .order-search-muted {
    color: #9ea7b3;
  }

  .order-search-summary {
    max-width: 340px;
    white-space: normal;
  }

  .order-search-items {
    max-width: 420px;
    white-space: normal;
  }

  .order-search-alert {
    display: none;
    margin: 12px 0 0;
  }

  .order-search-field-help {
    margin-top: 10px;
    padding: 10px 12px;
    background: #1f252c;
    border: 1px solid #3c4652;
    border-radius: 6px;
  }

  .order-search-help-title {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 4px;
  }

  .order-search-help-copy {
    color: #c8d0d9;
    margin-bottom: 8px;
  }

  .order-search-help-line {
    color: #aeb8c4;
    font-size: 12px;
    margin-top: 6px;
  }

  .order-search-help-chip {
    display: inline-block;
    margin: 3px 4px 0 0;
    padding: 2px 6px;
    background: #303842;
    border: 1px solid #4b5663;
    border-radius: 4px;
    color: #dbe2ea;
    font-size: 12px;
  }

  .order-search-help-actions {
    position: relative;
    margin-top: 10px;
  }

  .order-search-values-popover {
    display: none;
    position: absolute;
    z-index: 1060;
    left: 0;
    top: 100%;
    width: 560px;
    max-width: calc(100vw - 48px);
    margin-top: 6px;
    background: #20262e;
    border: 1px solid #4b5663;
    border-radius: 6px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, .35);
  }

  .order-search-values-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 10px;
    border-bottom: 1px solid #3c4652;
  }

  .order-search-values-body {
    padding: 10px;
  }

  .order-search-values-body .form-control {
    background-color: #1f252c;
    border-color: #4b5663;
    color: #f4f6f9;
  }

  .order-search-values-list {
    max-height: 300px;
    overflow: auto;
    margin-top: 8px;
    border: 1px solid #3c4652;
    border-radius: 6px;
  }

  .order-search-values-row {
    display: block;
    width: 100%;
    padding: 8px 10px;
    border: 0;
    border-top: 1px solid rgba(255, 255, 255, .08);
    background: transparent;
    color: #e4e6eb;
    text-align: left;
  }

  .order-search-values-row:first-child {
    border-top: 0;
  }

  .order-search-values-row:hover,
  .order-search-values-row:focus {
    background: #2d3640;
    outline: none;
  }

  .order-search-values-main {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
  }

  .order-search-values-value {
    font-weight: 600;
    word-break: break-word;
  }

  .order-search-values-path {
    font-family: Consolas, Monaco, monospace;
    font-size: 12px;
    word-break: break-word;
  }

  .order-search-values-sub {
    margin-top: 4px;
    color: #aeb8c4;
    font-size: 12px;
    word-break: break-word;
  }

  .order-search-json-modal .modal-content {
    background: #242a31;
    color: #e4e6eb;
    border: 1px solid #3c4652;
  }

  .order-search-json-modal .modal-header,
  .order-search-json-modal .modal-footer {
    border-color: #3c4652;
  }

  .order-search-json-modal .form-control {
    background-color: #1f252c;
    border-color: #4b5663;
    color: #f4f6f9;
  }

  .order-search-json-modal .close {
    color: #f4f6f9;
    text-shadow: none;
  }

  .order-search-json-table-wrap {
    max-height: 58vh;
    overflow: auto;
    border: 1px solid #3c4652;
    border-radius: 6px;
  }

  #orderSearchJsonExplorerTable td,
  #orderSearchJsonExplorerTable th {
    vertical-align: top;
  }

  .order-search-json-path {
    color: #f7f9fb;
    font-family: Consolas, Monaco, monospace;
    font-size: 12px;
    word-break: break-word;
  }

  .order-search-json-examples {
    max-width: 520px;
    white-space: normal;
  }

  #orderSearchTable_wrapper .order-search-dt-toolbar {
    margin: 10px 0 8px;
  }

  #orderSearchTable_wrapper .dt-buttons {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }

  #orderSearchTable_wrapper .dt-buttons .btn {
    margin: 0;
  }

  #orderSearchTable_wrapper .dataTables_filter {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    margin: 0;
    text-align: right !important;
  }

  #orderSearchTable_wrapper .dataTables_filter label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    white-space: nowrap;
  }

  #orderSearchTable_wrapper .dataTables_filter input {
    width: 260px;
    max-width: 100%;
    margin-left: 0;
    background-color: #1f252c;
    border-color: #4b5663;
    color: #f4f6f9;
  }
  @media (max-width: 991.98px) {
    .order-search-filter-row {
      grid-template-columns: 1fr;
    }

    .order-search-filter-row .btn {
      width: 100%;
    }

    .order-search-values-popover {
      width: calc(100vw - 36px);
    }
  }
</style>

<div class="order-search-page">
  <div class="order-search-toolbar">
    <div>
      <h4 class="mb-0">Universal Order Search</h4>
    </div>
    <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
      <select id="orderSearchMode" class="form-control form-control-sm" style="width:150px;">
        <option value="AND">All filters</option>
        <option value="OR">Any filter</option>
      </select>
      <select id="orderSearchLimit" class="form-control form-control-sm" style="width:120px;">
        <option value="100">100 rows</option>
        <option value="300" selected>300 rows</option>
        <option value="500">500 rows</option>
        <option value="1000">1000 rows</option>
      </select>
      <button type="button" class="btn btn-sm btn-outline-info" id="orderSearchAddFilter" title="Add filter">
        <i class="fas fa-plus"></i>
      </button>
      <button type="button" class="btn btn-sm btn-info" id="orderSearchRun">
        <i class="fas fa-search mr-1"></i>Search
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="orderSearchReset" title="Reset filters">
        <i class="fas fa-undo"></i>
      </button>
      <button type="button" class="btn btn-sm btn-outline-light" id="orderSearchJsonExplorerOpen" title="Browse JSON fields">
        <i class="fas fa-code mr-1"></i>JSON Fields
      </button>
    </div>
  </div>

  <div class="order-search-builder">
    <div id="orderSearchFilters"></div>
    <div class="order-search-field-help" id="orderSearchFieldHelp" style="display:none;"></div>
  </div>

  <div class="alert order-search-alert" id="orderSearchAlert"></div>

  <div class="order-search-results-head">
    <div>
      <strong>Results</strong>
      <span class="small order-search-muted ml-2" id="orderSearchResultMeta">Ready</span>
    </div>
    <div class="small order-search-muted" id="orderSearchLoading" style="display:none;">
      <span class="spinner-border spinner-border-sm mr-1"></span>Searching
    </div>
  </div>

  <table id="orderSearchTable" class="table table-sm table-striped table-hover table-dark nowrap" style="width:100%;">
    <thead>
      <tr>
        <th>Order</th>
        <th>Source</th>
        <th>Status</th>
        <th>Date</th>
        <th>Customer</th>
        <th>Country</th>
        <th>Items</th>
        <th>Matched filters</th>
        <th></th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>

  <div class="modal fade order-search-json-modal" id="orderSearchJsonExplorerModal" tabindex="-1" role="dialog" aria-labelledby="orderSearchJsonExplorerTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="orderSearchJsonExplorerTitle">JSON Field Explorer</h5>
            <div class="small order-search-muted">Shows real JSON keys found in orders and order items.</div>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="d-flex align-items-center flex-wrap mb-3" style="gap:8px;">
            <select id="orderSearchJsonExplorerSource" class="form-control form-control-sm" style="width:230px;">
              <option value="all">All JSON sources</option>
            </select>
            <input type="text" id="orderSearchJsonExplorerQuery" class="form-control form-control-sm" style="width:260px;" placeholder="Filter by key or example value">
            <button type="button" class="btn btn-sm btn-outline-info" id="orderSearchJsonExplorerRefresh">
              <i class="fas fa-sync-alt mr-1"></i>Refresh
            </button>
            <span class="small order-search-muted" id="orderSearchJsonExplorerMeta">Ready</span>
          </div>
          <div class="order-search-json-table-wrap">
            <table class="table table-sm table-dark table-hover mb-0" id="orderSearchJsonExplorerTable">
              <thead>
                <tr>
                  <th style="width:190px;">Source</th>
                  <th>JSON path</th>
                  <th style="width:90px;">Count</th>
                  <th>Examples</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td colspan="4" class="text-center order-search-muted py-4">Use refresh to load JSON fields.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const fields = <?= $orderSearchFieldsJson ?: '[]' ?>;
  const operatorLabels = <?= $orderSearchOperatorsJson ?: '{}' ?>;
  const endpoint = 'scripts/orders/search_builder.php';
  const fieldsByKey = {};
  fields.forEach(function (field) { fieldsByKey[field.key] = field; });

  let filterSerial = 0;
  let searchTable = null;
  let activeFilterId = null;
  let jsonExplorerLoaded = false;
  let jsonExplorerTimer = null;
  let fieldValuesTimer = null;
  const suggestionTimers = {};

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function fieldOptions(selected) {
    const groups = [];
    const byGroup = {};
    fields.forEach(function (field) {
      if (!byGroup[field.group]) {
        byGroup[field.group] = [];
        groups.push(field.group);
      }
      byGroup[field.group].push(field);
    });

    const orderedGroups = groups.filter(function (group) { return group !== 'Advanced JSON'; });
    if (groups.indexOf('Advanced JSON') !== -1) orderedGroups.push('Advanced JSON');

    return orderedGroups.map(function (group) {
      const options = byGroup[group].map(function (field) {
        return '<option value="' + escapeHtml(field.key) + '"' + (field.key === selected ? ' selected' : '') + '>' + escapeHtml(field.label) + '</option>';
      }).join('');
      return '<optgroup label="' + escapeHtml(group) + '">' + options + '</optgroup>';
    }).join('');
  }

  function operatorOptions(field, selected) {
    const ops = field && field.operators && field.operators.length ? field.operators : ['contains'];
    if (ops.indexOf(selected) === -1) selected = ops[0];
    return ops.map(function (op) {
      return '<option value="' + escapeHtml(op) + '"' + (op === selected ? ' selected' : '') + '>' + escapeHtml(operatorLabels[op] || op) + '</option>';
    }).join('');
  }

  function operatorNeedsValue(operator) {
    return ['is_empty', 'is_not_empty'].indexOf(operator) === -1;
  }

  function operatorNeedsSecondValue(operator) {
    return operator === 'between';
  }

  function inputTypeForField(field) {
    if (!field) return 'text';
    if (field.type === 'date') return 'date';
    if (field.type === 'number') return 'number';
    return 'text';
  }

  function firstFieldKey() {
    return fields.length ? fields[0].key : 'global_text';
  }

  function getRowField($row) {
    const fieldKey = $row.find('.order-search-field').val() || firstFieldKey();
    return fieldsByKey[fieldKey] || fieldsByKey[firstFieldKey()];
  }

  function activeFilterRow() {
    if (activeFilterId !== null) {
      const $row = $('#orderSearchFilters .order-search-filter-row').filter(function () {
        return String($(this).data('filterId')) === String(activeFilterId);
      });
      if ($row.length) return $row.eq(0);
    }
    return $('#orderSearchFilters .order-search-filter-row').first();
  }

  function renderFieldHelp(field) {
    const $help = $('#orderSearchFieldHelp');
    if (!field) {
      $help.hide().empty();
      return;
    }

    const hasHelp = !!field.help;
    const sources = field.sources || [];
    const isJsonLike = !!field.advanced || /json/i.test(field.group || '') || /json/i.test(field.label || '');
    const showHelp = hasHelp || isJsonLike;
    if (!showHelp) {
      $help.hide().empty();
      return;
    }

    const badges = [];
    if (field.group) badges.push('<span class="badge badge-secondary">' + escapeHtml(field.group) + '</span>');
    if (field.advanced) badges.push('<span class="badge badge-warning">Advanced raw JSON</span>');

    let html = '<div class="order-search-help-title"><strong>' + escapeHtml(field.label) + '</strong>' + badges.join('') + '</div>';
    if (field.help) {
      html += '<div class="order-search-help-copy">' + escapeHtml(field.help) + '</div>';
    } else if (field.advanced) {
      html += '<div class="order-search-help-copy">Raw JSON text search. Use it when a friendly field does not cover the value you need.</div>';
    } else {
      html += '<div class="order-search-help-copy">JSON-backed field built from order item option data.</div>';
    }

    if (sources.length) {
      html += '<div class="order-search-help-line">Searches in</div><div>';
      html += sources.slice(0, 8).map(function (source) {
        return '<span class="order-search-help-chip">' + escapeHtml(source) + '</span>';
      }).join('');
      if (sources.length > 8) {
        html += '<span class="order-search-help-chip">+' + (sources.length - 8) + ' more</span>';
      }
      html += '</div>';
    }

    if (isJsonLike) {
      html += '<div class="order-search-help-actions">' +
        '<button type="button" class="btn btn-sm btn-outline-info order-search-values-open" data-field-key="' + escapeHtml(field.key) + '"><i class="fas fa-list-ul mr-1"></i>Browse values</button>' +
        '<span class="small order-search-muted ml-2">Real database values for this field</span>' +
        '<div class="order-search-values-popover" id="orderSearchValuesPopover" data-field-key="' + escapeHtml(field.key) + '">' +
          '<div class="order-search-values-head">' +
            '<strong>Values for ' + escapeHtml(field.label) + '</strong>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary order-search-values-close" title="Close"><i class="fas fa-times"></i></button>' +
          '</div>' +
          '<div class="order-search-values-body">' +
            '<input type="text" class="form-control form-control-sm" id="orderSearchValuesQuery" placeholder="Filter values">' +
            '<div class="small order-search-muted mt-2" id="orderSearchValuesMeta">Ready</div>' +
            '<div class="order-search-values-list" id="orderSearchValuesList"><div class="text-center order-search-muted py-3">Loading...</div></div>' +
          '</div>' +
        '</div>' +
      '</div>';
    }

    $help.html(html).show();
  }
  function updateActiveFieldHelp($row) {
    const $target = $row && $row.length ? $row : activeFilterRow();
    renderFieldHelp($target.length ? getRowField($target) : null);
  }

  function setActiveFilterRow($row) {
    if (!$row || !$row.length) return;
    activeFilterId = $row.data('filterId');
    updateActiveFieldHelp($row);
  }

  function syncFilterRow($row) {
    const field = getRowField($row);
    const $operator = $row.find('.order-search-operator');
    const currentOperator = $operator.val() || (field.operators && field.operators[0]) || 'contains';
    $operator.html(operatorOptions(field, currentOperator));

    const operator = $operator.val();
    const needsValue = operatorNeedsValue(operator);
    const needsSecond = operatorNeedsSecondValue(operator);
    const type = inputTypeForField(field);
    const placeholder = field.placeholder || '';
    const listId = 'orderSearchValueList' + $row.data('filterId');

    $row.find('.order-search-value').attr('type', type).attr('placeholder', placeholder);
    $row.find('.order-search-value2').attr('type', type).attr('placeholder', needsSecond ? 'To' : '');
    $row.find('.order-search-value-wrap').toggle(needsValue);
    $row.find('.order-search-value2-wrap').toggle(needsSecond);

    if (field.suggest && type === 'text') {
      $row.find('.order-search-value').attr('list', listId);
    } else {
      $row.find('.order-search-value').removeAttr('list');
      $row.find('datalist').empty();
    }

    if (String($row.data('filterId')) === String(activeFilterId)) {
      updateActiveFieldHelp($row);
    }
  }

  function addFilter(fieldKey, operator, value) {
    const id = ++filterSerial;
    fieldKey = fieldKey || firstFieldKey();
    const field = fieldsByKey[fieldKey] || fieldsByKey[firstFieldKey()];
    operator = operator || (field.operators && field.operators[0]) || 'contains';

    const html = '' +
      '<div class="order-search-filter-row" data-filter-id="' + id + '">' +
        '<select class="form-control form-control-sm order-search-field">' + fieldOptions(fieldKey) + '</select>' +
        '<select class="form-control form-control-sm order-search-operator">' + operatorOptions(field, operator) + '</select>' +
        '<div class="order-search-value-wrap"><input class="form-control form-control-sm order-search-value" value="' + escapeHtml(value || '') + '"><datalist id="orderSearchValueList' + id + '"></datalist></div>' +
        '<div class="order-search-value2-wrap" style="display:none;"><input class="form-control form-control-sm order-search-value2"></div>' +
        '<button type="button" class="btn btn-sm btn-outline-danger order-search-remove" title="Remove filter"><i class="fas fa-times"></i></button>' +
      '</div>';
    const $row = $(html);
    $('#orderSearchFilters').append($row);
    syncFilterRow($row);
    setActiveFilterRow($row);
    $row.find('.order-search-value').trigger('focus');
  }

  function collectFilters() {
    const filters = [];
    $('#orderSearchFilters .order-search-filter-row').each(function () {
      const $row = $(this);
      filters.push({
        field: $row.find('.order-search-field').val(),
        operator: $row.find('.order-search-operator').val(),
        value: $row.find('.order-search-value').val(),
        value2: $row.find('.order-search-value2').val()
      });
    });
    return filters;
  }

  function showAlert(type, message) {
    const $alert = $('#orderSearchAlert');
    if (!message) {
      $alert.hide().removeClass('alert-danger alert-warning alert-info alert-success').text('');
      return;
    }
    $alert.removeClass('alert-danger alert-warning alert-info alert-success')
      .addClass('alert-' + type)
      .text(message)
      .show();
  }

  function loadSuggestions($row) {
    const fieldKey = $row.find('.order-search-field').val();
    const field = fieldsByKey[fieldKey];
    if (!field || !field.suggest || inputTypeForField(field) !== 'text') return;

    const q = $row.find('.order-search-value').val() || '';
    const listId = 'orderSearchValueList' + $row.data('filterId');
    $.getJSON(endpoint, { action: 'suggest', field: fieldKey, q: q })
      .done(function (resp) {
        if (!resp || !resp.ok) return;
        const options = (resp.items || []).map(function (item) {
          return '<option value="' + escapeHtml(item) + '"></option>';
        }).join('');
        $('#' + listId).html(options);
      });
  }

  function scheduleSuggestions($row) {
    const id = $row.data('filterId');
    clearTimeout(suggestionTimers[id]);
    suggestionTimers[id] = setTimeout(function () { loadSuggestions($row); }, 180);
  }

  function jsonExplorerSourceOptions(sources, selected) {
    let html = '<option value="all"' + (selected === 'all' ? ' selected' : '') + '>All JSON sources</option>';
    Object.keys(sources || {}).forEach(function (key) {
      html += '<option value="' + escapeHtml(key) + '"' + (key === selected ? ' selected' : '') + '>' + escapeHtml(sources[key]) + '</option>';
    });
    return html;
  }

  function renderJsonExplorerRows(rows) {
    if (!rows || !rows.length) {
      return '<tr><td colspan="4" class="text-center order-search-muted py-4">No JSON fields matched.</td></tr>';
    }
    return rows.map(function (row) {
      const examples = (row.examples || []).map(function (example) {
        return '<span class="order-search-help-chip">' + escapeHtml(example) + '</span>';
      }).join('');
      return '<tr>' +
        '<td>' + escapeHtml(row.source_label || row.source || '') + '</td>' +
        '<td><div class="order-search-json-path">' + escapeHtml(row.path || '') + '</div></td>' +
        '<td>' + escapeHtml(row.count || 0) + '</td>' +
        '<td class="order-search-json-examples">' + (examples || '<span class="order-search-muted">No examples</span>') + '</td>' +
      '</tr>';
    }).join('');
  }

  function loadJsonExplorer() {
    const $meta = $('#orderSearchJsonExplorerMeta');
    $meta.html('<span class="spinner-border spinner-border-sm mr-1"></span>Loading');
    $.getJSON(endpoint, {
      action: 'json_explorer',
      source: $('#orderSearchJsonExplorerSource').val() || 'all',
      q: $('#orderSearchJsonExplorerQuery').val() || '',
      limit: 400
    }).done(function (resp) {
      if (!resp || !resp.ok) {
        $meta.text(resp && resp.error ? resp.error : 'JSON explorer failed.');
        return;
      }
      const selected = $('#orderSearchJsonExplorerSource').val() || 'all';
      $('#orderSearchJsonExplorerSource').html(jsonExplorerSourceOptions(resp.sources || {}, selected));
      $('#orderSearchJsonExplorerTable tbody').html(renderJsonExplorerRows(resp.rows || []));
      let meta = String(resp.total || 0) + ' fields';
      if (resp.limited) meta += ', showing ' + (resp.rows || []).length;
      $meta.text(meta);
      jsonExplorerLoaded = true;
    }).fail(function (xhr) {
      const resp = xhr.responseJSON || null;
      $meta.text(resp && resp.error ? resp.error : 'JSON explorer request failed.');
    });
  }

  function scheduleJsonExplorer() {
    clearTimeout(jsonExplorerTimer);
    jsonExplorerTimer = setTimeout(loadJsonExplorer, 250);
  }

  function renderFieldValueRows(mode, rows) {
    if (!rows || !rows.length) {
      return '<div class="text-center order-search-muted py-3">No values found.</div>';
    }

    return rows.map(function (row) {
      if (mode === 'paths') {
        const examples = (row.examples || []).slice(0, 5).map(function (example) {
          return '<span class="order-search-help-chip">' + escapeHtml(example) + '</span>';
        }).join('');
        return '<button type="button" class="order-search-values-row order-search-value-pick" data-value="' + escapeHtml(row.path || '') + '">' +
          '<div class="order-search-values-main">' +
            '<div class="order-search-values-path">' + escapeHtml(row.path || '') + '</div>' +
            '<span class="badge badge-secondary">' + escapeHtml(row.count || 0) + '</span>' +
          '</div>' +
          '<div class="order-search-values-sub">' + (examples || 'No examples') + '</div>' +
        '</button>';
      }

      const sources = (row.sources || []).slice(0, 3).map(function (source) {
        return '<span class="order-search-help-chip">' + escapeHtml(source) + '</span>';
      }).join('');
      return '<button type="button" class="order-search-values-row order-search-value-pick" data-value="' + escapeHtml(row.value || '') + '">' +
        '<div class="order-search-values-main">' +
          '<div class="order-search-values-value">' + escapeHtml(row.value || '') + '</div>' +
          '<span class="badge badge-info">' + escapeHtml(row.count || 0) + '</span>' +
        '</div>' +
        '<div class="order-search-values-sub">' + (sources || 'JSON value') + '</div>' +
      '</button>';
    }).join('');
  }

  function loadFieldValues() {
    const $popover = $('#orderSearchValuesPopover');
    const fieldKey = $popover.attr('data-field-key') || '';
    if (!fieldKey) return;

    $('#orderSearchValuesMeta').html('<span class="spinner-border spinner-border-sm mr-1"></span>Loading');
    $.getJSON(endpoint, {
      action: 'field_values',
      field: fieldKey,
      q: $('#orderSearchValuesQuery').val() || '',
      limit: 120
    }).done(function (resp) {
      if (!resp || !resp.ok) {
        $('#orderSearchValuesMeta').text(resp && resp.error ? resp.error : 'Values failed to load.');
        return;
      }
      const noun = resp.mode === 'paths' ? 'JSON paths' : 'values';
      let meta = String(resp.total || 0) + ' ' + noun;
      if (resp.limited) meta += ', showing ' + (resp.rows || []).length;
      $('#orderSearchValuesMeta').text(meta + '. Click a row to use it in the active filter.');
      $('#orderSearchValuesList').html(renderFieldValueRows(resp.mode || 'values', resp.rows || []));
    }).fail(function (xhr) {
      const resp = xhr.responseJSON || null;
      $('#orderSearchValuesMeta').text(resp && resp.error ? resp.error : 'Values request failed.');
    });
  }

  function openFieldValues(fieldKey) {
    const $popover = $('#orderSearchValuesPopover');
    if (!$popover.length) return;
    const isSameOpen = $popover.is(':visible') && String($popover.attr('data-field-key')) === String(fieldKey);
    if (isSameOpen) {
      $popover.hide();
      return;
    }
    $popover.attr('data-field-key', fieldKey).show();
    $('#orderSearchValuesQuery').val('').trigger('focus');
    $('#orderSearchValuesList').html('<div class="text-center order-search-muted py-3">Loading...</div>');
    loadFieldValues();
  }

  function scheduleFieldValues() {
    clearTimeout(fieldValuesTimer);
    fieldValuesTimer = setTimeout(loadFieldValues, 250);
  }

  function applyFieldValue(value) {
    const $row = activeFilterRow();
    if (!$row.length || value === '') return;
    $row.find('.order-search-value').val(value).trigger('input').focus();
    $('#orderSearchValuesPopover').hide();
    scheduleSuggestions($row);
  }

  function renderOrder(data, type, row) {
    const orderNumber = row.order_number || ('#' + row.id);
    if (type !== 'display') return orderNumber;
    const ext = row.external_order_id ? '<div class="small order-search-muted">' + escapeHtml(row.external_order_id) + '</div>' : '';
    return '<strong>' + escapeHtml(orderNumber) + '</strong>' + ext;
  }

  function renderStatus(data, type, row) {
    const status = data || '';
    if (type !== 'display') return status;
    return status ? '<span class="badge badge-secondary">' + escapeHtml(status) + '</span>' : '';
  }

  function renderCustomer(data, type, row) {
    const name = row.customer_name || '';
    if (type !== 'display') return name;
    const email = row.customer_email ? '<div class="small order-search-muted">' + escapeHtml(row.customer_email) + '</div>' : '';
    return escapeHtml(name) + email;
  }

  function renderCountry(data, type, row) {
    const country = row.country_code || '';
    if (type !== 'display') return country;
    const city = row.city ? '<div class="small order-search-muted">' + escapeHtml(row.city) + '</div>' : '';
    return (country ? '<span class="badge badge-info">' + escapeHtml(country) + '</span>' : '') + city;
  }

  function renderActions(data, type, row) {
    if (type !== 'display') return data || '';
    return '<a class="btn btn-sm btn-outline-info" href="' + escapeHtml(row.detail_url || '#') + '" title="Open in Orders"><i class="fas fa-external-link-alt"></i></a>';
  }

  function runSearch() {
    showAlert('', '');
    $('#orderSearchLoading').show();
    $('#orderSearchRun').prop('disabled', true);

    $.ajax({
      url: endpoint,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'search',
        mode: $('#orderSearchMode').val(),
        limit: $('#orderSearchLimit').val(),
        filters: JSON.stringify(collectFilters())
      }
    }).done(function (resp) {
      if (!resp || !resp.ok) {
        showAlert('danger', resp && resp.error ? resp.error : 'Search failed.');
        return;
      }
      searchTable.clear().rows.add(resp.data || []).draw();
      const shown = (resp.data || []).length;
      let meta = String(resp.total || 0) + ' found';
      if (resp.limited) meta += ', showing ' + shown + ' (limit ' + resp.limit + ')';
      if (resp.message) meta = resp.message;
      $('#orderSearchResultMeta').text(meta);
      if (resp.message) showAlert('info', resp.message);
    }).fail(function (xhr) {
      const resp = xhr.responseJSON || null;
      showAlert('danger', resp && resp.error ? resp.error : 'Search request failed.');
    }).always(function () {
      $('#orderSearchLoading').hide();
      $('#orderSearchRun').prop('disabled', false);
    });
  }

  $(function () {
    searchTable = $('#orderSearchTable').DataTable({
      data: [],
      responsive: true,
      autoWidth: false,
      pageLength: 50,
      order: [],
      lengthChange: true,
      dom: "<'row order-search-dt-toolbar align-items-center'<'col-sm-12 col-md-6 mb-2 mb-md-0'B><'col-sm-12 col-md-6'f>>rt<'row align-items-center mt-2'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
      buttons: ['copy', 'csv', 'excel', 'pdf', 'print'],
      columns: [
        { data: 'order_number', render: renderOrder },
        { data: 'source_code', defaultContent: '' },
        { data: 'status', render: renderStatus },
        { data: 'order_date', defaultContent: '' },
        { data: 'customer_name', render: renderCustomer },
        { data: 'country_code', render: renderCountry },
        { data: 'item_summary', className: 'order-search-items', defaultContent: '' },
        { data: 'match_summary', className: 'order-search-summary', defaultContent: '' },
        { data: 'detail_url', orderable: false, searchable: false, render: renderActions }
      ]
    });

    addFilter('global_text');

    $('#orderSearchAddFilter').on('click', function () { addFilter(); });
    $('#orderSearchRun').on('click', runSearch);
    $('#orderSearchJsonExplorerOpen').on('click', function () {
      $('#orderSearchJsonExplorerModal').modal('show');
      if (!jsonExplorerLoaded) {
        loadJsonExplorer();
      }
    });
    $('#orderSearchJsonExplorerRefresh').on('click', loadJsonExplorer);
    $('#orderSearchJsonExplorerSource').on('change', loadJsonExplorer);
    $('#orderSearchJsonExplorerQuery')
      .on('input', scheduleJsonExplorer)
      .on('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          loadJsonExplorer();
        }
      });
    $('#orderSearchFieldHelp')
      .on('click', '.order-search-values-open', function (e) {
        e.preventDefault();
        openFieldValues($(this).attr('data-field-key') || '');
      })
      .on('click', '.order-search-values-close', function (e) {
        e.preventDefault();
        $('#orderSearchValuesPopover').hide();
      })
      .on('input', '#orderSearchValuesQuery', scheduleFieldValues)
      .on('keydown', '#orderSearchValuesQuery', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          loadFieldValues();
        }
      })
      .on('click', '.order-search-value-pick', function (e) {
        e.preventDefault();
        applyFieldValue($(this).attr('data-value') || '');
      });
    $(document).on('mousedown', function (e) {
      if (!$(e.target).closest('#orderSearchFieldHelp .order-search-help-actions').length) {
        $('#orderSearchValuesPopover').hide();
      }
    });
    $('#orderSearchReset').on('click', function () {
      $('#orderSearchFilters').empty();
      addFilter('global_text');
      searchTable.clear().draw();
      $('#orderSearchResultMeta').text('Ready');
      showAlert('', '');
    });

    $('#orderSearchFilters')
      .on('click focusin', '.order-search-filter-row, .order-search-filter-row .form-control', function () {
        setActiveFilterRow($(this).closest('.order-search-filter-row'));
      })
      .on('change', '.order-search-field, .order-search-operator', function () {
        const $row = $(this).closest('.order-search-filter-row');
        setActiveFilterRow($row);
        syncFilterRow($row);
        scheduleSuggestions($row);
      })
      .on('focus input', '.order-search-value', function () {
        const $row = $(this).closest('.order-search-filter-row');
        setActiveFilterRow($row);
        scheduleSuggestions($row);
      })
      .on('click', '.order-search-remove', function () {
        const $rows = $('#orderSearchFilters .order-search-filter-row');
        if ($rows.length <= 1) {
          $(this).closest('.order-search-filter-row').find('.order-search-value, .order-search-value2').val('');
          return;
        }
        $(this).closest('.order-search-filter-row').remove();
        updateActiveFieldHelp();
      })
      .on('keydown', '.order-search-value, .order-search-value2', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          runSearch();
        }
      });
  });
})();
</script>