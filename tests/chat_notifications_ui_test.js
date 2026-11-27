const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
function api(request, expectedStatus = 200) {
  const result = spawnSync('php', ['tests/chat_notifications_test.php', '--api'], {
    cwd: root, input: JSON.stringify(request), encoding: 'utf8', timeout: 30000
  });
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stderr, 'http_status=' + expectedStatus);
  return JSON.parse(result.stdout);
}
const token = 'a'.repeat(64);
for (const mode of ['persistent', 'brief', 'badge']) {
  const response = api({ endpoint: 'notification_preferences', method: 'POST',
    post: { toast_mode: mode, sound_enabled: '0', csrf_token: token, user_id: 2 }, expected_preference_rows: 1 });
  assert.equal(response.preferences.toast_mode, mode);
  assert.equal(response.preferences.sound_enabled, false);
}
api({ endpoint: 'notification_preferences', method: 'POST', post: { toast_mode: 'badge', sound_enabled: '1', csrf_token: 'bad' }, expected_preference_rows: 0 }, 403);
api({ endpoint: 'notification_preferences', method: 'POST', post: { toast_mode: 'invalid', sound_enabled: '1', csrf_token: token }, expected_preference_rows: 0 }, 422);
api({ endpoint: 'notification_preferences', user_id: 0 }, 401);
api({ endpoint: 'mark_read', method: 'POST', initial_read: 102, post: { thread_id: 10, last_message_id: 101 }, expected_read: 102 });
api({ endpoint: 'mark_read', method: 'POST', post: { thread_id: 10, last_message_id: 200 }, expected_read: 100 }, 422);
api({ endpoint: 'mark_read', method: 'POST', post: { thread_id: 30, last_message_id: 300 }, expected_read: 100 }, 403);
const unreadResponse = api({ endpoint: 'get_unread_threads' });
assert.equal(unreadResponse.threads.find(thread => thread.thread_id === 10).last_message_id, 102);
assert.equal(unreadResponse.threads.find(thread => thread.thread_id === 20).message_type, 'announcement');
assert.equal(unreadResponse.read_positions.length, 2);
const updatesResponse = api({ endpoint: 'check_updates' });
assert.equal(updatesResponse.read_positions.find(thread => thread.thread_id === 10).last_read_message_id, 100);

function render(file) {
  const result = spawnSync('php', ['-d', 'short_open_tag=1', '-r',
    "session_start(['use_cookies'=>0,'cache_limiter'=>'','save_path'=>sys_get_temp_dir()]); $_SESSION=['user_id'=>1,'permission'=>500]; include '" + file + "';"
  ], { cwd: root, encoding: 'utf8', timeout: 30000 });
  assert.equal(result.status, 0, result.stderr);
  return result.stdout;
}
const chat = render('includes/chat.php').replace('})(window.jQuery);',
  'window.chatTest = { showChatToast, renderMessages, markThreadRead, setThread: id => { currentThreadId = id; } }; })(window.jQuery);');
