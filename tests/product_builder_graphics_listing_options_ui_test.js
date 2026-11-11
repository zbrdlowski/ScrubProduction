const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1800,height:1100}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('dialog',dialog=>dialog.accept());
  const disabledKeys=['graphics_name','graphics_name_font','graphics_number_font','graphics_printer'];
  const previous={graphics_name:'Alex <Team>',graphics_name_font:'Retired name font',graphics_number_font:'Retired number font',graphics_printer:'Old printer',graphics_number:'99',graphics_material:'Standard',graphics_finish:'Gloss'};
  let listing={id:701,version:1,name:'Legacy graphics listing',sku:'G_LEGACY',bundle_type:'G',category_kind:'specific',brand:'KTM',model:'SX 50',year_range:'2016-2023',modelcode:'VXR7',recommended_price:'210.00',recommended_currency:'EUR',component_count:1,components:[{component_type:'G',label:'ARTLEX ST',quantity:1,options:{...previous}}]},saved=null,saveCount=0;
  const fixture=name=>JSON.parse(fs.readFileSync('.cache/product-builder/'+name+'.json','utf8'));
  await page.route('**/scripts/product_builder/api.php?*',async route=>{
   const action=new URL(route.request().url()).searchParams.get('action');let body={ok:true};
   if(action==='categories')body.rows=fixture('categories');
   else if(action==='boxes')body.rows=[];
   else if(action==='designs'){body.rows=fixture('designs');body.versions=fixture('graphics-versions');}
   else if(action==='products')Object.assign(body,{rows:[listing],stats:{total:1,specific:1,universal:0}});
   else if(action==='product')body.product=listing;
   else if(action==='save_product'){
    const data=JSON.parse(route.request().postData());const options=data.components[0].options;
    for(const key of disabledKeys)assert.equal(options[key],previous[key],'Disabled legacy value must survive save: '+key);
    assert.equal(options.graphics_number,saveCount===0?'No':'Yes');
    saved=data;saveCount++;listing={...listing,...data,id:701,version:saveCount+1};body.product={id:701,sku:listing.sku};
   }else throw Error('Unexpected action '+action);
   await route.fulfill({json:body});
  });
  await page.goto('http://localhost/darkscrub/.cache/product-builder/preview.html');
  await page.selectOption('#pb-bundle','G');
  for(const key of disabledKeys){assert.equal(await page.locator('[data-spec="'+key+'"]').isDisabled(),true);assert.equal(await page.locator('[data-spec="'+key+'"]').locator('..').getAttribute('class'),'pb-spec-field pb-spec-field-disabled');}
  const number=page.locator('[data-spec="graphics_number"]');
  assert.equal(await number.evaluate(node=>node.tagName),'SELECT');
  assert.deepEqual(await number.locator('option').evaluateAll(options=>options.filter(o=>o.value).map(o=>[o.value,o.textContent])),[['Yes','Yes'],['No','No']]);
  assert.equal(await number.inputValue(),'','New product does not assume a number choice.');
  await number.selectOption('No');await page.selectOption('#pb-bundle','GS');await page.selectOption('#pb-bundle','G');assert.equal(await number.inputValue(),'No');
  await page.locator('[data-component-row="G"]').screenshot({path:'.cache/product-builder/graphics-listing-options-form.png'});
  // Removing shared definitions must not make saved disabled fields editable or visible in details.
  await page.evaluate(()=>{window.productBuilderConfig.specFields.G=window.productBuilderConfig.specFields.G.filter(field=>!['graphics_name_font','graphics_number'].includes(field.key));});
  await page.locator('[data-product="701"]').click();
  await page.waitForFunction(()=>document.getElementById('pb-sku').value==='G_LEGACY');
  assert.equal(await number.inputValue(),'Yes','Legacy rider number is represented by Yes.');
  assert.deepEqual(await number.locator('option').evaluateAll(options=>options.filter(o=>o.value).map(o=>o.value)),['Yes','No'],'A legacy number is never appended as a third option.');
  for(const key of disabledKeys){assert.equal(await page.locator('[data-spec="'+key+'"]').inputValue(),previous[key]);assert.equal(await page.locator('[data-spec="'+key+'"]').isDisabled(),true);}

  await number.selectOption('No');await page.click('#pb-save');await page.waitForFunction(()=>document.getElementById('pb-message').textContent.includes('Product saved.'));
  assert.equal(saved.components[0].options.graphics_number,'No');assert.equal(await number.inputValue(),'No');
  await number.selectOption('Yes');await page.click('#pb-save');await page.waitForResponse(r=>r.url().includes('action=products'));assert.equal(saved.components[0].options.graphics_number,'Yes');
  await page.locator('[data-product-expand="701"]').click();const detail=page.locator('#pb-list-components-701');await detail.locator('.pb-bundle-detail').waitFor();
  for(const key of disabledKeys)assert.equal(await detail.locator('[data-detail-field="'+key+'"]').count(),0);
  assert.equal(await detail.locator('[data-detail-field="graphics_number"] dd').textContent(),'Yes');
  await page.keyboard.press('Escape');
  await page.goto('http://localhost/darkscrub/.cache/product-builder/listings-reader.html');await page.locator('[data-product="701"]').click();
  const modal=page.locator('#pb-detail-modal');await modal.locator('.pb-bundle-detail').waitFor();
  for(const key of disabledKeys)assert.equal(await modal.locator('[data-detail-field="'+key+'"]').count(),0);
  assert.equal(await modal.locator('[data-detail-field="graphics_number"] dd').textContent(),'Yes');
  await page.goto('http://localhost/darkscrub/.cache/product-builder/i18n-listings-sk.html');await page.locator('[data-product-expand="701"]').click();await page.locator('#pb-list-components-701 .pb-bundle-detail').waitFor();
  assert.equal(await page.locator('#pb-list-components-701 [data-detail-field="graphics_number"] dd').textContent(),'Áno');
  await page.goto('http://localhost/darkscrub/.cache/product-builder/i18n-builder-sk.html');await page.selectOption('#pb-bundle','TFP');
  assert.deepEqual(await number.locator('option').evaluateAll(options=>options.filter(o=>o.value).map(o=>o.textContent)),['Áno','Nie']);
  assert.equal(await page.locator('[data-component-row="F"] [data-spec="fitting"]').isDisabled(),false,'Fitting remains editable.');
  for(const key of disabledKeys)assert.equal(await page.locator('[data-spec="'+key+'"]').isDisabled(),true,'Trim Kit uses the graphics listing rules.');
  await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);assert.deepEqual(errors,[]);
  console.log('Graphics listing options: disabled fields, Yes/No saving, legacy numbers and preserved values, retired definitions, inline/modal details, SK/EN, Trim Kit and mobile OK.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
