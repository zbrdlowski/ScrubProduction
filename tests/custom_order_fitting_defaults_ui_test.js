const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

(async () => {
  const root = path.resolve(__dirname, '..');
  const fixtures = ['ui_unexported', 'ui_unexported_fitting', 'ui_unexported_edit_fitting'];
  for (const fixture of fixtures) {
    execFileSync(process.env.CUSTOM_ORDERS_TEST_PHP || 'php', [path.join(__dirname, 'custom_order_export_lock_test.php'), fixture], {cwd:root});
  }
  const browser = await chromium.launch({channel:'chrome', headless:true});
  try {
    const page = await browser.newPage({viewport:{width:1550,height:1100}});
    const errors = [], mutations = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.origin !== 'http://localhost' || !url.pathname.startsWith('/darkscrub/')) return route.abort();
      const relative = decodeURIComponent(url.pathname.slice('/darkscrub/'.length));
      if (relative.endsWith('.php')) {
        mutations.push(relative);
        return route.fulfill({status:409,json:{ok:false,message:'Unexpected mutation in UI fixture'}});
      }
      const file = path.resolve(root, relative);
      if (!file.startsWith(root + path.sep) || !fs.existsSync(file)) return route.fulfill({status:404,body:''});
      return route.fulfill({body:fs.readFileSync(file),contentType:({'.html':'text/html','.js':'text/javascript','.css':'text/css'})[path.extname(file)] || 'application/octet-stream'});
    });
    const form = page.locator('form.custom-add-item-form');
    const type = form.locator('[name="item_type_code"]');
    const title = form.locator('[name="title"]');
    const price = form.locator('[name="unit_price"]');
    const load = async (fixture = fixtures[0]) => {
      await page.goto('http://localhost/darkscrub/.cache/custom-order-export-lock/' + fixture + '.html');
      await form.waitFor();
    };
    const values = async (expectedTitle, expectedPrice, message) => {
      assert.equal(await title.inputValue(), expectedTitle, message + ': title');
      assert.equal(await price.inputValue(), expectedPrice, message + ': price');
    };
    await load();
    await values('', '0', 'Initial blank builder');
    // Repeatedly choose Fitting, then every other product type, including no selection.
    for (const nextType of ['G','P','S','T','M','']) {
      await type.selectOption('F');
      await values('Fitting', '39.90', 'New Fitting defaults');
      await type.selectOption(nextType);
      await values('', '0', 'Leaving Fitting for ' + (nextType || 'no type'));
    }
    // Other product details entered before choosing Fitting remain user-owned.
    await type.selectOption('G');
    await title.fill('Graphics kit');
    await price.fill('129.50');
    await type.selectOption('F');
    await values('Graphics kit', '129.50', 'Existing draft values');
    await type.selectOption('P');
    await values('Graphics kit', '129.50', 'Existing draft values after switch');

    await load();
    await type.selectOption('F');
    await title.fill('Custom service');
    await type.selectOption('S');
    await values('Custom service', '0', 'Keep manual title, clear automatic price');
    await load();
    await type.selectOption('F');
    await price.fill('55');
    await type.selectOption('G');
    await values('', '55', 'Clear automatic title, keep manual price');
    await load();
    await type.selectOption('F');
    await title.fill('Fitting edited');
    await title.fill('Fitting');
    await price.fill('42');
    await price.fill('39.90');
    await type.selectOption('P');
    await values('Fitting', '39.90', 'Explicit manual values matching defaults must survive');

    // Opening a new Fitting directly is also used by the page returned after Save.
    await load('ui_unexported_fitting');
    await values('Fitting', '39.90', 'Server-rendered new Fitting');
    await type.selectOption('G');
    await values('', '0', 'Clear server-rendered automatic defaults');
    // Repeat sync via the Graphics subcategory; it must not reintroduce Fitting.
    const subcategory = form.locator('[name="graphics_subcategory"]');
    await subcategory.evaluate(select => select.dispatchEvent(new Event('change', {bubbles:true})));
    await values('', '0', 'Subcategory change after Fitting');
    await form.screenshot({path:path.join(root, '.cache/custom-order-export-lock/fitting-defaults-fixed.png')});

    // Already saved items are never cleared or re-priced by the new-item defaults.
    await load('ui_unexported_edit_fitting');
    await values('Fitting', '39.90', 'Saved Fitting');
    await type.selectOption('G');
    await values('Fitting', '39.90', 'Saved item values after changing type');
    await title.fill('Saved item override');
    await price.fill('0');
    await type.selectOption('F');
    await values('Saved item override', '0', 'Saved Fitting must not get new-item defaults');

    assert.deepEqual(mutations, [], 'Type changes must not write to the server');
    assert.deepEqual(errors, [], 'No browser errors');
    console.log('custom_order_fitting_defaults_ui: OK');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
