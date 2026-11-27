const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

(async () => {
  const root = path.resolve(__dirname, '..');
  const cache = path.join(root, '.cache/custom-order-item-note');
  const fixtures = ['ui_assigned','ui_unassigned','ui_exported','ui_manager','ui_list'];
  for (const fixture of fixtures) execFileSync(process.env.CUSTOM_ORDERS_TEST_PHP || 'php',
    [path.join(__dirname, 'custom_order_item_note_test.php'), fixture], {cwd:root});
  const html = Object.fromEntries(fixtures.map(name => [name, fs.readFileSync(path.join(cache, name + '.html'), 'utf8')]));
  const browser = await chromium.launch({channel:'chrome',headless:true});
  try {
    const page = await browser.newPage({viewport:{width:1700,height:1100}});
    const errors = [], dialogs = [], saves = [];
    let state = 'ui_assigned', note = 'Original Scrub note', detailLoads = 0;
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', async dialog => { dialogs.push(dialog.message()); await dialog.accept(); });
    const currentHtml = () => html[state].replaceAll('Original Scrub note', note);
    const detailHtml = () => currentHtml().split('<!-- CUSTOM_ORDER_DETAIL_START -->')[1].split('<!-- CUSTOM_ORDER_DETAIL_END -->')[0];
    await page.route('**/*', async route => {
      const request = route.request(), url = new URL(request.url());
      if (url.origin !== 'http://localhost' || !url.pathname.startsWith('/darkscrub/')) return route.abort();
      const relative = decodeURIComponent(url.pathname.slice('/darkscrub/'.length));
      if (relative === 'scripts/custom_orders/save_item_note.php') {
        assert.equal(request.method(), 'POST');
        const data = Object.fromEntries([...request.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n(?=--)/g)].map(match => [match[1],match[2]]));
        assert.deepEqual(Object.keys(data).sort(), ['csrf_token','custom_item_id','custom_order_id','inline_edit','scrub_note']);
        assert.equal(data.custom_order_id, '123'); assert.equal(data.custom_item_id, '456');
        assert.equal(data.csrf_token, 'fixture-csrf-token');
        note = data.scrub_note; saves.push(data);
        return route.fulfill({json:{ok:true,message:'Scrub note saved.'}});
      }
      if (relative === 'scripts/custom_orders/get_order_detail.php') {
        detailLoads++;
        return route.fulfill({json:{ok:true,order_id:123,html:detailHtml()}});
      }
      if (relative === 'scripts/custom_orders/take_order.php') {
        state = 'ui_assigned';
        return route.fulfill({json:{ok:true,assignment_html:'<span class="custom-order-assigned-avatar-wrap"><span class="custom-order-assigned-avatar">D</span><button type="button" class="custom-order-remove-assignment-btn" data-assignment-id="234" data-custom-order-id="123">&times;</button></span>'}});
      }
      if (relative === 'scripts/custom_orders/remove_order_assignment.php') {
        state = 'ui_unassigned';
        return route.fulfill({json:{ok:true,assignment_html:'<button type="button" class="custom-order-take-btn" data-custom-order-id="123">Take</button>'}});
      }
      if (relative.endsWith('.php')) throw new Error('Unexpected endpoint: ' + relative);
      if (relative.startsWith('.cache/custom-order-item-note/') && relative.endsWith('.html')) {
        return route.fulfill({contentType:'text/html',body:relative.endsWith('/ui_list.html') ? html.ui_list : currentHtml()});
      }
      const file = path.resolve(root, relative);
      if (!file.startsWith(root + path.sep) || !fs.existsSync(file)) return route.fulfill({status:404,body:''});
      return route.fulfill({body:fs.readFileSync(file),contentType:({'.css':'text/css','.js':'text/javascript'})[path.extname(file)] || 'application/octet-stream'});
    });
    const load = async name => {
      state = name === 'ui_list' ? 'ui_unassigned' : name;
      await page.goto('http://localhost/darkscrub/.cache/custom-order-item-note/' + name + '.html');
    };
    const form = page.locator('#custom-item-inline-edit-456');
    const editableNote = form.locator('[name="scrub_note"]');
    await load('ui_assigned');
    assert.equal(await editableNote.isEnabled(), true);
    assert.equal(await form.locator('[data-custom-item-note-save]').isEnabled(), true);
    for (const name of ['title','sku','qty','unit_price','custom_label','spec_graphics_buyer_note']) {
      assert.equal(await form.locator(`[name="${name}"]`).isDisabled(), true, name);
    }
    assert.equal(await page.locator('.custom-add-item-form [name="title"]').isDisabled(), true, 'Assignment does not enable adding products');
    await editableNote.fill('Designer draft instructions');
    await Promise.all([page.waitForEvent('domcontentloaded'), form.locator('[data-custom-item-note-save]').click()]);
    assert.equal(saves.length, 1);
    assert.equal(await editableNote.inputValue(), 'Designer draft instructions');
    assert.deepEqual(dialogs, [], 'Saving only the note must not request category information');
    await form.screenshot({path:path.join(cache,'assigned-note.png')});

    for (const fixture of ['ui_unassigned','ui_exported']) {
      await load(fixture);
      assert.equal(await editableNote.count(), 0);
      assert.equal(await form.locator('[name="spec_graphics_my_item_note"]').isDisabled(), true, fixture);
      assert.equal(await form.locator('button[type="submit"]').first().isDisabled(), true, fixture);
    }
    await load('ui_manager');
    assert.match(await form.getAttribute('action'), /save_item\.php$/);
    assert.equal(await form.locator('[name="title"]').isEnabled(), true);
    assert.equal(await form.locator('[name="spec_graphics_my_item_note"]').isEnabled(), true);

    // Opening from the list and changing assignment must refresh permissions immediately.
    await load('ui_list');
    await page.locator('.custom-order-table-row[data-order-id="123"]').click();
    await form.waitFor();
    assert.equal(await form.locator('[name="spec_graphics_my_item_note"]').isDisabled(), true);
    await page.locator('[data-custom-assigned-cell="123"] .custom-order-take-btn').first().click();
    await editableNote.waitFor();
    assert.equal(await editableNote.isEnabled(), true, 'Take enables note editing in the open detail');
    await editableNote.fill('Updated in injected detail');
    await form.locator('[data-custom-item-note-save]').click();
    await page.waitForFunction(() => document.querySelector('#custom-item-inline-edit-456 [data-custom-item-note-save]')?.textContent.trim() === 'Save note');
    assert.equal(saves.length, 2);
    assert.equal(await editableNote.inputValue(), 'Updated in injected detail');
    await page.locator('[data-custom-assigned-cell="123"] .custom-order-assigned-avatar-wrap').first().hover();
    await page.locator('[data-custom-assigned-cell="123"] .custom-order-remove-assignment-btn').first().click();
    await form.locator('[name="spec_graphics_my_item_note"]').waitFor();
    assert.equal(await editableNote.count(), 0, 'Removing assignment revokes note editing');
    assert.equal(await form.locator('[name="spec_graphics_my_item_note"]').isDisabled(), true);
    assert.ok(detailLoads >= 4, 'Details must reload after assignment, save and removal');
    assert.deepEqual(errors, []);
    console.log('custom_order_item_note_ui: OK');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
