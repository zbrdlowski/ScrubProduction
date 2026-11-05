const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const page=await browser.newPage({viewport:{width:1600,height:1100}}),errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
 const designs=JSON.parse(fs.readFileSync('.cache/product-builder/designs.json','utf8'));
 const design=designs.find(d=>d.component_type==='G');design.name='Anomally';
 const categories=JSON.parse(fs.readFileSync('.cache/product-builder/categories.json','utf8'));
 let versions=JSON.parse(fs.readFileSync('.cache/product-builder/graphics-versions.json','utf8')),listing=null,saves=0;
 const r1=versions.find(v=>v.name==='R1'),r2=versions.find(v=>v.name==='R2'),r10=versions.find(v=>v.name==='R10');
 await page.route('**/scripts/product_builder/api.php?*',async route=>{
  const action=new URL(route.request().url()).searchParams.get('action'),data=route.request().method()==='POST'?JSON.parse(route.request().postData()):{};let result={ok:true};
  if(action==='boxes'){await route.fulfill({json:{ok:true,rows:[]}});return;}
   else if(action==='designs')Object.assign(result,{rows:designs,versions:versions.slice().sort((a,b)=>Number(a.sort_order)-Number(b.sort_order)||a.name.localeCompare(b.name))});
  else if(action==='categories')result.rows=categories;
  else if(action==='products')Object.assign(result,{rows:listing?[listing]:[],stats:{total:listing?1:0,specific:0,universal:listing?1:0}});
  else if(action==='save_graphics_version'){
   if(versions.some(v=>v.name.toLowerCase()===data.name.toLowerCase()&&String(v.id)!==String(data.id))){await route.fulfill({status:422,json:{ok:false,error:'A Graphics version with this name already exists.'}});return;}
   const id=Number(data.id)||999,previous=versions.find(v=>Number(v.id)===id);
   versions=versions.filter(v=>Number(v.id)!==id).concat({...data,id,version:Number(data.version||0)+1,usage_count:previous?.usage_count||0});result.id=id;
  }else if(action==='delete_graphics_version')versions=versions.filter(v=>String(v.id)!==data.id);
  else if(action==='save_product'){
   saves++;assert.equal(data.components.length,3);assert.equal(data.components[0].graphics_version_id,String(r1.id));assert.equal(data.components[1].graphics_version_id,String(r10.id));assert.ok(!data.components[2].graphics_version_id);
   assert.equal(data.components[0].label,saves===1?'Anomally R1 - team':'Anomally R1 - team edited');assert.equal(data.components[1].label,'Anomally R10');
   assert.equal(data.components[0].options.graphics_finish,'Matte');
   listing={...data,id:501,sku:data.sku||data.bundle_type+'_VERSIONTEST',version:Number(data.version||0)+1,component_count:3};
   listing.components=listing.components.map(c=>{const clean={...c,design_name:designs.find(d=>String(d.id)===c.design_id)?.name,graphics_version_name:versions.find(v=>String(v.id)===c.graphics_version_id)?.name};delete clean.generated_name;return clean;});
   versions.forEach(v=>{v.usage_count=listing.components.some(c=>String(v.id)===c.graphics_version_id)?1:0;});result.product={id:501,sku:listing.sku};
  }else if(action==='product')result.product=listing;
  else throw new Error('Unexpected action '+action);
  await route.fulfill({json:result});
 });
 await page.goto(process.env.PRODUCT_BUILDER_TEST_URL||'http://localhost/darkscrub/.cache/product-builder/preview.html');await page.selectOption('#pb-bundle','GS');
 const graphics=page.locator('[data-component-row="G"]'),first=graphics.first(),seat=page.locator('[data-component-row="S"]');
 await page.waitForFunction(()=>document.querySelectorAll('[data-field="graphics_version_id"] option').length===13);
 assert.deepEqual(await first.locator('[data-field="graphics_version_id"] option').allTextContents(),['Select…','ST','LE','R1','R2','R3','R4','R5','R6','R7','R8','R9','R10']);
 assert.equal(await seat.locator('[data-field="graphics_version_id"]').count(),0);
 await first.locator('.select2-selection').click();await page.locator('.select2-container--open .select2-search__field').fill('Anomally');await page.getByRole('option',{name:'Anomally',exact:true}).click();
 await first.locator('[data-field="graphics_version_id"]').selectOption(String(r1.id));assert.equal(await first.locator('[data-field="label"]').inputValue(),'Anomally R1');
 await first.locator('[data-field="label"]').fill('Anomally R1 - team');await first.locator('[data-field="graphics_version_id"]').selectOption(String(r2.id));assert.equal(await first.locator('[data-field="label"]').inputValue(),'Anomally R2 - team');
 await first.locator('[data-field="graphics_version_id"]').selectOption('');assert.equal(await first.locator('[data-field="label"]').inputValue(),'Anomally - team');
 await first.locator('[data-field="graphics_version_id"]').selectOption(String(r1.id));await first.locator('[data-spec="graphics_finish"]').selectOption('Matte');
 await page.locator('[data-add-component="G"]').click();const second=graphics.nth(1);
 await second.locator('[data-field="graphics_version_id"]').selectOption(String(r10.id));
 await second.locator('.select2-selection').click();await page.locator('.select2-container--open .select2-search__field').fill('Anomally');await page.getByRole('option',{name:'Anomally',exact:true}).click();
 assert.equal(await second.locator('[data-field="label"]').inputValue(),'Anomally R10');
 await page.selectOption('#pb-bundle','S');await page.selectOption('#pb-bundle','GS');assert.equal(await first.locator('[data-field="label"]').inputValue(),'Anomally R1 - team');assert.equal(await second.locator('[data-field="graphics_version_id"]').inputValue(),String(r10.id));
 await page.locator('[data-design-manager="V"]').click();await page.waitForFunction(()=>document.getElementById('pb-design-count').textContent==='12 of 12 versions');
 assert.equal(await page.locator('#pb-design-title').textContent(),'Graphics versions');assert.equal(await page.locator('#pb-version-order').isVisible(),true);
 await page.locator('#pb-design-name').fill('ST');await page.locator('#pb-design-save').click();await page.waitForFunction(()=>document.getElementById('pb-design-message').textContent.includes('already exists'));
 await page.locator('#pb-design-name').fill('R11');await page.locator('#pb-version-order').fill('13');await page.locator('#pb-design-save').click();await page.waitForFunction(()=>document.getElementById('pb-design-message').textContent==='Version saved.');
 assert.equal(await first.locator('[data-field="graphics_version_id"] option').count(),14);assert.equal(await first.locator('[data-field="label"]').inputValue(),'Anomally R1 - team');
 await page.locator('#pb-design-name').fill('SPECIAL');await page.locator('#pb-version-order').fill('0');await page.locator('#pb-design-save').click();await page.waitForFunction(()=>document.querySelector('[data-field="graphics_version_id"] option:nth-child(2)').textContent==='SPECIAL');
 await page.locator('#pb-design-delete').click();await page.waitForFunction(()=>document.getElementById('pb-design-message').textContent==='Version deleted.');
 const close=async()=>{await page.locator('#pb-design-modal .close').click();await page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));};await close();
 await page.locator('#pb-name').fill('Versions test');await page.fill('#pb-recommended-price','149.90');await seat.locator('[data-field="label"]').fill('Existing seat name');
 await page.locator('[name="category_kind"][value="universal"]').check();await page.selectOption('#pb-uni-brand',categories[0].brand);await page.selectOption('#pb-uni-category',String(categories[0].id));
 await page.locator('#pb-save').click();await page.waitForFunction(()=>document.getElementById('pb-message').textContent.includes('Product saved.'));
 await page.locator('#pb-cancel').click();await page.locator('[data-product="501"]').click();
 assert.equal(await first.locator('[data-field="graphics_version_id"]').inputValue(),String(r1.id));assert.equal(await first.locator('[data-field="label"]').inputValue(),'Anomally R1 - team');
 await first.locator('[data-field="label"]').fill('Anomally R1 - team edited');await page.locator('#pb-save').click();await page.waitForFunction(()=>!document.getElementById('pb-product-form').inert);assert.equal(saves,2);
 await page.locator('[data-product-expand="501"]').click();await page.waitForFunction(()=>document.querySelector('#pb-list-components-501')?.dataset.loaded==='1');assert.ok((await page.locator('#pb-list-components-501').textContent()).includes('Version: R1'));
 await page.locator('[data-design-manager="V"]').click();await page.locator('[data-design-edit="'+r1.id+'"]').click();assert.equal(await page.locator('#pb-design-delete').isDisabled(),true);
 await page.locator('#pb-design-name').fill('R-one');await page.locator('#pb-design-save').click();await page.waitForFunction(()=>document.querySelector('[data-field="graphics_version_id"] option:checked').textContent==='R-one');
 assert.equal(await first.locator('[data-field="label"]').inputValue(),'Anomally R1 - team edited');
 await page.locator('#pb-design-modal').screenshot({path:'.cache/product-builder/graphics-versions-editor.png'});await close();
 await page.locator('#pb-components').screenshot({path:'.cache/product-builder/graphics-versions-components.png'});
 await page.setViewportSize({width:390,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.locator('[data-design-manager="V"]').click();await page.waitForTimeout(350);assert.equal(await page.locator('#pb-design-modal').evaluate(modal=>modal.scrollWidth<=innerWidth),true);await close();
 assert.deepEqual(errors,[]);console.log('Graphics version UI: default order, G-only placement, generated names, preserved suffix, independent rows, bundle switching, editable library, save/reopen and mobile OK.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
