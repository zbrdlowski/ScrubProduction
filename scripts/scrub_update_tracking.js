/**
 * scrub_update_tracking.js
 * ------------------------------------------------------------------
 * "Update Tracking" — Web / eBay / Graphics Templates / Seatcover Templates
 * checklisty pre modely, ktorým bol nedávno pridaný/rozšírený modelový rok
 * (cez "Add / Update Model Year"). Endpoint: scrub_update_tracking_ajax.php
 *
 * Celé zabalené v IIFE, aby: (a) sa nič nedeklarovalo do globálneho scope
 * (predíde to konfliktom/duplicitným-deklaráciám, keby sa skript omylom
 * načítal 2x), (b) sme mohli obaliť inicializáciu do try/catch a mať istotu,
 * že chyba v jednej časti nezhodí registráciu zvyšku.
 * ------------------------------------------------------------------
 */
(function () {
    console.log('[scrub_update_tracking] script loaded');

    const AJAX_URL = 'scripts/scrub_update_tracking_ajax.php';

    const TRACKING_ITEMS = [
        ['Web', 'done_web'],
        ['eBay', 'done_ebay'],
        ['Graphics Templates', 'done_graphics_templates'],
        ['Seatcover Templates', 'done_seatcover_templates'],
    ];

    function ptEscHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parseTrackingData(tracking) {
        if (typeof tracking === 'string') {
            try { return JSON.parse(tracking); } catch (e) { return null; }
        }
        return tracking || null;
    }

    function isTrackingPending(tracking) {
        return !!tracking && TRACKING_ITEMS.some(function (item) {
            return !parseInt(tracking[item[1]], 10);
        });
    }

    function buildTrackingTableBadges(tracking) {
        const abbreviations = ['WEB', 'EB', 'GT', 'ST'];
        let html = '<span class="badge-pair config-badges">';
        TRACKING_ITEMS.forEach(function (item, index) {
            const isDone = !tracking || !!parseInt(tracking[item[1]], 10);
            html += '<span class="badge meta-status-badge config-mini-badge ' +
                (isDone ? 'badge-success' : 'badge-danger') + '" title="' +
                ptEscHtml(item[0]) + '">' + abbreviations[index] + '</span>';
        });
        return html + '</span>';
    }

    function refreshTrackingTableCell($row, tracking) {
        const pending = isTrackingPending(tracking);
        const $cell = $row.find('.tracking-status-cell');
        if (!$cell.length) return;

        $cell
            .attr('data-order', pending ? '0' : '1')
            .attr('data-search', pending ? 'Pending' : 'Complete')
            .html(buildTrackingTableBadges(tracking));

        // Obnov DataTables cache; prekreslenie prebehne pri najbližšom filtri/radení.
        if ($.fn.DataTable && $.fn.DataTable.isDataTable('#scrubTable')) {
            $('#scrubTable').DataTable().cell($cell).invalidate('dom');
        }
    }

    // ── VIEW renderer (4 mini bloky, col-md-3) ─────────────────────────────

    function renderTrackingView(rowkey, tracking) {
        const $container = $('#tracking-view-' + rowkey);
        if (!$container.length) return;

        if (!tracking) {
            $container.html(
                '<div class="col-12 text-muted"><em>Zatiaľ žiadny update na sledovanie — ' +
                'založí sa automaticky pri pridaní nového roku cez "Add / Update Model Year".</em></div>'
            );
            return;
        }

        let html = '';
        TRACKING_ITEMS.forEach(function (item) {
            const label = item[0], field = item[1];
            const isDone = !!parseInt(tracking[field], 10);
            const badge = isDone
                ? '<span class="badge meta-status-badge badge-success"><i class="fas fa-check mr-1"></i>DONE</span>'
                : '<span class="badge meta-status-badge badge-danger"><i class="fas fa-times mr-1"></i>PENDING</span>';

            html += `
                <div class="col-md-3 mb-3">
                    <div class="block-card card">
                        <div class="card-header text-light">${ptEscHtml(label)}</div>
                        <div class="card-body">
                            <div class="field-row">
                                <span class="field-key">Status</span>
                                <span class="field-val">${badge}</span>
                            </div>
                        </div>
                    </div>
                </div>`;
        });

        $container.html(html);
    }

    // ── EDIT renderer ────────────────────────────────────────────────────

    function renderTrackingEditor(rowkey, tracking) {
        const $editor = $('#tracking-editor-' + rowkey);
        if (!tracking) { $editor.html(''); return; }

        let html = '<div class="card bg-secondary mb-2"><div class="card-body py-2">';
        TRACKING_ITEMS.forEach(function (item) {
            const label = item[0], field = item[1];
            const checked = !!parseInt(tracking[field], 10);
            const toggleId = 'tt-' + rowkey + '-' + field;

            html += `
                <div class="tracking-toggle-row">
                    <span class="field-key">${ptEscHtml(label)}</span>
                    <div class="d-flex align-items-center" style="gap:10px;">
                        <div class="custom-control custom-switch">
                            <input type="checkbox"
                                   class="custom-control-input tracking-field-toggle"
                                   id="${toggleId}"
                                   data-field="${field}"
                                   ${checked ? 'checked' : ''}>
                            <label class="custom-control-label text-light" for="${toggleId}"></label>
                        </div>
                        <span class="tracking-toggle-display badge ${checked ? 'badge-success' : 'badge-danger'}" style="min-width:70px;">
                            ${checked ? 'DONE' : 'PENDING'}
                        </span>
                    </div>
                </div>`;
        });
        html += '</div></div>';

        $editor.html(html);
    }

    // ── Alert badge (počet nedokončených) ───────────────────────────────────

    function refreshPendingCount() {
        $.get(AJAX_URL, { action: 'count_pending' }, function (resp) {
            if (!resp || !resp.ok) return;
            const $badge = $('#trackingPendingBadge');
            $badge.text(resp.count);
            $badge.toggleClass('show', resp.count > 0);
        }, 'json').fail(function (xhr) {
            console.error('[scrub_update_tracking] count_pending zlyhalo:', xhr.status, xhr.responseText);
        });
    }

    // ── Alert modal: zoznam nedokončených tracking záznamov ────────────────

    function buildPendingRowHtml(r) {
        let cellsHtml = '';
        TRACKING_ITEMS.forEach(function (item) {
            const field = item[1];
            const val = parseInt(r[field], 10);
            cellsHtml += `
                <td class="text-center">
                    <input type="checkbox" class="tut-checkbox" data-trackid="${r.trackid}" data-field="${field}" ${val ? 'checked' : ''}>
                </td>`;
        });

        return `
            <tr data-trackid="${r.trackid}">
                <td>${ptEscHtml(r.brand)}</td>
                <td>${ptEscHtml(r.model)}</td>
                <td>${ptEscHtml(r.rangeyear)}</td>
                <td><code>${ptEscHtml(r.modelcode)}</code></td>
                <td class="text-center">${ptEscHtml(r.new_year)}</td>
                ${cellsHtml}
            </tr>`;
    }

    function loadPendingTrackingList() {
        console.log('[scrub_update_tracking] loadPendingTrackingList() called');
        const $body = $('#tut_body');
        if (!$body.length) {
            console.error('[scrub_update_tracking] #tut_body nenájdené v DOM — modal HTML zrejme chýba.');
            return;
        }
        $body.html('<tr><td colspan="9" class="text-center text-muted py-3">Načítavam…</td></tr>');

        $.get(AJAX_URL, { action: 'list_pending' }, function (resp) {
            console.log('[scrub_update_tracking] list_pending odpoveď:', resp);
            if (!resp || !resp.ok) {
                $body.html('<tr><td colspan="9" class="text-center text-danger py-3">' + (resp && resp.error ? resp.error : 'Chyba') + '</td></tr>');
                return;
            }
            if (!resp.rows.length) {
                $body.html('<tr><td colspan="9" class="text-center text-muted py-3">Žiadne nedokončené updaty 🎉</td></tr>');
                return;
            }

            let html = '';
            resp.rows.forEach(function (r) { html += buildPendingRowHtml(r); });
            $body.html(html);
        }, 'json').fail(function (xhr) {
            console.error('[scrub_update_tracking] list_pending zlyhalo:', xhr.status, xhr.responseText);
            $body.html('<tr><td colspan="9" class="text-center text-danger py-3">Chyba pri načítaní (HTTP ' + xhr.status + '). Pozri konzolu.</td></tr>');
        });
    }

    // Synchronizuj tracking blok v hlavnej tabuľke, ak je daný riadok otvorený
    function syncRowTrackingData(row) {
        $('tr.model-row').each(function () {
            const $row = $(this);
            const tracking = parseTrackingData($row.data('tracking'));
            if (!tracking || String(tracking.trackid) !== String(row.trackid)) return;

            const updated = Object.assign({}, tracking, {
                done_web: row.done_web,
                done_ebay: row.done_ebay,
                done_graphics_templates: row.done_graphics_templates,
                done_seatcover_templates: row.done_seatcover_templates,
            });
            $row.data('tracking', updated);
            refreshTrackingTableCell($row, updated);

            if ($row.hasClass('open')) {
                renderTrackingView($row.data('rowkey'), updated);
            }
        });
    }

    // ── Registrácia event handlerov — každá skupina vo vlastnom try/catch,  ──
    // aby prípadná chyba v jednej neznemožnila registráciu ostatných.

    function safeOn(label, fn) {
        try {
            fn();
        } catch (err) {
            console.error('[scrub_update_tracking] chyba pri registrácii "' + label + '":', err);
        }
    }

    // 1) Otvorenie alert modalu — PRIORITNE, hneď na začiatku registrácie.
    safeOn('alert modal open', function () {
        $(document).on('shown.bs.modal', '#updateTrackingModal', function () {
            loadPendingTrackingList();
        });

        // Záložný spôsob, keby z nejakého dôvodu 'shown.bs.modal' nenaskočil
        // (napr. iná verzia Bootstrapu / konflikt s iným pluginom) — natiahni
        // dáta rovno pri kliknutí na tlačidlo, s malým oneskorením kým sa modal zobrazí.
        $(document).on('click', '#btnUpdateTrackingAlert', function () {
            setTimeout(loadPendingTrackingList, 200);
        });
    });

    // 2) Prekresli tracking VIEW pri otvorení/zatvorení riadku
    safeOn('row expand tracking view', function () {
        $(document)
            .off('click.trackingExpand', 'tr.model-row')
            .on('click.trackingExpand', 'tr.model-row', function (e) {
                if ($(e.target).closest('a, button').length) return;
                const rowkey = $(this).data('rowkey');
                const tracking = parseTrackingData($(this).data('tracking'));
                renderTrackingView(rowkey, tracking);
            });
    });

    // 3) Edit tracking blok v rozklikanom riadku
    safeOn('edit tracking block', function () {
        $(document)
            .off('click.trackingEdit', '.btn-edit-tracking')
            .on('click.trackingEdit', '.btn-edit-tracking', function (e) {
                e.stopPropagation();
                const rowkey = $(this).data('rowkey');
                const tracking = parseTrackingData($('tr.model-row[data-rowkey="' + rowkey + '"]').data('tracking'));
                if (!tracking) return;

                renderTrackingEditor(rowkey, tracking);
                $('#tracking-view-' + rowkey).hide();
                $('#tracking-edit-' + rowkey).show();
                $(this).hide();
            });

        $(document)
            .off('click.trackingCancel', '.btn-cancel-tracking-edit')
            .on('click.trackingCancel', '.btn-cancel-tracking-edit', function (e) {
                e.stopPropagation();
                const rowkey = $(this).data('rowkey');
                $('#tracking-edit-' + rowkey).hide();
                $('#tracking-view-' + rowkey).show();
                $('.btn-edit-tracking[data-rowkey="' + rowkey + '"]').show();
            });

        $(document)
            .off('change.trackingToggle', '.tracking-field-toggle')
            .on('change.trackingToggle', '.tracking-field-toggle', function () {
                const checked = $(this).is(':checked');
                $(this).closest('.tracking-toggle-row').find('.tracking-toggle-display')
                    .text(checked ? 'DONE' : 'PENDING')
                    .removeClass('badge-success badge-danger')
                    .addClass(checked ? 'badge-success' : 'badge-danger');
            });

        $(document)
            .off('click.trackingSave', '.btn-save-tracking')
            .on('click.trackingSave', '.btn-save-tracking', function (e) {
                e.stopPropagation();
                const $btn = $(this);
                const rowkey = $btn.data('rowkey');
                const $row = $('tr.model-row[data-rowkey="' + rowkey + '"]');
                const tracking = parseTrackingData($row.data('tracking'));
                if (!tracking || !tracking.trackid) return;

                const payload = { action: 'update_flags', trackid: tracking.trackid };
                $('#tracking-editor-' + rowkey + ' .tracking-field-toggle').each(function () {
                    payload[$(this).data('field')] = $(this).is(':checked') ? 1 : 0;
                });

                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');

                $.post(AJAX_URL, payload, function (resp) {
                    if (!resp || !resp.ok) {
                        alert(resp && resp.error ? resp.error : 'Save failed');
                        $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save');
                        return;
                    }

                    const updated = Object.assign({}, tracking, {
                        done_web: resp.done_web,
                        done_ebay: resp.done_ebay,
                        done_graphics_templates: resp.done_graphics_templates,
                        done_seatcover_templates: resp.done_seatcover_templates,
                    });
                    $row.data('tracking', updated);
                    refreshTrackingTableCell($row, updated);
                    renderTrackingView(rowkey, updated);

                    $('#tracking-edit-' + rowkey).hide();
                    $('#tracking-view-' + rowkey).show();
                    $('.btn-edit-tracking[data-rowkey="' + rowkey + '"]').show();
                    $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save');

                    refreshPendingCount();
                }, 'json').fail(function (xhr) {
                    console.error('[scrub_update_tracking] update_flags zlyhalo:', xhr.status, xhr.responseText);
                    alert('Save request failed');
                    $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save');
                });
            });
    });

    // 4) Checkboxy priamo v alert modale
    safeOn('alert modal checkboxes', function () {
        $(document)
            .off('change.tutCheckbox', '.tut-checkbox')
            .on('change.tutCheckbox', '.tut-checkbox', function () {
                const $cb = $(this);
                const trackid = $cb.data('trackid');
                const field = $cb.data('field');
                const value = $cb.is(':checked') ? 1 : 0;

                $cb.prop('disabled', true);

                $.post(AJAX_URL, {
                    action: 'toggle_flag',
                    trackid: trackid,
                    field: field,
                    value: value,
                }, function (resp) {
                    $cb.prop('disabled', false);
                    if (!resp || !resp.ok) {
                        alert(resp && resp.error ? resp.error : 'Chyba pri ukladaní');
                        $cb.prop('checked', !value);
                        return;
                    }

                    const row = resp.row;
                    const allDone = !!(row.done_web && row.done_ebay && row.done_graphics_templates && row.done_seatcover_templates);
                    const $tr = $cb.closest('tr');

                    syncRowTrackingData(row);

                    if (allDone) {
                        $tr.addClass('tracking-row-done');
                        setTimeout(function () {
                            $tr.fadeOut(300, function () { $(this).remove(); });
                        }, 500);
                    }

                    refreshPendingCount();
                }, 'json').fail(function (xhr) {
                    console.error('[scrub_update_tracking] toggle_flag zlyhalo:', xhr.status, xhr.responseText);
                    $cb.prop('disabled', false);
                    $cb.prop('checked', !value);
                    alert('Chyba servera pri ukladaní.');
                });
            });
    });

    console.log('[scrub_update_tracking] všetky handlery zaregistrované');
})();
