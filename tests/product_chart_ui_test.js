const {chromium} = require('playwright');
const fs = require('fs'), path = require('path'), assert = require('assert/strict');
(async () => {
    const browser = await chromium.launch({channel:'chrome', headless:true});
    try {
        const context = await browser.newContext({viewport:{width:1700,height:1200}});
        const root = path.resolve(__dirname, '..'), errors = [];
        const oldOrder = ['expand','brand','model','range','code','graphics','plastics','seat_cover','config','update'];
        let preferences = {column_order:oldOrder,filters:{}}, writes = 0, failSave = false, holdSave = false, releaseSave;
        let tracking = {trackid:1,new_year:2027,done_web:1,done_ebay:0,done_graphics_templates:1,done_seatcover_templates:0,done_products:0};
        let lastMeta, lastPayload;
        const flags = ['done_web','done_ebay','done_graphics_templates','done_seatcover_templates','done_products'];
        await context.route('http://localhost/darkscrub/**', async route => {
            const request = route.request(), url = new URL(request.url()), relative = decodeURIComponent(url.pathname.slice('/darkscrub/'.length));
            if (relative === 'scripts/user_ui_preferences_ajax.php') {
                if (request.method() === 'POST') preferences = JSON.parse(request.postData()).value;
                return route.fulfill({json:{ok:true,preferences:{product_chart:preferences}}});
            }
            if (relative === 'scripts/save_model_meta.php') {
                writes++; lastPayload = Object.fromEntries(new URLSearchParams(request.postData()));
                if (holdSave) { holdSave = false; await new Promise(resolve => { releaseSave = resolve; }); }
                if (failSave) { failSave = false; return route.fulfill({status:500,json:{ok:false,error:'Test save failed. Nothing was saved.'}}); }
                lastMeta = JSON.parse(lastPayload.meta_json);
                if (lastPayload.tracking_json) tracking = {...tracking,...JSON.parse(lastPayload.tracking_json)};
                return route.fulfill({json:{ok:true,tracking:lastPayload.tracking_json ? tracking : null}});
            }
            if (relative === 'scripts/scrub_update_tracking_ajax.php') {
                const data = request.method() === 'POST' ? new URLSearchParams(request.postData()) : url.searchParams;
                if (data.get('action') === 'toggle_flag') { tracking[data.get('field')] = Number(data.get('value')); return route.fulfill({json:{ok:true,row:tracking}}); }
                if (data.get('action') === 'count_pending') return route.fulfill({json:{ok:true,count:flags.every(flag=>tracking[flag]) ? 0 : 1}});
                return route.fulfill({json:{ok:true,rows:[{...tracking,brand:'Beta',model:'RR Enduro 4T 125 LC',rangeyear:'2021-2027',modelcode:'C57F'}]}});
            }
            const file = path.resolve(root, relative);
            if (!file.startsWith(root + path.sep) || file.endsWith('.php') || !fs.existsSync(file)) throw Error('Unexpected request: ' + relative);
            const mime = {'.html':'text/html','.js':'text/javascript','.css':'text/css','.woff2':'font/woff2','.woff':'font/woff','.ttf':'font/ttf'};
            return route.fulfill({body:fs.readFileSync(file),contentType:mime[path.extname(file)] || 'application/octet-stream'});
        });
        const page = await context.newPage(); page.on('pageerror',e=>errors.push(e.message)); page.setDefaultTimeout(8000);
        const url = 'http://localhost/darkscrub/.cache/product-chart/preview.html';
        await page.goto(url); await page.locator('#scrubTable_wrapper').waitFor();
        const expected = [...oldOrder]; expected.splice(expected.indexOf('graphics')+1,0,'gfp');
        assert.deepEqual(await page.locator('#productChartColumnList li').evaluateAll(items=>items.map(item=>item.dataset.columnId)),expected);
        assert.deepEqual(await page.locator('#scrubTable > thead > tr > th').allTextContents(),['','BRAND','MODEL','RANGE','CODE','GRAPHICS','GFP','PLASTICS','SEAT COVER','CONFIG','UPDATE']);
        const row = page.locator('tr.model-row[data-modelcode="C57F"]');
        assert.deepEqual(await row.locator('.gfp-status-cell .badge').allTextContents(),['N','N','E']);
        await row.locator('td').nth(1).click();
        const panel = page.locator('tr.scrub-detail-wrapper .scrub-detail-panel');
        assert.equal(await panel.locator('.pc-matrix tbody tr').count(),4);
        assert.equal(await panel.locator('input').count(),0);
        assert.equal(await panel.locator('.btn-edit-model-meta').count(),1);
        await panel.screenshot({path:path.join(root,'.cache/product-chart/detail-view.png')});
        await panel.locator('.btn-edit-model-meta').click();
        const edit = panel.locator('.meta-edit-area');
        assert.equal(await edit.locator('.btn-save-model-meta:visible').count(),1);
        assert.equal(await edit.locator('.pc-meta-toggle').count(),16);
        assert.equal(await edit.locator('.pc-tracking-toggle').count(),5);
        const toggle = (block,field) => edit.locator(`.pc-meta-toggle[data-block="${block}"][data-field="${field}"]`);
        await toggle('GFP','Available').check(); await toggle('GFP','eBay').check();
        await toggle('Graphics','eBay').check(); await toggle('Configuration','Add Accessories').check();
        await edit.locator('.pc-tracking-toggle[data-field="done_ebay"]').check();
        await panel.screenshot({path:path.join(root,'.cache/product-chart/detail-edit.png')});
        // Filtering cannot hide a draft and strand its save/cancel actions.
        await page.selectOption('#trackingStatusFilter','Complete');
        assert.equal(await panel.isVisible(),true);
        assert.match(await panel.locator('.pc-save-message').textContent(),/Save or cancel/);
        await page.selectOption('#trackingStatusFilter','');
        // Cancelling discards both sections without a request.
        await edit.locator('.btn-cancel-meta-edit').click(); assert.equal(writes,0);
        await panel.locator('.btn-edit-model-meta').click(); assert.equal(await toggle('GFP','eBay').isChecked(),false);
        assert.equal(await edit.locator('.pc-tracking-toggle[data-field="done_ebay"]').isChecked(),false);
        await toggle('GFP','Available').check(); await toggle('GFP','eBay').check();
        for (const flag of flags) await edit.locator(`.pc-tracking-toggle[data-field="${flag}"]`).check();
        failSave = true; await edit.locator('.btn-save-model-meta').click(); await panel.locator('.pc-save-message.is-error').waitFor();
        assert.equal(await toggle('GFP','eBay').isChecked(),true); assert.equal(await edit.isVisible(),true);
        assert.deepEqual(await row.locator('.gfp-status-cell .badge').allTextContents(),['N','N','E']);
        holdSave = true; await edit.locator('.btn-save-model-meta').click();
        await page.waitForFunction(()=>document.querySelector('.scrub-detail-wrapper .btn-save-model-meta').disabled);
        assert.equal(await edit.locator('.btn-cancel-meta-edit').isDisabled(),true);
        assert.equal(await toggle('GFP','eBay').isDisabled(),true);
        while (!releaseSave) await new Promise(resolve=>setTimeout(resolve,20)); releaseSave();
        await edit.waitFor({state:'hidden'}); assert.equal(writes,2);
        assert.equal(lastMeta.GFP.eBay,'yes'); assert.equal(lastMeta.Graphics.Note,'Keep this custom value'); assert.equal(lastMeta.Notes.Owner,'Test team');
        assert.equal(JSON.parse(lastPayload.tracking_original_json).done_ebay,0);
        assert.deepEqual(await row.locator('.gfp-status-cell .badge').allTextContents(),['Y','N','E']);
        assert.equal(await row.locator('.gfp-status-cell [title="eBay: YES"].badge-success').count(),1);
        assert.equal(await row.locator('.tracking-status-cell').getAttribute('data-search'),'Complete');
        assert.equal(await row.locator('.tracking-status-cell .badge-success').count(),5);
        assert.equal(await row.locator('.plastics-status-cell a').getAttribute('href'),'index.php?page=scrublistings&modelcode=C57F');
        await page.selectOption('#trackingStatusFilter','Pending'); assert.equal(await row.count(),0);
        await page.selectOption('#trackingStatusFilter',''); await panel.waitFor();
        // Reordered DataTables redraws preserve expanded details and metadata.
        await page.locator('#scrubTable > thead th').filter({hasText:'BRAND'}).click(); await panel.waitFor();
        assert.equal(await page.locator('.scrub-detail-wrapper > td').getAttribute('colspan'),'11');
        await panel.locator('.btn-edit-model-meta').click(); assert.equal(await toggle('GFP','eBay').isChecked(),true);
        await toggle('GFP','eBay').uncheck(); await edit.locator('.btn-save-model-meta').click(); await edit.waitFor({state:'hidden'});
        assert.equal(await row.locator('.gfp-status-cell [title="eBay: NO"].badge-danger').count(),1);
        // The existing alert modal still synchronizes the row badges and filter cache.
        await page.locator('#btnUpdateTrackingAlert').click();
        const modal = page.locator('#updateTrackingModal'); await modal.waitFor();
        const alertProduct = modal.locator('.tut-checkbox[data-field="done_products"]');
        await alertProduct.waitFor(); await alertProduct.uncheck();
        await page.waitForFunction(()=>document.querySelector('tr.model-row[data-modelcode="C57F"] .tracking-status-cell').dataset.search==='Pending');
        assert.equal(await row.locator('.tracking-status-cell .badge-danger').count(),1);
        await modal.locator('[data-dismiss="modal"]').first().click(); await modal.waitFor({state:'hidden'});
        await panel.locator('.btn-edit-model-meta').click();
        assert.equal(await edit.locator('.pc-tracking-toggle[data-field="done_products"]').isChecked(),false);
        await edit.locator('.btn-cancel-meta-edit').click();
        // Column arrangement persists GFP and preserves all other columns on reload.
        const customOrder = ['gfp',...expected.filter(id=>id!=='gfp')];
        await page.evaluate(order=>order.forEach(id=>$('#productChartColumnList').append($('#productChartColumnList [data-column-id="'+id+'"]'))),customOrder);
        await page.evaluate(()=>$('#saveProductChartColumns').trigger('click')); await page.waitForLoadState('load');
        await page.waitForFunction(()=>document.querySelector('#scrubTable > thead th').textContent==='GFP');
        assert.deepEqual(preferences.column_order,customOrder);
        await row.locator('td').filter({hasText:'Beta'}).click(); await panel.locator('.btn-edit-model-meta').click();
        // Narrow layout: matrix and save actions stay inside the detail at 390px.
        await page.setViewportSize({width:390,height:1000});
        await page.addStyleTag({content:'.scrub-detail-inner{width:370px!important;max-width:370px!important}.pc-detail-grid{grid-template-columns:minmax(0,1fr)!important}'});
        assert.equal(await panel.locator('.scrub-detail-inner').evaluate(el=>el.scrollWidth<=el.clientWidth+1),true);
        await panel.locator('.scrub-detail-inner').screenshot({path:path.join(root,'.cache/product-chart/detail-mobile.png')});
        await edit.locator('.btn-cancel-meta-edit').click();
        await page.setViewportSize({width:1700,height:1200});
        // Empty/legacy model has four editable product rows and no fabricated tracking.
        const empty = page.locator('tr.model-row[data-modelcode="TEST2"]'); await empty.locator('td').filter({hasText:'KTM'}).click();
        await panel.locator('.btn-edit-model-meta').click(); assert.equal(await edit.locator('.pc-tracking-toggle').count(),0);
        await edit.locator('.pc-meta-toggle[data-block="GFP"][data-field="eBay"]').check(); await edit.locator('.btn-save-model-meta').click(); await edit.waitFor({state:'hidden'});
        assert.equal(lastPayload.tracking_json,undefined);
        const reader = await context.newPage(); reader.on('pageerror',e=>errors.push(e.message));
        await reader.goto(url.replace('preview.html','reader.html')); await reader.locator('#scrubTable_wrapper').waitFor();
        await reader.locator('tr.model-row[data-modelcode="C57F"] td').filter({hasText:'Beta'}).click();
        assert.equal(await reader.locator('.scrub-detail-wrapper .btn-edit-model-meta').count(),0);
        assert.equal(await reader.locator('.scrub-detail-wrapper input').count(),0);
        assert.deepEqual(errors,[]);
        console.log('Product chart UI passed: GFP/migrated order, clear editor, one save, cancel, failures/retry, in-flight lock, custom fields, table badges/filter/redraw, saved column arrangement, mobile form and reader permissions.');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
