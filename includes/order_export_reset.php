<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/admin/order_export_reset_lib.php';

if (!orderExportResetCurrentUserAllowed()) {
  ?>
  <div class="alert alert-danger">
    <i class="fas fa-lock mr-1"></i> No permission for this tool.
  </div>
  <?php
  return;
}
?>

<style>
  .order-reset-tool {
    width: 100%;
    max-width: 980px;
    margin-left: auto;
    margin-right: auto;
  }

  .order-reset-panel {
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 6px;
    background: rgba(255, 255, 255, .03);
  }

  .order-reset-muted {
    color: #adb5bd;
  }

  .order-reset-kv {
    display: grid;
    grid-template-columns: minmax(120px, 180px) 1fr;
    gap: 8px 14px;
  }

  .order-reset-kv dt {
    margin: 0;
    color: #adb5bd;
    font-weight: 600;
  }

  .order-reset-kv dd {
    margin: 0;
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .order-reset-counts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 8px;
  }

  .order-reset-count {
    border: 1px solid rgba(255, 255, 255, .10);
    border-radius: 6px;
    padding: 8px 10px;
    background: rgba(0, 0, 0, .12);
  }

  .order-reset-count strong {
    display: block;
    font-size: 1.05rem;
  }

  @media (max-width: 575.98px) {
    .order-reset-kv {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="order-reset-tool">
  <div class="order-reset-panel p-3 p-md-4">
    <form id="orderExportResetLookupForm" autocomplete="off">
      <div class="form-group mb-2">
        <label for="orderExportResetNumber">Order number</label>
        <div class="input-group">
          <input
            type="text"
            class="form-control"
            id="orderExportResetNumber"
            name="order_number"
            placeholder="SO21474, 2026001885, 04-15179-85762"
            required
          >
          <span class="input-group-append">
            <button type="submit" class="btn btn-primary" id="orderExportResetLookupBtn" title="Load order">
              <i class="fas fa-search mr-1"></i> Load
            </button>
          </span>
        </div>
      </div>
      <div class="order-reset-muted small">
        Supports Shoptet, MXLocker, eBay and Custom Orders. Custom Orders also unlocks the export button.
      </div>
    </form>

    <div id="orderExportResetResult" class="mt-4"></div>
  </div>
</div>

<script>
  (function () {
    var endpoint = 'scripts/admin/order_export_reset_ajax.php';
    var $form = $('#orderExportResetLookupForm');
    var $input = $('#orderExportResetNumber');
    var $result = $('#orderExportResetResult');
    var $lookupBtn = $('#orderExportResetLookupBtn');
    var currentOrderNumber = '';

    function escapeHtml(value) {
      return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function sourceLabel(code) {
      var labels = {
        CUSTOM: 'Custom Orders',
        SHOPTET: 'Shoptet',
        MX_LOCKER: 'MXLocker',
        EBAY: 'eBay'
      };
      return labels[code] || code || '';
    }

    function money(total, currency) {
      if (total === null || total === undefined || total === '') {
        return '-';
      }
      return escapeHtml(total) + (currency ? ' ' + escapeHtml(currency) : '');
    }

    function setLoading(isLoading) {
      $lookupBtn.prop('disabled', isLoading);
      $lookupBtn.html(isLoading ? '<i class="fas fa-circle-notch fa-spin mr-1"></i> Loading' : '<i class="fas fa-search mr-1"></i> Load');
    }

    function renderAlert(type, icon, message) {
      $result.html(
        '<div class="alert alert-' + type + ' mb-0">' +
          '<i class="fas ' + icon + ' mr-1"></i>' + escapeHtml(message) +
        '</div>'
      );
    }

    function renderCounts(counts) {
      var labels = {
        order_items: 'Items',
        order_item_statuses: 'Item statuses',
        order_item_categories: 'Item categories',
        order_item_assignments: 'Item assignments',
        order_addresses: 'Addresses',
        order_activity: 'Activity',
        order_assignments: 'Assignments',
        order_categories: 'Categories',
        order_financial_adjustments: 'Financial adjustments',
        order_invoices: 'Invoices',
        order_photos: 'Photos',
        order_production_notes: 'Production notes',
        order_status_history: 'Status history',
        order_tracking_numbers: 'Tracking numbers',
        shipments: 'Shipments'
      };
      var html = '<div class="order-reset-counts mt-3">';

      Object.keys(labels).forEach(function (key) {
        html += '<div class="order-reset-count"><strong>' + escapeHtml(counts[key] || 0) + '</strong><span>' + labels[key] + '</span></div>';
      });

      return html + '</div>';
    }

    function renderLookup(data) {
      if (!data.found) {
        var custom = data.custom_order;
        var extra = '';
        if (custom) {
          extra =
            '<div class="mt-3 order-reset-kv">' +
              '<dt>Custom order</dt><dd>#' + escapeHtml(custom.id) + ' / ' + escapeHtml(custom.official_order_number || custom.internal_code || '') + '</dd>' +
              '<dt>Status</dt><dd>' + escapeHtml(custom.status || '') + '</dd>' +
              '<dt>Production link</dt><dd>' + escapeHtml(custom.production_order_id || 'not linked') + '</dd>' +
            '</div>';
        }

        $result.html(
          '<div class="alert alert-warning mb-0"><i class="fas fa-info-circle mr-1"></i>' + escapeHtml(data.message || 'Order not found.') + '</div>' +
          extra
        );
        return;
      }

      if (data.ambiguous) {
        var rows = (data.orders || []).map(function (order) {
          return '<tr><td>#' + escapeHtml(order.id) + '</td><td>' + escapeHtml(sourceLabel(order.source_code)) + '</td><td>' + escapeHtml(order.external_order_id || '') + '</td><td>' + escapeHtml(order.status || '') + '</td></tr>';
        }).join('');
        $result.html(
          '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-1"></i>' + escapeHtml(data.message) + '</div>' +
          '<div class="table-responsive"><table class="table table-sm table-dark table-striped mb-0"><thead><tr><th>ID</th><th>Source</th><th>External ID</th><th>Status</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
        );
        return;
      }

      var order = data.order || {};
      var custom = data.custom_order;
      currentOrderNumber = order.order_number || '';
      var customHtml = '';
      if (custom) {
        customHtml =
          '<h5 class="mt-4 mb-2">Custom Orders link</h5>' +
          '<dl class="order-reset-kv">' +
            '<dt>Custom order</dt><dd>#' + escapeHtml(custom.id) + ' / ' + escapeHtml(custom.official_order_number || custom.internal_code || '') + '</dd>' +
            '<dt>Status</dt><dd>' + escapeHtml(custom.status || '') + '</dd>' +
            '<dt>Exported at</dt><dd>' + escapeHtml(custom.exported_at || '-') + '</dd>' +
            '<dt>Production ID</dt><dd>' + escapeHtml(custom.production_order_id || '-') + '</dd>' +
          '</dl>';
      }

      var resetDisabled = data.can_reset ? '' : ' disabled';
      var warning = data.message ? '<div class="alert alert-warning mt-3"><i class="fas fa-info-circle mr-1"></i>' + escapeHtml(data.message) + '</div>' : '';

      $result.html(
        '<h5 class="mb-2">Production order</h5>' +
        '<dl class="order-reset-kv">' +
          '<dt>Order</dt><dd><strong>' + escapeHtml(order.order_number || '') + '</strong></dd>' +
          '<dt>Source</dt><dd>' + escapeHtml(sourceLabel(order.source_code)) + ' <span class="badge badge-secondary">' + escapeHtml(order.source_code || '') + '</span></dd>' +
          '<dt>Production ID</dt><dd>#' + escapeHtml(order.id || '') + '</dd>' +
          '<dt>External ID</dt><dd>' + escapeHtml(order.external_order_id || '-') + '</dd>' +
          '<dt>Status</dt><dd>' + escapeHtml(order.status || '-') + '</dd>' +
          '<dt>Total</dt><dd>' + money(order.total, order.currency) + '</dd>' +
          '<dt>Payment</dt><dd>' + escapeHtml(order.payment_method || '-') + '</dd>' +
          '<dt>Shipping</dt><dd>' + escapeHtml(order.shipping_method || '-') + '</dd>' +
          '<dt>Customer</dt><dd>' + escapeHtml(order.customer_name || '-') + (order.customer_email ? '<br><span class="order-reset-muted">' + escapeHtml(order.customer_email) + '</span>' : '') + '</dd>' +
        '</dl>' +
        customHtml +
        warning +
        '<h5 class="mt-4 mb-2">Rows that will be removed/reset</h5>' +
        renderCounts(data.counts || {}) +
        '<div class="mt-4 d-flex flex-wrap align-items-center">' +
          '<button type="button" class="btn btn-danger mr-2 mb-2" id="orderExportResetBtn"' + resetDisabled + ' title="Reset this export/import lock">' +
            '<i class="fas fa-undo-alt mr-1"></i> Reset export' +
          '</button>' +
          '<span class="order-reset-muted small mb-2">Deletes the Production order and linked rows. Custom Orders is set back for export.</span>' +
        '</div>'
      );
    }

    function lookupOrder() {
      var orderNumber = $.trim($input.val());
      if (!orderNumber) {
        renderAlert('warning', 'fa-info-circle', 'Enter an order number first.');
        return;
      }

      setLoading(true);
      $result.html('<div class="order-reset-muted"><i class="fas fa-circle-notch fa-spin mr-1"></i> Loading order...</div>');

      $.post(endpoint, { action: 'lookup', order_number: orderNumber }, function (data) {
        if (!data || !data.ok) {
          renderAlert('danger', 'fa-exclamation-triangle', (data && data.error) ? data.error : 'Lookup failed.');
          return;
        }
        renderLookup(data);
      }, 'json').fail(function (xhr) {
        renderAlert('danger', 'fa-exclamation-triangle', xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'Lookup failed.');
      }).always(function () {
        setLoading(false);
      });
    }

    $form.on('submit', function (event) {
      event.preventDefault();
      lookupOrder();
    });

    $result.on('click', '#orderExportResetBtn', function () {
      if (!currentOrderNumber) {
        return;
      }
      if (!window.confirm('Reset export for ' + currentOrderNumber + '? This deletes the Production order and linked rows.')) {
        return;
      }

      var $button = $(this);
      $button.prop('disabled', true).html('<i class="fas fa-circle-notch fa-spin mr-1"></i> Resetting');

      $.post(endpoint, { action: 'reset', order_number: currentOrderNumber }, function (data) {
        if (!data || !data.ok) {
          renderAlert('danger', 'fa-exclamation-triangle', (data && data.error) ? data.error : 'Reset failed.');
          return;
        }

        var deleted = data.deleted || {};
        var rows = Object.keys(deleted).map(function (key) {
          return '<tr><td>' + escapeHtml(key) + '</td><td class="text-right">' + escapeHtml(deleted[key]) + '</td></tr>';
        }).join('');

        $result.html(
          '<div class="alert alert-success"><i class="fas fa-check-circle mr-1"></i>' + escapeHtml(data.message || 'Reset complete.') + '</div>' +
          '<div class="table-responsive"><table class="table table-sm table-dark table-striped mb-0"><thead><tr><th>Table/action</th><th class="text-right">Rows</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
        );
      }, 'json').fail(function (xhr) {
        renderAlert('danger', 'fa-exclamation-triangle', xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'Reset failed.');
      });
    });
  })();
</script>
