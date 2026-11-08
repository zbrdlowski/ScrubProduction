const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const errors=[];let saved=0;
 let listing=null;
 const categories=[{id:11,brand:'KTM',category_name:'Default',included_models:'All KTM models',modelcode:'AB23',version:1,usage_count:0,price_unlicensed:null,price_licensed:null}];
 const designs=[{id:901,component_type:'G',name:'Anomally',version:1,usage_count:0},{id:902,component_type:'S',name:'Seat One',version:1,usage_count:0}];
 const versions=[{id:903,name:'R1',sort_order:1,version:1,usage_count:0}];
 const page=await browser.newPage({viewport:{width:1500,height:1080}});page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
 await page.route('**/scripts/product_builder/api.php?*',async route=>{
  const req=route.request(),url=new URL(req.url()),action=url.searchParams.get('action'),data=req.method()==='POST'?JSON.parse(req.postData()):{};let body={ok:true};
  if(action==='categories')body.rows=categories;
  else if(action==='designs')Object.assign(body,{rows:designs,versions});
  else if(action==='boxes')body.rows=[];
  else if(action==='products')Object.assign(body,{rows:listing?[listing]:[],stats:{total:listing?1:0,specific:0,universal:listing?1:0}});
  else if(action==='plastic_item')body.item={barcode:url.searchParams.get('barcode'),label:'Kit',color:'OEM'};
  else if(action==='save_product'){
   assert.equal(data.bundle_type,'TFP');assert.deepEqual(data.components.map(c=>c.component_type),['G','F','P']);
   const [g,f,p]=data.components;assert.equal(g.design_id,'901');assert.equal(g.graphics_version_id,'903');assert.equal(g.options.graphics_finish,'Matte');assert.equal(g.options.graphics_material,'Standard');assert.equal(f.options.fitting,'jm-1');assert.equal(p.reference,'HB00046');assert.equal(p.color,'OEM');assert.equal(g.notes,'Keep production note');
   saved++;listing={...data,id:801,version:Number(data.version)+1,sku:data.sku||'TFP_ABC1234567890ABC12345',created_at:'2026-11-06 08:00:00',component_count:3};listing.components=listing.components.map(c=>({...c,design_name:c.design_id?'Anomally':null,graphics_version_name:c.graphics_version_id?'R1':null}));body.product={id:801,sku:listing.sku};
  }else if(action==='product')body.product=listing;
  else throw Error('Unexpected action '+action);
  await route.fulfill({json:body});
 });
 await page.goto('http://localhost/darkscrub/.cache/product-builder/i18n-builder-sk.html');
 await page.selectOption('#pb-bundle','TFP');await page.waitForFunction(()=>document.querySelectorAll('[data-component-row="G"] [data-field="design_id"] option').length===2);
 assert.equal(await page.locator('#pb-bundle option:checked').textContent(),'TFP · Trim Kit (Grafika + Montáž + Plasty)');
 assert.deepEqual(await page.locator('[data-component-group]').evaluateAll(nodes=>nodes.map(n=>n.dataset.componentGroup)),['G','F','P']);
 assert.equal(await page.locator('[data-component-row="S"],[data-component-row="T"]').count(),0);
 const g=page.locator('[data-component-row="G"]');assert.equal(await g.locator('.pb-spec-bar').count(),1);
 await g.locator('[data-field="design_id"]').selectOption('901');await g.locator('[data-field="graphics_version_id"]').selectOption('903');assert.equal(await g.locator('[data-field="label"]').inputValue(),'Anomally R1');await g.locator('[data-field="label"]').fill('Anomally R1 OEM');
 await g.locator('[data-spec="graphics_material"]').selectOption('Standard');await g.locator('[data-spec="graphics_finish"]').selectOption('Matte');await g.locator('[data-field="notes"]').fill('Keep production note');await page.selectOption('[data-spec="fitting"]','jm-1');
 await page.locator('[data-component-row="P"] [data-field="reference"]').fill('HB00046');await page.waitForFunction(()=>document.querySelector('[data-component-row="P"] [data-field="color"]').value==='OEM');
 for(const bundle of ['TFPS','GFP','TFP','GT','TFP']){
  await page.selectOption('#pb-bundle',bundle);
  assert.equal(await page.locator('[data-component-row="G"] [data-field="label"]').inputValue(),'Anomally R1 OEM');assert.equal(await page.locator('[data-spec="graphics_finish"]').inputValue(),'Matte');
  assert.equal(await page.locator('[data-component-row="G"]').count(),1);
  assert.equal(await page.locator('[data-component-row="S"]').count(),bundle==='TFPS'?1:0);
 }
 await page.fill('#pb-name','TFP OEM style kit');await page.fill('#pb-recommended-price','199.90');await page.locator('[name="category_kind"][value="universal"]').check();await page.selectOption('#pb-uni-brand','KTM');await page.selectOption('#pb-uni-category','11');
 await page.locator('#pb-save').click();await page.waitForFunction(()=>document.getElementById('pb-message').textContent.includes('Produkt bol uložený.')&&!document.getElementById('pb-product-form').inert);assert.equal(saved,1);assert.match(await page.locator('#pb-sku').inputValue(),/^TFP_/);
 await page.locator('[data-product-expand="801"]').click();await page.locator('.pb-component-details').waitFor();assert.deepEqual(await page.locator('.pb-component-details .pb-type').allTextContents(),['G','F','P']);assert.match(await page.locator('.pb-component-details').textContent(),/Dizajn: Anomally/);assert.match(await page.locator('.pb-component-details').textContent(),/Verzia: R1/);
 // Simulate a legacy API response: old T rows must render in G without losing any entered data.
 listing.components[0].component_type='T';listing.components[0].label='Original trim text';
 await page.locator('#pb-new').click();await page.locator('[data-product="801"]').click();await page.waitForFunction(()=>document.querySelector('[data-component-row="G"] [data-field="label"]')?.value==='Original trim text');
 assert.equal(await page.locator('[data-component-row="T"],[data-component-row="S"]').count(),0);assert.equal(await page.locator('[data-component-row="G"] [data-field="notes"]').inputValue(),'Keep production note');assert.equal(await page.locator('[data-component-row="G"] [data-field="design_id"]').inputValue(),'901');
 await page.locator('#pb-save').click();await page.waitForFunction(()=>document.getElementById('pb-message').textContent.includes('Produkt bol uložený.')&&!document.getElementById('pb-product-form').inert);assert.equal(saved,2);assert.equal(listing.components[0].component_type,'G');assert.equal(listing.components[0].label,'Original trim text');
 await page.locator('#pb-type-panel').scrollIntoViewIfNeeded();await page.screenshot({path:'.cache/product-builder/trim-kit-sk.png'});
 await page.setViewportSize({width:390,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.goto('http://localhost/darkscrub/.cache/product-builder/i18n-listings-sk.html');await page.locator('[data-product-expand="801"]').click();await page.locator('.pb-component-details').waitFor();assert.deepEqual(await page.locator('.pb-component-details .pb-type').allTextContents(),['G','F','P']);
 await page.goto('http://localhost/darkscrub/.cache/product-builder/i18n-builder-en.html');await page.selectOption('#pb-bundle','TFP');assert.equal(await page.locator('#pb-bundle option:checked').textContent(),'TFP · Trim Kit (Graphics + Fitting + Plastics)');assert.deepEqual(await page.locator('[data-component-group]').evaluateAll(nodes=>nodes.map(n=>n.dataset.componentGroup)),['G','F','P']);
 assert.deepEqual(errors,[]);console.log('Trim Kit UI: TFP G/F/P, TFPS seat cover, graphics design/version/options, barcode, draft preservation, save/reopen, legacy T, Listings and SK/EN/mobile OK.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
