const {chromium}=require('playwright');
const fs=require('fs');
const assert=require('assert/strict');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const page=await browser.newPage({viewport:{width:1440,height:1100}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  page.on('dialog',dialog=>dialog.accept());
  const categories=JSON.parse(fs.readFileSync('.cache/product-builder/categories.json','utf8'));
  const category=categories.find(c=>c.brand==='KTM'&&c.category_name==='Default');
  let listing={id:91,name:'Existing plastics listing',sku:'OLD-SHOP-001',bundle_type:'P',category_kind:'universal',uni_category_id:category.id,brand:category.brand,model:category.category_name,modelcode:category.modelcode,year_range:'',notes:'Keep this listing note',request_key:'a'.repeat(32),version:1,component_count:5,components:[
   {component_type:'P',reference:'KT00543',label:'Kit + Airbox + Forks',color:'',quantity:1,notes:'Orange',options:{plastics_retired_note:'Historical line 1\nHistorical line 2'}},
   {component_type:'P',reference:'KT00295',label:'Front plate',color:'',quantity:2,notes:'Orange'},
   {component_type:'P',reference:'KT00233',label:'Front fender',color:'Custom saved orange',quantity:1,notes:'Orange'},
   {component_type:'P',reference:'KT00671',label:'Adapter for front fender',color:'',quantity:1,notes:'Black'},
   {component_type:'P',reference:'MISSING',label:'Legacy custom component',color:'',quantity:1,notes:'Preserve manual details'}
  ]};
  const original=structuredClone(listing);const lookups=[];let saves=0,hold=false,releaseLookup;
  await page.route('**/scripts/product_builder/api.php?*',async route=>{
   const url=new URL(route.request().url()),action=url.searchParams.get('action');let result={ok:true};
   if(action==='boxes'){await route.fulfill({json:{ok:true,rows:[]}});return;}
   else if(action==='designs'){result.rows=JSON.parse(fs.readFileSync('.cache/product-builder/designs.json','utf8'));result.versions=JSON.parse(fs.readFileSync('.cache/product-builder/graphics-versions.json','utf8'));}
   else if(action==='categories')result.rows=categories;
   else if(action==='products')Object.assign(result,{rows:[listing],stats:{total:1,specific:0,universal:1}});
   else if(action==='product')result.product=structuredClone(listing);
   else if(action==='plastic_item'){
    const code=url.searchParams.get('barcode');lookups.push(code);
    if(hold&&code==='KT00543'){hold=false;await new Promise(resolve=>{releaseLookup=resolve;});}
    result.item=code==='MISSING'?null:{barcode:code,label:'Stock description must not replace custom name',color:code==='KT00543'||code==='KT00671'?'BLACK':'ORANGE'};
   } else if(action==='save_product'){
    const data=JSON.parse(route.request().postData());saves++;
    assert.equal(data.sku,original.sku);assert.equal(data.notes,original.notes);
    assert.equal(data.components[0].options.plastics_retired_note,original.components[0].options.plastics_retired_note,'Retired multiline settings survive editing.');
    for(let i=0;i<data.components.length;i++){
     assert.equal(data.components[i].label,original.components[i].label,'Preserve stored component names.');
     assert.equal(data.components[i].notes,original.components[i].notes,'Preserve legacy notes.');
    }
    listing={...listing,...data,version:listing.version+1};result.product={id:91,sku:listing.sku};
   } else throw new Error('Unexpected API action '+action);
   await route.fulfill({json:result});
  });
  await page.goto(process.env.PRODUCT_BUILDER_TEST_URL||'http://localhost/darkscrub/.cache/product-builder/preview.html');
  await page.locator('[data-product="91"]').click();
  await page.waitForFunction(()=>document.querySelectorAll('[data-component-row="P"]').length===5&&!document.querySelector('[data-lookup-pending="1"]'));
  const rows=page.locator('[data-component-row="P"]');
  assert.deepEqual(await rows.locator('[data-field="color"]').evaluateAll(inputs=>inputs.map(i=>i.value)),['BLACK','ORANGE','Custom saved orange','BLACK','']);
  assert.deepEqual(await rows.locator('[data-field="label"]').evaluateAll(inputs=>inputs.map(i=>i.value)),original.components.map(c=>c.label));
  assert.deepEqual(await rows.locator('[data-field="notes"]').evaluateAll(inputs=>inputs.map(i=>i.value)),original.components.map(c=>c.notes));
  assert.equal(lookups.includes('KT00233'),false,'Saved nonempty colors do not trigger a lookup.');
  assert.equal(saves,0,'Opening edit mode must never save automatically.');
  assert.equal(listing.components[0].color,'','Stored color stays unchanged until Save.');
  assert.match(await rows.nth(4).locator('[data-lookup-status]').textContent(),/not found/);
  await page.fill('#pb-recommended-price','149.90');await page.click('#pb-save');
  await page.waitForFunction(()=>document.getElementById('pb-message').textContent==='Product saved. SKU: OLD-SHOP-001');
  await page.waitForFunction(()=>!document.querySelector('[data-lookup-pending="1"]'));
  assert.equal(saves,1);assert.equal(listing.components[0].color,'BLACK');
  assert.equal(lookups.filter(code=>code==='KT00543').length,1,'Saved color is restored without another stock lookup.');
  // A color typed while the legacy lookup is in flight must win over the response.
  listing.components[0].color='';hold=true;
  const requested=page.waitForRequest(r=>r.url().includes('barcode=KT00543'));
  await page.locator('[data-product="91"]').click();await requested;
  await rows.first().locator('[data-field="color"]').fill('Manual blue');
  const response=page.waitForResponse(r=>r.url().includes('barcode=KT00543'));releaseLookup();await response;
  await page.waitForFunction(()=>!document.querySelector('[data-lookup-pending="1"]'));
  assert.equal(await rows.first().locator('[data-field="color"]').inputValue(),'Manual blue');
  assert.equal(await rows.first().locator('[data-field="label"]').inputValue(),'Kit + Airbox + Forks');
  // A bundle rerender must resume in color-only mode, preserving the legacy title.
  hold=true;
  const rerenderRequest=page.waitForRequest(r=>r.url().includes('barcode=KT00543'));
  await page.locator('[data-product="91"]').click();await rerenderRequest;
  await page.selectOption('#pb-bundle','GP');
  await page.waitForFunction(()=>document.querySelector('[data-component-row="P"] [data-field="color"]').value==='BLACK');
  releaseLookup();
  await page.selectOption('#pb-bundle','P');
  assert.equal(await rows.first().locator('[data-field="label"]').inputValue(),'Kit + Airbox + Forks','Resuming a lookup must preserve color-only behavior.');
  assert.equal(await rows.first().locator('[data-field="notes"]').inputValue(),'Orange');
  await page.screenshot({path:'.cache/product-builder/legacy-colors.png',fullPage:true});
  assert.deepEqual(errors,[]);
  console.log('Legacy listing colors: OK (load missing colors, retain stored colors/names/notes, explicit save, manual edit race and bundle switching).');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