const profile = render('includes/profile_notifications.php');
const navbar = fs.readFileSync(path.join(root, 'includes/navbar.php'), 'utf8');
const navScript = navbar.slice(navbar.indexOf('let chatNotifInitialized'), navbar.indexOf('</script>', navbar.indexOf('let chatNotifInitialized')));
const defaults = () => ({ toast_mode: 'persistent', sound_enabled: true, revision: 0 });
const preferences = new Map([[1, defaults()], [2, defaults()]]);
let items = [];
let readPositions = [];
let markRequests = 0;

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.CHAT_TEST_BROWSER_CHANNEL || undefined });
  try {
    const context = await browser.newContext();
    const errors = [];
    await context.addInitScript(() => {
      window.soundCount = 0;
      window.nativeNotifications = [];
      window.Audio = class { play() { window.soundCount++; return Promise.resolve(); } };
      window.Notification = class {
        static permission = 'granted';
        static requestPermission() { return Promise.resolve('granted'); }
        constructor(title, options) { this.title = title; this.options = options; this.closed = false; window.nativeNotifications.push(this); }
        close() { this.closed = true; }
      };
    });
    await context.route('http://chat.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/index.php') {
        const userId = Number(url.searchParams.get('user') || 1);
        const page = url.searchParams.get('page');
        const body = page === 'chat' ? chat : page === 'profile' ? profile : '<h1>Orders</h1>';
        const config = JSON.stringify({ userId, csrfToken: token, preferences: preferences.get(userId) });
        return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8">'
          + '<link rel="stylesheet" href="/dist/css/adminlte.min.css"><script src="/plugins/jquery/jquery.min.js"></script>'
          + '<script src="/plugins/bootstrap/js/bootstrap.bundle.min.js"></script></head><body class="dark-mode p-3">'
          + '<span id="chatUnreadBadge"></span><span id="chatNotifToggle"></span><span id="chatNotifHeader"></span><div id="chatNotifList"></div>'
          + '<script>window.darkScrubChatNotificationConfig=' + config + ';</script><script src="/scripts/chat/notifications.js"></script>'
          + '<script>' + navScript + '</script>' + body + '</body></html>' });
      }
      if (url.pathname === '/scripts/chat/notification_preferences.php') {
        const post = new URLSearchParams(route.request().postData());
        assert.equal(post.get('csrf_token'), token);
        const next = { toast_mode: post.get('toast_mode'), sound_enabled: post.get('sound_enabled') === '1', revision: preferences.get(1).revision + 1 };
        preferences.set(1, next);
        return route.fulfill({ json: { status: 'success', preferences: next } });
      }
      if (url.pathname === '/scripts/chat/mark_read.php') {
        markRequests++;
        const post = new URLSearchParams(route.request().postData());
        const threadId = Number(post.get('thread_id'));
        const messageId = Number(post.get('last_message_id'));
        readPositions = [{ thread_id: threadId, last_read_message_id: messageId }];
        items = items.filter(item => item.thread_id !== threadId || item.last_message_id > messageId);
        return route.fulfill({ json: { status: 'success', last_read_message_id: messageId } });
      }
      if (url.pathname === '/scripts/chat/get_unread_threads.php') {
        const referrer = new URL(route.request().headers().referer);
        const userId = Number(referrer.searchParams.get('user') || 1);
        return route.fulfill({ json: { status: 'success', count: items.length, threads: items,
          read_positions: readPositions, notification_preferences: preferences.get(userId) } });
      }
      if (url.pathname.startsWith('/scripts/chat/') && url.pathname.endsWith('.php')) {
        return route.fulfill({ json: { status: 'success', contacts: [], threads: [], messages: [] } });
      }
      const asset = path.resolve(root, '.' + url.pathname);
      assert.ok(asset.startsWith(root + path.sep));
      if (fs.existsSync(asset)) return route.fulfill({ path: asset });
      return route.fulfill({ status: 404, body: '' });
    });
    async function open(pageName, user = 1) {
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(error.message));
      await page.goto('http://chat.test/index.php?page=' + pageName + '&user=' + user);
      await page.waitForFunction(() => chatNotifInitialized);
      await page.locator('body').click({ position: { x: 5, y: 5 } });
      return page;
    }
    async function refresh(pages) {
      await Promise.all(pages.map(page => page.evaluate(() => new Promise(resolve => {
        const request = $.ajax; $.ajax = function (options) {
          if (options.url !== 'scripts/chat/get_unread_threads.php') return request.apply(this, arguments);
          const complete = options.complete;
          options.complete = function () { if (complete) complete.apply(this, arguments); resolve(); };
          $.ajax = request; return request.call(this, options);
        }; loadChatNotifications();
      }))));
    }
    const a = await open('orders');
    const b = await open('orders');
    const c = await open('chat');
    const settings = await open('profile');
    const otherUser = await open('orders', 2);
    const pages = [a, b, c, settings];
    const message = (id, thread = 10) => ({ thread_id: thread, last_message_id: id, name: 'Sender', photo: '',
      message_text: 'Same text', message_type: 'text', created_at: '2026-11-20 10:00:00' });
    const first = message(101);
    items = [first, message(200, 20)];
    await refresh([a, b, otherUser]);
    await a.locator('#chatNavbarToastContainer .toast').first().waitFor();
    await b.locator('#chatNavbarToastContainer .toast').first().waitFor();
    await c.evaluate(item => window.chatTest.showChatToast({ ...item, message_id: item.last_message_id, sender_name: item.name }), first);
    assert.equal(await c.locator('.chat-toast').count(), 1);
    await a.locator('#chatNavbarToastContainer .toast').first().locator('.close').click();
    await b.waitForFunction(() => document.querySelectorAll('#chatNavbarToastContainer .toast').length === 1);
    await c.locator('.chat-toast').waitFor({ state: 'detached' });
    assert.equal(markRequests, 0, 'Closing a toast must not mark a message as read.');
    assert.equal(await b.locator('#chatUnreadBadge').innerText(), '2');
    assert.equal(await otherUser.locator('#chatNavbarToastContainer .toast').count(), 2, 'Dismissal is user-scoped.');
    await c.evaluate(item => window.chatTest.showChatToast({ ...item, message_id: item.last_message_id, sender_name: item.name }), first);
    assert.equal(await c.locator('.chat-toast').count(), 0, 'Dismissed messages stay dismissed.');

    items = [message(102), message(200, 20)];
    await refresh([a, b]);
    await b.waitForFunction(() => document.querySelectorAll('#chatNavbarToastContainer .toast').length === 2);
    readPositions = [{ thread_id: 10, last_read_message_id: 101 }];
    await refresh([a, b]);
    assert.equal(await b.locator('#chatNavbarToastContainer .toast').count(), 2, 'Reading an older message must preserve newer toasts.');
    await c.evaluate(() => {
      window.chatTest.setThread(10);
      Object.defineProperty(document, 'hasFocus', { configurable: true, value: () => false });
      window.chatTest.renderMessages([{ id: 102, message_type: 'text', message_text: 'Background message', created_at: '' }]);
    });
    assert.equal(markRequests, 0, 'Background chat must not mark incoming messages as read.');
    await c.bringToFront();
    await c.evaluate(() => {
      Object.defineProperty(document, 'hasFocus', { configurable: true, value: () => true });
      window.chatTest.renderMessages([{ id: 102, message_type: 'text', message_text: 'Foreground message', created_at: '' }]);
    });
    await b.waitForFunction(() => document.querySelectorAll('#chatNavbarToastContainer .toast').length === 1, null, { timeout: 5000 });
    await b.waitForFunction(() => document.querySelector('#chatUnreadBadge').textContent === '1');
    assert.equal(markRequests, 1);
    assert.equal(await b.evaluate(() => window.nativeNotifications.filter(n => !n.closed && n.options.tag.endsWith(':10:102')).length), 0);

    async function save(mode, sound) {
      await settings.locator('#chatNotificationMode').selectOption(mode);
      await settings.locator('#chatNotificationSound').setChecked(sound, { force: true });
      await settings.locator('#chatNotificationSettingsForm button[type="submit"]').click();
      await settings.waitForFunction(() => document.querySelector('#chatNotificationSettingsStatus').textContent === 'Nastavenia upozornení boli uložené.');
      await b.waitForFunction(mode => window.darkScrubChatNotifications.getPreferences().toast_mode === mode, mode);
    }
    await a.clock.install();
    await save('brief', false);
    await a.clock.runFor(10500);
    await b.waitForFunction(() => document.querySelectorAll('#chatNavbarToastContainer .toast').length === 0);
    assert.equal(await b.locator('#chatUnreadBadge').innerText(), '1', 'Auto-hide preserves unread badge.');
    await save('badge', false);
    items = [message(201, 20)];
    const soundsBefore = await Promise.all(pages.map(page => page.evaluate(() => window.soundCount)));
    await refresh([a, b]);
    assert.equal(await b.locator('#chatNavbarToastContainer .toast').count(), 0);
    assert.equal(await b.locator('#chatUnreadBadge').innerText(), '1');
    assert.deepEqual(await Promise.all(pages.map(page => page.evaluate(() => window.soundCount))), soundsBefore);
    assert.equal(await b.evaluate(() => window.nativeNotifications.filter(n => !n.closed).length), 0);
    await b.reload();
    await b.waitForFunction(() => chatNotifInitialized);
    assert.equal(await b.evaluate(() => window.darkScrubChatNotifications.getPreferences().toast_mode), 'badge');

    await save('persistent', true);
    await b.locator('body').click({ position: { x: 5, y: 5 } });
    items = [message(202, 20)];
    const soundBaseline = await Promise.all([a, b].map(page => page.evaluate(() => window.soundCount)));
    await refresh([a, b]);
    await b.waitForFunction(() => document.querySelectorAll('#chatNavbarToastContainer .toast').length === 1);
    const soundAfter = await Promise.all([a, b].map(page => page.evaluate(() => window.soundCount)));
    assert.equal(soundAfter.reduce((n, value, i) => n + value - soundBaseline[i], 0), 1, 'One sound per message across windows.');
    await a.clock.runFor(11000);
    assert.equal(await a.locator('#chatNavbarToastContainer .toast').count(), 1, 'Persistent toasts do not expire.');

    await b.evaluate(() => window.darkScrubChatNotifications.syncServer({
      status: 'success', notification_preferences: { toast_mode: 'badge', sound_enabled: false, revision: 1 }
    }));
    assert.equal(await b.evaluate(() => window.darkScrubChatNotifications.getPreferences().toast_mode), 'persistent', 'Old poll responses cannot undo saved preferences.');
    const fallbackPages = [];
    for (let i = 0; i < 2; i++) {
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(error.message));
      await page.addInitScript(() => { window.BroadcastChannel = undefined; });
      await page.goto('http://chat.test/index.php?page=orders&user=1');
      await page.evaluate(() => {
        const element = document.createElement('div');
        element.id = 'fallback-toast';
        document.body.appendChild(element);
        window.darkScrubChatNotifications.registerToast(77, 770, () => element.remove());
      });
      fallbackPages.push(page);
    }
    await fallbackPages[0].evaluate(() => window.darkScrubChatNotifications.dismiss(77, 770));
    await fallbackPages[1].locator('#fallback-toast').waitFor({ state: 'detached' });
    await fallbackPages[0].close();
    await fallbackPages[1].close();
    await settings.screenshot({ path: path.join(require('node:os').tmpdir(), 'chat-notification-settings.png') });
    assert.deepEqual(errors, []);
    console.log('chat_notifications: OK (API, settings, multi-window dismiss/read, background chat, timeout, badge, sound, user isolation)');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
