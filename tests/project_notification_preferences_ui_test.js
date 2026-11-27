const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
const token = 'a'.repeat(64);
function api(request, status = 200) {
  const result = spawnSync('php', ['tests/project_workflow_request.php'], { cwd: root, input: JSON.stringify(request), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stderr, 'http_status=' + status, result.stdout);
  return JSON.parse(result.stdout);
}
assert.equal(api({}).preferences.toast_mode, 'persistent');
for (const mode of ['persistent','brief','badge']) {
  const response = api({ method: 'POST', post: { action: 'save_preferences', toast_mode: mode, csrf_token: token, user_id: 9 } });
  assert.equal(response.preferences.toast_mode, mode);
  assert.equal(response.preferences.revision, 1);
}
api({ method: 'POST', post: { action: 'save_preferences', toast_mode: 'badge', csrf_token: 'bad' } }, 403);
api({ method: 'POST', post: { action: 'save_preferences', toast_mode: 'invalid', csrf_token: token } }, 422);
api({ user_id: 0, method: 'POST', post: { action: 'save_preferences', toast_mode: 'badge', csrf_token: token } }, 401);
const fixture = spawnSync('php', ['tests/project_workflow_request.php','--render-profile'], { cwd: root, encoding: 'utf8' });
assert.equal(fixture.status,0,fixture.stderr); assert.equal(fixture.stderr,'');
const preferences = new Map([7,8].map(id=>[id,{toast_mode:'persistent',revision:0}]));
const items = [
  {id:51,project_id:1,task_id:1,kind:'task_review',task_title:'Review design',project_title:'Honda Designz'},
  {id:52,project_id:1,task_id:2,kind:'task_done',task_title:'Completed design',project_title:'Honda Designz'},
  {id:53,project_id:1,task_id:3,kind:'task_ready',task_title:'List on website',project_title:'Honda Designz'}
];
const posts = [];
(async()=>{
  const browser = await chromium.launch({headless:true,channel:process.env.PROJECT_TEST_BROWSER_CHANNEL || undefined});
  try {
    const context = await browser.newContext();
    const errors=[];
    await context.route('http://projects.test/**', async route=>{
      const url=new URL(route.request().url());
      if(url.pathname==='/index.php') {
        const userId=Number(url.searchParams.get('user')||7);
        const config={userId,csrfToken:token,endpoint:'scripts/projects/notifications.php',preferences:preferences.get(userId)};
        return route.fulfill({contentType:'text/html',body:fixture.stdout.replace(/window.darkScrubProjectNotifications = .*?;<\/script>/,
          'window.darkScrubProjectNotifications = '+JSON.stringify(config)+';</script>')});
      }
      if(url.pathname==='/scripts/projects/notifications.php') {
        const userId=Number(new URL(route.request().headers().referer).searchParams.get('user')||7);
        if(route.request().method()==='POST') {
          const body=Object.fromEntries(new URLSearchParams(route.request().postData()));posts.push(body);
          assert.equal(body.action,'save_preferences');assert.equal(body.csrf_token,token);
          preferences.set(userId,{toast_mode:body.toast_mode,revision:preferences.get(userId).revision+1});
          return route.fulfill({json:{preferences:preferences.get(userId)}});
        }
        return route.fulfill({json:{items,count:items.length,preferences:preferences.get(userId)}});
      }
      const file=path.resolve(root,'.'+url.pathname);
      assert.ok(file.startsWith(root+path.sep));
      return fs.existsSync(file)?route.fulfill({path:file}):route.fulfill({status:404,body:''});
    });
    async function open(user=7) {
      const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
      await page.goto('http://projects.test/index.php?page=profile&tab=notifications&user='+user);
      await page.waitForFunction(()=>window.darkScrubProjectNotificationService && document.getElementById('projectNotificationBadge').textContent==='3');
      return page;
    }
    const page=await open(), second=await open(), other=await open(8);
    const ownerToasts='#projectNotificationToasts [data-kind="task_review"], #projectNotificationToasts [data-kind="task_done"]';
    assert.equal(await page.locator(ownerToasts).count(),2);
    async function save(mode) {
      await page.locator('#projectNotificationMode').selectOption(mode);
      await page.locator('#projectNotificationSettingsForm button').click();
      await page.waitForFunction(mode=>window.darkScrubProjectNotificationService.getPreferences().toast_mode===mode,mode);
      await page.locator('#projectNotificationSettingsStatus.text-success').waitFor();
    }
    await save('badge');
    await second.waitForFunction(()=>window.darkScrubProjectNotificationService.getPreferences().toast_mode==='badge');
    assert.equal(await page.locator(ownerToasts).count(),0);assert.equal(await second.locator(ownerToasts).count(),0);
    assert.equal(await page.locator('#projectNotificationToasts [data-kind="task_ready"]').count(),1);
    assert.equal(await page.locator('#projectNotificationBadge').textContent(),'3');
    assert.equal(await page.locator('#projectNotificationItems button').filter({hasText:'Approve'}).count(),1);
    assert.equal(await other.locator(ownerToasts).count(),2,'Other employee settings remain unchanged.');
    assert.equal(await page.locator('#chatNotificationMode').inputValue(),'persistent','Chat setting is independent.');
    await page.reload();
    await page.waitForFunction(()=>document.getElementById('projectNotificationBadge').textContent==='3');
    assert.equal(await page.locator('#projectNotificationMode').inputValue(),'badge');
    assert.equal(await page.locator(ownerToasts).count(),0,'Saved setting survives navigation.');
    await save('brief');
    assert.equal(await page.locator(ownerToasts).count(),2);
    await save('persistent');
    await page.waitForTimeout(10500);
    assert.equal(await page.locator(ownerToasts).count(),2,'Persistent cancels brief timers.');
    await save('brief');
    await page.waitForFunction(()=>!document.querySelector('#projectNotificationToasts [data-kind="task_review"]'),null,{timeout:15000});
    assert.equal(await page.locator(ownerToasts).count(),0,'Both owner toast types expire after 10 seconds.');
    assert.equal(await page.locator('#projectNotificationToasts [data-kind="task_ready"]').count(),1,'Assignee alerts stay persistent.');
    assert.equal(await page.locator('#projectNotificationBadge').textContent(),'3','Timeout does not acknowledge notices.');
    assert.ok(posts.every(post=>post.action==='save_preferences'),'Preferences never approve, dismiss or mark notifications read.');
    await page.reload();
    await page.waitForFunction(()=>document.getElementById('projectNotificationBadge').textContent==='3');
    assert.equal(await page.locator(ownerToasts).count(),0,'Expired toast does not reappear after reload.');
    assert.deepEqual(errors,[]);
    console.log('project_notification_preferences_ui: OK (profile save, persistence, all modes, timers, cross-tab sync, user isolation, API auth/CSRF)');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
