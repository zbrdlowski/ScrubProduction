/**
 * scrub_update_tracking.js
 * ------------------------------------------------------------------
 * "Update Tracking" — Web / eBay / Graphics Templates / Seatcover Templates
 * Alert modal pre modely, ktorým bol nedávno pridaný/rozšírený modelový rok
 * (cez "Add / Update Model Year"). Endpoint: scrub_update_tracking_ajax.php
 * Detail modelu a spoločné uloženie spravuje product_chart_actions.js.
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
    const CAN_EDIT = window.productChartCanEdit === true;

    const TRACKING_ITEMS = [
        ['Web', 'done_web'],
        ['eBay', 'done_ebay'],
        ['Graphics Templates', 'done_graphics_templates'],
        ['Seatcover Templates', 'done_seatcover_templates'],
        ['Products', 'done_products'],
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

    function getTrackingFilters() {
        const filters = window.productChartTrackingFilters || {};
        return {
            brand: filters.brand || '',
            model: filters.model || '',
            range: filters.range || '',
        };
    }

    function trackingRequestData(action) {
        return Object.assign({ action: action }, getTrackingFilters());
    }

    // ── Alert badge (počet nedokončených) ───────────────────────────────────

    function refreshPendingCount() {
        if (!CAN_EDIT) return;
        $.get(AJAX_URL, trackingRequestData('count_pending'), function (resp) {
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
        if (!CAN_EDIT) return;
        console.log('[scrub_update_tracking] loadPendingTrackingList() called');
        const $body = $('#tut_body');
        if (!$body.length) {
            console.error('[scrub_update_tracking] #tut_body nenájdené v DOM — modal HTML zrejme chýba.');
            return;
        }
        $body.html('<tr><td colspan="10" class="text-center text-muted py-3">Načítavam…</td></tr>');

        $.get(AJAX_URL, trackingRequestData('list_pending'), function (resp) {
            console.log('[scrub_update_tracking] list_pending odpoveď:', resp);
            if (!resp || !resp.ok) {
                $body.html('<tr><td colspan="10" class="text-center text-danger py-3">' + (resp && resp.error ? resp.error : 'Chyba') + '</td></tr>');
                return;
            }
            if (!resp.rows.length) {
                $body.html('<tr><td colspan="10" class="text-center text-muted py-3">Žiadne nedokončené updaty 🎉</td></tr>');
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
                done_products: row.done_products,
            });
            $row.data('tracking', updated).attr('data-tracking', JSON.stringify(updated));
            pcUpdateRowBadges($row);

            if ($row.hasClass('open')) {
                renderMetaView($row.data('rowkey'), $row.data('meta'));
                const $panel = $('#detail-' + $row.data('rowkey'));
                if ($panel.data('editing')) {
                    pcMessage($row.data('rowkey'), 'Tracking changed in the alert. Cancel and reopen the editor to load the latest status.', true);
                }
            }
        });
        if ($.fn.dataTable && $.fn.dataTable.isDataTable('#scrubTable')) $('#scrubTable').DataTable().draw(false);
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

    $(document).off('productchart:saved.tracking').on('productchart:saved.tracking', refreshPendingCount);

    // 4) Checkboxy priamo v alert modale
    safeOn('alert modal checkboxes', function () {
        $(document)
            .off('change.tutCheckbox', '.tut-checkbox')
            .on('change.tutCheckbox', '.tut-checkbox', function () {
                if (!CAN_EDIT) return;
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
                    const allDone = TRACKING_ITEMS.every(function (item) { return !!Number(row[item[1]]); });
                    const $tr = $cb.closest('tr');

                    syncRowTrackingData(row);

                    if (allDone) {
                        $tr.addClass('tracking-row-done');
                        setTimeout(function () {
                            $tr.fadeOut(300, function () {
                                $(this).remove();
                                if (!$('#tut_body tr[data-trackid]').length) {
                                    $('#tut_body').html('<tr><td colspan="10" class="text-center text-muted py-3">Žiadne nedokončené updaty 🎉</td></tr>');
                                }
                            });
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
