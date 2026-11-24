console.log('PRODUCT CHART ACTIONS LOADED v2.0');

// ── Helpers ────────────────────────────────────────────────────────────────

function pcEscHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function pcCanEdit() {
    return window.productChartCanEdit === true;
}

async function pcCopyCode(text) {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return;
        } catch (error) {
            // Pri odmietnuti Clipboard API skus aj fallback pre HTTP.
        }
    }

    const activeElement = document.activeElement;
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;padding:0;border:0;opacity:0;font-size:16px;';
    // Explicitne zapis len kod ako cisty text, nezavisle od vyberu na stranke.
    textarea.addEventListener('copy', function (event) {
        if (event.clipboardData) {
            event.clipboardData.setData('text/plain', text);
            event.preventDefault();
            event.stopPropagation();
        }
    });
    const copyContainer = document.fullscreenElement || document.body;
    copyContainer.appendChild(textarea);

    try {
        textarea.focus({ preventScroll: true });
        textarea.select();
        textarea.setSelectionRange(0, text.length);
        if (!document.execCommand('copy')) throw new Error('Copy failed');
    } finally {
        textarea.remove();
        if (activeElement && typeof activeElement.focus === 'function') {
            activeElement.focus({ preventScroll: true });
        }
    }
}

// Native handler na tabulke funguje aj po prekresleni DataTables a vymene jQuery.
(function () {
    const table = document.getElementById('scrubTable');
    if (!table) return;
    const feedbackTimers = new WeakMap();

    table.addEventListener('click', async function (event) {
        const badge = event.target.closest('.product-code-badge');
        if (!badge || !table.contains(badge)) return;
        event.preventDefault();
        event.stopPropagation();

        const code = badge.textContent.trim();
        if (!code) return;
        const status = document.getElementById('productChartCopyStatus');
        if (status) status.textContent = '';

        try {
            await pcCopyCode(code);
        } catch (error) {
            if (status) status.textContent = 'Kód sa nepodarilo skopírovať.';
            window.prompt('Automatické kopírovanie zlyhalo. Skopírujte tento kód pomocou Ctrl+C:', code);
            return;
        }

        if (typeof navigator.vibrate === 'function') {
            try { navigator.vibrate(18); } catch (error) { /* Haptika je volitelna. */ }
        }

        clearTimeout(feedbackTimers.get(badge));
        badge.classList.add('is-copied');
        badge.title = 'Skopírované: ' + code;
        if (status) status.textContent = 'Kód ' + code + ' bol skopírovaný.';
        feedbackTimers.set(badge, setTimeout(function () {
            badge.classList.remove('is-copied');
            badge.title = 'Kliknutím skopírovať kód';
            feedbackTimers.delete(badge);
        }, 1200));
    });
})();

// Detekcia či hodnota je yes/no typ
function isYesNoValue(val) {
    if (typeof val !== 'string') return false;
    return ['yes', 'no'].includes(val.toLowerCase().trim());
}

// Fixed fields are edited in a compact form. Custom metadata remains in Advanced.
const PC_PRODUCTS = [
    ['Graphics', 'graphics-status-cell'], ['GFP', 'gfp-status-cell'],
    ['Plastics', 'plastics-status-cell'], ['Seat Cover', 'seatcover-status-cell']
];
const PC_PRODUCT_FIELDS = ['Available', 'Web', 'eBay'];
const PC_CONFIG = [
    ['Create New Categories', 'CNC'], ['Add Filters', 'FLT'],
    ['Add Accessories', 'ACC'], ['Add Existing Designs', 'EXD']
];
const PC_TRACKING = [
    ['Web', 'done_web', 'WEB'], ['eBay', 'done_ebay', 'EB'],
    ['Graphics Templates', 'done_graphics_templates', 'GT'],
    ['Seatcover Templates', 'done_seatcover_templates', 'ST'], ['Products', 'done_products', 'PR']
];

