const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({channel:'chrome',headless:true});
  const page = await browser.newPage({viewport:{width:1792,height:1000}});
  const errors=[], mutations=[];
  page.on('pageerror', error=>errors.push(error.message));
  let version=1,failTemplate=false;
  let columns=[
    {id:1,title:'Backlog',color:'#94a3b8',is_done:0},
    {id:2,title:'To do',color:'#60a5fa',is_done:0},
    {id:3,title:'In progress',color:'#fbbf24',is_done:0},
    {id:4,title:'Done',color:'#34d399',is_done:1}
  ];
  const cards=[
    {id:11,column_id:3,title:'Pridať Number plates do všetkých listingov',description:'',priority:'normal',due_date:'2026-11-15',label:'',reference:'',reference_url:'',created_at:'2026-11-13 12:00:00',completed_at:null},
    {id:12,column_id:4,title:'Dokončená úloha',description:'Zachovať históriu.',priority:'high',due_date:null,label:'',reference:'',reference_url:'',created_at:'2026-11-10 12:00:00',completed_at:'2026-11-12 12:00:00'}
  ];
  const fixtures=Object.fromEntries(['sk','en'].map(lang=>[lang,fs.readFileSync('.cache/kanban/preview-'+lang+'.html','utf8')]));
  await page.route('**/index.php?*',route=>{
    const lang=new URL(route.request().url()).searchParams.get('kb_lang')||'sk';
    return route.fulfill({contentType:'text/html',body:fixtures[lang]});
  });
  await page.route('**/scripts/kanban/api.php*',async route=>{
    const request=route.request(),url=new URL(request.url());
    if(request.method()==='POST') {
      const data=request.postDataJSON();
      mutations.push({action:url.searchParams.get('action'),data});
      assert.equal(url.searchParams.get('action'),'apply_template');
      assert.equal(data.version,version);
      assert.equal(request.headers()['x-csrf-token'],'kanban-ui-test-token');
      assert.equal(data.template,'design');
      if(failTemplate) {
        failTemplate=false;
        await route.fulfill({status:500,json:{ok:false,error:'Test: uloženie zlyhalo.'}});return;
      }
      columns=[
        {id:101,title:'Zadanie',color:'#94a3b8',is_done:0},
        {id:102,title:'Rozpracované',color:'#60a5fa',is_done:0},
        {id:103,title:'Na schválenie',color:'#c084fc',is_done:0},
        {id:104,title:'Úpravy',color:'#fbbf24',is_done:0},
        {id:105,title:'Hotové',color:'#34d399',is_done:1}
      ];
      cards.forEach(c=>{c.column_id=c.completed_at?105:101;});version++;
      await route.fulfill({json:{ok:true}});return;
    }
    await route.fulfill({json:{ok:true,board:{id:1,version,done_limit:20},columns:columns.map(c=>{
      const rows=cards.filter(card=>card.column_id===c.id);
      return {...c,cards:rows,total:rows.length,has_more:false};
    })}});
  });
  const idle=()=>page.waitForFunction(()=>document.getElementById('kb-board')?.getAttribute('aria-busy')==='false'&&!document.getElementById('kb-refresh').disabled);
  const centered=async()=>{
    const geometry=await page.evaluate(()=>{
      const board=document.getElementById('kb-board').getBoundingClientRect();
      const lanes=[...document.querySelectorAll('.kb-lane')].map(el=>el.getBoundingClientRect());
      return {left:lanes[0].left-board.left,right:board.right-lanes.at(-1).right};
    });
    assert(geometry.left>20 && Math.abs(geometry.left-geometry.right)<2,'Whole group is centered on wide screens');
  };
  try {
    await page.goto('http://localhost/darkscrub/index.php?page=kanban&kb_lang=sk');await idle();
    await centered();
    const helpPlacement=await page.evaluate(()=>{
      const help=document.getElementById('kb-guide-open');
      const group=document.querySelector('.kb-language-bar .btn-group');
      return help.nextElementSibling===group && help.getBoundingClientRect().right<group.getBoundingClientRect().left;
    });
    assert(helpPlacement,'Help immediately precedes SK/EN');
    assert.equal((await page.locator('#kb-guide-open').innerText()).trim(),'Návod');
    await page.screenshot({path:'.cache/kanban/centered-board.png',fullPage:true});
    await page.locator('#kb-guide-open').click();
    assert(await page.locator('#kb-guide-dialog').isVisible());
    assert.match(await page.locator('.kb-guide-content').innerText(),/Prvý výber a návrat k šablónam/);
    assert.equal(await page.locator('.kb-guide-content h2').count(),8);
    assert.equal(await page.locator('.kb-guide-content script').count(),0);
    await page.screenshot({path:'.cache/kanban/guide-sk.png',fullPage:true});
    await page.keyboard.press('Escape');
    assert(!(await page.locator('#kb-guide-dialog').isVisible()));
    await page.locator('[data-kb-language=en]').click();await idle();
    await page.locator('#kb-guide-open').click();
    assert.equal(await page.locator('#kb-guide-title').innerText(),'Kanban guide');
    assert.match(await page.locator('.kb-guide-content').innerText(),/Choosing and reopening templates/);
    await page.locator('#kb-guide-dialog .kb-dialog-header [data-close]').click();
    await page.locator('#kb-templates-open').click();
    assert(await page.locator('#kb-onboarding').isVisible());
    assert(!(await page.locator('#kb-board').isVisible()));
    assert.match(await page.locator('#kb-template-notice').innerText(),/Your cards are kept/);
    await page.locator('[data-template=design]').click();
    assert.equal(await page.locator('#kb-template-title').innerText(),'Apply template?');
    assert.match(await page.locator('#kb-template-summary').innerText(),/Active cards move to “Brief” and completed cards to “Done”/);
    await page.locator('#kb-template-form [data-close]').click();
    await page.locator('#kb-template-back').click();
    assert.equal(mutations.length,0,'Reopening and cancelling never writes data');
    assert.equal(await page.locator('.kb-card').count(),2);
    await centered();
    await page.locator('[data-kb-language=sk]').click();await idle();
    await page.locator('#kb-templates-open').click();
    await page.locator('[data-template=design]').click();
    assert.match(await page.locator('#kb-template-summary').innerText(),/Aktívne karty sa presunú do stĺpca „Zadanie“/);
    failTemplate=true;
    await page.locator('#kb-template-form [type=submit]').click();await idle();
    assert(await page.locator('#kb-template-dialog').isVisible(),'Failed save keeps confirmation open');
    assert.equal(await page.locator('#kb-template-form .kb-form-error').innerText(),'Test: uloženie zlyhalo.');
    assert.equal(columns.length,4,'Failed save preserves old columns');
    await page.locator('#kb-template-form [type=submit]').click();await idle();
    assert(!(await page.locator('#kb-template-dialog').isVisible()));
    assert(!(await page.locator('#kb-onboarding').isVisible()));
    assert.equal(await page.locator('.kb-lane').count(),5);
    assert.equal(await page.locator('[data-column="101"] .kb-card').count(),1);
    assert.equal(await page.locator('[data-column="105"] .kb-card').count(),1);
    assert.equal(await page.locator('#kb-done-limit').inputValue(),'20');
    await centered();
    await page.setViewportSize({width:390,height:844});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No page overflow on mobile');
    const start=await page.evaluate(()=>{
      const board=document.getElementById('kb-board');board.scrollLeft=0;
      return {board:board.getBoundingClientRect().left,first:board.firstElementChild.getBoundingClientRect().left,overflow:board.scrollWidth>board.clientWidth};
    });
    assert(start.overflow && Math.abs(start.first-start.board-2)<2,'First column remains reachable on mobile');
    const end=await page.evaluate(()=>{
      const board=document.getElementById('kb-board');board.scrollLeft=board.scrollWidth;
      return {board:board.getBoundingClientRect().right,last:board.lastElementChild.getBoundingClientRect().right};
    });
    assert(Math.abs(end.board-end.last)<4,'Last column remains reachable on mobile');
    await page.locator('#kb-guide-open').click();
    assert(await page.locator('#kb-guide-dialog').isVisible());
    assert(await page.locator('#kb-guide-dialog').evaluate(el=>el.scrollWidth<=el.clientWidth),'Guide fits mobile width');
    await page.screenshot({path:'.cache/kanban/guide-mobile.png',fullPage:true});
    await page.keyboard.press('Escape');
    assert.deepEqual(errors,[]);
    console.log('kanban_help_ui: centered board, mobile overflow, SK/EN guide, chooser cancel, failed apply and confirmed template switch passed.');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
