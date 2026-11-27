const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
function api(request, status = 200) {
  const result = spawnSync('php', ['tests/project_workflow_request.php'], { cwd: root, input: JSON.stringify(request), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr + result.stdout);
  assert.equal(result.stderr, 'http_status=' + status, result.stdout);
  return JSON.parse(result.stdout);
}
const token = 'a'.repeat(64);
const initial = api({});
assert.equal(initial.items[0].kind, 'task_review');
assert.equal(initial.items.length, 1);
api({ user_id: 0 }, 401);
api({ method: 'DELETE' }, 405);
api({ method: 'POST', post: { action: 'approve_task', project_id: 1, task_id: 1, csrf_token: 'bad' } }, 403);
api({ user_id: 8, method: 'POST', post: { action: 'approve_task', project_id: 1, task_id: 1, csrf_token: token } }, 422);
api({ method: 'POST', post: { action: 'approve_task', project_id: 2, task_id: 1, csrf_token: token } }, 422);
const approved = api({ method: 'POST', post: { action: 'approve_task', project_id: 1, task_id: 1, csrf_token: token } });
assert.deepEqual(approved.items.map(n => n.kind), ['task_done']);
assert.equal(api({ user_id: 9 }).items.length, 0);
const rendered = spawnSync('php', ['tests/project_workflow_request.php', '--render'], { cwd: root, encoding: 'utf8' });
assert.equal(rendered.status, 0, rendered.stderr);
assert.equal(rendered.stderr, '');
let items = initial.items;
const posts = [];
(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.PROJECT_TEST_BROWSER_CHANNEL || undefined });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    await context.addInitScript(() => {
      window.submissions = [];
      HTMLFormElement.prototype.submit = function () { window.submissions.push(Object.fromEntries(new FormData(this))); };
    });
    await context.route('http://projects.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/scripts/projects/notifications.php') {
        if (route.request().method() === 'POST') {
          const body = Object.fromEntries(new URLSearchParams(route.request().postData())); posts.push(body);
          assert.equal(body.csrf_token, token);
          if (body.action === 'mark_read') items = items.filter(n => String(n.id) !== body.id);
          else items = [];
        }
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items, count: items.length }) });
      }
      if (url.pathname === '/index.php' || url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: rendered.stdout });
      const filename = path.resolve(root, '.' + url.pathname);
      if (!filename.startsWith(root + path.sep) || !fs.existsSync(filename)) return route.fulfill({ status: 404, body: '' });
      const types = { '.js': 'text/javascript', '.css': 'text/css', '.jpg': 'image/jpeg', '.png': 'image/png', '.woff2': 'font/woff2' };
      return route.fulfill({ body: fs.readFileSync(filename), contentType: types[path.extname(filename)] || 'application/octet-stream' });
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://projects.test/index.php?page=projects&view=1');
    await page.waitForSelector('#projectNotificationToasts [data-notification-id]');
    assert.equal(await page.locator('#projectNotificationBadge').textContent(), '1');
    assert.equal(await page.locator('#project-task-1 .project-task-complete').isChecked(), true);
    assert.equal(await page.locator('#project-task-2 .project-task-complete').count(), 0);
    assert.deepEqual(await page.locator('#projectTasksTable tbody tr').evaluateAll(rows => rows.map(r => r.dataset.taskId)), ['1','2','3','4']);
    assert.equal(await page.locator('#project-task-2').evaluate(el => el.classList.contains('project-task-subtask')), true);
    assert.equal(await page.locator('#addTaskParent option[value="2"]').count(), 0);
    await page.locator('#project-task-2 button[title="Edit task"]').click();
    await page.waitForSelector('#editTaskModal.show');
    assert.equal(await page.locator('#editTaskParent').inputValue(), '1');
    assert.equal(await page.locator('#editTaskReview').isChecked(), false);
    await page.locator('#editTaskModal [data-dismiss="modal"]').first().click();
    await page.waitForSelector('#editTaskModal', { state: 'hidden' });
    async function drag(source, target) {
      const dataTransfer = await page.evaluateHandle(() => new DataTransfer());
      await page.dispatchEvent(source, 'dragstart', { dataTransfer });
      await page.dispatchEvent(target, 'dragover', { dataTransfer });
      await page.dispatchEvent(target, 'drop', { dataTransfer });
      await page.dispatchEvent(source, 'dragend', { dataTransfer });
    }
    await drag('#project-task-4 .project-task-drag', '#project-task-1');
    let submitted = await page.evaluate(() => window.submissions.pop());
    assert.equal(submitted.action, 'move_task'); assert.equal(submitted.parent_task_id, '1'); assert.equal(submitted.task_id, '4'); assert.equal(submitted.csrf_token, token);
    await drag('#project-task-2 .project-task-drag', '#projectTaskRootDrop');
    submitted = await page.evaluate(() => window.submissions.pop());
    assert.equal(submitted.parent_task_id, '0'); assert.equal(submitted.task_id, '2');
    await drag('#project-task-4 .project-task-drag', '#project-task-2');
    assert.equal(await page.evaluate(() => window.submissions.length), 0);
    assert.equal(await page.locator('#projectTaskMoveError').isVisible(), true);
    await page.locator('#projectNotificationToasts button').filter({ hasText: 'Approve' }).click();
    await page.waitForFunction(() => document.getElementById('projectNotificationBadge').hidden);
    assert.equal(posts.at(-1).action, 'approve_task');
    assert.equal(await page.locator('#projectNotificationToasts [data-notification-id]').count(), 0);
    items = [{ id: 99, project_id: 1, task_id: 2, kind: 'task_ready', task_title: '<img src=x onerror=alert(1)>', project_title: 'Honda Designz' }];
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.waitForSelector('#projectNotificationToasts [data-notification-id="99"]');
    assert.equal(await page.locator('#projectNotificationToasts img').count(), 0);
    assert.match(await page.locator('#projectNotificationToasts a').textContent(), /<img/);
    await page.locator('#projectNotificationToasts [aria-label="Hide popup; keep notification"]').click();
    assert.equal(await page.locator('#projectNotificationBadge').textContent(), '1');
    await page.reload();
    await page.waitForFunction(() => document.getElementById('projectNotificationBadge').textContent === '1');
    assert.equal(await page.locator('#projectNotificationToasts [data-notification-id="99"]').count(), 0);
    await page.locator('#projectNotificationToggle').click();
    await page.locator('#projectNotificationItems button').filter({ hasText: 'Mark read' }).click();
    await page.waitForFunction(() => document.getElementById('projectNotificationBadge').hidden);
    assert.equal(posts.at(-1).action, 'mark_read');
    fs.mkdirSync(path.join(root, 'outputs/project-workflow'), { recursive: true });
    await page.locator('#projectNotificationToggle').click();
    await page.screenshot({ path: path.join(root, 'outputs/project-workflow/desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await page.locator('#projectNotificationToggle').isVisible(), true);
    await page.screenshot({ path: path.join(root, 'outputs/project-workflow/mobile.png'), fullPage: true });
    assert.deepEqual(errors, []);
    console.log('project_workflow_ui: OK (API auth/CSRF, hierarchy, modals, drag/drop, review, live alerts, acknowledgement, escaping, mobile)');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