function pcObject(value) {
    if (typeof value === 'string') {
        try { value = JSON.parse(value); } catch (error) { return {}; }
    }
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}
function pcRow(rowkey) { return $('tr.model-row[data-rowkey="' + rowkey + '"]'); }
function pcTracking(rowkey) {
    const tracking = pcObject(pcRow(rowkey).data('tracking'));
    return tracking.trackid ? tracking : null;
}
function pcYes(value) { return String(value || '').toLowerCase().trim() === 'yes'; }
function pcStandardField(block, field) {
    return (PC_PRODUCTS.some(function (item) { return item[0] === block; }) && PC_PRODUCT_FIELDS.includes(field)) ||
        (block === 'Configuration' && PC_CONFIG.some(function (item) { return item[0] === field; }));
}
function pcCustomMeta(meta) {
    const custom = {};
    Object.keys(meta).forEach(function (block) {
        const fields = pcObject(meta[block]);
        Object.keys(fields).forEach(function (field) {
            if (pcStandardField(block, field)) return;
            if (!custom[block]) custom[block] = {};
            custom[block][field] = fields[field];
        });
    });
    return custom;
}
function pcValue(checked, yesLabel, noLabel, title) {
    return '<span class="pc-value ' + (checked ? 'is-yes' : 'is-no') + '" title="' + pcEscHtml(title) + '">' +
        pcEscHtml(checked ? yesLabel : noLabel) + '</span>';
}
function pcCheckbox(checked, className, attrs, label, yesLabel, noLabel) {
    return '<label class="pc-check" title="' + pcEscHtml(label) + '">' +
        '<input type="checkbox" class="' + className + '" ' + attrs + ' aria-label="' + pcEscHtml(label) + '" ' + (checked ? 'checked' : '') + '>' +
        pcValue(checked, yesLabel, noLabel, label + ': ' + (checked ? 'YES' : 'NO')) + '</label>';
}
function pcDetailHtml(rowkey, rawMeta, tracking, editing) {
    const meta = pcObject(rawMeta);
    let html = '<div class="pc-detail-grid"><section class="pc-section"><div class="pc-section-heading">' +
        '<h4>Product availability</h4><p>Available = product ready · Web / eBay = listed for sale</p></div>' +
        '<table class="pc-matrix"><thead><tr><th scope="col">Product</th><th scope="col">Available</th><th scope="col">Web</th><th scope="col">eBay</th></tr></thead><tbody>';
    PC_PRODUCTS.forEach(function (item) {
        const block = item[0], fields = pcObject(meta[block]);
        html += '<tr><th scope="row">' + pcEscHtml(block) + '</th>';
        PC_PRODUCT_FIELDS.forEach(function (field) {
            const yes = pcYes(fields[field]), label = block + ' — ' + field;
            const yesText = field === 'eBay' ? 'E' : 'YES', noText = field === 'eBay' ? 'E' : 'NO';
            html += '<td>' + (editing
                ? pcCheckbox(yes, 'pc-meta-toggle', 'data-block="' + pcEscHtml(block) + '" data-field="' + field + '"', label, yesText, noText)
                : pcValue(yes, yesText, noText, label + ': ' + (yes ? 'YES' : 'NO'))) + '</td>';
        });
        html += '</tr>';
    });
    html += '</tbody></table></section><section class="pc-section"><div class="pc-section-heading">' +
        '<h4>Configuration</h4><p>Mark the completed setup steps.</p></div><div class="pc-checklist">';
    PC_CONFIG.forEach(function (item) {
        const field = item[0], yes = pcYes(pcObject(meta.Configuration)[field]);
        html += '<div class="pc-checklist-row"><span>' + pcEscHtml(field) + '</span>' + (editing
            ? pcCheckbox(yes, 'pc-meta-toggle', 'data-block="Configuration" data-field="' + pcEscHtml(field) + '"', field, 'YES', 'NO')
            : pcValue(yes, 'YES', 'NO', field)) + '</div>';
    });
    html += '</div></section><section class="pc-section pc-tracking"><div class="pc-section-heading">' +
        '<h4>Model year update' + (tracking ? ' · ' + pcEscHtml(tracking.new_year) : '') + '</h4>' +
        '<p>Confirm where the latest model year has been applied.</p></div>';
    if (tracking) {
        html += '<div class="pc-tracking-grid">';
        PC_TRACKING.forEach(function (item) {
            const label = item[0], field = item[1], done = !!Number(tracking[field]);
            html += '<div class="pc-tracking-item"><span class="pc-tracking-name">' + label + '</span>' + (editing
                ? pcCheckbox(done, 'pc-tracking-toggle', 'data-field="' + field + '"', label + ' — model year update', 'DONE', 'PENDING')
                : pcValue(done, 'DONE', 'PENDING', label)) + '</div>';
        });
        html += '</div>';
    } else {
        html += '<div class="pc-empty-tracking">No model year update to track. A checklist is created when a new model year is added.</div>';
    }
    html += '</section></div>';
    const custom = pcCustomMeta(meta);
    if (editing || Object.keys(custom).length) {
        html += '<details class="pc-advanced"><summary>Additional fields' + (editing ? ' · advanced' : '') + '</summary><div class="pc-advanced-content">';
        if (editing) {
            html += '<div class="d-flex justify-content-between align-items-center mb-3"><span class="pc-edit-hint">Optional custom information.</span>' +
                '<button type="button" class="btn btn-sm btn-outline-info btn-add-meta-block" data-rowkey="' + rowkey + '"><i class="fas fa-plus mr-1"></i> Add block</button></div><div class="pc-custom-editor">';
            Object.keys(custom).forEach(function (block) { html += buildBlockEditorHtml(block, custom[block]); });
            html += '</div>';
        } else {
            html += '<div class="pc-custom-view">';
            Object.keys(custom).forEach(function (block) {
                html += '<div><h5>' + pcEscHtml(block) + '</h5>';
                Object.keys(custom[block]).forEach(function (field) {
                    html += '<div class="pc-custom-field"><span>' + pcEscHtml(field) + '</span><span>' + pcEscHtml(custom[block][field]) + '</span></div>';
                });
                html += '</div>';
            });
            html += '</div>';
        }
        html += '</div></details>';
    }
    return html;
}
function renderMetaView(rowkey, meta) {
    $('#view-' + rowkey).html(pcDetailHtml(rowkey, meta, pcTracking(rowkey), false));
}
function renderMetaEditor(rowkey, meta) {
    $('#detail-' + rowkey).data('tracking-original', pcTracking(rowkey));
    $('#editor-' + rowkey).html(pcDetailHtml(rowkey, meta, pcTracking(rowkey), true));
}

