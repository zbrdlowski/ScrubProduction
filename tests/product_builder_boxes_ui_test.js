const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const page=await browser.newPage({viewport:{width:1500,height:1100}}),errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
 const categories=JSON.parse(fs.readFileSync('.cache/product-builder/categories.json','utf8')),designs=JSON.parse(fs.readFileSync('.cache/product-builder/designs.json','utf8')),versions=JSON.parse(fs.readFileSync('.cache/product-builder/graphics-versions.json','utf8'));
 let boxes=[{id:900,name:'Plastics box',length_cm:'60.00',width_cm:'40.00',height_cm:'30.00',weight_kg:'0.500',version:1}],writes=0,failNext=false;
 const handler=async route=>{
  const action=new URL(route.request().url()).searchParams.get('action'),data=route.request().method()==='POST'?JSON.parse(route.request().postData()):{};let result={ok:true};
  if(action==='categories')result.rows=categories;
  else if(action==='designs')Object.assign(result,{rows:designs,versions});
  else if(action==='products')Object.assign(result,{rows:[],stats:{total:0,specific:0,universal:0}});
  else if(action==='boxes')result.rows=boxes;
  else if(action==='save_box'){
   writes++;assert.ok(route.request().headers()['x-csrf-token']);
   if(failNext){failNext=false;await route.fulfill({status:422,json:{ok:false,error:'This box has changed or no longer exists. Refresh the list and open it again.'}});return;}
   if(boxes.some(row=>row.name.toLowerCase()===data.name.toLowerCase()&&String(row.id)!==data.id)){await route.fulfill({status:422,json:{ok:false,error:'A box with this name already exists.'}});return;}
   const id=Number(data.id)||901,row={...data,id,version:Number(data.version||0)+1};for(const key of ['length_cm','width_cm','height_cm','weight_kg'])row[key]=Number(data[key].replace(',','.')).toFixed(key==='weight_kg'?3:2);
   boxes=boxes.filter(row=>Number(row.id)!==id).concat(row);result.id=id;
  }else if(action==='delete_box'){writes++;boxes=boxes.filter(row=>String(row.id)!==data.id);}
  else throw Error('Unexpected request '+action);
  await route.fulfill({json:result});
 };
 await page.route('**/scripts/product_builder/api.php?*',handler);const url=process.env.PRODUCT_BUILDER_TEST_URL||'http://localhost/darkscrub/.cache/product-builder/preview.html';
 await page.goto(url);await page.fill('#pb-name','Unsaved listing');await page.fill('#pb-recommended-price','159.90');
 const trigger=page.locator('[data-target="#pb-box-modal"]');await trigger.click();await page.waitForFunction(()=>document.getElementById('pb-box-count').textContent==='1 of 1 boxes');
 assert.equal(await page.locator('#pb-box-weight').inputValue(),'0');
 await page.fill('#pb-box-name','Box <img src=x onerror=alert(1)>');await page.fill('#pb-box-length','0');await page.fill('#pb-box-width','40');await page.fill('#pb-box-height','12.25');await page.locator('#pb-box-save').click();assert.equal(writes,0);assert.equal(await page.locator('#pb-box-length').evaluate(el=>el.validity.customError),true);
 await page.fill('#pb-box-length','60,5');await page.fill('#pb-box-weight','-1');await page.locator('#pb-box-save').click();assert.equal(writes,0);assert.equal(await page.locator('#pb-box-weight').evaluate(el=>el.validity.patternMismatch),true);
 await page.fill('#pb-box-weight','0');await page.locator('#pb-box-save').click();await page.waitForFunction(()=>document.getElementById('pb-box-message').textContent==='Box saved.'&&!document.getElementById('pb-box-form').inert);
 assert.equal(boxes[1].weight_kg,'0.000');assert.equal(boxes[1].length_cm,'60.50');assert.equal(await page.locator('#pb-box-rows img').count(),0);assert.match(await page.locator('#pb-box-rows').textContent(),/<img src=x onerror=alert\(1\)>/);
 await page.fill('#pb-box-name','Seat cover box');await page.fill('#pb-box-weight','0,125');await page.locator('#pb-box-save').click();await page.waitForFunction(()=>document.querySelector('#pb-box-rows').textContent.includes('Seat cover box')&&!document.getElementById('pb-box-form').inert);
 assert.equal(boxes.find(row=>row.id===901).weight_kg,'0.125');
 await page.locator('#pb-box-new').click();await page.locator('[data-box-edit="901"]').click();assert.equal(await page.locator('#pb-box-weight').inputValue(),'0.125');assert.equal(await page.locator('#pb-box-height').inputValue(),'12.25');
 const dimensions=await page.locator('#pb-box-form .col-4').evaluateAll(nodes=>nodes.map(el=>el.getBoundingClientRect().top));assert.ok(dimensions.every(y=>y===dimensions[0]),'Three dimension inputs are adjacent.');
 await page.locator('#pb-box-modal').screenshot({path:'.cache/product-builder/boxes-desktop.png'});
 await page.fill('#pb-box-search','PLASTICS');assert.equal(await page.locator('#pb-box-rows [data-box-edit]').count(),1);await page.fill('#pb-box-search','missing');assert.match(await page.locator('#pb-box-rows').textContent(),/No boxes match/);await page.fill('#pb-box-search','');
 await page.fill('#pb-box-name','Plastics box');await page.locator('#pb-box-save').click();await page.waitForFunction(()=>document.getElementById('pb-box-message').textContent.includes('already exists'));assert.equal(await page.locator('#pb-box-name').inputValue(),'Plastics box');
 await page.fill('#pb-box-name','Seat cover box');await page.fill('#pb-box-weight','0.250');failNext=true;await page.locator('#pb-box-save').click();await page.waitForFunction(()=>document.getElementById('pb-box-message').textContent.includes('has changed'));assert.equal(await page.locator('#pb-box-weight').inputValue(),'0.250');assert.equal(boxes.find(row=>row.id===901).weight_kg,'0.125');
 await page.locator('#pb-box-refresh').click();await page.waitForFunction(()=>!document.getElementById('pb-box-message').textContent);await page.locator('[data-box-edit="901"]').click();assert.equal(await page.locator('#pb-box-weight').inputValue(),'0.125');
 await page.setViewportSize({width:390,height:1000});await page.locator('#pb-box-modal').screenshot({path:'.cache/product-builder/boxes-mobile.png'});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.locator('#pb-box-delete').click();await page.waitForFunction(()=>document.getElementById('pb-box-message').textContent==='Box deleted.');assert.equal(boxes.length,1);assert.equal(boxes[0].id,900);assert.equal(await page.locator('#pb-box-weight').inputValue(),'0');
 await page.locator('#pb-box-modal .close').click();await page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));assert.equal(await trigger.evaluate(el=>el===document.activeElement),true);assert.equal(await page.locator('#pb-name').inputValue(),'Unsaved listing');assert.equal(await page.locator('#pb-recommended-price').inputValue(),'159.90');
 await trigger.click();await page.waitForSelector('#pb-box-modal.show');await page.locator('#pb-box-modal .close').click();await page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));
 const reader=await browser.newPage();reader.on('pageerror',e=>errors.push(e.message));await reader.route('**/scripts/product_builder/api.php?*',handler);
 const html=fs.readFileSync('.cache/product-builder/preview.html','utf8').replace(/<form id="pb-product-form">[\s\S]*?<\/form>/,'').replace(/<form id="pb-box-form"[\s\S]*?<\/form>/,'').replace('"canEdit":true','"canEdit":false');
 await reader.route('**/.cache/product-builder/preview.html',route=>route.fulfill({contentType:'text/html',body:html}));await reader.goto(url);await reader.locator('[data-target="#pb-box-modal"]').click();await reader.waitForFunction(()=>document.getElementById('pb-box-count').textContent==='1 of 1 boxes');assert.equal(await reader.locator('[data-box-edit]').count(),0);assert.equal(await reader.locator('#pb-box-form').count(),0);
 assert.deepEqual(errors,[]);console.log('Boxes UI: CRUD, three dimension fields, zero/decimal weights, search, escaping, validation, duplicates, stale edits, modal close, draft preservation, mobile and read-only access OK.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
