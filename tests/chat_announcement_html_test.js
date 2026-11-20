const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const rendered = spawnSync('php', ['-d', 'short_open_tag=1', '-r',
  "session_start(['use_cookies' => 0, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]); $_SESSION = ['user_id' => 1, 'permission' => 500]; include 'includes/chat.php';"
], { encoding: 'utf8', cwd: root, timeout: 30000 });
assert.equal(rendered.status, 0, rendered.stderr || rendered.error?.message);
assert.doesNotMatch(rendered.stdout, /Fatal error|Warning:|Notice:/);
for (const script of rendered.stdout.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)) {
  new vm.Script(script[1]);
}
const body = rendered.stdout.replace('})(window.jQuery);',
  'window.chatTest = { renderMessages, formatAnnouncementHtml, chatMessagePreview, showChatToast }; })(window.jQuery);');
assert.notEqual(body, rendered.stdout);
const html = '<!doctype html><html><head><meta charset="utf-8"><title>Chat test</title>'
  + '<link rel="stylesheet" href="/dist/css/adminlte.min.css">'
  + '<script src="/plugins/jquery/jquery.min.js"></script></head><body class="dark-mode p-3">'
  + body + '</body></html>';

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.CHAT_TEST_BROWSER_CHANNEL || undefined });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    const externalRequests = [];
    await context.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.origin !== 'http://chat.test') {
        externalRequests.push(url.href);
        return route.abort();
      }
      if (url.pathname === '/') {
        return route.fulfill({ contentType: 'text/html', body: html });
      }
      if (url.pathname.startsWith('/scripts/chat/')) {
        return route.fulfill({ json: { status: 'success', contacts: [], threads: [], messages: [] } });
      }
      const asset = path.resolve(root, '.' + decodeURIComponent(url.pathname));
      assert.ok(asset.startsWith(root + path.sep));
      if (fs.existsSync(asset)) return route.fulfill({ path: asset });
      return route.fulfill({ status: 404, body: '' });
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://chat.test/?page=chat');
    await page.locator('label[for="chatBroadcastModeToggle"]').click();
    assert.equal(await page.locator('#chatAnnouncementHtmlHint').isVisible(), true);
    await page.locator('label[for="chatBroadcastModeToggle"]').click();
    assert.equal(await page.locator('#chatAnnouncementHtmlHint').isVisible(), false);

    const formatted = '<h1>Dôležitý oznam</h1><p>Prvý riadok<br />Druhý <strong>tučný</strong> a <em>kurzíva</em>.</p>'
      + '<ul><li>Jeden</li><li>Dva</li></ul>'
      + '<a href="https://example.test/?a=1&amp;b=2">Podrobnosti</a>';
    const malicious = '<script>window.chatXss = true</script><style>body { display:none }</style>'
      + '<h2 onclick="window.chatXss=true" style="position:fixed" id="chatMessages">Safe heading</h2>'
      + '<img src="https://attacker.test/image" onerror="window.chatXss=true">'
      + '<iframe src="https://attacker.test/frame"></iframe>'
      + '<svg onload="window.chatXss=true"><a href="javascript:alert(1)">SVG</a></svg>'
      + '<math><mtext><img src=x onerror="window.chatXss=true"></mtext></math>'
      + '<a href="javascript:alert(1)" onclick="window.chatXss=true">Script link</a>'
      + '<a href="java&#x09;script:alert(1)">Encoded link</a>'
      + '<a href="data:text/html,test">Data link</a>'
      + '<a href="https://example.test/" onmouseover="window.chatXss=true">Safe link</a>'
      + ' https://example.test/&quot;onmouseover=&quot;window.chatXss=true';
    const plain = 'Žltý oznam &amp; tím 😀\nDruhý riadok https://example.test/?a=1&b=2\nY:\\Shared Folder\\file.pdf';
    const messages = [formatted, formatted, malicious, plain].map((text, index) => ({
      id: index + 1, message_text: text, message_type: index === 1 ? 'text' : 'announcement',
      sender_name: 'Administrator', created_at: '2026-11-20 10:00:00', is_own: false
    }));
    await page.evaluate(messages => window.chatTest.renderMessages(messages), messages);
    const bubbles = page.locator('#chatMessages .chat-bubble');
    assert.equal(await bubbles.count(), 4);
    const announcement = bubbles.nth(0);
    assert.equal(await announcement.locator('h1').innerText(), 'Dôležitý oznam');
    assert.equal(await announcement.locator('br').count(), 1);
    assert.equal(await announcement.locator('strong').innerText(), 'tučný');
    assert.equal(await announcement.locator('em').innerText(), 'kurzíva');
    assert.deepEqual(await announcement.locator('li').allTextContents(), ['Jeden', 'Dva']);
    const link = announcement.locator('a');
    assert.equal(await link.getAttribute('href'), 'https://example.test/?a=1&b=2');
    assert.equal(await link.getAttribute('rel'), 'noopener noreferrer');
    assert.equal(await link.getAttribute('target'), '_blank');
    assert.equal(await bubbles.nth(1).locator('h1, p, br, strong, em, ul, li').count(), 0);
    assert.ok((await bubbles.nth(1).innerText()).includes('<h1>Dôležitý oznam</h1>'));

    const sanitized = bubbles.nth(2);
    assert.equal(await sanitized.locator('script, style, img, iframe, svg, math, [onclick], [onerror], [onload], [onmouseover], [style], [id]').count(), 0);
    assert.equal(await sanitized.locator('h2').innerText(), 'Safe heading');
    for (const label of ['Script link', 'Encoded link', 'Data link']) {
      assert.equal(await sanitized.getByText(label, { exact: true }).getAttribute('href'), null);
    }
    assert.equal(await page.evaluate(() => window.chatXss), undefined);
    assert.deepEqual(externalRequests, [], 'Unsafe content must not trigger external resource requests.');
    assert.match(await bubbles.nth(3).innerText(), /Žltý oznam & tím 😀\nDruhý riadok/);
    assert.equal(await bubbles.nth(3).locator('.chat-web-link').getAttribute('href'), 'https://example.test/?a=1&b=2');
    assert.equal(await bubbles.nth(3).locator('.chat-copy-path').getAttribute('data-path'), encodeURIComponent('Y:\\Shared Folder\\file.pdf'));

    const previews = await page.evaluate(({ formatted, malicious }) => [
      window.chatTest.chatMessagePreview({ last_message_text: formatted }, 'announcement'),
      window.chatTest.chatMessagePreview({ message_text: formatted }, 'dm'),
      window.chatTest.chatMessagePreview({ message_text: malicious }, 'announcement'),
      window.chatTest.chatMessagePreview({ message_text: 'Úvod<h2>Nadpis</h2>Koniec' }, 'announcement'),
      window.chatTest.formatAnnouncementHtml('&lt;h1&gt;Literal&lt;/h1&gt;'),
      window.chatTest.formatAnnouncementHtml('<pre><code>&lt;script&gt;test&lt;/script&gt;\nhttps://example.test/</code></pre>')
    ], { formatted, malicious });
    assert.equal(previews[0], 'Dôležitý oznam Prvý riadok Druhý tučný a kurzíva. Jeden Dva Podrobnosti');
    assert.equal(previews[1], formatted);
    assert.doesNotMatch(previews[2], /<script>|display:none|attacker\.test/);
    assert.equal(previews[3], 'Úvod Nadpis Koniec');
    assert.equal(previews[4], '&lt;h1&gt;Literal&lt;/h1&gt;');
    assert.match(previews[5], /^<pre><code>&lt;script&gt;test&lt;\/script&gt;\nhttps:\/\/example.test\/<\/code><\/pre>$/);

    await page.evaluate(formatted => window.chatTest.showChatToast({
      thread_id: 99, message_id: 99, thread_type: 'announcement',
      sender_name: 'Administrator', message_text: formatted
    }), formatted);
    assert.equal(await page.locator('.chat-toast-preview').innerText(), previews[0]);
    const screenshot = path.join(os.tmpdir(), 'chat-announcement-html.png');
    await page.screenshot({ path: screenshot });
    assert.deepEqual(errors, [], 'No browser script errors.');
    console.log('chat_announcement_html: OK (formatting, links, plain messages, previews, XSS filtering)');
    console.log('Screenshot: ' + screenshot);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
