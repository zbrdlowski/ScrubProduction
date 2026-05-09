console.log('ORDER DETAIL ACTIONS LOADED v-profile-1');
$(document)
  .off('click.takeOrder', '.btn-take-order')
  .on('click.takeOrder', '.btn-take-order', function (e) {
    e.preventDefault();
    e.stopPropagation();

    const $btn = $(this);
    const orderId = $btn.data('order-id');

    if (!orderId) {
      alert('Missing order ID');
      return;
    }

    $btn.prop('disabled', true).text('...');

    $.ajax({
      url: 'scripts/orders/take_order.php',
      method: 'POST',
      dataType: 'json',
      data: {
        order_id: orderId
      },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert('TAKE error: ' + (resp && resp.error ? resp.error : 'unknown'));
          $btn.prop('disabled', false).text('TAKE');
          return;
        }

        location.reload();
      },
      error: function (xhr) {
        console.log(xhr.responseText);
        alert('TAKE error request failed');
        $btn.prop('disabled', false).text('TAKE');
      }
    });
  });
(function () {
  let currentOptionsItemId = 0;
  let currentInternalOptions = {};

  function copyTextFallback(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();

    let copied = false;
    try {
      copied = document.execCommand('copy');
    } catch (e) {
      copied = false;
    }

    document.body.removeChild(textarea);
    return copied;
  }

  function escapeHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function getOptionsData($btn) {
    const raw = $btn.attr('data-options') || '{}';

    try {
      return JSON.parse(raw);
    } catch (e) {
      return {};
    }
  }

  window.getOptionsData = getOptionsData;

  function renderOptionsPretty(data) {
    if (!data || Object.keys(data).length === 0) {
      return '<div class="text-muted">No options</div>';
    }

    function section(title, obj) {
      if (!obj || Object.keys(obj).length === 0) return '';

      let rows = '';

      for (let k in obj) {
        rows += `
          <div class="mb-1">
            <span class="text-muted">${escapeHtml(k)}:</span>
            <b>${escapeHtml(obj[k])}</b>
          </div>
        `;
      }

      return `
        <div class="card bg-secondary mb-3">
          <div class="card-header py-2">
            <b>${escapeHtml(title)}</b>
          </div>
          <div class="card-body py-2">
            ${rows}
          </div>
        </div>
      `;
    }

    const bike = {};
    const personal = {};
    const graphics = {};
    const files = {};
    const other = {};

    for (let k in data) {
      let v = data[k];

      if (v === null || v === '' || typeof v === 'object') continue;

      let label = k;

      if (k === 'name-color') label = 'number plates color';
      if (k === 'applyinggraphics') label = 'Fitting';

      if (k === 'number-font' || k === 'name-font') {
        const match = String(v).match(/(\d+)$/);
        if (match) v = match[1];
      }

      const key = k.toLowerCase();

      if (k === 'Category Info' || key.includes('category') || key.includes('bike') || key.includes('manufacturer')) {
        bike[label] = v;
      } else if (key.includes('name') || key.includes('number') || key.includes('font')) {
        personal[label] = v;
      } else if (key.includes('material') || key.includes('finish') || key.includes('graphics') || key.includes('rim') || key.includes('fork')) {
        graphics[label] = v;
      } else if (key.includes('file') || key.includes('logo') || key.includes('upload')) {
        files[label] = v;
      } else if (!key.startsWith('_') && key !== 'source_raw') {
        other[label] = v;
      }
    }

    let warnings = [];

    if (!data.name && !data.Name) warnings.push('Missing rider name');
    if (!data.file && !data.logo && !data.uploaded_file) warnings.push('Missing uploaded file / logo');

    let html = '';

    if (warnings.length) {
      html += `
        <div class="alert alert-warning">
          <b>Check before production:</b><br>
          ${warnings.map(w => `<span class="badge badge-danger mr-1">${escapeHtml(w)}</span>`).join('')}
        </div>
      `;
    }

    html += section('Bike / Category', bike);
    html += section('Personalization', personal);
    html += section('Graphics', graphics);
    html += section('Files', files);
    html += section('Other', other);

    return html;
  }

  window.renderOptionsPretty = renderOptionsPretty;

  function renderInternalOptions(data) {
    if (!data || Object.keys(data).length === 0) {
      return '<div class="text-muted">No internal production blocks yet.</div>';
    }

    let html = '';

    Object.keys(data).forEach(function (blockName) {
      html += `
        <div class="card bg-secondary mb-2">
          <div class="card-header py-2">
            <b>${escapeHtml(blockName)}</b>
          </div>
          <div class="card-body py-2">
      `;

      const fields = data[blockName] || {};

      Object.keys(fields).forEach(function (key) {
        html += `
          <div class="mb-1">
            <span class="text-muted">${escapeHtml(key)}:</span>
            <b>${escapeHtml(fields[key])}</b>
          </div>
        `;
      });

      html += `
          </div>
        </div>
      `;
    });

    return html;
  }
  window.renderInternalOptions = renderInternalOptions;

  function renderInternalEditor(data) {
    let html = '';

    if (!data || Object.keys(data).length === 0) {
      data = {
        'Production Info': {
          'Note': ''
        }
      };
    }

    Object.keys(data).forEach(function (blockName) {
      const fields = data[blockName] || {};

      html += `
        <div class="card bg-secondary mb-2 internal-block">
          <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <input type="text"
                   class="form-control form-control-sm internal-block-name"
                   value="${escapeHtml(blockName)}"
                   placeholder="Block name"
                   style="max-width:320px;">

            <div>
              <button type="button" class="btn btn-xs btn-outline-light btn-add-internal-field">
                <i class="fas fa-plus"></i> Field
              </button>
              <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-block">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </div>

          <div class="card-body py-2 internal-fields">
      `;

      Object.keys(fields).forEach(function (key) {
        html += `
          <div class="form-row align-items-center mb-2 internal-field">
            <div class="col-md-4">
              <input type="text" class="form-control form-control-sm internal-field-key" value="${escapeHtml(key)}" placeholder="Field name">
            </div>
            <div class="col-md-7">
              <input type="text" class="form-control form-control-sm internal-field-value" value="${escapeHtml(fields[key])}" placeholder="Value">
            </div>
            <div class="col-md-1 text-right">
              <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-field">×</button>
            </div>
          </div>
        `;
      });

      html += `
          </div>
        </div>
      `;
    });

    $('#internalBlocksEditor').html(html);
  }
  window.renderInternalEditor = renderInternalEditor;
  function collectInternalEditorData() {
    const data = {};

    $('#internalBlocksEditor .internal-block').each(function () {
      const blockName = $(this).find('.internal-block-name').val().trim();

      if (!blockName) return;

      data[blockName] = {};

      $(this).find('.internal-field').each(function () {
        const key = $(this).find('.internal-field-key').val().trim();
        const value = $(this).find('.internal-field-value').val().trim();

        if (key) data[blockName][key] = value;
      });

      if (Object.keys(data[blockName]).length === 0) {
        delete data[blockName];
      }
    });

    return data;
  }
  window.collectInternalEditorData = collectInternalEditorData;

function findOpenOrderIdFromElement($el) {
  // profile_orders.php detail row
  const $profileDetailRow = $el.closest('tr.profile-order-detail-row, .profile-order-detail-row');
  if ($profileDetailRow.length) {
    return parseInt($profileDetailRow.data('detail-for'), 10) || 0;
  }

  // orders.php detail wrapper/row
  const $detailWrap = $el.closest('.detail-wrap');
  if ($detailWrap.length) {
    const $detailRow = $detailWrap.closest('tr');
    const $ordersRow = $detailRow.prev('.order-row');

    if ($ordersRow.length) {
      return parseInt($ordersRow.data('order-id'), 10) || 0;
    }
  }

  // fallback: button/select has order id directly
  const directOrderId = $el.data('order-id');
  if (directOrderId) {
    return parseInt(directOrderId, 10) || 0;
  }

  return 0;
}

function refreshOrderDetail(orderId) {
  orderId = parseInt(orderId, 10) || 0;

  if (!orderId) {
    location.reload();
    return;
  }

  $.post('scripts/orders/get_order_detail.php', {
    order_id: orderId
  }, function (res) {
    if (!res || !res.ok) {
      location.reload();
      return;
    }

    // orders.php layout
    const $ordersDetail = $('#detail-' + orderId);
    if ($ordersDetail.length) {
      $ordersDetail.html(res.html).show();
      return;
    }

    // profile_orders.php layout
const $profileDetailRow = $('.profile-order-detail-row[data-detail-for="' + orderId + '"]');
if ($profileDetailRow.length) {
  if (typeof window.refreshProfileOrdersList === 'function') {
    window.refreshProfileOrdersList(orderId);
    return;
  }

  $profileDetailRow.show();
  $profileDetailRow.find('td').html(res.html);
  return;
}

    location.reload();
  }, 'json').fail(function () {
    location.reload();
  });
}

  function ensureOptionsModal() {
    if ($('#optionsModal').length) return;

    $('body').append(`
      <div class="modal fade" id="optionsModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
          <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
              <h5 class="modal-title">
                <i class="fas fa-list-alt mr-1"></i> Product Options
              </h5>
              <button type="button" class="close text-light" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>

            <div class="modal-body">
              <div class="mb-3">
                <h6 class="text-muted mb-2">
                  <i class="fas fa-download mr-1"></i> Imported Product Options
                </h6>
                <div id="optionsModalBody"></div>
              </div>

              <hr class="border-secondary my-3">

              <div class="mb-2">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h6 class="text-muted mb-0">
                    <i class="fas fa-tools mr-1"></i> Internal Production Blocks
                  </h6>

                  <button type="button" class="btn btn-sm btn-outline-warning" id="btnEditInternalOptions">
                    <i class="fas fa-edit mr-1"></i> Edit internal
                  </button>
                </div>

                <div id="internalOptionsView" class="mb-2"></div>

                <div id="internalOptionsEditBox" style="display:none;">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted">Add production information as blocks and fields.</small>

                    <button type="button" class="btn btn-sm btn-outline-info" id="btnAddInternalBlock">
                      <i class="fas fa-plus mr-1"></i> Add block
                    </button>
                  </div>

                  <div id="internalBlocksEditor"></div>

                  <div class="d-flex justify-content-end mt-2">
                    <button type="button" class="btn btn-sm btn-success" id="btnSaveInternalOptions">
                      <i class="fas fa-save mr-1"></i> Save internal
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div class="modal-footer border-secondary">
              <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>
    `);
  }

  $(document)
    .off('click.orderDetailActions', '.btn-view-options')
    .on('click.orderDetailActions', '.btn-view-options', function (e) {
      e.preventDefault();
      e.stopPropagation();

      ensureOptionsModal();

      const $btn = $(this);
      const data = getOptionsData($btn);

      $('#optionsModalBody').html(renderOptionsPretty(data));

      currentOptionsItemId = $btn.data('item-id') || 0;

      try {
        currentInternalOptions = JSON.parse($btn.attr('data-internal-options') || '{}');
      } catch (err) {
        currentInternalOptions = {};
      }

      $('#internalOptionsView').html(renderInternalOptions(currentInternalOptions));
      $('#internalOptionsEditBox').hide();
      $('#btnEditInternalOptions').show();

      $('#optionsModal').modal('show');
    });

  $(document)
    .off('click.orderDetailActions', '.btn-copy-options')
    .on('click.orderDetailActions', '.btn-copy-options', async function (e) {
      e.preventDefault();
      e.stopPropagation();

      const data = getOptionsData($(this));
      let text = '';

      for (let k in data) {
        if (k.startsWith('_')) continue;
        if (typeof data[k] === 'object') continue;
        text += `${k}: ${data[k]}\n`;
      }

      if (!text.trim()) {
        alert('Nothing to copy');
        return;
      }

      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        copyTextFallback(text);
      }

      const $btn = $(this);
      const oldText = $btn.text();
      $btn.text('COPIED');
      setTimeout(() => $btn.text(oldText), 1000);
    });

  $(document)
    .off('click.orderDetailActions', '.btn-copy-inline')
    .on('click.orderDetailActions', '.btn-copy-inline', async function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $btn = $(this);
      const text = $btn.attr('data-copy') || '';

      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        copyTextFallback(text);
      }

      const oldText = $btn.text();
      $btn.text('✔');
      setTimeout(() => $btn.text(oldText), 800);
    });

  $(document)
    .off('click.orderDetailActions', '.btn-assign-item')
    .on('click.orderDetailActions', '.btn-assign-item', function (e) {
  e.preventDefault();
  e.stopPropagation();

  const $btn = $(this);
  const itemId = $btn.data('item-id');

  $.ajax({
    url: 'scripts/orders/assign_order_item.php',
    method: 'POST',
    dataType: 'json',
    data: { item_id: itemId },
    success: function (resp) {
      if (!resp || !resp.ok) {
        alert(resp && resp.error ? resp.error : 'Assign item failed');
        return;
      }

      refreshOrderDetail(findOpenOrderIdFromElement($btn));
    },
    error: function (xhr) {
      console.log(xhr.responseText);
      alert('Assign item request failed');
    }
  });
});