function buildBlockEditorHtml(blockName, fields) {
    let fieldsHtml = '';
    Object.keys(fields).forEach(function (key) {
        fieldsHtml += buildFieldRowHtml(key, String(fields[key] ?? ''));
    });

    return `
        <div class="card bg-secondary mb-2 meta-block">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <input type="text"
                       class="form-control form-control-sm meta-block-name"
                       value="${pcEscHtml(blockName)}"
                       placeholder="Názov bloku"
                       style="max-width:240px;">
                <div style="display:flex; gap:6px; align-items:center;">
                    <div class="dropdown">
                        <button type="button"
                                class="btn btn-xs btn-outline-light dropdown-toggle"
                                data-toggle="dropdown">
                            <i class="fas fa-plus"></i> Add field
                        </button>
                        <div class="dropdown-menu dropdown-menu-right bg-dark border-secondary">
                            <a class="dropdown-item text-light btn-add-meta-field" href="#" data-type="toggle">
                                <i class="fas fa-toggle-on mr-2 text-info"></i> Yes / No toggle
                            </a>
                            <a class="dropdown-item text-light btn-add-meta-field" href="#" data-type="text">
                                <i class="fas fa-font mr-2 text-warning"></i> Text input
                            </a>
                        </div>
                    </div>
                    <button type="button" class="btn btn-xs btn-outline-danger btn-remove-meta-block">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <div class="card-body py-2 meta-fields">
                ${fieldsHtml}
            </div>
        </div>`;
}

// Detekuje typ fieldu z hodnoty a renderuje správny input
function buildFieldRowHtml(key, value) {
    const isToggle = isYesNoValue(value) || value === '';
    return buildFieldRowHtmlTyped(key, value, isToggle ? 'toggle' : 'text');
}

