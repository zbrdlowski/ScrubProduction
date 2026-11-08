const {chromium}=require('playwright'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const errors=[];let saves=0,sequence=0,release=null,nextMode='normal';
 let listing={id:801,version:1,request_key:'a'.repeat(32),name:'Orange Plastics kit',sku:'UNSUITABLE-SKU',bundle_type:'P',category_kind:'universal',uni_category_id:11,brand:'KTM',model:'Default',year_range:'',modelcode:'AB23',notes:'Keep listing note',recommended_price:'199.90',recommended_currency:'EUR',web_price:null,web_currency:'EUR',ebay_price:null,ebay_currency:'EUR',amazon_price:null,amazon_currency:'EUR',box_id:null,weight_kg:null,created_at:'2026-11-05 12:00:00',component_count:1,components:[{component_type:'P',label:'Front plate',reference:'KT00295',color:'ORANGE',quantity:1,options:{plastics_buyer_note:'Keep component option'}}]};
 const page=await browser.newPage({viewport:{width:1600,height:1000}});page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
 await page.route('**/scripts/product_builder/api.php?*',async route=>{const req=route.request(),action=new URL(req.url()).searchParams.get('action'),data=req.method()==='POST'?JSON.parse(req.postData()):{};let body={ok:true},status=200;
  if(action==='categories')body.rows=[{id:11,brand:'KTM',category_name:'Default',modelcode:'AB23',included_models:'Big bike',version:1}];
  else if(action==='designs')Object.assign(body,{rows:[],versions:[]});
  else if(action==='boxes')body.rows=[];
  else if(action==='products')Object.assign(body,{rows:[listing],stats:{total:1,specific:0,universal:1}});
  else if(action==='product')body.product=listing;
  else if(action==='generate_sku'){
   const mode=nextMode;nextMode='normal';body.sku=data.bundle_type+'_'+(++sequence).toString(16).toUpperCase().padStart(20,'0');
   if(mode==='error'){status=422;body={ok:false,error:'Generation failed'};}
   if(mode==='delay')await new Promise(resolve=>{release=resolve;});
  }
  else if(action==='save_product'){
   saves++;assert.equal(data.id,801);assert.equal(data.components[0].color,'ORANGE');assert.equal(data.components[0].options.plastics_buyer_note,'Keep component option');
   listing={...listing,...data,version:listing.version+1};body.product={id:801,sku:listing.sku};
  }else throw Error('Unexpected API action '+action);
  await route.fulfill({status,json:body});
 });
 const base='http://localhost/darkscrub/.cache/product-builder/';
 await page.goto(base+'i18n-builder-sk.html');await page.locator('[data-product="801"]').waitFor();
 const button=page.locator('#pb-generate-sku');assert.equal(await button.isDisabled(),true);assert.equal(await button.getAttribute('title'),'Vygenerovať nové SKU');
 const edit=async()=>{await page.locator('[data-product="801"]').click();await page.waitForFunction(()=>document.getElementById('pb-edit-label')?.textContent.includes('801')&&!document.getElementById('pb-product-form').inert);};
 const generate=async()=>{await button.click();await page.waitForFunction(()=>document.getElementById('pb-generate-sku').getAttribute('aria-busy')==='false');return page.locator('#pb-sku').inputValue();};
 await edit();let first=await generate();assert.match(first,/^P_[A-F0-9]{20}$/);assert.equal(saves,0);assert.equal(listing.sku,'UNSUITABLE-SKU');assert.match(await page.locator('.pb-product-identity').textContent(),/UNSUITABLE-SKU/);
 assert.notEqual(await generate(),first);await page.locator('#pb-cancel').click();await edit();assert.equal(await page.locator('#pb-sku').inputValue(),'UNSUITABLE-SKU');
 await page.fill('#pb-sku','');assert.equal(await page.locator('#pb-sku').evaluate(e=>e.reportValidity()),false);let savedSku=await generate();assert.equal(await page.locator('#pb-sku').evaluate(e=>e.validity.valid),true);
 await page.locator('.pb-save-bar').screenshot({path:'.cache/product-builder/sku-generation.png'});
 await page.locator('#pb-save').click();await page.waitForFunction(()=>!document.getElementById('pb-product-form').inert&&document.getElementById('pb-message').classList.contains('alert-success'));assert.equal(saves,1);assert.equal(listing.sku,savedSku);await edit();assert.equal(await page.locator('#pb-sku').inputValue(),savedSku);
 nextMode='error';await generate();assert.equal(await page.locator('#pb-sku').inputValue(),savedSku);assert.match(await page.locator('#pb-message').textContent(),/Generation failed/);assert.equal(await page.locator('#pb-save').isDisabled(),false);
 const delayed=async()=>{nextMode='delay';release=null;await button.click();await page.waitForFunction(()=>document.getElementById('pb-generate-sku').getAttribute('aria-busy')==='true');while(!release)await new Promise(r=>setTimeout(r,5));assert.equal(await page.locator('#pb-save').isDisabled(),true);await page.locator('#pb-product-form').evaluate(e=>e.dispatchEvent(new Event('submit',{cancelable:true})));assert.equal(saves,1);};
 const finish=async()=>{const response=page.waitForResponse(r=>r.url().includes('action=generate_sku'));release();await response;await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));};
 await delayed();await page.fill('#pb-sku','MANUAL-WINS');await finish();assert.equal(await page.locator('#pb-sku').inputValue(),'MANUAL-WINS');
 await delayed();await page.selectOption('#pb-bundle','TFP');await finish();assert.equal(await page.locator('#pb-sku').inputValue(),'MANUAL-WINS');assert.match(await generate(),/^TFP_[A-F0-9]{20}$/);
 await delayed();await page.locator('#pb-new').click();await finish();assert.equal(await page.locator('#pb-sku').inputValue(),'');assert.equal(await button.isDisabled(),true);assert.equal(saves,1);
 await page.goto(base+'i18n-builder-en.html');await page.locator('[data-product="801"]').waitFor();assert.equal(await button.getAttribute('title'),'Generate new SKU');await edit();
 await page.setViewportSize({width:390,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);const inputRect=await page.locator('#pb-sku').boundingBox(),buttonRect=await button.boundingBox();assert.ok(buttonRect.x>=inputRect.x+inputRect.width);assert.ok(Math.abs(inputRect.y-buttonRect.y)<2);
 await page.goto(base+'listings-reader.html');await page.locator('[data-product="801"]').waitFor();assert.equal(await button.count(),0);assert.deepEqual(errors,[]);
 console.log('SKU generation UI: explicit preview/save, cancel, validation, failure, delayed responses, manual edits, bundle prefix, reset, SK/EN, mobile and read-only Listings OK.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});


