const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const render = spawnSync('php', ['-d', 'short_open_tag=1', '-r',
  "session_start(['use_cookies'=>0,'cache_limiter'=>'','save_path'=>sys_get_temp_dir()]); $_SESSION=['user_id'=>1,'permission'=>500]; include 'includes/chat.php';"
], { cwd: root, encoding: 'utf8', timeout: 30000 });
assert.equal(render.status, 0, render.stderr);
assert.doesNotMatch(render.stdout, /Fatal error|Warning:|Notice:/);
const html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
  + '<link rel="stylesheet" href="/dist/css/adminlte.min.css"><link rel="stylesheet" href="/bootstrap/css/bootstrap.min.css">'
  + '<link rel="stylesheet" href="/plugins/fontawesome-free/css/all.min.css"><script src="/plugins/jquery/jquery.min.js"></script>'
  + '<style>html,body{font-size:88%}.main-header{height:50px}.main-footer{height:36px}.main-sidebar{padding-top:65px}.brand-link{padding:10px;font-size:20px}</style>'
  + '</head><body class="hold-transition dark-mode sidebar-mini sidebar-collapse layout-fixed layout-navbar-fixed layout-footer-fixed"><div class="wrapper">'
  + '<nav class="main-header navbar navbar-dark"><span>SCRUBDESIGNZ &nbsp; / &nbsp; Chat</span></nav>'
  + '<aside class="main-sidebar sidebar-dark-primary"><div class="brand-link text-center"><i class="fas fa-comments"></i></div></aside>'
  + '<div class="content-wrapper"><section class="content"><div class="card"><div class="card-header"></div><div class="card-body">'
  + render.stdout + '</div></div></section></div><footer class="main-footer">SCRUBDESIGNZ <span class="float-right">Interný chat</span></footer></div></body></html>';
const names = ['Adam Kováč', 'Barbora Nováková', 'Daniel Horváth', 'Eva Malá', 'Filip Urban', 'Jana Bieliková', 'Martin Polák', 'Petra Kováčová', 'Samuel Král', 'Zuzana Šimková'];
const previews = ['Návrhy sú pripravené, môžeš ich pozrieť.', 'Ďakujem, ozvem sa po obede.', 'Podklady som poslal do spoločného priečinka.', 'Dohodnuté, zajtra o deviatej.', 'Fotky sú už nahrané.', 'Dnes dokončíme posledné objednávky.'];
const contacts = names.map((name, i) => ({
  id: i + 2, name, firstname: name.split(' ')[0], lastname: name.split(' ')[1],
  item_type: 'user', thread_type: 'dm', thread_id: i + 10, is_active: i !== 4,
  photo: 'dist/img/user' + (i % 7 + 1) + (i % 7 === 1 ? '-160x160.jpg' : '-128x128.jpg'),
  department_name: i % 2 ? 'Design' : 'Production',
  status_bg: ['bg-success', 'bg-info', 'bg-warning', 'bg-danger'][i % 4],
  status_label: ['At work', 'Lunch', 'Break', 'At home'][i % 4],
  unread_count: i === 1 ? 2 : 0, last_message_at: '2026-11-23 10:42:00',
  last_message_text: previews[i % previews.length], last_message_type: 'text'
}));
contacts.push({ id: -90, thread_id: 90, item_type: 'announcement', thread_type: 'announcement',
  name: 'Hromadné správy', last_message_text: '<h1>Porada tímu</h1><p>Zajtra o 9:00 v zasadačke.</p>',
  status_bg: 'bg-maroon', status_label: 'Announcement', last_message_at: '2026-11-23 09:00:00' });
