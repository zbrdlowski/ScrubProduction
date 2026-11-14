const { chromium } = require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 const page=await browser.newPage({viewport:{width:1440,height:1000}});
 let level=0;
 const errors=[];
 page.on('pageerror',e=>errors.push(e.stack||e.message));
 await page.route('**/index.php?*',route=>{
  const url=new URL(route.request().url());
  return route.fulfill({contentType:'text/html',body:fs.readFileSync('.cache/kanban/shell-'+level+'-'+(url.searchParams.get('kb_lang')||'sk')+'.html','utf8')});
 });
 await page.route('**/scripts/kanban/api.php*',route=>route.fulfill({json:{ok:true,board:{id:1,done_limit:20,version:1},columns:[{id:1,title:'Osobné úlohy',color:'#60a5fa',is_done:0,total:0,has_more:false,cards:[]}]}}));
 const idle=()=>page.waitForFunction(()=>document.getElementById('kb-board')?.getAttribute('aria-busy')==='false'&&!document.getElementById('kb-refresh').disabled);
 try {
  for(level of [0,100,300,500,900]){
   await page.goto('http://localhost/darkscrub/index.php?page=kanban&kb_lang=sk');await idle();
   assert.equal(await page.locator('#kb-app').count(),1);
   assert.equal(await page.locator('.main-header a[href="index.php?page=kanban"]').count(),1);
   assert.equal(await page.locator('.main-sidebar a[href$="?page=kanban"]').count(),1);
   assert.match(await page.locator('link[href^="assets/css/kanban.css"]').getAttribute('href'), /[?]v=[a-f0-9]{12}$/);
   assert.match(await page.locator('script[src^="scripts/kanban/kanban.js"]').getAttribute('src'), /[?]v=[a-f0-9]{12}$/);
   if(level===0) assert.equal(await page.locator('.main-header a[href="index.php?page=product_builder"]').count(),0,'Restricted modules stay restricted');
   await page.screenshot({path:'.cache/kanban/access-'+level+'.png',fullPage:true});
   await page.locator('#kb-guide-open').click({timeout:5000});
   assert(await page.locator('#kb-guide-dialog').isVisible());
   await page.keyboard.press('Escape');
   await page.locator('[data-kb-language=en]').click({timeout:5000});await idle();
   assert.equal(await page.locator('#kb-title').innerText(),'My kanban');
   await page.locator('#kb-guide-open').click({timeout:5000});
   assert.equal(await page.locator('#kb-guide-title').innerText(),'Kanban guide');
   await page.keyboard.press('Escape');
  }
  // Also verify navigation and the guide on a restricted account at mobile width.
  level=0;
  await page.setViewportSize({width:390,height:844});
  await page.goto('http://localhost/darkscrub/index.php?page=kanban&kb_lang=sk');await idle();
  await page.locator('#kb-guide-open').click();
  assert(await page.locator('#kb-guide-dialog').isVisible());
  await page.keyboard.press('Escape');
  await page.locator('[data-kb-language=en]').click();await idle();
  assert.equal(await page.locator('#kb-title').innerText(),'My kanban');
  assert.deepEqual(errors.filter(error=>/scripts\/kanban\//.test(error)),[],'No Kanban JavaScript errors');
  console.log('kanban_access_ui: real application shell, Help and SK/EN for levels 0/100/300/500/900, restricted module links, asset versions and mobile passed.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