$(document)
  .off('change.orderDetailActions', '.item-status-select')
  .on('change.orderDetailActions', '.item-status-select', function (e) {
    e.preventDefault();
    e.stopPropagation();

    const $select = $(this);
    const itemId = parseInt($select.data('item-id'), 10) || 0;
    const status = $select.val();
    const orderId = findOpenOrderIdFromElement($select);

    if (!itemId) {
      alert('Missing item ID');
      return;
    }

    $select.prop('disabled', true);

    const $scope = $select.closest('tr');
    const note = $scope.find('.item-waiting-note').val() || $('.item-waiting-note[data-item-id="' + itemId + '"]').val() || '';
    const expectedDate = $scope.find('.item-expected-date').val() || $('.item-expected-date[data-item-id="' + itemId + '"]').val() || '';

    $.ajax({
      url: 'scripts/orders/update_item_status.php',
      method: 'POST',
      dataType: 'json',
      data: {
        item_id: itemId,
        status: status,
        note: note,
        expected_date: expectedDate
      },
      success: function (resp) {
        if (!resp || (!resp.success && !resp.ok)) {
          alert(resp && (resp.message || resp.error) ? (resp.message || resp.error) : 'Status update failed');
          $select.prop('disabled', false);
          return;
        }

        if (orderId) {
          refreshOrderDetail(orderId);
        } else {
          location.reload();
        }
      },
      error: function (xhr) {
        console.log(xhr.responseText);
        alert('Status update request failed');
        $select.prop('disabled', false);
      }
    });
  });

  $(document)
    .off('click.orderDetailActions', '.btn-edit-production-note')
    .on('click.orderDetailActions', '.btn-edit-production-note', function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $box = $(this).closest('.production-note-box');

      $box.find('.production-note-display').hide();
      $box.find('.btn-edit-production-note').hide();
      $box.find('.production-note-editor').show();
      $box.find('.production-note-input').focus();
    });

  $(document)
    .off('click.orderDetailActions', '.btn-cancel-production-note')
    .on('click.orderDetailActions', '.btn-cancel-production-note', function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $box = $(this).closest('.production-note-box');

      $box.find('.production-note-editor').hide();
      $box.find('.production-note-display').show();
      $box.find('.btn-edit-production-note').show();
    });

  $(document)
    .off('click.orderDetailActions', '.btn-save-production-note')
    .on('click.orderDetailActions', '.btn-save-production-note', function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $btn = $(this);
      const orderId = $btn.data('order-id');
      const $box = $btn.closest('.production-note-box');
      const note = $box.find('.production-note-input').val();

      $btn.prop('disabled', true).text('Saving...');

      $.post('scripts/orders/update_production_note.php', {
        order_id: orderId,
        production_note: note
      }, function (res) {
        if (!res || !res.ok) {
          alert(res && res.error ? res.error : 'Save failed');
          $btn.prop('disabled', false).text('Save');
          return;
        }

        refreshOrderDetail(orderId);
      }, 'json').fail(function () {
        alert('Save note request failed');
        $btn.prop('disabled', false).text('Save');
      });
    });

  $(document)
    .off('click.orderDetailActions', '#btnEditInternalOptions')
    .on('click.orderDetailActions', '#btnEditInternalOptions', function (e) {
      e.preventDefault();
      e.stopPropagation();

      renderInternalEditor(currentInternalOptions);

      $('#internalOptionsEditBox').show();
      $('#btnEditInternalOptions').hide();
    });

  $(document)
    .off('click.orderDetailActions', '#btnAddInternalBlock')
    .on('click.orderDetailActions', '#btnAddInternalBlock', function (e) {
      e.preventDefault();
      e.stopPropagation();

      $('#internalBlocksEditor').append(`
        <div class="card bg-secondary mb-2 internal-block">
          <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <input type="text" class="form-control form-control-sm internal-block-name" value="" placeholder="Block name" style="max-width:320px;">
            <div>
              <button type="button" class="btn btn-xs btn-outline-light btn-add-internal-field">
                <i class="fas fa-plus"></i> Field
              </button>
              <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-block">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </div>
          <div class="card-body py-2 internal-fields"></div>
        </div>
      `);
    });

  $(document)
    .off('click.orderDetailActions', '.btn-add-internal-field')
    .on('click.orderDetailActions', '.btn-add-internal-field', function (e) {
      e.preventDefault();
      e.stopPropagation();

      $(this).closest('.internal-block').find('.internal-fields').append(`
        <div class="form-row align-items-center mb-2 internal-field">
          <div class="col-md-4">
            <input type="text" class="form-control form-control-sm internal-field-key" placeholder="Field name">
          </div>
          <div class="col-md-7">
            <input type="text" class="form-control form-control-sm internal-field-value" placeholder="Value">
          </div>
          <div class="col-md-1 text-right">
            <button type="button" class="btn btn-xs btn-outline-danger btn-remove-internal-field">×</button>
          </div>
        </div>
      `);
    });

  $(document)
    .off('click.orderDetailActions', '.btn-remove-internal-field')
    .on('click.orderDetailActions', '.btn-remove-internal-field', function (e) {
      e.preventDefault();
      e.stopPropagation();

      $(this).closest('.internal-field').remove();
    });

  $(document)
    .off('click.orderDetailActions', '.btn-remove-internal-block')
    .on('click.orderDetailActions', '.btn-remove-internal-block', function (e) {
      e.preventDefault();
      e.stopPropagation();

      if (confirm('Remove this block?')) {
        $(this).closest('.internal-block').remove();
      }
    });

  $(document)
    .off('click.orderDetailActions', '#btnSaveInternalOptions')
    .on('click.orderDetailActions', '#btnSaveInternalOptions', function (e) {
      e.preventDefault();
      e.stopPropagation();

      const data = collectInternalEditorData();

$.post('scripts/orders/update_item_internal_options.php', {
  item_id: currentOptionsItemId,
  internal_options_json: JSON.stringify(data)
}, function (res) {
  if (!res || (!res.ok && !res.success)) {
    alert(res && (res.error || res.message) ? (res.error || res.message) : 'Save failed');
    return;
  }

  $('#optionsModal').modal('hide');
  refreshOrderDetail(
    findOpenOrderIdFromElement($('.btn-view-options[data-item-id="' + currentOptionsItemId + '"]'))
  );
}, 'json').fail(function (xhr) {
  console.log(xhr.responseText);
  alert('Save internal options request failed');
});
    });

})();