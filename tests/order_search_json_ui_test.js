const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const registry = JSON.parse(execFileSync('php', ['-r', "require 'includes/order_search_registry.php'; echo json_encode([orderSearchPublicFields(), orderSearchOperatorLabels()]);"], {cwd: root, encoding: 'utf8'}));
let template = fs.readFileSync(path.join(root, 'includes/order_search.php'), 'utf8')
  .replace(/^<\?php[\s\S]*?\?>/, '')
  .replace("<?= $orderSearchFieldsJson ?: '[]' ?>", () => JSON.stringify(registry[0]))
  .replace("<?= $orderSearchOperatorsJson ?: '{}' ?>", () => JSON.stringify(registry[1]));
const scripts = ['plugins/jquery/jquery.min.js', 'plugins/bootstrap/js/bootstrap.bundle.min.js',
  'plugins/datatables/jquery.dataTables.min.js', 'plugins/datatables-bs4/js/dataTables.bootstrap4.min.js',
  'plugins/datatables-buttons/js/dataTables.buttons.min.js', 'plugins/datatables-buttons/js/buttons.html5.min.js',
  'plugins/datatables-buttons/js/buttons.print.min.js'];
const fonts = fs.readdirSync(path.join(root, 'plugins/fontawesome-free/webfonts')).map(name => 'plugins/fontawesome-free/webfonts/' + name);
const styles = ['dist/css/adminlte.min.css', 'plugins/fontawesome-free/css/all.min.css', 'plugins/datatables-bs4/css/dataTables.bootstrap4.min.css'];
const html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' +
  styles.map(src => '<link rel="stylesheet" href="/' + src + '">').join('') +
  scripts.map(src => '<script src="/' + src + '"></script>').join('') +
  '</head><body class="dark-mode" style="padding:12px">' + template + '</body></html>';
(async () => {
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  try {
    const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
    const errors = [], searches = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://order-search.test/**', async route => {
      const req = route.request(), url = new URL(req.url());
      if (url.pathname === '/index.php') return route.fulfill({contentType: 'text/html', body: html});
      if (url.pathname === '/scripts/orders/search_builder.php') {
        const params = req.method() === 'POST' ? new URLSearchParams(req.postData()) : url.searchParams;
        const action = params.get('action');
        if (action === 'field_values') {
          const field = params.get('field');
          const rows = field === 'source_meta'
            ? [{path: '_followup.customs_ddp_amount', examples: ['5', '15'], count: 2}, {path: '"odd.key"', examples: ['0'], count: 1}]
            : field === 'item_options_text' || field === 'internal_options_text'
              ? [{path: 'price', examples: ['5'], count: 1}]
              : [{value: 'Chrome', sources: ['options_json'], count: 1}];
          return route.fulfill({json: {ok: true, mode: field === 'material' ? 'values' : 'paths', field, rows, total: rows.length}});
        }
        if (action === 'suggest') return route.fulfill({json: {ok: true, items: ['Chrome']}});
        if (action === 'search') {
          searches.push(JSON.parse(params.get('filters')));
          return route.fulfill({json: {ok: true, data: [], total: 0}});
        }
        throw new Error('Unexpected action: ' + action);
      }
      const asset = url.pathname.slice(1);
      if ([...scripts, ...styles, ...fonts].includes(asset)) return route.fulfill({path: path.join(root, asset)});
      return route.fulfill({status: 404, body: ''});
    });
    await page.goto('http://order-search.test/index.php');
    const first = page.locator('.order-search-filter-row').first();
    await first.locator('.order-search-field').selectOption('source_meta');
    assert.equal(await first.locator('.order-search-path').isVisible(), true);
    assert.equal(await first.locator('.order-search-operator').inputValue(), 'contains');
    await first.locator('.order-search-paths-open').click();
    await page.locator('#orderSearchValuesPopover').waitFor({state: 'visible'});
    await page.locator('.order-search-value-pick').first().click();
    assert.equal(await first.locator('.order-search-path').inputValue(), '_followup.customs_ddp_amount');
    assert.equal(await first.locator('.order-search-value').inputValue(), '');
    assert.equal(await first.locator('.order-search-operator').inputValue(), 'equals');
    await first.locator('.order-search-value').fill('5');
    await page.locator('#orderSearchRun').click();
    await page.waitForFunction(() => !document.querySelector('#orderSearchRun').disabled);
    assert.deepEqual(searches[0], [{field: 'source_meta', operator: 'equals', value: '5', value2: '', json_path: '_followup.customs_ddp_amount'}]);
    fs.mkdirSync(path.join(root, '.cache/order-search'), {recursive: true});
    await page.locator('.order-search-builder').screenshot({path: path.join(root, '.cache/order-search/json-filter.png')});

    await first.locator('.order-search-path').fill('_followup');
    await page.waitForFunction(() => document.querySelector('#orderSearchJsonPathList1 option')?.value === '_followup.customs_ddp_amount');
    await first.locator('.order-search-path').fill('_followup.customs_ddp_amount');
    await first.locator('.order-search-operator').selectOption('is_not_empty');
    assert.equal(await first.locator('.order-search-value').isVisible(), false);
    await first.locator('.order-search-path').fill('');
    assert.equal(await first.locator('.order-search-operator').inputValue(), 'is_not_empty');
    await first.locator('.order-search-operator').selectOption('contains');
    assert.equal(await first.locator('.order-search-value').isVisible(), true);
    await first.locator('.order-search-path').fill('_followup.customs_ddp_amount');
    assert.equal(await first.locator('.order-search-operator').inputValue(), 'equals');
    await first.locator('.order-search-operator').selectOption('not_equals');
    await first.locator('.order-search-value').fill('15');
    assert.equal(await first.locator('.order-search-operator').inputValue(), 'not_equals');

    await page.locator('#orderSearchAddFilter').click();
    const second = page.locator('.order-search-filter-row').nth(1);
    await second.locator('.order-search-field').selectOption('item_options_text');
    await second.locator('.order-search-paths-open').click();
    await page.locator('.order-search-value-pick').first().click();
    assert.equal(await second.locator('.order-search-path').inputValue(), 'price');
    assert.equal(await first.locator('.order-search-path').inputValue(), '_followup.customs_ddp_amount');
    await second.locator('.order-search-field').selectOption('material');
    assert.equal(await second.locator('.order-search-path').isVisible(), false);
    await page.locator('.order-search-values-open').click();
    await page.locator('.order-search-value-pick').first().click();
    assert.equal(await second.locator('.order-search-value').inputValue(), 'Chrome');
    await second.locator('.order-search-field').selectOption('source_meta');
    assert.equal(await second.locator('.order-search-path').inputValue(), '');
    assert.equal(await second.locator('.order-search-operator').inputValue(), 'contains');

    await page.setViewportSize({width: 390, height: 900});
    await page.locator('.order-search-builder').screenshot({path: path.join(root, '.cache/order-search/json-filter-mobile.png')});
    assert.equal(await first.evaluate(row => row.scrollWidth <= row.clientWidth), true, 'Mobile filter fits its container');
    await page.locator('#orderSearchReset').click();
    assert.equal(await page.locator('.order-search-filter-row').count(), 1);
    assert.equal(await first.locator('.order-search-field').inputValue(), 'global_text');
    assert.deepEqual(errors, []);
    console.log('Order search UI: JSON path picker, autocomplete, exact-value payload, operators, multiple filters, raw JSON fallback and mobile layout passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
