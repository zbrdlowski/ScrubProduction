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
    </div>
  </div>

  <div class="order-search-builder">
    <div id="orderSearchFilters"></div>
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

    return groups.map(function (group) {
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

  function syncFilterRow($row) {
    const fieldKey = $row.find('.order-search-field').val() || firstFieldKey();
    const field = fieldsByKey[fieldKey] || fieldsByKey[firstFieldKey()];
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
    $('#orderSearchReset').on('click', function () {
      $('#orderSearchFilters').empty();
      addFilter('global_text');
      searchTable.clear().draw();
      $('#orderSearchResultMeta').text('Ready');
      showAlert('', '');
    });

    $('#orderSearchFilters')
      .on('change', '.order-search-field, .order-search-operator', function () {
        const $row = $(this).closest('.order-search-filter-row');
        syncFilterRow($row);
        scheduleSuggestions($row);
      })
      .on('focus input', '.order-search-value', function () {
        scheduleSuggestions($(this).closest('.order-search-filter-row'));
      })
      .on('click', '.order-search-remove', function () {
        const $rows = $('#orderSearchFilters .order-search-filter-row');
        if ($rows.length <= 1) {
          $(this).closest('.order-search-filter-row').find('.order-search-value, .order-search-value2').val('');
          return;
        }
        $(this).closest('.order-search-filter-row').remove();
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