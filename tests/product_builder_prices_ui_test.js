const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const page=await browser.newPage({viewport:{width:1600,height:1100}}),errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
 const categories=JSON.parse(fs.readFileSync('.cache/product-builder/categories.json','utf8')),category=categories[0];
 const designs=JSON.parse(fs.readFileSync('.cache/product-builder/designs.json','utf8')),versions=JSON.parse(fs.readFileSync('.cache/product-builder/graphics-versions.json','utf8'));
 const channels=['recommended','web','ebay','amazon'];let saves=0;
 let listing={id:701,name:'Existing listing with prices',sku:'SHOP-PRICE-701',bundle_type:'G',category_kind:'universal',uni_category_id:category.id,brand:category.brand,model:category.category_name,modelcode:category.modelcode,year_range:'',notes:'Existing note',request_key:'a'.repeat(32),version:1,component_count:1,components:[{component_type:'G',label:'Existing custom design',quantity:1,options:{graphics_finish:'Matte'}}]};
 for(const key of channels){listing[key+'_price']=null;listing[key+'_currency']='EUR';}
 const handler=async route=>{
  const action=new URL(route.request().url()).searchParams.get('action'),data=route.request().method()==='POST'?JSON.parse(route.request().postData()):{};let result={ok:true};
  if(action==='boxes'){await route.fulfill({json:{ok:true,rows:[]}});return;}
   else if(action==='designs')Object.assign(result,{rows:designs,versions});
  else if(action==='categories')result.rows=categories;
  else if(action==='products')Object.assign(result,{rows:[listing],stats:{total:1,specific:0,universal:1}});
  else if(action==='product')result.product=listing;
  else if(action==='save_product'){
   saves++;assert.equal(data.sku,'SHOP-PRICE-701');assert.equal(data.components[0].label,'Existing custom design');assert.equal(data.components[0].options.graphics_finish,'Matte');
   assert.equal(data.recommended_price,saves===1?'149,90':'159.90');assert.equal(data.ebay_price,'0.00');assert.equal(data.ebay_currency,'USD');assert.equal(data.amazon_price,'189.99');assert.equal(data.amazon_currency,'CAD');
   assert.equal(data.web_price,saves===3?'':'129.50');assert.equal(data.web_currency,saves===1?'GBP':'CHF');
   listing={...listing,...data,version:listing.version+1};
   for(const key of channels)listing[key+'_price']=data[key+'_price']===''?null:Number(data[key+'_price'].replace(',','.')).toFixed(2);
   result.product={id:listing.id,sku:listing.sku};
  }else throw Error('Unexpected API action: '+action);
  await route.fulfill({json:result});
 };
 await page.route('**/scripts/product_builder/api.php?*',handler);
 const url=process.env.PRODUCT_BUILDER_TEST_URL||'http://localhost/darkscrub/.cache/product-builder/preview.html';
 await page.goto(url);await page.waitForSelector('[data-product="701"]');
 assert.deepEqual(await page.locator('[data-price-cell="recommended"], .pb-channel-price').allTextContents(),['—','—','—','—'],'Legacy prices remain unset.');
 await page.locator('[data-product="701"]').click();assert.equal(await page.locator('#pb-recommended-price').inputValue(),'');
 await page.locator('#pb-save').click();assert.equal(saves,0);assert.equal(await page.locator('#pb-recommended-price').evaluate(el=>el.validity.valueMissing),true);
 for(const [key,amount,currency] of [['recommended','149,90','EUR'],['web','129.50','GBP'],['ebay','0.00','USD'],['amazon','189.99','CAD']]){await page.fill('#pb-'+key+'-price',amount);await page.selectOption('#pb-'+key+'-currency',currency);}
 const save=async count=>{await page.locator('#pb-save').click();await page.waitForFunction(()=>!document.getElementById('pb-product-form').inert);assert.equal(saves,count);await page.waitForFunction(()=>document.querySelector('[data-price-cell="recommended"]').textContent===document.getElementById('pb-recommended-price').value+' EUR');};
 await save(1);assert.deepEqual(await page.locator('[data-price-cell="recommended"], .pb-channel-price').allTextContents(),['149.90 EUR','129.50 GBP','0.00 USD','189.99 CAD']);
 await page.locator('[data-product-expand="701"]').click();await page.waitForSelector('#pb-list-components-701 .pb-component-details');assert.equal(await page.locator('#pb-list-components-701>td').getAttribute('colspan'),'12');
 await page.locator('[data-product-expand="701"]').click();
 const boxes=await page.locator('.pb-price-field').evaluateAll(nodes=>nodes.map(el=>({x:el.getBoundingClientRect().x,y:el.getBoundingClientRect().y})));assert.ok(boxes.every(b=>Math.abs(b.y-boxes[0].y)<2),'Four prices share one row on desktop.');
 assert.equal(await page.locator('.pb-save-bar #pb-pricing-panel').count(),1,'Prices belong to the SKU/save bar.');
 const controlY=await page.locator('.pb-save-bar #pb-sku, .pb-save-bar [id$="-price"], .pb-save-bar #pb-save').evaluateAll(nodes=>nodes.map(el=>el.getBoundingClientRect().bottom));assert.ok(controlY.every(y=>Math.abs(y-controlY[0])<2),'SKU, prices and Save share one desktop row.');
 await page.locator('.pb-save-bar').screenshot({path:'.cache/product-builder/prices-form.png'});
 await page.locator('#pb-products').locator('xpath=ancestor::section').screenshot({path:'.cache/product-builder/prices-list.png'});
 await page.fill('#pb-recommended-price','-1');await page.locator('#pb-save').click();assert.equal(saves,1);assert.equal(await page.locator('#pb-recommended-price').evaluate(el=>el.validity.patternMismatch),true);
 await page.fill('#pb-recommended-price','159.90');await page.selectOption('#pb-web-currency','CHF');assert.equal(await page.locator('#pb-web-price').inputValue(),'129.50','Changing currency does not convert the amount.');await save(2);
 await page.fill('#pb-web-price','');await save(3);assert.equal(await page.locator('[data-price-cell="web"] .pb-channel-price').textContent(),'—');
 await page.locator('#pb-cancel').click();for(const key of channels){assert.equal(await page.locator('#pb-'+key+'-price').inputValue(),'');assert.equal(await page.locator('#pb-'+key+'-currency').inputValue(),'EUR');}
 await page.locator('[data-product="701"]').click();assert.equal(await page.locator('#pb-web-price').inputValue(),'');assert.equal(await page.locator('#pb-web-currency').inputValue(),'CHF');assert.equal(await page.locator('#pb-ebay-price').inputValue(),'0.00');
 await page.setViewportSize({width:390,height:900});await page.locator('.pb-save-bar').screenshot({path:'.cache/product-builder/prices-mobile.png'});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'No page overflow on mobile.');
 // Render the server fixture without the edit form to exercise the existing view-only branch.
 const reader=await browser.newPage({viewport:{width:1600,height:1000}});reader.on('pageerror',e=>errors.push(e.message));await reader.route('**/scripts/product_builder/api.php?*',handler);
 const html=fs.readFileSync('.cache/product-builder/preview.html','utf8').replace(/<form id="pb-product-form">[\s\S]*?<\/form>/,'').replace('"canEdit":true','"canEdit":false');
 await reader.route('**/.cache/product-builder/preview.html',route=>route.fulfill({contentType:'text/html',body:html}));await reader.goto(url);await reader.locator('[data-product="701"]').click();await reader.waitForSelector('#pb-detail-modal.show');
 assert.deepEqual(await reader.locator('#pb-detail .pb-price-overview-amount').allTextContents(),['159.90 EUR','—','0.00 USD','189.99 CAD']);
 assert.deepEqual(errors,[]);console.log('Price UI: legacy values, required price, per-channel currencies, save/reopen/clear, zero, expansion, responsive layout and view-only detail OK.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
