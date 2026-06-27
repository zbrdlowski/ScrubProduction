console.log('PRODUCT CHART ACTIONS LOADED v1.1');

// ── Helpers ────────────────────────────────────────────────────────────────

function pcEscHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Detekcia či hodnota je yes/no typ
function isYesNoValue(val) {
    if (typeof val !== 'string') return false;
    return ['yes', 'no'].includes(val.toLowerCase().trim());
}

// ── VIEW renderer ──────────────────────────────────────────────────────────

function renderMetaView(code, meta) {
    if (typeof meta === 'string') {
        try { meta = JSON.parse(meta); } catch (e) { meta = {}; }
    }
    meta = meta || {};

    const $container = $('#view-' + code);
    if (!$container.length) return;

    if (!meta || Object.keys(meta).length === 0) {
        $container.html('<div class="col-12 text-muted"><em>Žiadne meta dáta. Klikni Edit meta pre pridanie.</em></div>');
        return;
    }

    let html = '';
    Object.keys(meta).forEach(function (blockName) {
        const fields = meta[blockName] || {};
        let fieldsHtml = '';

        Object.keys(fields).forEach(function (key) {
            const val = String(fields[key] ?? '');
            let displayVal;
            const v = val.toLowerCase().trim();

            if (v === 'yes') {
                displayVal = '<span class="badge meta-status-badge badge-success"><i class="fas fa-check mr-1"></i>YES</span>';
            } else if (v === 'no') {
                displayVal = '<span class="badge meta-status-badge badge-danger"><i class="fas fa-times mr-1"></i>NO</span>';
            } else {
                displayVal = '<span class="text-light">' + pcEscHtml(val) + '</span>';
            }

            fieldsHtml += `
                <div class="field-row">
                    <span class="field-key">${pcEscHtml(key)}</span>
                    <span class="field-val">${displayVal}</span>
                </div>`;
        });

        html += `
            <div class="col-md-4 mb-3">
                <div class="block-card card">
                    <div class="card-header text-light">${pcEscHtml(blockName)}</div>
                    <div class="card-body">${fieldsHtml || '<span class="text-muted">Prázdny blok</span>'}</div>
                </div>
            </div>`;
    });

    $container.html(html);
}

// ── EDIT renderer ──────────────────────────────────────────────────────────