function buildFieldRowHtmlTyped(key, value, type) {
    let inputHtml;

    if (type === 'toggle') {
        const checked = (String(value).toLowerCase().trim() === 'yes');
        const toggleId = 'mt-' + Math.random().toString(36).substr(2, 9);
        inputHtml = `
            <div class="d-flex align-items-center" style="gap:10px;">
                <div class="custom-control custom-switch">
                    <input type="checkbox"
                           class="custom-control-input meta-field-toggle"
                           id="${toggleId}"
                           ${checked ? 'checked' : ''}>
                    <label class="custom-control-label text-light" for="${toggleId}"></label>
                </div>
                <input type="hidden" class="meta-field-value" value="${checked ? 'yes' : 'no'}">
                <span class="meta-toggle-display badge ${checked ? 'badge-success' : 'badge-danger'}" style="min-width:36px;">
                    ${key === 'eBay' ? 'E' : (checked ? 'YES' : 'NO')}
                </span>
            </div>`;
    } else {
        inputHtml = `
            <input type="text"
                   class="form-control form-control-sm meta-field-value"
                   value="${pcEscHtml(value)}"
                   placeholder="Hodnota">`;
    }

    // Ikona pre typ fieldu (klikateľná pre zmenu)
    const typeIcon = type === 'toggle'
        ? `<i class="fas fa-toggle-on text-info meta-type-icon" title="Prepnúť na text" data-current="toggle" style="cursor:pointer;"></i>`
        : `<i class="fas fa-font text-warning meta-type-icon" title="Prepnúť na Yes/No" data-current="text" style="cursor:pointer;"></i>`;

    return `
        <div class="form-row align-items-center mb-2 meta-field" data-field-type="${type}">
            <div class="col-md-1 text-center">
                ${typeIcon}
            </div>
            <div class="col-md-4">
                <input type="text"
                       class="form-control form-control-sm meta-field-key"
                       value="${pcEscHtml(key)}"
                       placeholder="Názov fieldu">
            </div>
            <div class="col-md-6">
                ${inputHtml}
            </div>
            <div class="col-md-1 text-right">
                <button type="button" class="btn btn-xs btn-outline-danger btn-remove-meta-field">×</button>
            </div>
        </div>`;
}