const baseMessages = [
  ['Ahoj! Stihol si už pozrieť nové návrhy na budúci týždeň?', false],
  ['Áno, práve ich prechádzam. Farebnosť aj rozloženie vyzerajú veľmi dobre.', true],
  ['Super. Ešte posielam finálne podklady k objednávkam.', false],
  ['Ďakujem! Doplním posledné rozmery a môžeme to posunúť do výroby.', true],
  ['Dohodnuté. Ak budeš niečo potrebovať, som v kancelárii.', false],
  ['Perfektné, ozvem sa. 👍', true]
].map(([message_text, is_own], i) => ({ id: 100 + i, message_text, is_own, message_type: 'text', created_at: '2026-11-23 10:' + (38 + i) + ':00' }));
let messages = baseMessages.slice();
let updates = [];
let markRequests = 0;
const sent = [];
const errors = [];

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.CHAT_TEST_BROWSER_CHANNEL || undefined });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await context.addInitScript(() => {
      delete window.Notification;
      HTMLMediaElement.prototype.play = () => Promise.resolve();
    });
    await context.route('**/*', async route => {
      const url = new URL(route.request().url());
      assert.equal(url.origin, 'http://chat.test');
      if (url.pathname === '/index.php') return route.fulfill({ contentType: 'text/html', body: html });
      if (url.pathname.startsWith('/scripts/chat/')) {
        const post = new URLSearchParams(route.request().postData() || '');
        const endpoint = path.basename(url.pathname);
        let data = { status: 'success' };
        if (endpoint === 'get_contacts.php') {
          const query = (url.searchParams.get('q') || '').toLowerCase();
          data.contacts = contacts.filter(c => c.name.toLowerCase().includes(query));
        } else if (endpoint === 'start_dm.php') data.thread_id = contacts.find(c => c.id === Number(post.get('other_user_id'))).thread_id;
        else if (endpoint === 'get_thread.php') {
          const contact = contacts.find(c => c.thread_id === Number(url.searchParams.get('thread_id')));
          data.thread = { id: contact.thread_id, title: contact.name, thread_type: contact.thread_type, other_user: contact.thread_type === 'dm' ? contact : null };
        } else if (endpoint === 'get_messages.php') data.messages = messages;
        else if (endpoint === 'check_updates.php') data.threads = updates;
        else if (endpoint === 'mark_read.php') { markRequests++; data.last_read_message_id = Number(post.get('last_message_id')); }
        else if (endpoint === 'send_message.php') {
          sent.push(post.get('message_text'));
          messages = [...messages, { id: 200 + sent.length, is_own: true, message_type: 'text', message_text: sent.at(-1), created_at: '2026-11-23 11:00:00' }];
        }
        return route.fulfill({ json: data });
      }
      const asset = path.resolve(root, '.' + decodeURIComponent(url.pathname));
      assert.ok(asset.startsWith(root + path.sep));
      return fs.existsSync(asset) ? route.fulfill({ path: asset }) : route.fulfill({ status: 404, body: '' });
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://chat.test/index.php?page=chat');
    await page.locator('.chat-contact').first().waitFor();
    await page.locator('.chat-contact[data-user-id="2"]').click();
    await page.locator('.chat-bubble').last().waitFor();

    async function assertFits(label) {
      await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
      const layout = await page.evaluate(() => {
        const root = document.querySelector('#chatPageRoot').getBoundingClientRect();
        const footer = document.querySelector('.main-footer').getBoundingClientRect();
        const composer = document.querySelector('.chat-composer').getBoundingClientRect();
        return { left: root.left, right: root.right, bottom: root.bottom, footer: footer.top,
          width: innerWidth, docWidth: document.documentElement.scrollWidth,
          composerBottom: composer.bottom, composerVisible: composer.width > 0 };
      });
      assert.ok(layout.left >= 0 && layout.right <= layout.width + 1 && layout.docWidth <= layout.width + 1, label + ': no horizontal page overflow ' + JSON.stringify(layout));
      assert.ok(layout.bottom <= layout.footer + 1, label + ': panel fits above fixed footer ' + JSON.stringify(layout));
      if (layout.composerVisible) assert.ok(layout.composerBottom <= layout.bottom + 1, label + ': composer stays inside panel');
    }
    await assertFits('desktop');
    const backgrounds = await page.locator('.chat-contact:not(.active)').evaluateAll(rows => [...new Set(rows.map(row => getComputedStyle(row).backgroundColor))]);
    assert.deepEqual(backgrounds, ['rgba(0, 0, 0, 0)'], 'Attendance does not color contact rows.');
    const rings = await page.locator('.chat-contact[data-thread-type="dm"] .chat-avatar').evaluateAll(rows => rows.slice(0, 4).map(row => getComputedStyle(row).borderTopColor));
    assert.equal(new Set(rings).size, 4, 'Four distinct attendance rings.');
    assert.equal(await page.locator('.chat-contact[data-user-id="6"] img').evaluate(img => getComputedStyle(img).filter), 'grayscale(1)');
    assert.equal(await page.locator('.chat-contact[data-user-id="-90"] .chat-contact-meta').innerText(), 'Porada tímu Zajtra o 9:00 v zasadačke.');
    await page.screenshot({ path: path.join(os.tmpdir(), 'chat-redesign-desktop.jpg'), type: 'jpeg', quality: 80 });

    for (const [width, height] of [[2560, 1440], [1920, 1080], [1366, 768], [1024, 600]]) {
      await page.setViewportSize({ width, height });
      await assertFits(width + 'x' + height);
    }
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.screenshot({ path: path.join(os.tmpdir(), 'chat-redesign-laptop.jpg'), type: 'jpeg', quality: 80 });
    await page.locator('#chatEmojiToggle').click();
    await page.locator('.chat-emoji-btn').filter({ hasText: '👍' }).click();
    assert.equal(await page.locator('#chatMessageInput').inputValue(), '👍');
    await page.locator('#chatMessageInput').fill('Test novej konverzácie');
    await page.locator('#chatSendBtn').click();
    await page.getByText('Test novej konverzácie', { exact: true }).waitFor();
    assert.deepEqual(sent, ['Test novej konverzácie']);

    await page.locator('#chatAttachmentInput').setInputFiles({ name: 'podklady.txt', mimeType: 'text/plain', buffer: Buffer.from('test') });
    assert.equal(await page.locator('#chatAttachmentPreview').isVisible(), true);
    await page.locator('#chatAttachmentRemove').click();
    assert.equal(await page.locator('#chatAttachmentPreview').isVisible(), false);
    await page.locator('.chat-conversation').dispatchEvent('dragenter', { dataTransfer: await page.evaluateHandle(() => {
      const transfer = new DataTransfer(); transfer.items.add(new File(['test'], 'dragged.txt', { type: 'text/plain' })); return transfer;
    }) });
    assert.equal(await page.locator('#chatAttachmentDropzone').isVisible(), true);
    await page.locator('#chatAttachmentDropzone').dispatchEvent('drop', { dataTransfer: await page.evaluateHandle(() => {
      const transfer = new DataTransfer(); transfer.items.add(new File(['test'], 'dragged.txt', { type: 'text/plain' })); return transfer;
    }) });
    assert.equal(await page.locator('#chatAttachmentName').innerText(), 'dragged.txt');
    assert.equal(await page.locator('#chatAttachmentDropzone').isVisible(), false);
    await page.locator('#chatAttachmentRemove').click();

    await page.setViewportSize({ width: 390, height: 844 });
    await assertFits('mobile conversation');
    assert.equal(await page.locator('.chat-contacts').isVisible(), false);
    await page.screenshot({ path: path.join(os.tmpdir(), 'chat-redesign-mobile.jpg'), type: 'jpeg', quality: 80 });
    await page.locator('#chatShowContacts').click();
    assert.equal(await page.locator('.chat-contacts').isVisible(), true);
    assert.equal(await page.locator('.chat-conversation').isVisible(), false);
    await page.screenshot({ path: path.join(os.tmpdir(), 'chat-redesign-contacts.jpg'), type: 'jpeg', quality: 80 });
    await page.locator('#chatSearch').fill('Barbora');
    await page.waitForFunction(() => document.querySelectorAll('.chat-contact').length === 1);
    await page.locator('#chatSearch').fill('');
    await page.waitForFunction(() => document.querySelectorAll('.chat-contact').length === 11);

    // A background conversation must stay unread while the mobile contact list is visible.
    await page.clock.install();
    const readsBefore = markRequests;
    updates = [{ thread_id: 10, last_message_id: 300, last_sender_id: 2, unread_count: 1, sender_name: 'Adam Kováč', message_text: 'New mobile message' }];
    await page.clock.runFor(5500);
    updates[0].last_message_id = 301;
    await page.clock.runFor(5500);
    assert.equal(markRequests, readsBefore, 'Hidden mobile conversation is not marked read.');
    await page.locator('label[for="chatBroadcastModeToggle"]').click();
    await page.locator('.chat-contact[data-user-id="2"]').click();
    await page.waitForFunction(() => document.querySelector('#chatBroadcastSummary').textContent === 'Selected recipients: 1');
    await page.locator('#chatComposeBroadcast').click();
    assert.equal(await page.locator('#chatAnnouncementHtmlHint').isVisible(), true);
    assert.equal(await page.locator('#chatAttachToggle').isDisabled(), true);
    await assertFits('mobile broadcast');
    await page.locator('#chatShowContacts').click();
    await page.locator('label[for="chatBroadcastModeToggle"]').click();
    await page.locator('.chat-contact[data-user-id="2"]').click();
    for (const [width, height] of [[320, 568], [740, 400]]) {
      await page.setViewportSize({ width, height });
      await assertFits(width + 'x' + height);
    }
    assert.deepEqual(errors, [], 'No browser script errors.');
    console.log('chat_layout: OK (8 screen sizes, neutral contacts, status rings, inactive avatars, previews, messaging, emoji, attachment drag/drop, mobile navigation, broadcast, unread state)');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
