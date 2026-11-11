const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const page=await browser.newPage({viewport:{width:1800,height:1050}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const categories=JSON.parse(fs.readFileSync('.cache/product-builder/categories.json','utf8'));
 const stockRows=[
  {component_type:'P',label:'Kit + Airbox + Forks',reference:'000042',color:'ORANGE',quantity:2,inventory_supplier:'Supplier <img src=x onerror=alert(1)>',inventory_quantity:12,inventory_status:'matched',options:{}},
  {component_type:'P',label:'Front plate',reference:'ZERO',color:'ORANGE',quantity:1,inventory_supplier:'Supplier B',inventory_quantity:0,inventory_status:'matched'},
  {component_type:'P',label:'Unknown stock',reference:'UNKNOWN',quantity:1,inventory_supplier:null,inventory_quantity:null,inventory_status:'matched'},
  {component_type:'P',label:'Missing barcode',reference:'MISSING',quantity:1,inventory_supplier:null,inventory_quantity:null,inventory_status:'not_found'},
  {component_type:'P',label:'Duplicate barcode',reference:'DUP',quantity:1,inventory_supplier:null,inventory_quantity:null,inventory_status:'ambiguous'},
  {component_type:'G',label:'Graphics',reference:'000042',quantity:1},
  {component_type:'S',label:'Seat cover',reference:'SC-01',quantity:1},
  {component_type:'F',label:'Fitting',reference:'',quantity:1}
 ];
 const listing={id:801,name:'Orange Restyle KTM Plastics kit',sku:'P_690175',bundle_type:'GFPS',category_kind:'universal',uni_category_id:categories[0].id,brand:'KTM',model:'SX 2011–2012',modelcode:'X6XZ',recommended_price:'199.90',recommended_currency:'EUR',components:stockRows,component_count:stockRows.length};
 let reads=0,failNext=false;
 const handler=async route=>{const action=new URL(route.request().url()).searchParams.get('action');let body={ok:true};
  if(action==='categories')body.rows=categories;
  else if(action==='designs')Object.assign(body,{rows:[],versions:[]});
  else if(action==='boxes')body.rows=[];
  else if(action==='products')Object.assign(body,{rows:[listing,{...listing,id:802,bundle_type:'G',component_count:1},{...listing,id:803,bundle_type:'S',component_count:1},{...listing,id:804,bundle_type:'F',component_count:1}],stats:{total:4,specific:0,universal:4}});
  else if(action==='product'){
   reads++;if(failNext){failNext=false;await route.fulfill({status:500,json:{ok:false,error:'Unable to load the data.'}});return;}
   const id=new URL(route.request().url()).searchParams.get('id');body.product={...listing,components:id==='802'?[stockRows[5]]:id==='803'?[stockRows[6]]:id==='804'?[stockRows[7]]:stockRows};
  }else throw Error('Unexpected action '+action);
  await route.fulfill({json:body});
 };
 const url=process.env.PRODUCT_BUILDER_TEST_URL||'http://localhost/darkscrub/.cache/product-builder/preview.html';
 await page.route('**/scripts/product_builder/api.php?*',handler);await page.goto(url);
 const toggle=page.locator('[data-product-expand="801"]'),detail=page.locator('#pb-list-components-801');
 await toggle.click();await detail.locator('[data-inventory-quantity]').first().waitFor();
 assert.deepEqual(await detail.locator('.pb-plastics-table th').allTextContents(),['Type','Component name / details','Product code / reference','Color','Quantity','Preferred supplier','Stock quantity']);
 assert.equal(await detail.locator('.pb-plastics-table tbody tr').count(),5);assert.equal(await detail.locator('[data-detail-component]').count(),8);assert.equal(await detail.locator('.pb-plastics-table tbody tr').first().locator('td').nth(4).textContent(),'2');
 assert.equal(await detail.locator('[data-inventory-supplier]').first().textContent(),stockRows[0].inventory_supplier);
 assert.equal(await detail.locator('img').count(),0,'Supplier text must be escaped.');
 assert.deepEqual(await detail.locator('[data-inventory-quantity]').allTextContents(),['12','0','—','—','—']);
 assert.match(await detail.locator('[data-inventory-quantity]').nth(3).getAttribute('title'),/not found/);
 assert.match(await detail.locator('[data-inventory-quantity]').nth(4).getAttribute('title'),/Multiple inventory/);
 for(const index of [5,6,7]){const row=detail.locator('[data-detail-component]').nth(index);assert.equal(await row.locator('[data-inventory-supplier]').textContent(),'Scrub');assert.equal(await row.locator('[data-inventory-quantity]').count(),0,'Manufactured components do not invent stock quantities.');}
 await page.locator('#pb-products').locator('xpath=ancestor::section').screenshot({path:'.cache/product-builder/component-inventory-desktop.png'});
 await toggle.click();stockRows[0].inventory_quantity=7;stockRows[0].inventory_supplier='Updated supplier';await toggle.click();
 await page.waitForFunction(()=>document.querySelector('#pb-list-components-801 [data-inventory-quantity]').textContent==='7');assert.equal(reads,2,'Reopening reloads current inventory.');
 assert.equal(await detail.locator('[data-inventory-supplier]').first().textContent(),'Updated supplier');
 await toggle.click();failNext=true;await toggle.click();await page.waitForFunction(()=>document.querySelector('#pb-list-components-801').textContent.includes('Close and reopen to retry.'));
 await toggle.click();await toggle.click();await detail.locator('[data-inventory-quantity]').first().waitFor();assert.equal(reads,4);
 for(const id of [802,803,804]){
  await page.keyboard.press('Escape');
  await page.locator('[data-product-expand="'+id+'"]').click();const component=page.locator('#pb-list-components-'+id);await component.locator('.pb-component-details').waitFor();
  assert.equal(await component.locator('.pb-detail-card').count(),1,'Manufactured components use their own parameter cards.');assert.equal(await component.locator('table').count(),1);
  assert.equal(await component.locator('[data-detail-field="supplier"] dt').textContent(),'Preferred supplier');assert.equal(await component.locator('[data-inventory-supplier]').textContent(),'Scrub');
 }
 await page.setViewportSize({width:390,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Table remains within its horizontal scroll container.');
 const reader=await browser.newPage({viewport:{width:1440,height:1000}});reader.on('pageerror',e=>errors.push(e.message));await reader.route('**/scripts/product_builder/api.php?*',handler);
 const html=fs.readFileSync('.cache/product-builder/preview.html','utf8').replace(/<form id="pb-product-form">[\s\S]*?<\/form>/,'').replace('"canEdit":true','"canEdit":false');await reader.route('**/.cache/product-builder/preview.html',r=>r.fulfill({contentType:'text/html',body:html}));await reader.goto(url);
 await reader.locator('[data-product="801"]').click();await reader.locator('#pb-detail-modal.show').waitFor();assert.equal(await reader.locator('#pb-detail [data-inventory-quantity]').first().textContent(),'7');assert.equal(await reader.locator('#pb-detail [data-inventory-supplier]').first().textContent(),'Updated supplier');
 assert.deepEqual(await reader.locator('#pb-detail [data-inventory-supplier]').allTextContents(),['Updated supplier','Supplier B','—','—','—','Scrub','Scrub','Scrub']);
 await reader.locator('#pb-detail-modal .close').click();await reader.locator('#pb-detail-modal').waitFor({state:'hidden'});
 for(const id of [802,803,804]){
  await reader.locator('[data-product="'+id+'"]').click();await reader.locator('#pb-detail-modal.show').waitFor();assert.equal(await reader.locator('#pb-detail [data-inventory-supplier]').textContent(),'Scrub');
  assert.equal(await reader.locator('#pb-detail .pb-detail-card').count(),1);assert.equal(await reader.locator('#pb-detail table').count(),1);await reader.locator('#pb-detail-modal .close').click();await reader.locator('#pb-detail-modal').waitFor({state:'hidden'});
 }
 assert.deepEqual(errors,[]);console.log('Inventory UI: supplier/stock columns, zero vs unknown, escaped text, separate component quantities, refresh/retry, Scrub for graphics, fitting and seat covers (mixed/standalone), mobile and read-only detail OK.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