function collectMetaEditorData(rowkey) {
    const data = {};
    $('#editor-' + rowkey + ' .meta-block').each(function () {
        const block = $(this).find('.meta-block-name').val().trim();
        if (!block) return;
        if (!data[block]) data[block] = {};
        $(this).find('.meta-field').each(function () {
            const key = $(this).find('.meta-field-key').val().trim();
            if (key) data[block][key] = $(this).find('.meta-field-value').val().trim();
        });
    });
    $('#editor-' + rowkey + ' .pc-meta-toggle').each(function () {
        const block = $(this).data('block'), field = $(this).data('field');
        if (!data[block]) data[block] = {};
        data[block][field] = this.checked ? 'yes' : 'no';
    });
    return data;
}
function pcMessage(rowkey, text, isError) {
    $('#detail-' + rowkey + ' .pc-save-message').text(text).toggleClass('is-error', !!isError);
}
function pcFinishEditing(rowkey) {
    $('#detail-' + rowkey).data('editing', false).data('dirty', false);
    $('#edit-' + rowkey).hide();
    renderMetaView(rowkey, pcRow(rowkey).data('meta'));
    $('#view-' + rowkey).show();
    $('.btn-edit-model-meta[data-rowkey="' + rowkey + '"]').show();
}
function pcAttachDetail($row) {
    const $panel = $('#detail-' + $row.data('rowkey'));
    if (!$panel.length || $panel.closest('tr.scrub-detail-wrapper').length) return;
    const $wrapper = $('<tr class="scrub-detail-wrapper"><td style="padding:0; border-top:none;"></td></tr>');
    $wrapper.children('td').attr('colspan', $row.children('td').length).append($panel);
    $row.after($wrapper);
    $panel.show();
}
function pcRestoreOpenDetails() {
    $('#scrubTable tbody > tr.model-row.open').each(function () { pcAttachDetail($(this)); });
}
function pcCloseDetail($row) {
    const rowkey = $row.data('rowkey'), $panel = $('#detail-' + rowkey);
    const $wrapper = $panel.closest('tr.scrub-detail-wrapper');
    pcFinishEditing(rowkey);
    $('#scrubDetailStore').append($panel.hide());
    $wrapper.remove();
    $row.removeClass('open').find('.toggle-icon').removeClass('fa-chevron-down').addClass('fa-chevron-right');
}
$(document).off('click.scrubExpand', 'tr.model-row').on('click.scrubExpand', 'tr.model-row', function (event) {
    if ($(event.target).closest('a, button').length) return;
    const $row = $(this), rowkey = $row.data('rowkey');
    const $blocked = $('.scrub-detail-panel').filter(function () { return $(this).data('saving') || $(this).data('dirty'); }).first();
    if ($blocked.length) {
        pcMessage($blocked.data('rowkey'), $blocked.data('saving') ? 'Saving changes…' : 'Save or cancel your changes before closing this model.', true);
        return;
    }
    if ($row.hasClass('open')) { pcCloseDetail($row); return; }
    $('tr.model-row.open').each(function () { pcCloseDetail($(this)); });
    renderMetaView(rowkey, $row.data('meta'));
    $row.addClass('open').find('.toggle-icon').removeClass('fa-chevron-right').addClass('fa-chevron-down');
    pcAttachDetail($row);
});
$(document).off('click.scrubEditMeta', '.btn-edit-model-meta').on('click.scrubEditMeta', '.btn-edit-model-meta', function () {
    if (!pcCanEdit()) return;
    const rowkey = $(this).data('rowkey');
    renderMetaEditor(rowkey, pcRow(rowkey).data('meta'));
    $('#detail-' + rowkey).data('editing', true).data('dirty', false);
    pcMessage(rowkey, '');
    $('#view-' + rowkey).hide(); $('#edit-' + rowkey).show(); $(this).hide();
});
$(document).off('click.scrubCancelMeta', '.btn-cancel-meta-edit').on('click.scrubCancelMeta', '.btn-cancel-meta-edit', function () {
    const rowkey = $(this).data('rowkey');
    if ($('#detail-' + rowkey).data('saving')) return;
    pcFinishEditing(rowkey); pcMessage(rowkey, '');
    if ($.fn.dataTable && $.fn.dataTable.isDataTable('#scrubTable')) $('#scrubTable').DataTable().draw(false);
});
$(document).off('change.pcDetailToggle', '.pc-meta-toggle, .pc-tracking-toggle').on('change.pcDetailToggle', '.pc-meta-toggle, .pc-tracking-toggle', function () {
    const $input = $(this), checked = this.checked, tracking = $input.hasClass('pc-tracking-toggle');
    const ebay = !tracking && $input.data('field') === 'eBay';
    $input.siblings('.pc-value').text(tracking ? (checked ? 'DONE' : 'PENDING') : (ebay ? 'E' : (checked ? 'YES' : 'NO')))
        .toggleClass('is-yes', checked).toggleClass('is-no', !checked)
        .attr('title', $input.attr('aria-label') + ': ' + (checked ? 'YES' : 'NO'));
});
$(document).off('input.pcDirty change.pcDirty', '.meta-edit-area input').on('input.pcDirty change.pcDirty', '.meta-edit-area input', function () {
    $(this).closest('.scrub-detail-panel').data('dirty', true);
});
$(document).off('click.pcStructureDirty', '.btn-add-meta-block, .btn-add-meta-field, .btn-remove-meta-field, .btn-remove-meta-block, .meta-type-icon')
    .on('click.pcStructureDirty', '.btn-add-meta-block, .btn-add-meta-field, .btn-remove-meta-field, .btn-remove-meta-block, .meta-type-icon', function () {
        $(this).closest('.scrub-detail-panel').data('dirty', true);
    });
$(window).off('beforeunload.pcDetail').on('beforeunload.pcDetail', function (event) {
    if ($('.scrub-detail-panel').filter(function () { return $(this).data('dirty') || $(this).data('saving'); }).length) {
        event.preventDefault(); event.originalEvent.returnValue = '';
    }
});

