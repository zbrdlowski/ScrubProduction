const {chromium}=require('playwright'),assert=require('assert/strict');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const page=await browser.newPage({viewport:{width:1800,height:1100}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  const components=[{component_type:'G',label:'ARTLEX ST',quantity:1,design_name:'ARTLEX',graphics_version_name:'ST',options:{graphics_material:'Standard',graphics_finish:'Gloss'}},{component_type:'F',label:'Fitting',quantity:1,options:{fitting:'jm-1'}},{component_type:'P',label:'Kit',reference:'KT00692',color:'ORANGE',quantity:1,inventory_supplier:'Rtech',inventory_quantity:1,inventory_status:'matched'}];
  const listing={id:801,name:'Plastics + Graphics KTM SX 50',sku:'GFP_ARTLEX_ST',bundle_type:'GFP',category_kind:'specific',brand:'KTM',model:'SX 50',year_range:'2016-2023',modelcode:'VXR7',created_at:'2026-11-06 12:00:00',recommended_price:'210',recommended_currency:'EUR',component_count:3,components,img_status:'R'};
  let failNext=false,pendingRelease=null,holdNext=false;
  await page.route('**/scripts/product_builder/api.php?*',async route=>{
   assert.equal(route.request().method(),'GET','Focus must never write product data.');
   const url=new URL(route.request().url()),action=url.searchParams.get('action');
   if(action==='products')return route.fulfill({json:{ok:true,rows:[listing,{...listing,id:802,name:'Second listing'}],stats:{total:2,specific:2,universal:0}}});
   if(action==='product'){
    if(holdNext){holdNext=false;await new Promise(resolve=>{pendingRelease=resolve;});}
    if(failNext){failNext=false;return route.fulfill({status:503,json:{ok:false,error:'Test loading failure'}});}
    return route.fulfill({json:{ok:true,product:{...listing,id:Number(url.searchParams.get('id'))}}});
   }
   throw Error('Unexpected action '+action);
  });
  await page.goto('http://localhost/darkscrub/.cache/product-builder/listings-reader.html');
  await page.locator('[data-product-expand="801"]').waitFor();
  // Exercise the same ancestor structure as index.php, plus fixed navigation above ordinary content.
  await page.evaluate(()=>{
   const wrapper=document.createElement('div');wrapper.className='wrapper';
   const content=document.createElement('div');content.className='content-wrapper';content.style.marginLeft='0';
   const outerCard=document.createElement('div');outerCard.className='card';
   const cardBody=document.createElement('div');cardBody.className='card-body';
   cardBody.append(document.getElementById('product-builder'));outerCard.append(cardBody);content.append(outerCard);wrapper.append(content);document.body.append(wrapper);
   const nav=document.createElement('div');nav.id='test-fixed-nav';nav.style.cssText='position:fixed;top:0;left:0;width:100%;height:20px;z-index:1037;background:#38535d';nav.textContent='Navigation';document.body.append(nav);
  });
  const toggle=page.locator('[data-product-expand="801"]'),detail=page.locator('#pb-list-components-801'),backdrop=page.locator('.pb-components-backdrop');
  const open=async()=>{await toggle.click();await detail.locator('.pb-bundle-detail').waitFor();await page.waitForFunction(()=>!document.getElementById('pb-list-components-801').dataset.loading);assert.equal(await backdrop.isVisible(),true);};
  const closed=async()=>{assert.equal(await backdrop.isVisible(),false);assert.equal(await detail.isVisible(),false);assert.equal(await toggle.getAttribute('aria-expanded'),'false');assert.equal(await page.locator('.pb-components-focused').count(),0);};
  await open();
  assert.equal(await page.locator('.pb-components-focused').count(),2,'Only the product and its detail are raised.');
  assert.equal(await page.evaluate(()=>document.elementFromPoint(10,10).classList.contains('pb-components-backdrop')),true,'Fixed navigation is dimmed and covered.');
  // The wider table can be scrolled horizontally when Components is clicked. Probe the visible portion.
  await detail.locator('[data-detail-component="P"]').scrollIntoViewIfNeeded();
  assert.equal(await detail.locator('[data-detail-component="P"]').evaluate(node=>{const r=node.getBoundingClientRect(),view=node.closest('.table-responsive').getBoundingClientRect();const x=(Math.max(0,r.left,view.left)+Math.min(innerWidth,r.right,view.right))/2,y=(Math.max(0,r.top)+Math.min(innerHeight,r.bottom))/2;return node.contains(document.elementFromPoint(x,y));}),true,'The detail stays above the dimmer inside the real ancestor structure.');
  await page.evaluate(()=>{
   Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{throw Error('Use fallback');}}});
   document.execCommand=command=>{window.focusTestCopied=document.activeElement.value;return command==='copy';};
   Object.defineProperty(navigator,'vibrate',{configurable:true,value:duration=>{window.focusTestVibration=duration;return true;}});
  });
  const skuCopy=toggle.locator('xpath=ancestor::tr').locator('[data-pb-copy]').first();
  await skuCopy.click();await page.waitForFunction(()=>document.querySelector('.pb-components-focused .pb-copy-success'));
  assert.equal(await page.evaluate(()=>window.focusTestCopied),'GFP_ARTLEX_ST');assert.equal(await page.evaluate(()=>window.focusTestVibration),25);
  assert.equal(await backdrop.isVisible(),true,'Clipboard fallback must preserve the highlighted listing.');
  await page.screenshot({path:'.cache/product-builder/component-focus-desktop.png',fullPage:true});
  await page.keyboard.press('Escape');await closed();assert.equal(await toggle.evaluate(node=>node===document.activeElement),true,'Escape restores focus.');
  await open();await page.mouse.click(10,10);await closed();
  await open();await toggle.click();await closed();
  await open();await page.locator('#pb-search').focus();await closed();
  // A list refresh also clears the backdrop, even if triggered without moving focus.
  await open();await page.evaluate(()=>document.getElementById('pb-search').dispatchEvent(new Event('input',{bubbles:true})));await closed();await toggle.waitFor();
  await open();await page.locator('[data-product="801"]').click();await page.locator('#pb-detail-modal.show').waitFor();await closed();await page.locator('#pb-detail-modal [data-dismiss="modal"]').click();await page.locator('#pb-detail-modal').waitFor({state:'hidden'});
  holdNext=true;await toggle.click();await page.waitForFunction(()=>document.getElementById('pb-list-components-801').dataset.loading==='1');
  await page.keyboard.press('Escape');await closed();
  while(!pendingRelease)await new Promise(resolve=>setTimeout(resolve,10));pendingRelease();pendingRelease=null;
  await page.waitForFunction(()=>!document.getElementById('pb-list-components-801').dataset.loading);await closed();
  failNext=true;await toggle.click();await detail.getByText(/Test loading failure/).waitFor();assert.equal(await backdrop.isVisible(),true);await page.keyboard.press('Escape');await closed();
  await open();await page.keyboard.press('Escape');await page.locator('[data-product-expand="802"]').click();await page.locator('#pb-list-components-802 .pb-bundle-detail').waitFor();assert.equal(await toggle.getAttribute('aria-expanded'),'false');assert.equal(await page.locator('.pb-components-focused').count(),2);await page.keyboard.press('Escape');
  await page.setViewportSize({width:390,height:844});await open();
  await page.locator('#pb-saved-products>.table-responsive').evaluate(node=>{node.scrollLeft=0;});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,'No page overflow on mobile.');
  await page.screenshot({path:'.cache/product-builder/component-focus-mobile.png',fullPage:true});
  await page.keyboard.press('Escape');await closed();
  assert.deepEqual(errors,[]);
  console.log('Component focus: page dimming, active rows, fixed navigation, outside click, Escape, toggle, keyboard focus, modal, refresh, delayed/error responses, second listing and mobile OK.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
