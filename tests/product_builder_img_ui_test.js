const {chromium}=require('playwright'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
 const context=await browser.newContext({viewport:{width:1750,height:1100}}),errors=[],writes=[];
 let fail=false,hold=false,release;
 let row={id:801,name:'Husqvarna Graphics kit',sku:'G_IMG_01',bundle_type:'G',category_kind:'universal',uni_category_id:11,brand:'Husqvarna',model:'All',year_range:'',modelcode:'ZA23',notes:'Keep note',request_key:'a'.repeat(32),version:1,created_at:'2026-11-09 12:00:00',recommended_price:'199.90',recommended_currency:'EUR',component_count:1,components:[{component_type:'G',label:'Graphics',quantity:1,options:{}}],img_status:'none',img_path:null,img_version:1};
 const specific={...row,id:802,sku:'P_IMG_02',category_kind:'specific',brand:'KTM',model:'SX 50',year_range:'2016-2023',modelcode:'AB23',img_status:'A'};
 await context.addInitScript(()=>{window.copyWrites=[];window.vibrations=[];Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>window.copyWrites.push(text)}});Object.defineProperty(navigator,'vibrate',{configurable:true,value:duration=>{window.vibrations.push(duration);return true;}});});
 await context.route('**/scripts/product_builder/api.php?*',async route=>{
  const req=route.request(),action=new URL(req.url()).searchParams.get('action');let body={ok:true};
  if(action==='products')Object.assign(body,{rows:[row,specific],stats:{total:2,universal:1,specific:1}});
  else if(action==='product')body.product=row;
  else if(action==='categories')body.rows=[{id:11,brand:'Husqvarna',category_name:'All',modelcode:'ZA23',included_models:'Models',version:1}];
  else if(action==='designs')Object.assign(body,{rows:[],versions:[]});else if(action==='boxes')body.rows=[];
  else if(action==='save_product_image'){
   const data=JSON.parse(req.postData());assert.ok(req.headers()['x-csrf-token']);writes.push(data);
   if(hold)await new Promise(resolve=>{release=resolve;});
   if(fail){fail=false;await route.fulfill({status:422,json:{ok:false,error:'Save rejected for testing.'}});return;}
   if(Number(data.img_version)!==row.img_version){await route.fulfill({status:422,json:{ok:false,error:'Another user has changed the IMG status. Reload the latest status and try again.'}});return;}
   row={...row,img_status:data.img_status,img_path:data.img_status==='Path'?data.img_path:row.img_path,img_version:row.img_version+1};
   body.image={id:row.id,img_status:row.img_status,img_path:row.img_path,img_version:row.img_version};
  }else throw Error('Unexpected action '+action);
  await route.fulfill({json:body});
 });
 const base='http://localhost/darkscrub/.cache/product-builder/',page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto(base+'preview.html');
 const cell=page.locator('[data-img-cell="801"]'),select=cell.locator('select');await select.waitFor();
 const headers=await page.locator('.pb-products-table>thead th').allTextContents();assert.equal(headers[4],'IMG');assert.equal(headers.length,12);assert.ok(!headers.includes('Modelcode'));
 const category=page.locator('.pb-category-cell').first();assert.equal(await category.locator('.pb-category-badge').textContent(),'U');assert.equal(await page.locator('.pb-category-badge').nth(1).textContent(),'S');assert.equal(await category.locator('code').textContent(),'ZA23');await category.locator('[data-pb-copy]').click();assert.equal(await page.evaluate(()=>window.copyWrites.at(-1)),'ZA23');
 assert.deepEqual(await select.locator('option').allTextContents(),['✗','R','A','Path']);assert.equal(await select.inputValue(),'none');
 const color=()=>cell.evaluate(e=>getComputedStyle(e).backgroundColor);assert.equal(await color(),'rgb(129, 61, 67)');
 await page.fill('#pb-name','Unfinished product draft');await select.selectOption('R');await page.waitForFunction(()=>document.querySelector('[data-img-cell="801"]').dataset.imgStatus==='R');assert.equal(row.img_status,'R');assert.equal(await color(),'rgb(113, 61, 129)');assert.equal(await page.locator('#pb-name').inputValue(),'Unfinished product draft');
 await select.selectOption('A');await page.waitForFunction(()=>document.querySelector('[data-img-cell="801"]').dataset.imgStatus==='A');assert.equal(await color(),'rgb(165, 133, 36)');
 await page.locator('[data-product-expand="801"]').click();await page.locator('#pb-list-components-801 .pb-component-details').waitFor();await cell.hover();assert.equal(await color(),'rgb(165, 133, 36)','Expanded/hover styles preserve the IMG status color.');
 const modal=page.locator('#pb-img-modal'),input=page.locator('#pb-img-path-input');let count=writes.length;
 await select.selectOption('Path');await modal.locator('.modal-content').waitFor({state:'visible'});await input.fill('Y:\\Not saved');await modal.locator('.close').click();await modal.waitFor({state:'hidden'});assert.equal(writes.length,count);assert.equal(await select.inputValue(),'A','Cancel keeps the persisted state.');
 await select.selectOption('Path');await modal.waitFor({state:'visible'});await page.locator('#pb-img-save').click();assert.equal(writes.length,count,'Empty paths do not submit.');
 const path=String.raw`Y:\E-SHOP\_WEB_2025\GRAPHICS_KIT\HUSQVARNA AT`;
 await input.fill(path);hold=true;await page.locator('#pb-img-save').click();await page.waitForFunction(()=>document.querySelector('[data-img-cell="801"]').getAttribute('aria-busy')==='true');assert.equal(await select.isDisabled(),true);await modal.locator('.close').click();assert.equal(await modal.isVisible(),true,'Do not close during an unfinished save.');hold=false;release();await modal.waitFor({state:'hidden'});assert.equal(row.img_path,path);assert.equal(await select.inputValue(),'Path');assert.equal(await color(),'rgb(39, 99, 66)');
 await cell.locator('[data-pb-copy]').click();assert.equal(await page.evaluate(()=>window.copyWrites.at(-1)),path);assert.equal(await cell.locator('.fa-check').count(),1);assert.equal(await page.evaluate(()=>window.vibrations.at(-1)),25);
 await cell.locator('[data-img-path]').click();await modal.waitFor({state:'visible'});assert.equal(await input.inputValue(),path);await page.locator('#pb-img-copy').click();assert.equal(await page.evaluate(()=>window.copyWrites.at(-1)),path);await modal.locator('.close').click();await modal.waitFor({state:'hidden'});
 fail=true;await select.selectOption('R');await cell.locator('[role="alert"]').waitFor();assert.equal(await select.inputValue(),'Path','Failed saves revert to the committed state.');assert.equal(row.img_status,'Path');await cell.locator('[data-img-reload]').click();await page.waitForFunction(()=>!document.querySelector('[data-img-cell="801"] [role="alert"]'));
 // A different operator changes IMG; the stale local revision must not overwrite it.
 row={...row,img_status:'R',img_version:row.img_version+1};await select.selectOption('A');await cell.locator('[role="alert"]').waitFor();assert.match(await cell.textContent(),/Another user/);await cell.locator('[data-img-reload]').click();await page.waitForFunction(()=>document.querySelector('[data-img-cell="801"]').dataset.imgStatus==='R');
 await select.selectOption('Path');await modal.waitFor({state:'visible'});assert.equal(await input.inputValue(),path);
 const special=String.raw`\\server\Shared Designs\O'Neal & <img src=x> "AT"`;
 await input.fill(special);await page.locator('#pb-img-save').click();await modal.waitFor({state:'hidden'});assert.equal(row.img_path,special);assert.equal(await cell.locator('img').count(),0);await cell.locator('[data-pb-copy]').click();assert.equal(await page.evaluate(()=>window.copyWrites.at(-1)),special);
 await page.locator('#pb-saved-products').screenshot({path:'.cache/product-builder/img-status-desktop.png'});
 page.once('dialog',d=>d.accept());await page.reload();await select.waitFor();assert.equal(await select.inputValue(),'Path','Saved status survives a reload.');await cell.locator('[data-img-path]').click();await modal.waitFor({state:'visible'});assert.equal(await input.inputValue(),special);await modal.locator('.close').click();await modal.waitFor({state:'hidden'});
 const reader=await context.newPage();reader.on('pageerror',e=>errors.push(e.message));await reader.goto(base+'listings-reader.html');const readerCell=reader.locator('[data-img-cell="801"]');await readerCell.waitFor();assert.equal(await readerCell.locator('select').isDisabled(),true);count=writes.length;await readerCell.locator('[data-pb-copy]').click();assert.equal(await reader.evaluate(()=>window.copyWrites.at(-1)),special);await readerCell.locator('[data-img-path]').click();await reader.locator('#pb-img-modal.show').waitFor();assert.equal(await reader.locator('#pb-img-path-input').getAttribute('readonly'),'');assert.equal(await reader.locator('#pb-img-save').isVisible(),false);assert.equal(writes.length,count);await reader.locator('#pb-img-modal .close').click();
 await reader.setViewportSize({width:390,height:900});assert.equal(await reader.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.goto(base+'i18n-listings-sk.html');await page.locator('[data-img-path="801"]').click();await modal.waitFor({state:'visible'});assert.equal(await page.locator('#pb-img-title').textContent(),'Adresár obrázkov');assert.equal(await page.locator('#pb-img-copy').getAttribute('title'),'Kopírovať cestu k adresáru');await modal.locator('.close').click();await modal.waitFor({state:'hidden'});
 await select.selectOption('none');await page.waitForFunction(()=>document.querySelector('[data-img-cell="801"]').dataset.imgStatus==='none');assert.equal(row.img_status,'none');assert.equal(row.img_path,special,'Switching status keeps the folder path for reuse.');
 assert.deepEqual(errors,[]);console.log('IMG UI: category badges/copy, autosave/colors, draft preservation, Path validation/cancel/pending, exact paths/copy/haptics, failures/conflicts/reload, reader permissions, SK/EN and mobile OK.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