// ── Toggle switch → hidden input sync ─────────────────────────────────────

$(document)
    .off('change.metaToggle', '.meta-field-toggle')
    .on('change.metaToggle', '.meta-field-toggle', function () {
        if (!pcCanEdit() || $(this).closest('.scrub-detail-panel').data('saving')) return;
        const $row = $(this).closest('.meta-field');
        const checked = $(this).is(':checked');
        $row.find('.meta-field-value').val(checked ? 'yes' : 'no');
        $row.find('.meta-toggle-display')
            .text($row.find('.meta-field-key').val().trim() === 'eBay' ? 'E' : (checked ? 'YES' : 'NO'))
            .removeClass('badge-success badge-danger')
            .addClass(checked ? 'badge-success' : 'badge-danger');
    });

// ── Zmena typu fieldu (ikona toggle/text) ──────────────────────────────────

$(document)
    .off('click.metaTypeSwitch', '.meta-type-icon')
    .on('click.metaTypeSwitch', '.meta-type-icon', function (e) {
        e.stopPropagation();
        if (!pcCanEdit() || $(this).closest('.scrub-detail-panel').data('saving')) return;
        const $field = $(this).closest('.meta-field');
        const current = $(this).data('current');
        const key = $field.find('.meta-field-key').val();
        const newType = current === 'toggle' ? 'text' : 'toggle';
        const newValue = newType === 'toggle' ? 'no' : '';

        const newRow = $(buildFieldRowHtmlTyped(key, newValue, newType));
        $field.replaceWith(newRow);
    });

// ── Add block ──────────────────────────────────────────────────────────────

$(document)
    .off('click.scrubAddBlock', '.btn-add-meta-block')
    .on('click.scrubAddBlock', '.btn-add-meta-block', function (e) {
        e.stopPropagation();
        if (!pcCanEdit() || $(this).closest('.scrub-detail-panel').data('saving')) return;
        const rowkey = $(this).data('rowkey');
        $('#editor-' + rowkey + ' .pc-custom-editor').append(buildBlockEditorHtml('', {}));
    });

// ── Add field (dropdown) ───────────────────────────────────────────────────

$(document)
    .off('click.scrubAddField', '.btn-add-meta-field')
    .on('click.scrubAddField', '.btn-add-meta-field', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!pcCanEdit() || $(this).closest('.scrub-detail-panel').data('saving')) return;
        const type = $(this).data('type') || 'text';
        const newRow = buildFieldRowHtmlTyped('', type === 'toggle' ? 'no' : '', type);
        $(this).closest('.meta-block').find('.meta-fields').append(newRow);
    });

// ── Remove field ──────────────────────────────────────────────────────────

$(document)
    .off('click.scrubRemoveField', '.btn-remove-meta-field')
    .on('click.scrubRemoveField', '.btn-remove-meta-field', function (e) {
        e.stopPropagation();
        if (!pcCanEdit() || $(this).closest('.scrub-detail-panel').data('saving')) return;
        $(this).closest('.meta-field').remove();
    });

// ── Remove block ──────────────────────────────────────────────────────────

$(document)
    .off('click.scrubRemoveBlock', '.btn-remove-meta-block')
    .on('click.scrubRemoveBlock', '.btn-remove-meta-block', function (e) {
        e.stopPropagation();
        if (!pcCanEdit() || $(this).closest('.scrub-detail-panel').data('saving')) return;
        if (confirm('Odstrániť tento blok?')) {
            $(this).closest('.meta-block').remove();
        }
    });

