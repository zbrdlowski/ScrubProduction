const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({channel:'chrome',headless:true});
  const page = await browser.newPage({viewport:{width:1440,height:1000}});
  const errors=[],requests=[];
  page.on('pageerror', e=>errors.push(e.message));
  let hasBoard=false, version=1;
  const savedCard={id:51,column_id:1,title:'Done <b> vlastný názov',description:'Popis zostáva v pôvodnom jazyku.',
    label:'High',reference:'Zákazník ABC',reference_url:'',priority:'urgent',due_date:'2020-01-05',
    created_at:'2020-01-02 10:00:00',completed_at:null};
  const savedColumn={id:1,title:'Moje vlastné zadania',color:'#60a5fa',is_done:0};
  const completed={id:2,title:'Hotové – vlastný názov',color:'#34d399',is_done:1};
  const fixtures={
    sk:fs.readFileSync('.cache/kanban/preview-sk.html','utf8'),
    en:fs.readFileSync('.cache/kanban/preview-en.html','utf8')
  };
  await page.route('**/index.php?*',async route=>{
    const url=new URL(route.request().url());
    if(url.searchParams.get('page')!=='kanban')return route.continue();
    const pref=(await page.context().cookies()).find(c=>c.name==='kanban_lang_701')?.value;
    const lang=url.searchParams.get('kb_lang')||pref||'sk';
    await route.fulfill({contentType:'text/html',body:fixtures[lang]});
  });
  await page.route('**/scripts/kanban/api.php*',async route=>{
    const req=route.request(),url=new URL(req.url()),lang=url.searchParams.get('kb_lang');
    assert.ok(['sk','en'].includes(lang),'Every request includes language');
    requests.push({method:req.method(),lang});
    const data=req.method()==='POST'?req.postDataJSON():null;
    if(data) {
      assert.equal(req.headers()['x-csrf-token'],'kanban-ui-test-token');
      if(url.searchParams.get('action')==='initialize')hasBoard=true;
      else if(url.searchParams.get('action')==='save_card') {
        assert.equal(data.priority,'high','Saved enum remains canonical');
        Object.assign(savedCard,data);version++;
      } else if(url.searchParams.get('action')==='delete_column') {
        await route.fulfill({status:422,json:{ok:false,error:lang==='sk'?'Stĺpec obsahuje karty. Najprv ich presuňte do iného stĺpca.':'The column contains cards. Move them to another column first.'}});
        return;
      } else if(url.searchParams.get('action')==='settings') {
        await route.fulfill({status:409,json:{ok:false,error:lang==='sk'?'Nástenka sa zmenila v inom okne. Obnovte ju a zopakujte zmenu.':'The board was changed in another window. Refresh it and try again.'}});
        return;
      } else throw Error('Unexpected action '+url.searchParams.get('action'));
      await route.fulfill({json:{ok:true}});return;
    }
    await route.fulfill({json:{ok:true,board:hasBoard?{id:1,version,done_limit:20}:null,columns:hasBoard?[
      {...savedColumn,cards:[savedCard],total:1,has_more:false},
      {...completed,cards:[],total:0,has_more:false}
    ]:[]}});
  });
  const idle=()=>page.waitForFunction(()=>document.getElementById('kb-board')?.getAttribute('aria-busy')==='false'&&document.getElementById('kb-refresh')?.disabled===false);
  try {
    await page.goto('http://localhost/darkscrub/index.php?page=kanban');
    await idle();
    assert.equal(await page.locator('#kb-title').innerText(),'Môj kanban');
    assert.equal(await page.locator('[data-template=design] strong').innerText(),'Grafika');
    assert.equal(await page.locator('[data-kb-language=sk]').getAttribute('aria-pressed'),'true');
    // Same layout contract as the Product Builder language bar: first row, right aligned, 42px buttons.
    const geometry=await page.evaluate(()=>{
      const app=document.getElementById('kb-app').getBoundingClientRect();
      const bar=document.querySelector('.kb-language-bar').getBoundingClientRect();
      const button=document.querySelector('[data-kb-language=en]').getBoundingClientRect();
      const heading=document.querySelector('.kb-heading').getBoundingClientRect();
      return {right:Math.abs(app.right-button.right),above:bar.bottom<=heading.top,width:button.width};
    });
    assert(geometry.right<=1 && geometry.above && geometry.width>=42);
    await page.locator('[data-kb-language=en]').click();await idle();
    assert.equal(await page.locator('#kb-title').innerText(),'My kanban');
    assert.equal(await page.locator('html').getAttribute('lang'),'en');
    assert.equal(await page.locator('[data-template=design] strong').innerText(),'Design');
    assert.equal(await page.locator('[data-template=support] span').innerText(),'New → Handling → Waiting for customer → Resolved');
    assert.equal((await page.context().cookies()).find(c=>c.name==='kanban_lang_701').value,'en');
    await page.locator('[data-template=general]').click();await idle();
    assert.equal(await page.locator('.kb-lane-title').first().innerText(),savedColumn.title);
    assert.equal(await page.locator('.kb-card-title').innerText(),savedCard.title);
    assert.equal(await page.locator('.kb-card-title b').count(),0);
    assert.equal(await page.locator('.kb-priority').innerText(),'Urgent');
    assert.match(await page.locator('.kb-card-bottom').innerText(),/05\/01\/2020 · overdue/);
    assert.equal(await page.locator('[data-column="2"]').getAttribute('data-empty-label'),'Drop a card here');
    assert.equal(await page.locator('#kb-add-card').innerText(),'New card');
    await page.locator('#kb-add-card').click();
    assert.equal(await page.locator('#kb-card-dialog-title').innerText(),'New card');
    await page.locator('#kb-card-form [type=submit]').click();
    assert.equal(await page.locator('#kb-card-form [name=title]').evaluate(el=>el.validationMessage),'Please fill out this field.');
    await page.keyboard.press('Escape');
    await page.locator('.kb-card-title').click();
    assert.equal(await page.locator('#kb-card-dialog-title').innerText(),'Card details #51');
    assert.equal(await page.locator('#kb-card-form [name=title]').inputValue(),savedCard.title);
    assert.equal(await page.locator('#kb-card-form [name=priority] option:checked').innerText(),'Urgent');
    assert.equal(await page.locator('#kb-card-form [name=title]').evaluate(el=>el.validationMessage),'','Reset clears localized validation');
    await page.locator('#kb-card-form [name=priority]').selectOption('high');
    await page.locator('#kb-card-form [type=submit]').click();await idle();
    assert.equal(await page.locator('.kb-priority').innerText(),'High');
    await page.locator('[data-edit-column="1"]').click();
    assert.equal(await page.locator('#kb-column-dialog-title').innerText(),'Column settings');
    await page.locator('#kb-delete-column').click();
    assert.equal(await page.locator('#kb-confirm-text').innerText(),'Delete column “'+savedColumn.title+'”? The column must be empty, including older cards.');
    await page.locator('#kb-confirm-form [type=submit]').click();await idle();
    assert.equal(await page.locator('#kb-column-form .kb-form-error').innerText(),'The column contains cards. Move them to another column first.');
    await page.keyboard.press('Escape');
    await page.locator('#kb-done-limit').fill('0');await page.locator('#kb-done-limit').press('Tab');
    assert.equal(await page.locator('#kb-done-limit').evaluate(el=>el.validationMessage),'Please enter a value between 1 and 100.');
    await page.locator('#kb-done-limit').fill('20');
    await page.locator('#kb-done-limit').press('Tab');await idle();
    assert.match(await page.locator('#kb-message').innerText(),/The board was changed in another window/);
    await page.locator('#kb-refresh').click();await idle();
    await page.screenshot({path:'.cache/kanban/i18n-en.png',fullPage:true});
    await page.goto('http://localhost/darkscrub/index.php?page=kanban');await idle();
    assert.equal(await page.locator('#kb-title').innerText(),'My kanban','Preference survives navigation without query');
    await page.locator('[data-kb-language=sk]').click();await idle();
    assert.equal(await page.locator('#kb-title').innerText(),'Môj kanban');
    assert.equal(await page.locator('.kb-card-title').innerText(),savedCard.title,'Switch preserves saved text');
    assert.equal(await page.locator('.kb-priority').innerText(),'Vysoká');
    assert.match(await page.locator('.kb-card-bottom').innerText(),/5\. 1\. 2020 · po termíne/);
    assert.equal(await page.locator('[data-column="2"]').getAttribute('data-empty-label'),'Sem môžete potiahnuť kartu');
    await page.locator('#kb-add-card').click();
    await page.locator('#kb-card-form [type=submit]').click();
    assert.equal(await page.locator('#kb-card-form [name=title]').evaluate(el=>el.validationMessage),'Vyplňte toto pole.');
    await page.keyboard.press('Escape');
    await page.setViewportSize({width:390,height:844});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    await page.screenshot({path:'.cache/kanban/i18n-sk-mobile.png',fullPage:true});
    await page.locator('[data-kb-language=en]').click();await idle();
    assert.equal(await page.locator('#kb-title').innerText(),'My kanban');
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    // Check the real endpoint, independently of the intercepted UI data.
    for(const [lang,error] of [['sk','Prihláste sa do aplikácie.'],['en','Please sign in to the application.']]) {
      const response=await page.request.get('http://localhost/darkscrub/scripts/kanban/api.php?kb_lang='+lang);
      assert.equal(response.status(),401);
      assert.equal((await response.json()).error,error);
    }
    assert(requests.some(r=>r.lang==='sk') && requests.some(r=>r.lang==='en' && r.method==='POST'));
    assert.deepEqual(errors,[]);
    console.log('kanban_i18n_ui: SK/EN switch placement, persistence, form validation, dates, priorities, templates, API errors, unchanged saved values, and mobile passed.');
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
