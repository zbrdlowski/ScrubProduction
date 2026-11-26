const {chromium}=require('playwright'),assert=require('assert/strict');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('dialog',dialog=>dialog.accept());
  const cat={brand:'KTM',model:'EXC',year_range:'2008-2011',modelcode:'TEST'};
  let rows=[{id:1,source_kind:'order',order_number:'ORDER-1',title:'Automatic transfer',status:'pending',img_status:null,version:1,created_at:'2026-11-26 10:00:00',snapshot:{category:cat}},
   {id:2,source_kind:'manual',order_number:'DONE',title:'Completed manual transfer',status:'completed',product_id:22,img_status:'ready',version:2,created_at:'2026-11-26 09:00:00',snapshot:{category:cat}}];
  let failOptions=true,failSave=true,writes=[];
  await page.route('**/scripts/product_builder/api.php?*',async route=>{
   const req=route.request(),url=new URL(req.url()),action=url.searchParams.get('action');let result={ok:true};
   if(action==='transfers'){
    const filters=(url.searchParams.get('img_filters')||'').split(','),q=(url.searchParams.get('q')||'').toLowerCase();
    const selected=rows.filter(row=>(filters.includes(row.status==='completed'?'completed':row.img_status||'unset')||(q&&row.status==='completed'))&&(!q||(row.title+' '+row.order_number).toLowerCase().includes(q)));
    Object.assign(result,{rows:selected,page:1,pages:1,per_page:100,total:selected.length,from:selected.length?1:0,to:selected.length});
   }else if(action==='transfer_form_options'){
    if(failOptions){failOptions=false;return route.fulfill({status:500,json:{ok:false,error:'Test catalogue unavailable'}});}
    Object.assign(result,{models:[cat],categories:[{brand:'Honda',category_name:'Default',modelcode:'UNI1'}],designs:['Artlex'],versions:['ST','R1']});
   }else if(action==='save_manual_transfer'){
    assert.ok(req.headers()['x-csrf-token']);const data=JSON.parse(req.postData());writes.push(data);
    assert.deepEqual(Object.keys(data).sort(),['id','version','request_key','category','title','source_sku','design_name','version_name'].sort());
    assert.deepEqual(data.category,cat);
    if(failSave){failSave=false;return route.fulfill({status:422,json:{ok:false,error:'Test save failed'}});}
    let row=rows.find(r=>r.id===data.id);
    if(!row){row={id:90,source_kind:'manual',order_id:null,order_item_id:null,order_number:'',status:'pending',img_status:null,version:0,created_at:'2026-11-26 12:00:00'};rows.unshift(row);}
    else{assert.equal(row.source_kind,'manual');assert.equal(data.version,row.version);}
    Object.assign(row,{title:data.title,source_sku:data.source_sku,version:row.version+1,snapshot:{category:data.category,options:{},source_quantity:1,design_name:data.design_name,version_name:data.version_name}});result.transfer=row;
   }else throw Error('Unexpected API '+action);
   return route.fulfill({json:result});
  });
  const field=id=>page.locator('#pb-manual-'+id);
  const chooseCategory=async value=>{await field('category').evaluate((node,value)=>{window.jQuery(node).val(value).trigger('change');},value);};
  await page.goto('http://localhost/darkscrub/.cache/product-builder/transfers-viewer.html');await page.locator('[data-transfer-row="1"]').waitFor();
  assert.equal(await page.locator('[data-manual-transfer-edit="1"]').count(),0);
  await page.click('#pb-new-transfer');await page.locator('#pb-manual-transfer-modal.show').waitFor();await page.locator('#pb-manual-transfer-retry:visible').waitFor();
  assert.equal(await field('transfer-save').isDisabled(),true);await field('transfer-retry').click();await page.locator('#pb-manual-transfer-save:enabled').waitFor();
  assert.equal(await page.locator('#pb-manual-transfer-form input[id^="pb-manual-"],#pb-manual-transfer-form select[id^="pb-manual-"],#pb-manual-transfer-form textarea').count(),5);
  assert.equal(await page.locator('[data-manual-spec]').count(),0);
  assert.equal(await page.locator('#pb-manual-transfer-modal .select2-selection').evaluate(node=>getComputedStyle(node).backgroundColor),'rgb(52, 58, 64)');
  await field('design_name').fill(' Artlex ');await field('version_name').fill(' ST ');
  assert.equal(await field('title').inputValue(),'Artlex ST');
  await page.locator('#pb-manual-transfer-modal .select2-selection').click();
  await page.getByRole('option',{name:'KTM · EXC · 2008-2011 · TEST',exact:true}).click();
  assert.equal(await field('title').inputValue(),'Artlex ST For KTM EXC 2008-2011');
  await field('version_name').fill('');assert.equal(await field('title').inputValue(),'Artlex For KTM EXC 2008-2011');
  await field('version_name').fill('ST');await chooseCategory('');assert.equal(await field('title').inputValue(),'Artlex ST');
  await chooseCategory('1');assert.equal(await field('title').inputValue(),'Artlex ST For Honda Default');
  await chooseCategory('0');await field('source_sku').fill('SOURCE-SKU');
  assert.equal(await field('title').inputValue(),'Artlex ST For KTM EXC 2008-2011');
  await page.screenshot({path:'.cache/product-builder/manual-transfer-modal.png',fullPage:true});
  await field('title').fill('Manual <graphics> typo');
  await field('transfer-save').click();await page.waitForFunction(()=>document.querySelector('#pb-manual-transfer-error').textContent==='Test save failed');
  assert.equal(await field('title').inputValue(),'Manual <graphics> typo');assert.equal(rows.length,2);
  await field('transfer-save').click();await field('transfer-modal').waitFor({state:'hidden'});await page.locator('[data-transfer-row="90"]').waitFor();
  assert.equal(writes[0].request_key,writes[1].request_key,'Create retry uses the same request key.');assert.equal(rows.length,3);
  assert.match(await page.locator('[data-transfer-row="90"]').textContent(),/Manual <graphics> typo/);assert.equal(await page.locator('[data-transfer-row="90"] graphics').count(),0);
  assert.equal(await page.locator('[data-transfer-row="90"] td').count(),19);
  await page.click('[data-manual-transfer-edit="90"]');await page.locator('#pb-manual-transfer-save:enabled').waitFor();
  assert.equal(await field('title').inputValue(),'Manual <graphics> typo','Opening an edit keeps its saved name.');
  assert.equal(await field('category').inputValue(),'0');
  await field('version_name').fill('R1');assert.equal(await field('title').inputValue(),'Artlex R1 For KTM EXC 2008-2011');
  await field('title').fill('Manual graphics corrected');await field('transfer-save').click();await field('transfer-modal').waitFor({state:'hidden'});
  await page.waitForFunction(()=>document.querySelector('[data-transfer-row="90"]').textContent.includes('Manual graphics corrected'));
  assert.equal(rows.length,3);assert.equal(rows[0].version,2);
  await page.locator('[data-transfer-filter="completed"]').check();await page.locator('[data-transfer-row="2"]').waitFor();assert.equal(await page.locator('[data-manual-transfer-edit="2"]').count(),0);
  assert.equal(await page.locator('[data-transfer-prepare]').count(),0,'Manual creation does not grant access to Builder.');
  await page.setViewportSize({width:430,height:930});await page.click('#pb-new-transfer');await page.locator('#pb-manual-transfer-save:enabled').waitFor();
  await field('design_name').fill('Artlex');await field('version_name').fill('ST');await chooseCategory('0');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);await page.screenshot({path:'.cache/product-builder/manual-transfer-mobile.png',fullPage:true});
  await page.locator('#pb-manual-transfer-modal .close').click();await field('transfer-modal').waitFor({state:'hidden'});
  await page.goto('http://localhost/darkscrub/.cache/product-builder/i18n-transfers-sk.html');await page.locator('[data-transfer-row="1"]').waitFor();
  assert.equal(await page.locator('#pb-new-transfer').innerText(),'Nový manuálny transfer');
  await page.click('#pb-new-transfer');await page.locator('#pb-manual-transfer-save:enabled').waitFor();
  assert.equal(await field('transfer-title').innerText(),'Nový manuálny transfer');assert.equal(await field('transfer-save').innerText(),'Uložiť transfer');
  await field('design_name').fill('Artlex');await field('version_name').fill('ST');await chooseCategory('0');
  assert.equal(await field('title').inputValue(),'Artlex ST For KTM EXC 2008-2011','Product title uses For in both interface languages.');
  await page.locator('#pb-manual-transfer-modal .close').click();await field('transfer-modal').waitFor({state:'hidden'});
  assert.deepEqual(errors,[]);console.log('Manual transfer UI: five fields, generated names, category picker, UNI, empty inputs, editable name, create retry, edit, completed lock, EN/SK and mobile OK.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
