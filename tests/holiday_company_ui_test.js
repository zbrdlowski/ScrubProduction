const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const fixture = path.join(__dirname, 'holiday_company_test.php');
const pages = {};
for (const mode of ['render-admin', 'render-user', 'render-result', 'invalid-csrf', 'unauthorized']) {
  const render = spawnSync('php', [fixture, '--' + mode], { encoding: 'utf8', timeout: 60000, cwd: root });
  assert.equal(render.status, 0, render.stderr || render.error?.message);
  assert.doesNotMatch(render.stdout, /Fatal error|Warning:|Notice:/);
  pages[mode] = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<link rel="stylesheet" href="/dist/css/adminlte.min.css">'
    + '<link rel="stylesheet" href="/plugins/fontawesome-free/css/all.min.css">'
    + '<script src="/plugins/jquery/jquery.min.js"></script></head><body class="dark-mode">'
    + render.stdout + '</body></html>';
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.HOLIDAY_TEST_BROWSER_CHANNEL || undefined });
  try {
    const context = await browser.newContext();
    const errors = [];
    const submissions = [];
    await context.route('http://holiday.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/') {
        if (route.request().method() === 'POST') {
          submissions.push(new URLSearchParams(route.request().postData()));
          await route.fulfill({ contentType: 'text/html', body: '<p>Submission captured</p>' });
        } else {
          await route.fulfill({ contentType: 'text/html', body: pages[url.searchParams.get('mode') || 'render-admin'] });
        }
        return;
      }
      const asset = path.resolve(root, '.' + decodeURIComponent(url.pathname));
      assert.ok(asset.startsWith(root + path.sep));
      await route.fulfill({ path: asset });
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    const screenshots = [];
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      await page.setViewportSize(viewport);
      await page.goto('http://holiday.test/?mode=render-admin');
      await page.locator('#holidayCompanyButton').click();
      const modal = page.locator('#holidayCompanyModal');
      await modal.waitFor({ state: 'visible' });
      await page.locator('#holidayCompanyStart').fill('2026-12-07');
      await page.locator('#holidayCompanyStart').blur();
      assert.equal(await page.locator('#holidayCompanyEnd').inputValue(), '2026-12-07');
      await page.locator('#holidayCompanyEnd').fill('2026-12-06');
      assert.equal(await page.locator('#holidayCompanyEnd').evaluate(el => el.checkValidity()), false);
      await page.locator('#holidayCompanyEnd').fill('2026-12-14');
      await page.locator('#holidayCompanyNote').fill('Shared note');
      await modal.locator('.modal-content').evaluate(el => new Promise(resolve => requestAnimationFrame(() => resolve(el.offsetWidth))));
      const rect = await modal.locator('.modal-content').boundingBox();
      assert.ok(rect.x >= 0 && rect.x + rect.width <= viewport.width + 1, 'Modal must fit the viewport.');
      assert.equal(await page.locator('.modal-backdrop').count(), 1, 'Only one modal backdrop.');
      const screenshot = path.join(os.tmpdir(), `holiday-company-${viewport.width}.png`);
      await page.screenshot({ path: screenshot });
      screenshots.push(screenshot);
      await modal.locator('.close').click();
      await modal.waitFor({ state: 'hidden' });
      await page.locator('.modal-backdrop').waitFor({ state: 'detached' });
      assert.equal(await page.locator('body').evaluate(el => el.classList.contains('modal-open')), false);
      await page.locator('#holidayCompanyButton').click();
      await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
      await modal.waitFor({ state: 'hidden' });
      await page.locator('.modal-backdrop').waitFor({ state: 'detached' });
      assert.equal(await page.locator('.modal-backdrop').count(), 0, 'Cancel must remove the backdrop.');
    }

    await page.locator('#holidayCompanyButton').click();
    await page.locator('#holidayCompanyModal [type="submit"]').click();
    await page.getByText('Submission captured').waitFor();
    assert.equal(submissions.length, 1);
    assert.equal(submissions[0].get('action'), 'create_company_holiday');
    assert.equal(submissions[0].get('csrf_token'), 'a'.repeat(64));
    assert.equal(submissions[0].get('start_date'), '2026-12-07');
    assert.equal(submissions[0].get('end_date'), '2026-12-14');
    assert.equal(submissions[0].get('note'), 'Shared note');

    await page.goto('http://holiday.test/?mode=render-user');
    assert.equal(await page.locator('#holidayCompanyButton, #holidayCompanyModal').count(), 0, 'Ordinary users must not get the bulk tool.');
    assert.ok(await page.locator('.holiday-note-edit-form').count() > 0, 'Existing personal note editing must remain available.');
    assert.equal(await page.locator('#holidayRequestModal option[value="sick"]').count(), 0);
    await page.goto('http://holiday.test/?mode=invalid-csrf');
    assert.match(await page.locator('[role="alert"]').innerText(), /This form has expired/);
    await page.goto('http://holiday.test/?mode=unauthorized');
    assert.match(await page.locator('[role="alert"]').innerText(), /You do not have permission/);
    await page.goto('http://holiday.test/?mode=render-result');
    const result = page.locator('[role="status"]');
    assert.match(await result.innerText(), /Total days added: 10/);
    await result.locator('summary').click();
    assert.match(await result.innerText(), /09\.12\.2026/);
    assert.equal(await result.locator('li').count(), 2);
    assert.deepEqual(errors, [], 'No browser script errors.');
    console.log('holiday_company_ui: OK (desktop/mobile, open/close, dates, submission, access, notes, result)');
    console.log('Screenshots: ' + screenshots.join(', '));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
