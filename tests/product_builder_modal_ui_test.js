const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const page=await browser.newPage({viewport:{width:1440,height:1100}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/scripts/product_builder/api.php?*',async route=>{
  const url=new URL(route.request().url()),action=url.searchParams.get('action');let data={ok:true};
  if(action==='boxes')data.rows=[];
  else if(action==='models')data.rows=url.searchParams.get('level')==='brands'?[{brand:'KTM'}]:[];
  else{const file=action==='designs'?'designs':action==='categories'?'categories':'overview';const content=JSON.parse(fs.readFileSync('.cache/product-builder/'+file+'.json','utf8'));Object.assign(data,file==='overview'?content:{rows:content});}
  if(action==='designs')data.versions=JSON.parse(fs.readFileSync('.cache/product-builder/graphics-versions.json','utf8'));
  await route.fulfill({json:data});
 });
 await page.goto(process.env.PRODUCT_BUILDER_TEST_URL||'http://localhost/darkscrub/.cache/product-builder/preview.html');
 const versions=await page.evaluate(()=>({plugin:$.fn.modal.Constructor.VERSION,global:bootstrap.Modal.VERSION}));
 assert.equal(versions.plugin,'5.1.0');assert.equal(versions.global,'4.6.1');
 const verifyClosed=async id=>{await page.locator(id).waitFor({state:'hidden'});await page.waitForFunction(()=>!document.querySelector('.modal-backdrop')&&!document.body.classList.contains('modal-open'));assert.equal(await page.locator('.modal-backdrop').count(),0);assert.equal(await page.locator('body').evaluate(body=>body.classList.contains('modal-open')),false);};
 for(const [trigger,id] of [['[data-design-manager="G"]','#pb-design-modal'],['[data-design-manager="S"]','#pb-design-modal'],['[data-design-manager="V"]','#pb-design-modal'],['[data-target="#pb-uni-modal"]','#pb-uni-modal'],['[data-target="#pb-box-modal"]','#pb-box-modal'],['[data-target="#pb-model-modal"]','#pb-model-modal']]){
  for(let i=0;i<2;i++){
   await page.evaluate(id=>{window.pbModalShown=false;$(id).one('shown.bs.modal',()=>{window.pbModalShown=true;});},id);
   await page.locator(trigger).click();await page.waitForFunction(()=>window.pbModalShown);
   assert.equal(await page.locator('.modal-backdrop').count(),1,'One backdrop per opening');
   await page.locator(id+' .close').click();await verifyClosed(id);
   assert.equal(await page.locator(trigger).evaluate(button=>button===document.activeElement),true,'Focus returns to the opener');
  }
 }
 await page.evaluate(()=>{window.pbModalShown=false;$('#pb-model-modal').one('shown.bs.modal',()=>{window.pbModalShown=true;});});
 await page.locator('[data-target="#pb-model-modal"]').click();await page.waitForFunction(()=>window.pbModalShown);
 await page.locator('#pb-model-modal .modal-footer [data-dismiss="modal"]').click();await verifyClosed('#pb-model-modal');
 assert.deepEqual(errors,[]);
 console.log('Modal regression: Bootstrap 5/4 coexistence, X in Graphics/Seat Covers/UNI/Boxes/category, repeat opening, Cancel, focus and backdrop cleanup OK.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
