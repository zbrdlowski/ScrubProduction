/**
 * scrub_model_manage.js
 * ------------------------------------------------------------------
 * Logika pre modal "Add / Update Model Year" na product_chart.php.
 * Endpoint: scrub_model_manage_ajax.php
 *
 * Dva režimy:
 *  - "add"  (predvolený) — pridanie NOVÉHO roku ku skupine (existujúce
 *            správanie: rozšíri rangeyear, vloží nový scrubdata riadok
 *            + zvolené kompatibilné modely pre ten nový rok).
 *  - "edit" — editácia KONKRÉTNEHO existujúceho roku (napr. 2017 v strede
 *            rozsahu 2016-2018). Nemení scrubdata/rangeyear, iba CRUD nad
 *            scrubcompat riadkami pre daný compatcode+compatyear: pridanie,
 *            premenovanie, alebo zmazanie jednotlivých kompatibilných
 *            modelov. Stĺpec "Compat Year" je v tomto režime viditeľný.
 * ------------------------------------------------------------------
 */
(function () {
    const AJAX_URL = 'scripts/scrub_model_manage_ajax.php';

    let compatRowSeq = 0;
    let groupExists = false;
    let existingYears = [];
    let loadedBrand = '';
    let loadedModel = '';
    let editingYear = null; // null = "add new year" režim; inak konkrétny rok, ktorý editujeme

    function resetModal() {
        $('#mym_brand').val('').prop('readonly', false);
        $('#mym_model').val('').prop('readonly', false);
        $('#mym_modelcode').val('').prop('readonly', false);
        $('#mym_generate_code').prop('disabled', false);
        $('#mym_newyear').val('');
        $('#mym_status').html('');
        $('#mym_existing_years').html('<span class="text-muted">Nový modelcode – zatiaľ žiadne roky.</span>');
        $('#mym_ambiguous_picker').hide().empty();
        $('#mym_year_pills').hide().empty();
        $('#mym_edit_year_banner').hide().text('');
        $('#mym_newyear_group').show();
        $('#mym_rangeyear_preview').text('—');
        $('#mym_compat_table').removeClass('show-year-col');
        $('#mym_compat_body').empty();
        $('#mym_search_results').hide().empty();
        $('#mym_save_btn').text('Save').prop('disabled', false);
        compatRowSeq = 0;
        groupExists = false;
        existingYears = [];
        loadedBrand = '';
        loadedModel = '';
        editingYear = null;
    }

    function escAttr(str) {
        return $('<div>').text(str == null ? '' : str).html();
    }

    // compatid > 0  => existujúci riadok v scrubcompat (update/delete)
    // compatid = 0  => nový, zatiaľ neuložený riadok (insert, alebo len zahoď)
    function addCompatRow(brand = '', model = '', checked = true, compatid = 0, compatyear = '') {
        compatRowSeq++;
        const rid = 'compatrow_' + compatRowSeq;
        const row = $(`
            <tr id="${rid}" data-compatid="${compatid || 0}">
                <td class="text-center">
                    <input type="checkbox" class="mym-compat-check" ${checked ? 'checked' : ''}>
                </td>
                <td><input type="text" class="form-control form-control-sm mym-compat-brand" value="${escAttr(brand)}"></td>
                <td><input type="text" class="form-control form-control-sm mym-compat-model" value="${escAttr(model)}"></td>
                <td class="text-center mym-year-col">
                    <input type="number" class="form-control form-control-sm mym-compat-year" value="${escAttr(compatyear)}">
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger mym-remove-row"><i class="fas fa-times"></i></button>
                </td>
            </tr>
        `);
        row.find('.mym-remove-row').on('click', function () {
            const cid = parseInt(row.data('compatid'), 10) || 0;
            if (editingYear !== null && cid > 0) {
                // existujúci záznam v edit režime -> označ na zmazanie (skutočný DELETE pri Save),
                // ale nechaj riadok viditeľný (šedý/preškrtnutý), nech je jasné čo sa stane.
                row.addClass('mym-row-marked-delete');
                row.find('.mym-compat-check').prop('checked', false);
                row.find('input').prop('disabled', true);
                row.find('.mym-remove-row')
                    .removeClass('btn-outline-danger').addClass('btn-outline-secondary')
                    .html('<i class="fas fa-undo"></i>')
                    .off('click')
                    .on('click', function () {
                        row.removeClass('mym-row-marked-delete');
                        row.find('input').prop('disabled', false);
                        row.find('.mym-compat-check').prop('checked', true);
                        addCompatRowRestoreButton(row);
                    });
            } else {
                // nový, zatiaľ neuložený riadok -> jednoducho ho zahoď z formulára
                row.remove();
            }
        });
        $('#mym_compat_body').append(row);
    }

    function addCompatRowRestoreButton(row) {
        row.find('.mym-remove-row')
            .removeClass('btn-outline-secondary').addClass('btn-outline-danger')
            .html('<i class="fas fa-times"></i>')
            .off('click')
            .on('click', function () {
                const cid = parseInt(row.data('compatid'), 10) || 0;
                if (editingYear !== null && cid > 0) {
                    row.addClass('mym-row-marked-delete');
                    row.find('.mym-compat-check').prop('checked', false);
                    row.find('input').prop('disabled', true);
                    row.find('.mym-remove-row')
                        .removeClass('btn-outline-danger').addClass('btn-outline-secondary')
                        .html('<i class="fas fa-undo"></i>')
                        .off('click')
                        .on('click', function () {
                            row.removeClass('mym-row-marked-delete');
                            row.find('input').prop('disabled', false);
                            row.find('.mym-compat-check').prop('checked', true);
                            addCompatRowRestoreButton(row);
                        });
                } else {
                    row.remove();
                }
            });
    }

    function recomputeRangePreview() {
        const newyear = parseInt($('#mym_newyear').val(), 10);
        if (!newyear) {
            $('#mym_rangeyear_preview').text('—');
            return;
        }
        if (!existingYears.length) {
            // Úplne nový model — žiadny zmysel v "2027-2027", zobraz iba samotný rok
            $('#mym_rangeyear_preview').text(String(newyear));
            return;
        }
        const all = existingYears.concat([newyear]);
        const min = Math.min(...all);
        const max = Math.max(...all);
        $('#mym_rangeyear_preview').text(min === max ? String(min) : (min + '-' + max));
    }

    function renderAmbiguousPicker(modelcode, groups) {
        const $picker = $('#mym_ambiguous_picker').empty();
        const $list = $('<div class="list-group"></div>');
        $list.append('<div class="px-2 py-1 text-warning small">Tento kód má viacero modelov — vyber, ktorý chceš upraviť:</div>');
        groups.forEach(function (g) {
            const item = $('<a href="#" class="list-group-item list-group-item-action py-1 px-2"></a>')
                .text(g.brand + ' — ' + g.model)
                .on('click', function (e) {
                    e.preventDefault();
                    $picker.hide().empty();
                    loadGroup(modelcode, g.brand, g.model);
                });
            $list.append(item);
        });
        $picker.append($list).show();
    }

    // ── Prepínanie medzi "Add new year" a "Edit existing year" ────────────

    function renderYearPills() {
        const $pills = $('#mym_year_pills').empty();
        if (!groupExists || !existingYears.length) {
            $pills.hide();
            return;
        }

        const addBtn = $('<button type="button" class="btn btn-sm mym-year-pill mym-year-pill-add"></button>')
            .html('<i class="fas fa-plus mr-1"></i>Nový rok')
            .on('click', function () { setAddYearMode(); });
        $pills.append(addBtn);

        existingYears.forEach(function (y) {
            const btn = $('<button type="button" class="btn btn-sm mym-year-pill"></button>')
                .text(y)
                .on('click', function () { setEditYearMode(y); });
            $pills.append(btn);
        });

        highlightActivePill();
        $pills.show();
    }

    function highlightActivePill() {
        $('#mym_year_pills .mym-year-pill').removeClass('active');
        if (editingYear === null) {
            $('#mym_year_pills .mym-year-pill-add').addClass('active');
        } else {
            $('#mym_year_pills .mym-year-pill').filter(function () {
                return $(this).text().trim() === String(editingYear);
            }).addClass('active');
        }
    }

    function setAddYearMode() {
        editingYear = null;
        highlightActivePill();

        $('#mym_newyear_group').show();
        $('#mym_edit_year_banner').hide().text('');
        $('#mym_compat_table').removeClass('show-year-col');
        $('#mym_add_compat_row').show();
        $('#mym_save_btn').text('Save');
        $('#mym_status').html('');

        // znovu natiahni "template" kompatibilné modely (z posledného roku) pre pridanie nového roku
        loadGroup($('#mym_modelcode').val().trim(), loadedBrand, loadedModel, true);
    }

    function setEditYearMode(year) {
        editingYear = year;
        highlightActivePill();

        $('#mym_newyear_group').hide();
        $('#mym_edit_year_banner')
            .html('<i class="fas fa-edit mr-1"></i>Upravuješ kompatibilné modely pre rok <strong>' + year + '</strong> — zmeny sa ukladajú priamo do scrubcompat.')
            .show();
        $('#mym_compat_table').addClass('show-year-col');
        $('#mym_add_compat_row').show();
        $('#mym_save_btn').text('Save changes for ' + year);
        $('#mym_status').html('');
        $('#mym_compat_body').empty();

        const modelcode = $('#mym_modelcode').val().trim();

        $.get(AJAX_URL, { action: 'get_year_compat', modelcode: modelcode, year: year }, function (resp) {
            if (!resp.ok) {
                $('#mym_status').html('<div class="alert alert-danger py-1 px-2 mb-0">' + resp.error + '</div>');
                return;
            }
            (resp.rows || []).forEach(function (r) {
                addCompatRow(r.compatbrand, r.compatmodel, true, r.compatid, r.compatyear);
            });
            if (!resp.rows.length) {
                $('#mym_status').html(
                    '<div class="alert alert-info py-1 px-2 mb-0">Pre rok ' + year + ' zatiaľ nie sú žiadne kompatibilné modely — pridaj ich cez "+ Add row".</div>'
                );
            }
        }, 'json').fail(function (xhr) {
            console.error('get_year_compat zlyhalo:', xhr.status, xhr.responseText);
            $('#mym_status').html(
                '<div class="alert alert-danger py-1 px-2 mb-0">Chyba pri načítaní (HTTP ' + xhr.status + '). Pozri konzolu.</div>'
            );
        });
    }

    // silentReset = true → nevyresetuj brand/model/modelcode polia (voláme to interne pri prepnutí späť do "add" režimu)
    function loadGroup(modelcode, brand, model, silentReset) {
        $('#mym_ambiguous_picker').hide().empty();
        const params = { action: 'get_group', modelcode: modelcode };
        if (brand) params.brand = brand;
        if (model) params.model = model;

        $.get(AJAX_URL, params, function (resp) {
            if (!resp.ok) {
                $('#mym_status').html('<div class="alert alert-danger py-1 px-2 mb-0">' + resp.error + '</div>');
                return;
            }

            if (resp.ambiguous) {
                $('#mym_existing_years').html('<span class="text-muted">Modelcode je zdieľaný viacerými modelmi.</span>');
                $('#mym_year_pills').hide().empty();
                $('#mym_compat_body').empty();
                renderAmbiguousPicker(modelcode, resp.groups);
                return;
            }

            groupExists = resp.exists;
            existingYears = resp.years || [];
            loadedBrand = resp.brand || '';
            loadedModel = resp.model || '';

            if (groupExists) {
                if (!silentReset) {
                    $('#mym_brand').val(resp.brand).prop('readonly', true);
                    $('#mym_model').val(resp.model).prop('readonly', true);
                    $('#mym_modelcode').prop('readonly', true);
                    $('#mym_generate_code').prop('disabled', true);
                }
                $('#mym_existing_years').html(
                    'Existujúce roky: <strong>' + existingYears.join(', ') + '</strong>' +
                    ' &nbsp;|&nbsp; Aktuálny rangeyear: <strong>' + resp.rangeyear + '</strong>'
                );
                $('#mym_compat_body').empty();
                (resp.compat_template || []).forEach(function (r) {
                    addCompatRow(r.compatbrand, r.compatmodel, true);
                });
                if (resp.template_source_year) {
                    $('#mym_template_hint').text(
                        'Predvyplnené kompatibilné modely z roku ' + resp.template_source_year +
                        ' — odčiarkni tie, ktoré sa už nevyrábajú, alebo pridaj nové.'
                    );
                } else {
                    $('#mym_template_hint').text('');
                }
                renderYearPills();
            } else {
                $('#mym_brand').prop('readonly', false);
                $('#mym_model').prop('readonly', false);
                $('#mym_modelcode').prop('readonly', false);
                $('#mym_generate_code').prop('disabled', false);
                $('#mym_existing_years').html('<span class="text-muted">Nový modelcode – zatiaľ žiadne roky.</span>');
                $('#mym_year_pills').hide().empty();
                $('#mym_compat_body').empty();
                $('#mym_template_hint').text('Nový model – pridaj kompatibilné modely ručne.');
            }
            recomputeRangePreview();
        }, 'json').fail(function (xhr) {
            console.error('get_group zlyhalo:', xhr.status, xhr.responseText);
            $('#mym_status').html(
                '<div class="alert alert-danger py-1 px-2 mb-0">Chyba pri načítaní modelu (HTTP ' + xhr.status + '). Pozri konzolu / Network tab.</div>'
            );
        });
    }

    $(document).on('shown.bs.modal', '#modelYearModal', function () {
        resetModal();
    });

    $(document).on('click', '#mym_generate_code', function () {
        const $btn = $(this);
        $btn.prop('disabled', true);

        $.get(AJAX_URL, { action: 'generate_modelcode' }, function (resp) {
            $btn.prop('disabled', false);
            if (!resp.ok) {
                $('#mym_status').html('<div class="alert alert-danger py-1 px-2 mb-0">' + (resp.error || 'Generovanie zlyhalo.') + '</div>');
                return;
            }

            $('#mym_modelcode').val(resp.code);

            // Nový, garantovane unikátny kód → čistá skupina, žiadne existujúce roky
            $('#mym_ambiguous_picker').hide().empty();
            groupExists = false;
            existingYears = [];
            loadedBrand = '';
            loadedModel = '';
            $('#mym_existing_years').html('<span class="text-muted">Nový modelcode – zatiaľ žiadne roky.</span>');
            $('#mym_year_pills').hide().empty();
            $('#mym_compat_body').empty();
            $('#mym_template_hint').text('Nový model – pridaj kompatibilné modely ručne.');
            recomputeRangePreview();
        }, 'json').fail(function (xhr) {
            $btn.prop('disabled', false);
            console.error('generate_modelcode zlyhalo:', xhr.status, xhr.responseText);
            $('#mym_status').html('<div class="alert alert-danger py-1 px-2 mb-0">Chyba servera pri generovaní kódu (HTTP ' + xhr.status + ').</div>');
        });
    });

    $(document).on('input', '#mym_search', function () {
        const q = $(this).val().trim();
        if (q.length < 2) {
            $('#mym_search_results').hide().empty();
            return;
        }
        $.get(AJAX_URL, { action: 'search_modelcode', q: q }, function (resp) {
            if (!resp.ok || !resp.rows.length) {
                $('#mym_search_results').hide().empty();
                return;
            }
            const list = $('#mym_search_results').empty();
            resp.rows.forEach(function (r) {
                const item = $('<a href="#" class="list-group-item list-group-item-action py-1 px-2"></a>')
                    .text(r.brand + ' — ' + r.model + ' [' + r.modelcode + ']')
                    .on('click', function (e) {
                        e.preventDefault();
                        $('#mym_modelcode').val(r.modelcode);
                        $('#mym_search').val('');
                        list.hide().empty();
                        loadGroup(r.modelcode, r.brand, r.model);
                    });
                list.append(item);
            });
            list.show();
        }, 'json').fail(function (xhr) {
            console.error('search_modelcode zlyhalo:', xhr.status, xhr.responseText);
            $('#mym_status').html(
                '<div class="alert alert-danger py-1 px-2 mb-0">Chyba pri vyhľadávaní (HTTP ' + xhr.status + '). Pozri konzolu.</div>'
            );
        });
    });

    $(document).on('blur', '#mym_modelcode', function () {
        if ($(this).is('[readonly]')) return; // skupina už načítaná, nič nerob
        const code = $(this).val().trim();
        if (code === '') return;
        const brand = $('#mym_brand').is(':not([readonly])') ? $('#mym_brand').val().trim() : '';
        const model = $('#mym_model').is(':not([readonly])') ? $('#mym_model').val().trim() : '';
        loadGroup(code, brand, model);
    });

    $(document).on('input', '#mym_newyear', recomputeRangePreview);

    $(document).on('click', '#mym_add_compat_row', function () {
        if (editingYear !== null) {
            addCompatRow('', '', true, 0, editingYear);
        } else {
            addCompatRow('', '', true);
        }
    });

    // ── Save: "add new year" režim (pôvodné správanie) ─────────────────────

    function saveNewYear() {
        const brand = $('#mym_brand').val().trim();
        const model = $('#mym_model').val().trim();
        const modelcode = $('#mym_modelcode').val().trim();
        const newyear = parseInt($('#mym_newyear').val(), 10);

        if (!brand || !model || !modelcode || !newyear) {
            $('#mym_status').html('<div class="alert alert-danger py-1 px-2 mb-0">Vyplň brand, model, modelcode a rok.</div>');
            return;
        }

        const compatRows = [];
        $('#mym_compat_body tr').each(function () {
            if (!$(this).find('.mym-compat-check').is(':checked')) return;
            const cb = $(this).find('.mym-compat-brand').val().trim();
            const cm = $(this).find('.mym-compat-model').val().trim();
            if (cb && cm) compatRows.push({ compatbrand: cb, compatmodel: cm });
        });

        const $btn = $('#mym_save_btn');
        $btn.prop('disabled', true).text('Ukladám…');

        $.ajax({
            url: AJAX_URL + '?action=save',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                brand: brand,
                model: model,
                modelcode: modelcode,
                newyear: newyear,
                compat_rows: compatRows,
            }),
            dataType: 'json',
        }).done(function (resp) {
            if (!resp.ok) {
                $('#mym_status').html('<div class="alert alert-danger py-1 px-2 mb-0">' + resp.error + '</div>');
                $btn.prop('disabled', false).text('Save');
                return;
            }
            $('#mym_status').html(
                '<div class="alert alert-success py-1 px-2 mb-0">Uložené. Nový rangeyear: ' +
                resp.new_rangeyear + ', pridaných compat riadkov: ' + resp.inserted_compat + '. Stránka sa obnoví…</div>'
            );
            setTimeout(function () { window.location.reload(); }, 1200);
        }).fail(function () {
            $('#mym_status').html('<div class="alert alert-danger py-1 px-2 mb-0">Chyba servera pri ukladaní.</div>');
            $btn.prop('disabled', false).text('Save');
        });
    }

    // ── Save: "edit existing year" režim (CRUD nad scrubcompat) ────────────

    function saveYearCompat() {
        const modelcode = $('#mym_modelcode').val().trim();
        const year = editingYear;
        if (!modelcode || !year) return;

        const compatRows = [];
        $('#mym_compat_body tr').each(function () {
            const $row = $(this);
            const compatid = parseInt($row.data('compatid'), 10) || 0;
            const isMarkedDelete = $row.hasClass('mym-row-marked-delete');

            if (isMarkedDelete) {
                if (compatid > 0) compatRows.push({ compatid: compatid, deleted: true });
                return;
            }

            const checked = $row.find('.mym-compat-check').is(':checked');
            const cb = $row.find('.mym-compat-brand').val().trim();
            const cm = $row.find('.mym-compat-model').val().trim();
            const cy = parseInt($row.find('.mym-compat-year').val(), 10) || year;

            if (!checked) {
                if (compatid > 0) compatRows.push({ compatid: compatid, deleted: true });
                return; // nezaškrtnutý nový (neuložený) riadok -> jednoducho ignoruj
            }

            if (!cb || !cm) return;
            compatRows.push({ compatid: compatid, compatbrand: cb, compatmodel: cm, compatyear: cy });
        });

        const $btn = $('#mym_save_btn');
        $btn.prop('disabled', true).text('Ukladám…');

        $.ajax({
            url: AJAX_URL + '?action=save_year_compat',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ modelcode: modelcode, year: year, compat_rows: compatRows }),
            dataType: 'json',
        }).done(function (resp) {
            if (!resp.ok) {
                $('#mym_status').html('<div class="alert alert-danger py-1 px-2 mb-0">' + resp.error + '</div>');
                $btn.prop('disabled', false).text('Save changes for ' + year);
                return;
            }
            $('#mym_status').html(
                '<div class="alert alert-success py-1 px-2 mb-0">Uložené pre rok ' + year + ' — pridané: ' +
                resp.inserted + ', upravené: ' + resp.updated + ', zmazané: ' + resp.deleted + '.</div>'
            );
            // znova natiahni aktuálny stav pre tento rok (nech vidno reálne uložené dáta)
            setEditYearMode(year);
        }).fail(function (xhr) {
            console.error('save_year_compat zlyhalo:', xhr.status, xhr.responseText);
            $('#mym_status').html('<div class="alert alert-danger py-1 px-2 mb-0">Chyba servera pri ukladaní.</div>');
            $btn.prop('disabled', false).text('Save changes for ' + year);
        });
    }

    $(document).on('click', '#mym_save_btn', function () {
        if (editingYear !== null) {
            saveYearCompat();
        } else {
            saveNewYear();
        }
    });
})();