const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const context=await browser.newContext({locale:'en-US',viewport:{width:1800,height:1100}}),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const fixture=name=>JSON.parse(fs.readFileSync('.cache/product-builder/'+name+'.json','utf8'));
  const states={};let writes=0,nextError=false,holdSave=false,releaseSave=null,notifyHeldSave=null;
  const base={id:801,version:1,name:'Graphics + Plastics KTM SX 50',sku:'GFP_ARTLEX_ST',bundle_type:'GFP',category_kind:'specific',brand:'KTM',model:'SX 50',year_range:'2016-2023',modelcode:'VXR7',created_at:'2026-11-06 12:00:00',recommended_price:'150.00',recommended_currency:'EUR',web_price:'149.90',web_currency:'EUR',ebay_price:'159.90',ebay_currency:'GBP',amazon_price:'169.90',amazon_currency:'USD',component_count:1,components:[{component_type:'G',label:'ARTLEX ST',quantity:1,options:{graphics_number:'Yes'}}]};
  const snapshot=()=>({...base,channel_listings:Object.fromEntries(['web','ebay','amazon'].map(channel=>[channel,structuredClone(states[channel]||empty(channel))]))});
  const empty=channel=>({product_id:801,channel,status:'do_not_list',url:null,price:null,currency:'EUR',listed_date:null,sold_quantity:{web:10,ebay:7,amazon:null}[channel],version:0});
  await context.route('https://shop.example.test/**',route=>route.fulfill({contentType:'text/html',body:'Mock listing opened'}));
  await context.route('**/scripts/product_builder/api.php?*',async route=>{
   const action=new URL(route.request().url()).searchParams.get('action');let body={ok:true};
   if(action==='products')Object.assign(body,{rows:[snapshot()],stats:{total:1,specific:1,universal:0}});
   else if(action==='product')body.product=snapshot();
   else if(action==='categories')body.rows=fixture('categories');
   else if(action==='boxes')body.rows=[];
   else if(action==='designs'){body.rows=fixture('designs');body.versions=fixture('graphics-versions');}
   else if(action==='save_channel_listing'){
    writes++;const data=JSON.parse(route.request().postData());assert.ok(route.request().headers()['x-csrf-token']);
    if(holdSave){holdSave=false;await new Promise(resolve=>{releaseSave=resolve;notifyHeldSave?.();});}
    if(nextError){nextError=false;return route.fulfill({status:503,json:{ok:false,error:'Unable to save channel.'}});}
    const old=states[data.channel]||empty(data.channel);
    if(data.version!==old.version)return route.fulfill({status:422,json:{ok:false,error:'Another user has changed this channel. Reload the latest channel data and try again.'}});
    const saved={...old,status:data.status,version:old.version+1};
    if(data.status==='listed')Object.assign(saved,{price:Number(data.price.replace(',','.')).toFixed(2),currency:data.currency,url:data.url,listed_date:data.listed_date});
    states[data.channel]=saved;body.listing=saved;
   }else throw Error('Unexpected API action '+action);
   await route.fulfill({json:body});
  });
  const url='http://localhost/darkscrub/.cache/product-builder/';await page.goto(url+'preview.html');
  const cell=channel=>page.locator('[data-channel-cell="801:'+channel+'"]'),select=channel=>cell(channel).locator('select'),actions=channel=>page.locator('[data-channel-actions="801:'+channel+'"]'),stats=channel=>page.locator('[data-channel-stats="801:'+channel+'"]');
  await select('web').waitFor();for(const channel of ['web','ebay','amazon'])assert.equal(await select(channel).inputValue(),'do_not_list');
  assert.equal(await cell('web').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(129, 61, 67)');
  assert.equal(await page.locator('.pb-products-table>thead th').count(),19);
  assert.equal(await stats('web').locator('.pb-channel-sold').textContent(),'10 pcs');assert.equal(await stats('amazon').locator('.pb-channel-sold').textContent(),'—');
  assert.equal(await cell('web').locator('a,button[data-channel-edit]').count(),0);
  await page.selectOption('#pb-bundle','G');await page.fill('#pb-name','UNSAVED DRAFT');await page.fill('#pb-web-price','987.65');
  await select('web').selectOption('list');await page.waitForFunction(()=>document.querySelector('[data-channel-cell="801:web"]').dataset.listingStatus==='list');
  assert.equal(writes,1);assert.equal(await cell('web').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(113, 61, 129)');assert.equal(await select('ebay').inputValue(),'do_not_list');
  const modal=page.locator('#pb-channel-modal');
  await select('web').selectOption('listed');await modal.waitFor({state:'visible'});assert.equal(await page.locator('#pb-channel-price').inputValue(),'149.90');assert.equal(await page.locator('#pb-channel-currency').inputValue(),'EUR');
  await modal.locator('[data-dismiss="modal"]').last().click();await modal.waitFor({state:'hidden'});assert.equal(await select('web').inputValue(),'list');assert.equal(writes,1,'Cancelling does not save Listed.');
  await select('web').selectOption('listed');await modal.waitFor({state:'visible'});
  await page.fill('#pb-channel-url','javascript:alert(1)');await page.click('#pb-channel-save');assert.equal(writes,1);assert.equal(await page.locator('#pb-channel-message').isVisible(),true);
  const target='https://shop.example.test/product/kit%20blue?variant=AT&source=team';
  assert.match(await page.locator('#pb-channel-date').inputValue(),/^\d{2}[.]\d{2}[.]\d{4}$/);await page.fill('#pb-channel-date','29.10.2026');
  await page.fill('#pb-channel-url',target);
  for(const bad of ['11/26/2025','31.02.2026','29.02.2025']){await page.fill('#pb-channel-date',bad);await page.click('#pb-channel-save');assert.equal(writes,1,'Invalid EU dates never submit.');}
  await page.locator('#pb-channel-date-picker').evaluate(input=>{input.value='2024-02-29';input.dispatchEvent(new Event('change',{bubbles:true}));});
  assert.equal(await page.locator('#pb-channel-date').inputValue(),'29.02.2024');assert.equal(await page.locator('#pb-channel-date').evaluate(input=>input.validity.valid),true);
  await page.fill('#pb-channel-date','29.10.2026');assert.equal(await page.locator('#pb-channel-date-picker').inputValue(),'2026-10-29');
  await page.fill('#pb-channel-price','129,90');await page.selectOption('#pb-channel-currency','GBP');await page.fill('#pb-channel-url',target);await page.click('#pb-channel-save');await modal.waitFor({state:'hidden'});
  assert.equal(states.web.price,'129.90');assert.equal(states.web.currency,'GBP');assert.equal(await cell('web').locator('.pb-channel-price').textContent(),'129.90 GBP');assert.equal(await cell('web').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(39, 99, 66)');
  assert.equal(states.web.listed_date,'2026-10-29');assert.equal(await stats('web').locator('time').textContent(),'29.10.2026');assert.equal(await stats('web').locator('.pb-channel-sold').textContent(),'10 pcs');
  assert.equal(await page.locator('#pb-name').inputValue(),'UNSAVED DRAFT');assert.equal(await page.locator('#pb-web-price').inputValue(),'987.65','Inline work preserves unsaved price inputs.');
  const link=actions('web').locator('a');assert.equal(await link.getAttribute('href'),target);assert.equal(await link.getAttribute('target'),'_blank');assert.match(await link.getAttribute('rel'),/noopener/);
  const popupPromise=page.waitForEvent('popup');await link.click();const popup=await popupPromise;await popup.waitForLoadState();assert.equal(popup.url(),target);assert.equal(await popup.evaluate(()=>window.opener===null),true);await popup.close();
  await actions('web').locator('[data-channel-edit]').click();await modal.waitFor({state:'visible'});assert.equal(await page.locator('#pb-channel-price').inputValue(),'129.90');assert.equal(await page.locator('#pb-channel-date').inputValue(),'29.10.2026');assert.equal(await page.locator('#pb-channel-url').inputValue(),target);
  await page.fill('#pb-channel-price','0');await page.click('#pb-channel-save');await modal.waitFor({state:'hidden'});assert.equal(await cell('web').locator('.pb-channel-price').textContent(),'0.00 GBP');
  // An unsuccessful status change keeps the confirmed state and offers an explicit reload.
  nextError=true;await select('web').selectOption('do_not_list');await cell('web').locator('[data-channel-reload]').waitFor();assert.equal(await select('web').inputValue(),'listed');
  await cell('web').locator('[data-channel-reload]').click();await page.waitForFunction(()=>!document.querySelector('[data-channel-cell="801:web"] select').disabled);
  // A stale version never overwrites another operator's work.
  states.web={...states.web,status:'list',version:states.web.version+1};
  await select('web').selectOption('do_not_list');await cell('web').locator('[data-channel-reload]').waitFor();assert.equal(states.web.status,'list');await cell('web').locator('[data-channel-reload]').click();await page.waitForFunction(()=>document.querySelector('[data-channel-cell="801:web"] select').value==='list');
  await select('web').selectOption('listed');await modal.waitFor({state:'visible'});assert.equal(await page.locator('#pb-channel-url').inputValue(),target,'Last URL is retained for relisting.');
  const saveHeld=new Promise(resolve=>{notifyHeldSave=resolve;});holdSave=true;await page.click('#pb-channel-save');await saveHeld;await page.keyboard.press('Escape');assert.equal(await modal.isVisible(),true,'Cannot dismiss an in-flight save.');
  assert.ok(releaseSave);releaseSave();releaseSave=null;await modal.waitFor({state:'hidden'});
  await select('ebay').selectOption('listed');await modal.waitFor({state:'visible'});assert.equal(await page.locator('#pb-channel-price').inputValue(),'159.90');assert.equal(await page.locator('#pb-channel-currency').inputValue(),'GBP');
  await page.fill('#pb-channel-url','https://shop.example.test/ebay');await page.selectOption('#pb-channel-currency','USD');await page.click('#pb-channel-save');await modal.waitFor({state:'hidden'});assert.equal(states.ebay.currency,'USD');assert.equal(states.web.currency,'GBP');
  await select('amazon').selectOption('list');await page.waitForFunction(()=>document.querySelector('[data-channel-cell="801:amazon"]').dataset.listingStatus==='list');
  await actions('web').locator('[data-channel-edit]').click();await modal.waitFor({state:'visible'});await page.fill('#pb-channel-date','30.10.2026');await page.click('#pb-channel-save');await modal.waitFor({state:'hidden'});assert.equal(await stats('web').locator('time').textContent(),'30.10.2026');
  assert.ok((await page.locator('.pb-category-meta code').boundingBox()).height<25,'Modelcode stays on one line in the wider table.');
  await page.locator('#pb-saved-products').screenshot({path:'.cache/product-builder/channel-listings-desktop.png'});
  await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);await actions('web').locator('[data-channel-edit]').click();await modal.waitFor({state:'visible'});await page.waitForFunction(()=>getComputedStyle(document.querySelector('#pb-channel-modal')).opacity==='1'&&getComputedStyle(document.querySelector('#pb-channel-modal .modal-dialog')).transform==='none');const mobileDialog=await modal.locator('.modal-dialog').boundingBox();assert.ok(mobileDialog.width<=390);assert.ok(mobileDialog.y>=0&&mobileDialog.y+mobileDialog.height<=844,'Channel modal fits the mobile viewport.');await page.screenshot({path:'.cache/product-builder/channel-listings-mobile.png',animations:'disabled'});await modal.locator('.close').click();await modal.waitFor({state:'hidden'});
  const reader=await context.newPage({viewport:{width:1600,height:1000}});reader.on('pageerror',e=>errors.push(e.message));await reader.goto(url+'listings-reader.html');await reader.locator('[data-channel-select]').first().waitFor();assert.equal(await reader.locator('[data-channel-select]:enabled').count(),0);assert.equal(await reader.locator('[data-channel-edit]').count(),0);assert.equal(await reader.locator('[data-channel-actions="801:web"] a').getAttribute('href'),target);
  await reader.locator('[data-product="801"]').click();await reader.locator('#pb-detail-modal').waitFor({state:'visible'});
  assert.deepEqual(await reader.locator('#pb-detail .pb-price-overview-amount').allTextContents(),['150.00 EUR','0.00 GBP','159.90 USD','169.90 USD']);
  assert.equal(await reader.locator('#pb-detail .pb-price-overview a').first().getAttribute('href'),target);
  await reader.goto(url+'i18n-listings-sk.html');await reader.locator('[data-channel-select]').first().waitFor();assert.deepEqual(await reader.locator('[data-channel-cell="801:web"] option').allTextContents(),['Nelistovať','Zalistovať','Zalistované']);
  assert.deepEqual(errors,[]);console.log('Channel UI: defaults/colors, inline states, cancel, price/currency/URL, safe new tab, zero, draft preservation, failures, conflicts/reload, pending modal, independent channels, reader permissions, SK/EN and mobile OK.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
