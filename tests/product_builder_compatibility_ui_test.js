const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const page=await browser.newPage({viewport:{width:1720,height:1050}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const a={brand:'Yamaha',model:'WR450F',year_range:'2019-2023',modelcode:'NGL2'},b={brand:'Yamaha',model:'WR250F',year_range:'2020-2024',modelcode:'NGL2'},c={brand:'Suzuki',model:'DR-Z400 SM',year_range:'1999-2024',modelcode:'VMVQ'},d={brand:'Yamaha',model:'YZ450F',year_range:'2018-2020',modelcode:'YZ45'};
 let catalogue=[a,b,c,d],product=null,saves=[],fail=false;
 await page.route('**/scripts/product_builder/api.php?*',async route=>{
  const req=route.request(),u=new URL(req.url()),action=u.searchParams.get('action');let result={ok:true};
  if(action==='model_catalogue'){if(fail){fail=false;return route.fulfill({status:500,json:{ok:false,error:'Catalogue temporarily unavailable'}});}result.rows=catalogue;}
  else if(action==='categories')result.rows=[{id:11,brand:'Yamaha',category_name:'Default',modelcode:'UNI1',included_models:'All',version:1}];
  else if(action==='designs'){result.rows=[];result.versions=[];}
  else if(action==='boxes')result.rows=[];
  else if(action==='products')Object.assign(result,{rows:product?[{...product,component_count:1}]:[],stats:{total:product?1:0,specific:product?1:0,universal:0}});
  else if(action==='save_product'){const data=JSON.parse(req.postData());saves.push(data);product={...data,id:901,sku:'G_MULTI_TEST',request_key:data.request_key,version:Number(data.version)+1,created_at:'2026-11-23 10:00:00',img_status:'none'};result.product={id:901,sku:product.sku};}
  else if(action==='product')result.product=product;
  else throw Error('Unexpected API '+action);
  await route.fulfill({json:result});
 });
 const base='http://localhost/darkscrub/.cache/product-builder/';await page.goto(base+'preview.html');await page.waitForFunction(()=>!document.querySelector('#pb-product-form').inert);
 await page.selectOption('#pb-bundle','G');await page.fill('#pb-name','Yamaha WR450F / WR250F full kit');await page.fill('[data-component-row="G"] [data-field="label"]','Graphics');await page.fill('#pb-recommended-price','149.90');
 const open=async()=>{await page.locator('#pb-specific [data-target="#pb-model-modal"]').click();await page.locator('#pb-model-modal.show').waitFor();await page.waitForFunction(()=>document.querySelector('#pb-model-tree').getAttribute('aria-busy')==='false');};
 await open();assert.equal(await page.locator('#pb-model-apply').isDisabled(),true);
 await page.fill('#pb-model-search','NGL2');assert.equal(await page.locator('[data-model-leaf]').count(),2);
 await page.locator('[data-model-group][aria-label="Yamaha"]').check();assert.equal(await page.locator('#pb-model-selected li').count(),2);
 assert.equal(await page.locator('[data-model-leaf]:checked').count(),2);
 await page.locator('[data-model-leaf]').first().uncheck();assert.equal(await page.locator('[data-model-group][aria-label="Yamaha"]').evaluate(n=>n.indeterminate),true);
 await page.locator('[data-model-group][aria-label="Yamaha"]').check();
 await page.screenshot({path:'.cache/product-builder/multi-model-picker.png',fullPage:true});
 await page.click('#pb-model-apply');await page.locator('#pb-model-modal').waitFor({state:'hidden'});
 assert.match(await page.locator('#pb-specific-label').textContent(),/\+1 models/);assert.equal(await page.locator('#pb-summary-code').textContent(),'NGL2');
 assert.match(await page.locator('[data-component-category-text]').first().getAttribute('title'),/WR250F/);
 await page.click('#pb-save');await page.waitForFunction(()=>document.querySelector('#pb-sku').value==='G_MULTI_TEST');
 assert.equal(saves[0].compatibility.length,2);assert.equal(new Set(saves[0].compatibility.map(r=>r.modelcode)).size,1);
 await page.locator('.pb-compatibility-details summary').first().click();assert.match(await page.locator('.pb-compatibility-details').first().textContent(),/WR450F/);assert.match(await page.locator('.pb-compatibility-details').first().textContent(),/WR250F/);
 // Editing restores both models. Clearing and cancelling must never alter the saved/form selection.
 await open();assert.equal(await page.locator('#pb-model-selected li').count(),2);await page.click('#pb-model-clear');await page.locator('#pb-model-modal .close').click();await page.locator('#pb-model-modal').waitFor({state:'hidden'});
 await open();assert.equal(await page.locator('#pb-model-selected li').count(),2);
 await page.fill('#pb-model-search','Suzuki');await page.locator('[data-model-group][aria-label="Suzuki"]').check();assert.equal(await page.locator('#pb-model-selected li').count(),3);
 fail=true;await page.click('#pb-model-refresh');await page.locator('#pb-model-error').waitFor({state:'visible'});assert.equal(await page.locator('#pb-model-selected li').count(),3);
 catalogue.push({brand:'Honda',model:'CRF450R',year_range:'2025-2026',modelcode:'HON2'});await page.click('#pb-model-refresh');await page.waitForFunction(()=>document.querySelector('#pb-model-tree').getAttribute('aria-busy')==='false');
 await page.fill('#pb-model-search','');assert.equal(await page.locator('#pb-model-selected li').count(),3);assert.equal(await page.locator('[data-model-group][aria-label="Honda"]').count(),1);
 await page.click('#pb-model-apply');await page.locator('#pb-model-modal').waitFor({state:'hidden'});await page.click('#pb-save');await page.waitForFunction(()=>document.querySelector('#pb-message').textContent.includes('Product saved'));
 await page.waitForFunction(()=>document.querySelector('.pb-compatibility-details summary')?.textContent.includes('3'));
 assert.equal(saves[1].compatibility.length,3);assert.ok(saves[1].compatibility.some(r=>r.modelcode==='VMVQ'));
 await open();await page.setViewportSize({width:430,height:900});await page.screenshot({path:'.cache/product-builder/multi-model-mobile.png',fullPage:true});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);assert.deepEqual(errors,[]);
 console.log('Model tree UI: shared modelcode selection, indeterminate groups, search, staged cancel, save/edit round trip, catalogue refresh, display and mobile OK.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
