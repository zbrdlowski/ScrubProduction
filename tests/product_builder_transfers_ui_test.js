const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const context=await browser.newContext({viewport:{width:1820,height:1050}}),page=await context.newPage(),errors=[];
  context.on('page',p=>p.on('pageerror',e=>errors.push(e.message)));page.on('pageerror',e=>errors.push(e.message));
  const fixture=name=>JSON.parse(fs.readFileSync('.cache/product-builder/'+name+'.json','utf8'));
  let rows=[null,'skip','gen','ready'].map((state,i)=>({id:61+i,order_id:91+i,order_item_id:101+i,order_number:'TEST-'+(91+i),title:['ARTLEX Graphics <test>','KTM Factory Graphics','Husqvarna Enduro Graphics','Yamaha Racing Graphics'][i],source_sku:'G_SOURCE',status:state==='skip'?'dismissed':'pending',img_status:state,purpose:'new_listing',version:1,product_id:null,created_at:i<2?'2026-11-16 10:00:00':'2026-11-15 10:00:00',snapshot:{category:{brand:'KTM',model:'SX 50',year_range:'2016-2023',modelcode:'TEST'},category_text:'KTM · SX 50 · 2016-2023'}}));
  const folderPath='Y:\\E-SHOP\\GRAPHICS KIT\\KTM SX';
  const draft={name:rows[0].title,sku:'',bundle_type:'G',category_kind:'specific',brand:'KTM',model:'SX 50',year_range:'2016-2023',modelcode:'TEST',components:[{component_type:'G',label:'ARTLEX',reference:'',notes:'',quantity:1,options:{graphics_material:'Chrome',graphics_finish:'Gloss',graphics_number:'Yes'}}]};
  let product=null,payload=null,fail=false,requests=[];
  await context.route('**/index.php?page=product_builder&transfer=61',route=>route.fulfill({contentType:'text/html',body:fs.readFileSync('.cache/product-builder/builder-transfer.html','utf8')}));
  await context.route('**/scripts/product_builder/api.php?*',async route=>{
   const request=route.request(),url=new URL(request.url()),action=url.searchParams.get('action');requests.push(action);let result={ok:true};
   if(action==='transfers'){
    const query=(url.searchParams.get('q')||'').toLowerCase(),filters=url.searchParams.get('img_filters')?.split(',')||['unset','skip','gen','ready','completed'];
    if(query&&!filters.includes('completed'))filters.push('completed');
    const filtered=rows.filter(r=>filters.includes(r.product_id||r.status==='completed'?'completed':r.img_status||'unset')&&(r.title+' '+r.order_number+' '+(r.product_sku||'')+' '+r.snapshot.category_text).toLowerCase().includes(query));
    const size=Number(url.searchParams.get('per_page'))||100,pages=Math.max(1,Math.ceil(filtered.length/size)),p=Math.min(pages,Number(url.searchParams.get('page'))||1);
    Object.assign(result,{rows:filtered.slice((p-1)*size,p*size),page:p,pages,per_page:size,total:filtered.length,from:filtered.length?(p-1)*size+1:0,to:Math.min(p*size,filtered.length)});
   }else if(action==='transfer')result.transfer=rows.find(r=>r.id===Number(url.searchParams.get('id')));
   else if(action==='save_transfer_image'){
    const data=JSON.parse(request.postData());assert.ok(request.headers()['x-csrf-token']);const row=rows.find(r=>r.id===data.id);
    if(fail){fail=false;return route.fulfill({status:422,json:{ok:false,error:'Another user has changed this transfer. Reload it and try again.'}});}
    assert.equal(data.version,row.version);Object.assign(row,{img_status:data.img_status||null,status:data.img_status==='skip'?'dismissed':'pending',version:row.version+1});result.transfer=row;
    }else if(action==='save_transfer_folder'){
    const data=JSON.parse(request.postData());assert.ok(request.headers()['x-csrf-token']);const row=rows.find(r=>r.id===data.id);
    if(data.version!==row.version)return route.fulfill({status:422,json:{ok:false,error:'Another user has changed this transfer. Reload it and try again.'}});
    assert.equal(row.img_status,'ready');Object.assign(row,{folder_path:data.folder_path,version:row.version+1});result.transfer=row;
   }else if(action==='transfer_draft'){assert.equal(rows[0].img_status,'ready');result.draft={...draft,folder_path:rows[0].folder_path,transfer_version:rows[0].version};}
   else if(action==='categories')result.rows=fixture('categories');
   else if(action==='boxes')result.rows=fixture('boxes');
   else if(action==='designs'){result.rows=fixture('designs');result.versions=fixture('graphics-versions');}
   else if(action==='products')Object.assign(result,{rows:product?[{...product,component_count:1}]:[],stats:{total:product?1:0,specific:product?1:0,universal:0}});
   else if(action==='product')result.product=product;
   else if(action==='save_product'){
    payload=JSON.parse(request.postData());assert.equal(payload.transfer_id,61);assert.equal(payload.transfer_version,rows[0].version);
    product={...payload,img_status:'Path',img_path:rows[0].folder_path,id:801,version:1,sku:'G_GENERATED',created_at:'2026-11-16 12:00:00'};
    Object.assign(rows[0],{product_id:801,product_sku:product.sku,status:'completed',version:rows[0].version+1});result.product={id:801,sku:product.sku};
   }else throw Error('Unexpected API '+action);
   await route.fulfill({json:result});
  });
  const base='http://localhost/darkscrub/.cache/product-builder/';
  await page.goto(base+'transfers-editor.html');await page.locator('[data-transfer-row="61"]').waitFor();
  assert.equal(await page.locator('.pb-products-table thead th').count(),19);
  assert.equal(await page.locator('.pb-day-separator-row').count(),2);
  assert.deepEqual(requests,['transfers'],'Transfer page only loads the preparation queue.');
  assert.equal(await page.locator('[data-transfer-filter]').count(),5);
  assert.equal(await page.locator('[data-transfer-filter]:checked').count(),4);
  const filter=async(key,on)=>{await page.locator('[data-transfer-filter="'+key+'"]').setChecked(on);await page.waitForFunction(()=>!document.querySelector('[data-list-page-size]').disabled);};
  const visibleIds=()=>page.locator('[data-transfer-row]').evaluateAll(nodes=>nodes.map(n=>Number(n.dataset.transferRow)));
  await filter('unset',false);await filter('skip',false);await filter('completed',false);
  assert.deepEqual(await visibleIds(),[63,64],'Gen and Ready may be viewed together.');
  await filter('gen',false);await filter('ready',false);
  assert.deepEqual(await visibleIds(),[]);assert.match(await page.locator('#pb-products').textContent(),/Select at least one status/);
  for(const key of ['unset','skip','gen','ready','completed'])await filter(key,true);

  assert.equal(await page.locator('[data-transfer-img="61"]').inputValue(),'');
  assert.equal(await page.locator('[data-transfer-prepare="61"]').count(),0);
  assert.equal(await page.locator('[data-transfer-row="61"] test').count(),0,'Source text is escaped.');
  assert.equal(await page.locator('[data-transfer-row="61"] button:enabled').count(),0);
  assert.equal(await page.locator('[data-transfer-row="61"] select:enabled').count(),1);
  assert.equal(await page.locator('[data-transfer-row="61"] td').count(),19);
  assert.match(await page.locator('[data-transfer-row="61"] .pb-product-identity').textContent(),/TEST-91/);
  assert.doesNotMatch(await page.locator('[data-transfer-row="61"] .pb-product-identity').textContent(),/G_SOURCE/);
  const set=async value=>{await page.selectOption('[data-transfer-img="61"]',value);await page.waitForFunction(state=>document.querySelector('[data-transfer-row="61"]').dataset.transferState===state&& !document.querySelector('[data-transfer-img="61"]').disabled,value);};
  await set('skip');assert.ok((await page.locator('[data-transfer-row="61"] td').evaluateAll(cells=>cells.map(n=>getComputedStyle(n).backgroundColor))).every(color=>color==='rgb(129, 61, 67)'),'skip colors the entire row, including sticky Actions.');
  await set('gen');assert.ok((await page.locator('[data-transfer-row="61"] td').evaluateAll(cells=>cells.map(n=>getComputedStyle(n).backgroundColor))).every(color=>color==='rgb(113, 61, 129)'),'gen colors the entire row, including sticky Actions.');
  fail=true;await page.selectOption('[data-transfer-img="61"]','ready');await page.waitForFunction(()=>document.querySelector('#pb-message').textContent.includes('Another user'));
  assert.equal(await page.locator('[data-transfer-img="61"]').inputValue(),'gen');assert.equal(await page.locator('[data-transfer-prepare="61"]').count(),0);
  await set('ready');assert.equal(await page.locator('[data-transfer-row="61"] td').first().evaluate(n=>getComputedStyle(n).backgroundColor),'rgb(39, 99, 66)');
  await page.fill('#pb-search','TEST-94');await page.waitForFunction(()=>document.querySelectorAll('[data-transfer-row]').length===1);assert.equal(await page.locator('[data-transfer-row="64"]').count(),1);
  await page.fill('#pb-search','');await page.waitForFunction(()=>document.querySelectorAll('[data-transfer-row]').length===4);
  await page.locator('[data-transfer-folder="61"]').click();await page.locator('#pb-transfer-folder-modal.show').waitFor();
  await page.locator('#pb-transfer-folder-modal .close').click();await page.locator('#pb-transfer-folder-modal').waitFor({state:'hidden'});
  await page.locator('[data-transfer-folder="61"]:enabled').click();await page.locator('#pb-transfer-folder-modal.show').waitFor();
  await page.fill('#pb-transfer-folder-input',folderPath);
  rows[0].version++; // Another operator changes the transfer while its folder dialog is open.
  await page.click('#pb-transfer-folder-save');await page.waitForFunction(()=>!document.querySelector('#pb-transfer-folder-error').hidden);
  assert.equal(await page.locator('#pb-transfer-folder-modal').isVisible(),true);assert.equal(rows[0].folder_path,undefined);
  page.once('dialog',dialog=>dialog.accept());await page.click('#pb-transfer-folder-reload');await page.waitForFunction(()=>document.querySelector('#pb-transfer-folder-input').value==='');
  await page.fill('#pb-transfer-folder-input',folderPath);await page.screenshot({path:'.cache/product-builder/transfer-folder-modal.png'});
  await page.click('#pb-transfer-folder-save');await page.locator('#pb-transfer-folder-modal').waitFor({state:'hidden'});
  assert.equal(rows[0].folder_path,folderPath);
  await context.grantPermissions(['clipboard-read','clipboard-write']);
  await page.evaluate(()=>{window.vibrations=[];navigator.vibrate=ms=>{window.vibrations.push(ms);return true;};});
  await page.locator('[data-transfer-copy="61"]').click();await page.waitForFunction(()=>window.vibrations.length===1);
  assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),folderPath);
  await page.screenshot({path:'.cache/product-builder/transfer-database-desktop.png',fullPage:true});
  await page.locator('[data-transfer-prepare="61"]').click();await page.waitForFunction(()=>document.querySelector('#pb-name')?.value==='ARTLEX Graphics <test>');
  assert.equal(await page.locator('#pb-transfers').count(),0);assert.equal(await page.locator('#pb-transfer-modal').count(),0);
  assert.equal(await page.locator('#pb-bundle').inputValue(),'G');assert.equal(await page.locator('#pb-sku').inputValue(),'');assert.equal(await page.locator('#pb-recommended-price').inputValue(),'');
  assert.equal(await page.locator('[data-spec="graphics_number"]').inputValue(),'Yes');assert.match(await page.locator('#pb-specific-label').textContent(),/TEST/);
  assert.match(await page.locator('#pb-transfer-draft-banner').textContent(),/Y:\\E-SHOP/);assert.equal(await page.locator('.pb-transfer-draft-path code').textContent(),folderPath);
  await page.evaluate(()=>navigator.clipboard.writeText('before builder copy'));await page.locator('#pb-transfer-draft-banner [data-pb-copy]').click();
  assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),folderPath);
  await page.fill('#pb-recommended-price','149.90');await page.click('#pb-save');await page.waitForFunction(()=>document.querySelector('#pb-sku').value==='G_GENERATED');
  assert.equal(payload.transfer_id,61);assert.equal(payload.components[0].quantity,'1');assert.equal(rows[0].status,'completed');assert.equal(product.img_path,folderPath);assert.equal(product.img_status,'Path');
  await page.goto(base+'transfers-editor.html');await page.locator('[data-transfer-row="62"]').waitFor();
  assert.deepEqual(await visibleIds(),[62,63,64],'Created listings are hidden on a fresh page.');
  await page.fill('#pb-search','G_GENERATED');await page.locator('[data-transfer-row="61"]').waitFor();
  assert.deepEqual(await visibleIds(),[61]);assert.equal(await page.locator('[data-transfer-filter="completed"]').isChecked(),false);
  assert.equal(await page.locator('#pb-transfer-search-hint').isVisible(),true);
  assert.equal(await page.locator('[data-transfer-img="61"]').count(),0);assert.equal(await page.locator('[data-transfer-prepare="61"]').count(),0);assert.match(await page.locator('[data-transfer-row="61"]').textContent(),/Listing created/);
  await page.locator('[data-transfer-folder="61"]').click();await page.locator('#pb-transfer-folder-modal.show').waitFor();
  assert.equal(await page.locator('#pb-transfer-folder-input').inputValue(),folderPath);assert.equal(await page.locator('#pb-transfer-folder-input').getAttribute('readonly'),'');assert.equal(await page.locator('#pb-transfer-folder-save').isVisible(),false);
  await page.locator('#pb-transfer-folder-modal .close').click();await page.locator('#pb-transfer-folder-modal').waitFor({state:'hidden'});
  await page.fill('#pb-search','');await page.waitForFunction(()=>document.querySelectorAll('[data-transfer-row]').length===3);
  assert.deepEqual(await visibleIds(),[62,63,64],'Clearing the search restores active transfers.');
  for(const key of ['unset','skip','gen','ready'])await filter(key,false);
  await filter('completed',true);assert.deepEqual(await visibleIds(),[61],'Completed-only view includes the created listing.');
  // A legacy completed row with no IMG also belongs only to Completed.
  rows[0].img_status=null;await filter('completed',false);await filter('unset',true);assert.deepEqual(await visibleIds(),[]);
  await filter('completed',true);assert.deepEqual(await visibleIds(),[61]);
  await page.screenshot({path:'.cache/product-builder/transfer-filters-completed.png',fullPage:true});
  // Moving a row out of the active filter updates both the table and its count.
  await filter('completed',false);await filter('gen',true);
  await page.selectOption('[data-transfer-img="63"]','ready');
  await page.waitForFunction(()=>!document.querySelector('[data-transfer-row="63"]')&&!document.querySelector('[data-list-page-size]').disabled);
  assert.match(await page.locator('[data-list-range]').first().textContent(),/0–0 of 0/);

  await page.goto(base+'transfers-viewer.html');await page.locator('[data-transfer-row="62"]').waitFor();assert.equal(await page.locator('[data-transfer-img]:enabled').count(),0);assert.equal(await page.locator('[data-transfer-prepare]').count(),0);
  await page.setViewportSize({width:430,height:930});await page.screenshot({path:'.cache/product-builder/transfer-database-mobile.png',fullPage:true});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
  assert.deepEqual(errors,[]);console.log('Transfer D-base UI: shared 19 columns, dates, states/colors, disabled placeholders, stale save recovery, search, Ready handoff/save, completed lock, read-only access and mobile OK.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