function renderMetaEditor(code, meta) {
    if (typeof meta === 'string') {
        try { meta = JSON.parse(meta); } catch (e) { meta = {}; }
    }
    meta = meta || {};

    if (Object.keys(meta).length === 0) {
        meta = {
            'Graphics': { 'Available': 'no', 'Web': 'no' },
            'Plastics': { 'Available': 'no', 'Web': 'no' },
            'Seat Cover': { 'Available': 'no', 'Web': 'no' },
            'Configuration': {
                'Create New Categories': 'yes',
                'Add Filters': 'yes',
                'Add Accessories': 'yes',
                'Add Existing Designs': 'yes'
            }
        };
    }

    const $editor = $('#editor-' + code);
    let html = '';

    Object.keys(meta).forEach(function (blockName) {
        html += buildBlockEditorHtml(blockName, meta[blockName] || {});
    });

    $editor.html(html);
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
                    ${checked ? 'YES' : 'NO'}
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

// ── Collector ──────────────────────────────────────────────────────────────

function collectMetaEditorData(code) {
    const data = {};

    $('#editor-' + code + ' .meta-block').each(function () {
        const blockName = $(this).find('.meta-block-name').val().trim();
        if (!blockName) return;

        data[blockName] = {};

        $(this).find('.meta-field').each(function () {
            const key = $(this).find('.meta-field-key').val().trim();
            const value = $(this).find('.meta-field-value').val().trim();
            if (key) data[blockName][key] = value;
        });

        if (Object.keys(data[blockName]).length === 0) {
            delete data[blockName];
        }
    });

    return data;
}

// ── Expand / Collapse riadku ───────────────────────────────────────────────

$(document)
    .off('click.scrubExpand', 'tr.model-row')
    .on('click.scrubExpand', 'tr.model-row', function (e) {
        if ($(e.target).closest('a, button').length) return;

        const rowkey = $(this).data('rowkey');
        const code = $(this).data('modelcode');
        const meta = $(this).data('meta') || {};
        const $panel = $('#detail-' + rowkey);
        const $icon = $(this).find('.toggle-icon');
        const isOpen = $(this).hasClass('open');

        // Zatvor ostatné otvorené
        $('tr.model-row.open').not(this).each(function () {
            const otherRowkey = $(this).data('rowkey');
            const $otherPanel = $('#detail-' + otherRowkey);
            const $otherWrapper = $otherPanel.closest('tr.scrub-detail-wrapper');
            $otherPanel.slideUp(150, function () {
                $otherWrapper.remove();
                $('#scrubDetailStore').append($otherPanel);
            });
            $(this).removeClass('open');
            $(this).find('.toggle-icon').removeClass('fa-chevron-down').addClass('fa-chevron-right');
            $('#edit-' + otherRowkey).hide();
            $('#view-' + otherRowkey).show();
        });

        if (isOpen) {
            const $wrapper = $panel.closest('tr.scrub-detail-wrapper');
            $panel.slideUp(150, function () {
                $wrapper.remove();
                $('#scrubDetailStore').append($panel);
            });
            $(this).removeClass('open');
            $icon.removeClass('fa-chevron-down').addClass('fa-chevron-right');
        } else {
            renderMetaView(rowkey, meta);

            // Obal panel do tr/td aby bol validný v tbody
            const $wrapper = $('<tr class="scrub-detail-wrapper"><td colspan="9" style="padding:0; border-top:none;"></td></tr>');
            $wrapper.find('td').append($panel);
            $panel.css('display', 'none');
            $(this).after($wrapper);
            $panel.slideDown(200);

            $(this).addClass('open');
            $icon.removeClass('fa-chevron-right').addClass('fa-chevron-down');
        }
    });

// ── Edit meta ──────────────────────────────────────────────────────────────

$(document)
    .off('click.scrubEditMeta', '.btn-edit-model-meta')
    .on('click.scrubEditMeta', '.btn-edit-model-meta', function (e) {
        e.stopPropagation();
        const code = $(this).data('modelcode');
        const rowkey = $(this).data('rowkey');
        const meta = $('tr.model-row[data-rowkey="' + rowkey + '"]').data('meta') || {};

        renderMetaEditor(rowkey, meta);
        $('#view-' + rowkey).hide();
        $('#edit-' + rowkey).show();
        $(this).hide();
    });

// ── Cancel edit ────────────────────────────────────────────────────────────

$(document)
    .off('click.scrubCancelMeta', '.btn-cancel-meta-edit')
    .on('click.scrubCancelMeta', '.btn-cancel-meta-edit', function (e) {
        e.stopPropagation();
        const rowkey = $(this).data('rowkey');
        $('#edit-' + rowkey).hide();
        $('#view-' + rowkey).show();
        $('.btn-edit-model-meta[data-rowkey="' + rowkey + '"]').show();
    });

// ── Toggle switch → hidden input sync ─────────────────────────────────────

$(document)
    .off('change.metaToggle', '.meta-field-toggle')
    .on('change.metaToggle', '.meta-field-toggle', function () {
        const $row = $(this).closest('.meta-field');
        const checked = $(this).is(':checked');
        $row.find('.meta-field-value').val(checked ? 'yes' : 'no');
        $row.find('.meta-toggle-display')
            .text(checked ? 'YES' : 'NO')
            .removeClass('badge-success badge-danger')
            .addClass(checked ? 'badge-success' : 'badge-danger');
    });

// ── Zmena typu fieldu (ikona toggle/text) ──────────────────────────────────

$(document)
    .off('click.metaTypeSwitch', '.meta-type-icon')
    .on('click.metaTypeSwitch', '.meta-type-icon', function (e) {
        e.stopPropagation();
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
        const rowkey = $(this).data('rowkey');
        $('#editor-' + rowkey).append(buildBlockEditorHtml('', {}));
    });

// ── Add field (dropdown) ───────────────────────────────────────────────────

$(document)
    .off('click.scrubAddField', '.btn-add-meta-field')
    .on('click.scrubAddField', '.btn-add-meta-field', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const type = $(this).data('type') || 'text';
        const newRow = buildFieldRowHtmlTyped('', type === 'toggle' ? 'no' : '', type);
        $(this).closest('.meta-block').find('.meta-fields').append(newRow);
    });

// ── Remove field ──────────────────────────────────────────────────────────

$(document)
    .off('click.scrubRemoveField', '.btn-remove-meta-field')
    .on('click.scrubRemoveField', '.btn-remove-meta-field', function (e) {
        e.stopPropagation();
        $(this).closest('.meta-field').remove();
    });