// Both metadata and model-year tracking are saved in one database transaction.
function pcUpdateRowBadges($row) {
    const meta = pcObject($row.data('meta')), tracking = pcObject($row.data('tracking'));
    const badge = function (yes, label, title, small) {
        return '<span class="badge meta-status-badge ' + (small ? 'config-mini-badge ' : '') +
            (yes ? 'badge-success' : 'badge-danger') + '" title="' + pcEscHtml(title) + '">' + label + '</span>';
    };
    PC_PRODUCTS.forEach(function (item) {
        const fields = pcObject(meta[item[0]]);
        let html = '<span class="badge-pair">';
        PC_PRODUCT_FIELDS.forEach(function (field) {
            const yes = pcYes(fields[field]);
            html += badge(yes, field === 'eBay' ? 'E' : (yes ? 'Y' : 'N'), field === 'eBay' ? 'eBay: ' + (yes ? 'YES' : 'NO') : field, false);
        });
        html += '</span>';
        if (item[0] === 'Plastics') html = '<a class="badge-pair-link" href="index.php?page=scrublistings&amp;modelcode=' + encodeURIComponent($row.data('modelcode')) + '">' + html + '</a>';
        $row.find('.' + item[1]).html(html);
    });
    let configHtml = '<span class="badge-pair config-badges">';
    PC_CONFIG.forEach(function (item) { configHtml += badge(pcYes(pcObject(meta.Configuration)[item[0]]), item[1], item[0], true); });
    $row.find('.config-status-cell').html(configHtml + '</span>');
    let trackingHtml = '<span class="badge-pair config-badges">', pending = false;
    PC_TRACKING.forEach(function (item) {
        const done = !tracking.trackid || !!Number(tracking[item[1]]);
        if (!done) pending = true;
        trackingHtml += badge(done, item[2], item[0], true);
    });
    $row.find('.tracking-status-cell').html(trackingHtml + '</span>').attr('data-order', pending ? '0' : '1').attr('data-search', pending ? 'Pending' : 'Complete');
    // Invalidate search/order data as well as the visible cells.
    if ($.fn.dataTable && $.fn.dataTable.isDataTable('#scrubTable')) {
        $('#scrubTable').DataTable().row($row).invalidate('dom');
    }
}
$(document).off('click.scrubSaveMeta', '.btn-save-model-meta').on('click.scrubSaveMeta', '.btn-save-model-meta', function () {
    if (!pcCanEdit()) return;
    const $btn = $(this), rowkey = $btn.data('rowkey'), $panel = $('#detail-' + rowkey), $row = pcRow(rowkey);
    if ($panel.data('saving')) return;
    const meta = collectMetaEditorData(rowkey), tracking = $panel.data('tracking-original');
    const payload = {
        brand: $btn.data('brand'), model: $btn.data('model'), rangeyear: $btn.data('rangeyear'),
        modelcode: $btn.data('modelcode'), meta_json: JSON.stringify(meta)
    };
    if (tracking) {
        const flags = { trackid: tracking.trackid };
        $('#editor-' + rowkey + ' .pc-tracking-toggle').each(function () { flags[$(this).data('field')] = this.checked ? 1 : 0; });
        payload.tracking_json = JSON.stringify(flags);
        payload.tracking_original_json = JSON.stringify(tracking);
    }
    $panel.data('saving', true);
    $panel.find('input, button').prop('disabled', true);
    $('#editor-' + rowkey).prop('inert', true);
    $btn.html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving…');
    pcMessage(rowkey, '');
    $.ajax({
        url: 'scripts/save_model_meta.php', method: 'POST', dataType: 'json', data: payload,
        success: function (resp) {
            if (!resp || !resp.ok) { pcMessage(rowkey, resp && resp.error ? resp.error : 'Changes could not be saved. Please try again.', true); return; }
            $row.data('meta', meta).attr('data-meta', JSON.stringify(meta));
            if (resp.tracking) $row.data('tracking', resp.tracking).attr('data-tracking', JSON.stringify(resp.tracking));
            pcUpdateRowBadges($row);
            $panel.data('saving', false);
            pcFinishEditing(rowkey);
            pcMessage(rowkey, 'All changes saved.');
            if ($.fn.dataTable && $.fn.dataTable.isDataTable('#scrubTable')) $('#scrubTable').DataTable().draw(false);
            $(document).trigger('productchart:saved');
        },
        error: function (xhr) {
            pcMessage(rowkey, xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'Save failed. Your changes are still here; please try again.', true);
        },
        complete: function () {
            $panel.data('saving', false);
            $panel.find('input, button').prop('disabled', false);
            $('#editor-' + rowkey).prop('inert', false);
            $btn.html('<i class="fas fa-save mr-1"></i> Save all changes');
        }
    });
});
