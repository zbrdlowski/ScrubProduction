const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({channel:'chrome', headless:true});
  try {
    const page = await browser.newPage({viewport:{width:1550,height:1100}});
    const root = path.resolve(__dirname, '..');
    const errors = [], requests = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.origin !== 'http://localhost') return route.abort();
      const relative = decodeURIComponent(url.pathname.slice('/darkscrub/'.length));
      if (relative.endsWith('.php')) {
        requests.push(relative);
        return route.fulfill({status:409,json:{ok:false,message:'Unexpected mutation in UI fixture'}});
      }
      const file = path.resolve(root, relative);
      if (!file.startsWith(root + path.sep) || !fs.existsSync(file)) return route.fulfill({status:404,body:''});
      return route.fulfill({body:fs.readFileSync(file),contentType:({'.html':'text/html','.js':'text/javascript','.css':'text/css'})[path.extname(file)] || 'application/octet-stream'});
    });
    await page.goto('http://localhost/darkscrub/.cache/custom-order-export-lock/ui_exported.html');
    const item = page.locator('#custom-order-builder-panel .custom-existing-items form[action$="/save_item.php"]');
    assert.equal(await item.count(),1);
    for (const field of ['title','sku','qty','unit_price','custom_label','is_upsell']) {
      assert.equal(await item.locator(`[name="${field}"]`).isDisabled(),true,field);
    }
    assert.equal(await page.locator('.custom-status-control').isDisabled(),true);
    assert.equal(await page.locator('.custom-complexity-control').isDisabled(),true);
    assert.equal(await page.locator('[name="next_followup_at"]').isDisabled(),true);
    for (const field of ['shipping_street','shipping_email','shipping_price','payment_method']) {
      assert.equal(await page.locator(`#custom-twin-header-form-123 [name="${field}"]`).isEnabled(),true,field);
    }
    assert.equal(await page.locator('form[action$="/save_payment.php"] button[type="submit"]').first().isEnabled(),true);
    assert.equal(await page.locator('form[action$="/save_note.php"] textarea').isEnabled(),true);
    await item.locator('[data-toggle="modal"]').click();
    await page.locator('#custom-item-modal-456.show').waitFor();
    assert.match(await page.locator('#custom-item-modal-456').innerText(),/Original item/);
    await page.waitForFunction(() => {
      const modal = window.jQuery('#custom-item-modal-456').data('bs.modal');
      return modal && !modal._isTransitioning;
    });
    await page.locator('#custom-item-modal-456 [data-dismiss="modal"]').last().click();
    await page.locator('#custom-item-modal-456').waitFor({state:'hidden'});
    // Force an event from a stale/programmatic submit; the export guard runs before AJAX listeners.
    const allowed = await item.evaluate(form => form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
    assert.equal(allowed,false);
    assert.equal(requests.length,0,'Locked products must not submit');
    await page.evaluate(() => window.scrollTo(0,0));
    await page.screenshot({path:path.join(root,'.cache/custom-order-export-lock/header.png')});
    await page.locator('#custom-order-builder-panel').screenshot({path:path.join(root,'.cache/custom-order-export-lock/products.png')});
    await page.goto('http://localhost/darkscrub/.cache/custom-order-export-lock/ui_unexported.html');
    assert.equal(await page.locator('.custom-status-control').isEnabled(),true);
    assert.equal(await item.locator('[name="title"]').isEnabled(),true);
    assert.equal(await item.locator('[name="qty"]').isEnabled(),true);
    assert.deepEqual(errors,[]);
    console.log('custom_order_export_lock_ui: OK');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