// ── Remove block ──────────────────────────────────────────────────────────

$(document)
    .off('click.scrubRemoveBlock', '.btn-remove-meta-block')
    .on('click.scrubRemoveBlock', '.btn-remove-meta-block', function (e) {
        e.stopPropagation();
        if (confirm('Odstrániť tento blok?')) {
            $(this).closest('.meta-block').remove();
        }
    });

// ── Save meta ─────────────────────────────────────────────────────────────

$(document)
    .off('click.scrubSaveMeta', '.btn-save-model-meta')
    .on('click.scrubSaveMeta', '.btn-save-model-meta', function (e) {
        e.stopPropagation();
        const $btn = $(this);
        const rowkey = $btn.data('rowkey');
        const code = $btn.data('modelcode');
        const metaData = collectMetaEditorData(rowkey);

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');

        $.ajax({
            url: 'scripts/save_model_meta.php',
            method: 'POST',
            dataType: 'json',
            data: {
                brand: $btn.data('brand'),
                model: $btn.data('model'),
                rangeyear: $btn.data('rangeyear'),
                modelcode: code,
                meta_json: JSON.stringify(metaData)
            },
            success: function (resp) {
                if (!resp || !resp.ok) {
                    alert(resp && resp.error ? resp.error : 'Save failed');
                    $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save');
                    return;
                }

                // Aktualizuj data-meta v riadku
                const $row = $('tr.model-row[data-rowkey="' + rowkey + '"]');
                $row.data('meta', metaData);

                // Refresh badges v riadku
                const getVal = function (block, field) {
                    return String(((metaData[block] || {})[field] || 'no')).toLowerCase().trim();
                };

                const makeBadgePair = function (avail, web, href) {
                    const a = avail === 'yes';
                    const w = web === 'yes';

                    const html =
                        '<span class="badge meta-status-badge ' + (a ? 'badge-success' : 'badge-danger') + '" title="Available">' +
                        (a ? 'Y' : 'N') +
                        '</span>' +
                        '<span class="badge meta-status-badge ' + (w ? 'badge-success' : 'badge-danger') + ' ml-1" title="Web">' +
                        (w ? 'Y' : 'N') +
                        '</span>';

                    return href && href !== '#'
                        ? '<a href="' + href + '">' + html + '</a>'
                        : html;
                };

                const makeConfigBadges = function (meta) {
                    const fields = [
                        ['Create New Categories', 'CNC'],
                        ['Add Filters', 'FLT'],
                        ['Add Accessories', 'ACC'],
                        ['Add Existing Designs', 'EXD']
                    ];
                    let html = '<span class="badge-pair config-badges">';
                    fields.forEach(function (f) {
                        const key = f[0], abbr = f[1];
                        const val = String(((meta['Configuration'] || {})[key] || 'no')).toLowerCase().trim();
                        const isYes = val === 'yes';
                        html += '<span class="badge meta-status-badge config-mini-badge ' +
                            (isYes ? 'badge-success' : 'badge-danger') + '" title="' + pcEscHtml(key) + '">' +
                            abbr + '</span>';
                    });
                    html += '</span>';
                    return html;
                };

                const plasticsHref = 'index.php?page=scrublistings&modelcode=' + encodeURIComponent(code);
                const $cells = $row.find('td');

                $cells.eq(5).html(makeConfigBadges(metaData));
                $cells.eq(6).html(makeBadgePair(getVal('Graphics', 'Available'), getVal('Graphics', 'Web'), '#'));
                $cells.eq(7).html(makeBadgePair(getVal('Plastics', 'Available'), getVal('Plastics', 'Web'), plasticsHref));
                $cells.eq(8).html(makeBadgePair(getVal('Seat Cover', 'Available'), getVal('Seat Cover', 'Web'), '#'));

                // Prepni späť na view
                $('#edit-' + rowkey).hide();
                renderMetaView(rowkey, metaData);
                $('#view-' + rowkey).show();
                $('.btn-edit-model-meta[data-rowkey="' + rowkey + '"]').show();

                $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save');

                // Flash zelená na riadok
                $row.css('background-color', '#1a3a1a');
                setTimeout(function () { $row.css('background-color', ''); }, 1200);
            },
            error: function (xhr) {
                console.error(xhr.responseText);
                alert('Save request failed');
                $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save');
            }
        });
    });